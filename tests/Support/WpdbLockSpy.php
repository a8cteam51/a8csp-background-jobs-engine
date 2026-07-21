<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

/**
 * In-memory wpdb fake for execution-overlap lock statements.
 */
final class WpdbLockSpy extends \wpdb {
	/** @var array<string, string> Raw option values keyed by option name. */
	public array $rows = array();

	/** @var array<string, string> Autoload values keyed by option name. */
	public array $autoload = array();

	/** @var list<string> Prepared statements in execution order. */
	public array $recorded_queries = array();

	/** @var list<mixed>|null Scripted option-name scan result. */
	public ?array $option_name_results = null;

	/** @var array<string, array{template: string, args: list<mixed>}> */
	private array $prepared = array();

	/** @var array<string, list<callable(self): void>> */
	private array $before_operations = array();

	/** @var array<string, list<0|false>> */
	private array $scripted_results = array();

	/** Creates a disconnected options-table fake. */
	public function __construct() {
		parent::__construct( '', '', '', '' );
		$this->options = 'wp_options';
	}

	/**
	 * Stores a raw lock row as a test precondition.
	 *
	 * @param   string $key      Option name.
	 * @param   string $raw      Raw option value.
	 * @param   string $autoload Autoload value.
	 *
	 * @return  void
	 */
	public function put( string $key, string $raw, string $autoload = 'off' ): void {
		$this->rows[ $key ]     = $raw;
		$this->autoload[ $key ] = $autoload;
	}

	/**
	 * Returns whether one modeled row is excluded from autoloading.
	 *
	 * @param   string $key Option name.
	 *
	 * @return  bool
	 */
	public function is_non_autoloaded( string $key ): bool {
		return 'off' === ( $this->autoload[ $key ] ?? null );
	}

	/**
	 * Runs a callback immediately before the next matching operation reaches storage.
	 *
	 * @param   'count'|'insert'|'scan'|'select'|'update'|'delete' $operation Query operation.
	 * @param   callable(self): void                               $callback  Interleaving callback.
	 *
	 * @return  void
	 */
	public function before_next( string $operation, callable $callback ): void {
		$this->before_operations[ $operation ][] = $callback;
	}

	/**
	 * Scripts the affected-row result for the next matching write without changing storage.
	 *
	 * @param   'insert'|'update'|'delete' $operation Query operation.
	 * @param   int|false                  $result    Query result.
	 *
	 * @return  void
	 */
	public function script_result( string $operation, int|false $result ): void {
		if ( false !== $result && 0 !== $result ) {
			throw new \InvalidArgumentException( 'Script only failed or zero-row writes; successful writes come from modeled state.' );
		}

		$this->scripted_results[ $operation ][] = $result;
	}

	/**
	 * Prepares and records a lock statement without reparsing substituted values.
	 *
	 * @param   mixed $query   Query template.
	 * @param   mixed ...$args Query arguments.
	 *
	 * @return  string
	 */
	#[\Override]
	public function prepare( $query, ...$args ): string {
		if ( ! \is_string( $query ) ) {
			throw new \InvalidArgumentException( 'WpdbLockSpy query templates must be strings.' );
		}

		$arguments = \array_values( $args );
		$parts     = \preg_split( '/(%[dis])/', $query, -1, \PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy could not parse the prepared statement.' );
		}

		$prepared = '';
		$index    = 0;
		foreach ( $parts as $part ) {
			if ( '%s' !== $part && '%d' !== $part && '%i' !== $part ) {
				$prepared .= $part;
				continue;
			}

			if ( ! \array_key_exists( $index, $arguments ) ) {
				throw new \InvalidArgumentException( 'WpdbLockSpy requires one value for every placeholder.' );
			}
			if ( '%d' === $part && ! \is_int( $arguments[ $index ] ) ) {
				throw new \InvalidArgumentException( 'WpdbLockSpy integer placeholders require integer values.' );
			}

			$prepared .= match ( $part ) {
				'%i'    => self::quote_table( $arguments[ $index ] ),
				'%d'    => (string) $arguments[ $index ],
				default => self::quote( $arguments[ $index ] ),
			};
			++$index;
		}

		if ( \count( $arguments ) !== $index ) {
			throw new \InvalidArgumentException( 'WpdbLockSpy received more values than placeholders.' );
		}

		$this->prepared[ $prepared ] = array(
			'template' => $query,
			'args'     => $arguments,
		);

		return $prepared;
	}

	/**
	 * Executes a modeled lock write.
	 *
	 * @param   mixed $query Prepared statement.
	 *
	 * @return  int|bool
	 */
	#[\Override]
	public function query( $query ): int|bool {
		if ( ! \is_string( $query ) ) {
			throw new \InvalidArgumentException( 'WpdbLockSpy writes require a prepared query string.' );
		}

		$statement = $this->statement( $query );
		$operation = self::write_operation( $statement['template'] );

		$this->run_before( $operation );
		$this->recorded_queries[] = $query;

		$lifecycle_events = $GLOBALS['a8csp_bgje_test_lifecycle_events'] ?? null;
		if ( \is_array( $lifecycle_events ) ) {
			$operation_args     = self::without_table( $statement['args'] );
			$key_index          = 'update' === $operation ? 1 : 0;
			$lifecycle_events[] = array(
				'type'      => 'lock',
				'operation' => $operation,
				'key'       => $operation_args[ $key_index ] ?? null,
				'raw'       => 'update' === $operation ? ( $operation_args[0] ?? null ) : null,
			);

			$GLOBALS['a8csp_bgje_test_lifecycle_events'] = $lifecycle_events;
		}

		$scripted = isset( $this->scripted_results[ $operation ] )
			? \array_shift( $this->scripted_results[ $operation ] )
			: null;
		if ( null !== $scripted ) {
			$this->rows_affected = false === $scripted ? 0 : $scripted;

			return $scripted;
		}

		$result = match ( $operation ) {
			'insert' => $this->apply_insert( $statement['args'] ),
			'update' => $this->apply_update( $statement['args'] ),
			'delete' => $this->apply_delete( $statement['args'] ),
		};
		$this->rows_affected = $result;

		return $result;
	}

	/**
	 * Selects one modeled raw lock row.
	 *
	 * @phpstan-param 'OBJECT'|'ARRAY_A'|'ARRAY_N' $output
	 * @phpstan-return null|($output is 'ARRAY_A' ? array{option_value: string} : ($output is 'ARRAY_N' ? list{string} : \stdClass))
	 *
	 * @param   mixed $query  Prepared statement.
	 * @param   mixed $output Output shape.
	 * @param   mixed $y      Row offset.
	 *
	 * @return  array<array-key, mixed>|\stdClass|null
	 */
	#[\Override]
	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ): array|\stdClass|null {
		if ( ! \is_string( $query ) ) {
			throw new \InvalidArgumentException( 'WpdbLockSpy selects require a prepared query string.' );
		}

		$statement = $this->statement( $query );
		if ( ! \str_starts_with( $statement['template'], 'SELECT ' ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy get_row() accepts only lock SELECT statements.' );
		}

		$this->last_error = '';
		$this->run_before( 'select' );
		$this->recorded_queries[] = $query;
		if ( '' !== $this->last_error ) {
			return null;
		}

		$args = self::without_table( $statement['args'] );
		$key  = $args[0] ?? null;
		if ( ! \is_string( $key ) ) {
			return null;
		}

		$raw = $this->raw_value( $key );
		if ( null === $raw ) {
			return null;
		}

		$row = array( 'option_value' => $raw );

		return match ( $output ) {
			'ARRAY_A' => $row,
			'ARRAY_N' => \array_values( $row ),
			default   => (object) $row,
		};
	}

	/**
	 * Selects modeled raw option rows by exact option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param 'OBJECT'|'OBJECT_K'|'ARRAY_A'|'ARRAY_N' $output
	 * @phpstan-return ($output is 'ARRAY_A' ? list<array{option_name: string, option_value: string}> : ($output is 'ARRAY_N' ? list<list{string, string}> : ($output is 'OBJECT_K' ? array<string, \stdClass> : list<\stdClass>)))
	 *
	 * @param   mixed $query  Prepared statement.
	 * @param   mixed $output Output shape.
	 *
	 * @return  array<array-key, mixed>
	 */
	#[\Override]
	public function get_results( $query = null, $output = 'OBJECT' ): array {
		if ( ! \is_string( $query ) ) {
			throw new \InvalidArgumentException( 'WpdbLockSpy selects require a prepared query string.' );
		}

		$statement = $this->statement( $query );
		if ( ! \str_starts_with( $statement['template'], 'SELECT `option_name`, `option_value` ' ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy get_results() accepts only option-row SELECT statements.' );
		}

		$this->last_error = '';
		$this->run_before( 'select' );
		$this->recorded_queries[] = $query;
		if ( '' !== $this->last_error ) {
			return array();
		}

		$rows = array();
		foreach ( self::without_table( $statement['args'] ) as $key ) {
			if ( ! \is_string( $key ) ) {
				continue;
			}

			$raw = $this->raw_value( $key );
			if ( null === $raw ) {
				continue;
			}

			$row = array(
				'option_name'  => $key,
				'option_value' => $raw,
			);
			if ( 'OBJECT_K' === $output ) {
				$rows[ $key ] = (object) $row;
				continue;
			}

			$rows[] = match ( $output ) {
				'ARRAY_A' => $row,
				'ARRAY_N' => \array_values( $row ),
				default   => (object) $row,
			};
		}

		return $rows;
	}

	/**
	 * Returns option names matching one prepared escaped-prefix scan.
	 *
	 * @param   mixed $query Prepared statement.
	 * @param   mixed $x     Column offset.
	 *
	 * @return  list<mixed>
	 */
	#[\Override]
	public function get_col( $query = null, $x = 0 ): array {
		if ( ! \is_string( $query ) ) {
			throw new \InvalidArgumentException( 'WpdbLockSpy scans require a prepared query string.' );
		}

		$statement = $this->statement( $query );
		if ( ! \str_starts_with( $statement['template'], 'SELECT `option_name` ' ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy get_col() accepts only option-name scans.' );
		}

		$this->last_error = '';
		$this->run_before( 'scan' );
		$this->recorded_queries[] = $query;
		if ( '' !== $this->last_error ) {
			return array();
		}
		if ( null !== $this->option_name_results ) {
			return $this->option_name_results;
		}

		$args             = self::without_table( $statement['args'] );
		$pattern          = $args[0] ?? null;
		$has_total_length = \str_contains( $statement['template'], 'LENGTH(`option_name`) = %d' );
		$total_length     = $has_total_length ? ( $args[1] ?? null ) : null;
		$has_cursor       = \str_contains( $statement['template'], 'BINARY `option_name` > BINARY %s' );
		$cursor           = null;
		if ( $has_cursor ) {
			$cursor = $args[ $has_total_length ? 2 : 1 ] ?? null;
			if ( ! \is_string( $cursor ) ) {
				throw new \UnexpectedValueException( 'WpdbLockSpy keyset option scans require a string cursor.' );
			}
		}
		$limit_index = 1 + ( $has_total_length ? 1 : 0 ) + ( $has_cursor ? 1 : 0 );
		$limit       = $args[ $limit_index ] ?? null;
		if ( ! \is_string( $pattern ) || ! \str_ends_with( $pattern, '%' ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy option scans require one trailing-wildcard pattern.' );
		}
		if ( null !== $total_length && ! \is_int( $total_length ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy bounded option scans require an integer name length.' );
		}
		if ( null !== $limit && ! \is_int( $limit ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy bounded option scans require an integer limit.' );
		}

		$escaped_prefix = \substr( $pattern, 0, -1 );
		$prefix         = \preg_replace( '/\\\\([\\\\_%])/', '$1', $escaped_prefix );
		if ( ! \is_string( $prefix ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy could not decode the escaped option prefix.' );
		}

		$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();
		if ( ! \is_array( $options ) ) {
			throw new \UnexpectedValueException( 'Initialize the test option store as an array.' );
		}

		$names = \array_unique( array( ...\array_keys( $this->rows ), ...\array_keys( $options ) ) );
		$names = \array_values( \array_filter( $names, static fn ( mixed $name ): bool => \is_string( $name ) && 0 === \strncasecmp( $name, $prefix, \strlen( $prefix ) ) && ( null === $total_length || \strlen( $name ) === $total_length ) && ( null === $cursor || 0 < \strcmp( $name, $cursor ) ) ) );
		\sort( $names, \SORT_STRING );

		return null === $limit ? $names : \array_slice( $names, 0, $limit );
	}

	/**
	 * Counts option names matching one prepared escaped-prefix and exact-length query.
	 *
	 * @param   mixed $query Prepared statement.
	 * @param   mixed $x     Column offset.
	 * @param   mixed $y     Row offset.
	 *
	 * @return  string|null
	 */
	public function get_var( $query = null, $x = 0, $y = 0 ): ?string {
		if ( ! \is_string( $query ) ) {
			throw new \InvalidArgumentException( 'WpdbLockSpy counts require a prepared query string.' );
		}

		$statement = $this->statement( $query );
		if ( ! \str_starts_with( $statement['template'], 'SELECT COUNT(*) ' ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy get_var() accepts only option-name count statements.' );
		}

		$this->last_error = '';
		$this->run_before( 'count' );
		$this->recorded_queries[] = $query;
		if ( '' !== $this->last_error ) {
			return null;
		}

		$args         = self::without_table( $statement['args'] );
		$pattern      = $args[0] ?? null;
		$total_length = $args[1] ?? null;
		if (
			! \is_string( $pattern )
			|| ! \str_ends_with( $pattern, '%' )
			|| ! \is_int( $total_length )
		) {
			throw new \UnexpectedValueException( 'WpdbLockSpy option counts require a trailing-wildcard pattern and integer name length.' );
		}

		$escaped_prefix = \substr( $pattern, 0, -1 );
		$prefix         = \preg_replace( '/\\\\([\\\\_%])/', '$1', $escaped_prefix );
		if ( ! \is_string( $prefix ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy could not decode the escaped option prefix.' );
		}

		$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();
		if ( ! \is_array( $options ) ) {
			throw new \UnexpectedValueException( 'Initialize the test option store as an array.' );
		}

		$names = \array_unique( array( ...\array_keys( $this->rows ), ...\array_keys( $options ) ) );

		return (string) \count( \array_filter( $names, static fn ( mixed $name ): bool => \is_string( $name ) && \strlen( $name ) === $total_length && 0 === \strncasecmp( $name, $prefix, \strlen( $prefix ) ) ) );
	}

	/**
	 * Models INSERT IGNORE against option_name's unique key.
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   array $args Prepared arguments.
	 *
	 * @return  int
	 */
	private function apply_insert( array $args ): int {
		$args = self::without_table( $args );

		[ $key, $raw ] = self::string_pair( $args );
		if ( $this->has_row( $key ) ) {
			return 0;
		}

		$this->put( $key, $raw );

		return 1;
	}

	/**
	 * Models a raw-value compare-and-swap update, including MySQL's unchanged-row count.
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   array $args Prepared arguments.
	 *
	 * @return  int
	 */
	private function apply_update( array $args ): int {
		$args         = self::without_table( $args );
		$new_raw      = $args[0] ?? null;
		$key          = $args[1] ?? null;
		$expected_raw = $args[2] ?? null;
		if ( ! \is_string( $new_raw ) || ! \is_string( $key ) || ! \is_string( $expected_raw ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy UPDATE expects new value, key, and expected value.' );
		}

		$current_raw = $this->raw_value( $key );
		if ( null === $current_raw || $expected_raw !== $current_raw ) {
			return 0;
		}

		if ( $new_raw === $current_raw ) {
			return 0;
		}

		$this->replace_raw( $key, $new_raw );

		return 1;
	}

	/**
	 * Models a raw-value compare-and-swap delete.
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   array $args Prepared arguments.
	 *
	 * @return  int
	 */
	private function apply_delete( array $args ): int {
		$args = self::without_table( $args );

		[ $key, $expected_raw ] = self::string_pair( $args );
		$current_raw            = $this->raw_value( $key );
		if ( null === $current_raw || $expected_raw !== $current_raw ) {
			return 0;
		}

		$this->delete_raw( $key );

		return 1;
	}

	/**
	 * Returns one prepared statement and its original arguments.
	 *
	 * @param   mixed $query Prepared query.
	 *
	 * @return  array{template: string, args: list<mixed>}
	 */
	private function statement( mixed $query ): array {
		if ( ! \is_string( $query ) || ! isset( $this->prepared[ $query ] ) ) {
			throw new \UnexpectedValueException( 'Prepare every WpdbLockSpy query before execution.' );
		}

		return $this->prepared[ $query ];
	}

	/**
	 * Returns the write operation encoded by a lock statement.
	 *
	 * @param   string $query Query template.
	 *
	 * @return  'insert'|'update'|'delete'
	 */
	private static function write_operation( string $query ): string {
		return match ( true ) {
			\str_starts_with( $query, 'INSERT IGNORE ' ) => 'insert',
			\str_starts_with( $query, 'UPDATE ' )        => 'update',
			\str_starts_with( $query, 'DELETE ' )        => 'delete',
			default => throw new \UnexpectedValueException( 'WpdbLockSpy accepts only lock write statements.' ),
		};
	}

	/**
	 * Runs and consumes the next matching interleaving callback.
	 *
	 * @param   string $operation Query operation.
	 *
	 * @return  void
	 */
	private function run_before( string $operation ): void {
		$callback = isset( $this->before_operations[ $operation ] )
			? \array_shift( $this->before_operations[ $operation ] )
			: null;
		if ( null !== $callback ) {
			$callback( $this );
		}
	}

	/**
	 * Returns two required string arguments.
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   array $args Prepared arguments.
	 *
	 * @return  array{string, string}
	 */
	private static function string_pair( array $args ): array {
		$first  = $args[0] ?? null;
		$second = $args[1] ?? null;
		if ( ! \is_string( $first ) || ! \is_string( $second ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy expects two string arguments.' );
		}

		return array( $first, $second );
	}

	/**
	 * Removes and validates the prepared options-table identifier.
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   array $args Prepared arguments.
	 *
	 * @return  list<mixed>
	 */
	private static function without_table( array $args ): array {
		$table = \array_shift( $args );
		if ( 'wp_options' !== $table ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy queries must target its options table.' );
		}

		return $args;
	}

	/**
	 * Returns whether either modeled view contains an option row.
	 *
	 * @param   string $key Option name.
	 *
	 * @return  bool
	 */
	private function has_row( string $key ): bool {
		if ( \array_key_exists( $key, $this->rows ) ) {
			return true;
		}

		$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();

		return \is_array( $options ) && \array_key_exists( $key, $options );
	}

	/**
	 * Returns one row's exact database representation from either modeled view.
	 *
	 * @param   string $key Option name.
	 *
	 * @return  string|null
	 */
	private function raw_value( string $key ): ?string {
		if ( \array_key_exists( $key, $this->rows ) ) {
			return $this->rows[ $key ];
		}

		$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();
		if ( ! \is_array( $options ) || ! \array_key_exists( $key, $options ) ) {
			return null;
		}

		$raw = \maybe_serialize( $options[ $key ] );

		return \is_string( $raw ) ? $raw : null;
	}

	/**
	 * Replaces one row in the modeled view that currently owns it.
	 *
	 * @param   string $key Option name.
	 * @param   string $raw Exact replacement value.
	 *
	 * @return  void
	 */
	private function replace_raw( string $key, string $raw ): void {
		if ( \array_key_exists( $key, $this->rows ) ) {
			$this->rows[ $key ] = $raw;

			return;
		}

		$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();
		if ( ! \is_array( $options ) ) {
			throw new \UnexpectedValueException( 'Initialize the test option store as an array.' );
		}

		$options[ $key ]                    = \maybe_unserialize( $raw );
		$GLOBALS['a8csp_bgje_test_options'] = $options;
	}

	/**
	 * Deletes one row from whichever modeled view currently owns it.
	 *
	 * @param   string $key Option name.
	 *
	 * @return  void
	 */
	private function delete_raw( string $key ): void {
		if ( \array_key_exists( $key, $this->rows ) ) {
			unset( $this->rows[ $key ], $this->autoload[ $key ] );

			return;
		}

		$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();
		if ( ! \is_array( $options ) ) {
			throw new \UnexpectedValueException( 'Initialize the test option store as an array.' );
		}

		$autoload = $GLOBALS['a8csp_bgje_test_option_autoload'] ?? array();
		if ( ! \is_array( $autoload ) ) {
			throw new \UnexpectedValueException( 'Initialize the test option autoload store as an array.' );
		}

		unset( $options[ $key ], $autoload[ $key ] );
		$GLOBALS['a8csp_bgje_test_options']         = $options;
		$GLOBALS['a8csp_bgje_test_option_autoload'] = $autoload;
	}

	/**
	 * Quotes one prepared value for an observable SQL statement.
	 *
	 * @param   mixed $value Prepared value.
	 *
	 * @return  string
	 */
	private static function quote( mixed $value ): string {
		if ( ! \is_scalar( $value ) && null !== $value ) {
			throw new \InvalidArgumentException( 'WpdbLockSpy prepares only scalar values.' );
		}

		return "'" . \str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $value ) . "'";
	}

	/**
	 * Quotes one identifier placeholder for an observable SQL statement.
	 *
	 * @param   mixed $value Prepared identifier.
	 *
	 * @return  string
	 */
	private static function quote_table( mixed $value ): string {
		if ( ! \is_string( $value ) ) {
			throw new \InvalidArgumentException( 'WpdbLockSpy identifier placeholders require strings.' );
		}

		return "\x60" . \str_replace( "\x60", "\x60\x60", $value ) . "\x60";
	}
}
