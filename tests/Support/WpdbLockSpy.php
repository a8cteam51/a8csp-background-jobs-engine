<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

/**
 * In-memory wpdb fake for execution-overlap lock statements.
 */
final class WpdbLockSpy extends \wpdb {
	// region FIELDS AND CONSTANTS.

	/** @var array<string, string> Raw option values keyed by option name. */
	public array $rows = array();

	/** @var array<string, string> Autoload values keyed by option name. */
	public array $autoload = array();

	/** @var list<string> Prepared statements in execution order. */
	public array $recorded_queries = array();

	/** @var list<mixed>|null Scripted option-name scan result. */
	public ?array $option_name_results = null;

	/** @var list<\stdClass>|null Scripted option-row read result. */
	public ?array $option_row_results = null;

	/** @var array<\stdClass>|null Core-shaped result buffer. */
	public $last_result = array();

	/** @var array<string, array{template: string, args: list<mixed>}> */
	private array $prepared = array();

	/** @var array<string, list<callable(self): void>> */
	private array $before_operations = array();

	/** @var array<string, list<0|false>> */
	private array $scripted_results = array();

	/** @var list<string> Option-name fragments whose updates always report zero affected rows. */
	private array $failing_update_keys = array();

	/** @var list<string> Prepared-argument fragments whose option-name scans fail. */
	private array $failing_scan_needles = array();

	/** @var 'not_ready'|'query_filtered'|'reconnect_failed'|null Next Core query failure leg. */
	private ?string $next_read_failure_leg = null;

	// endregion.

	// region MAGIC METHODS.

	/** Creates a disconnected options-table fake. */
	public function __construct() {
		parent::__construct( '', '', '', '' );
		$this->options = 'wp_options';
	}

	// endregion.

	// region METHODS.

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
	 * @param   'insert'|'scan'|'select'|'update'|'delete' $operation Query operation.
	 * @param   callable(self): void                       $callback  Interleaving callback.
	 *
	 * @return  void
	 */
	public function before_next( string $operation, callable $callback ): void {
		$this->before_operations[ $operation ][] = $callback;
	}

	/**
	 * Makes every update against a matching option name report zero affected rows.
	 *
	 * Positional scripting shifts whenever a read or write is added anywhere earlier, so a fault that
	 * has to survive repeated admission attempts is matched on its target rather than its ordinal.
	 *
	 * @param   string $needle Option-name fragment whose updates must lose.
	 *
	 * @return  void
	 */
	public function fail_updates_targeting( string $needle ): void {
		$this->failing_update_keys[] = $needle;
	}

	/**
	 * Makes option-name scans with a matching prepared argument report a database error.
	 *
	 * @param   string $needle Prepared-argument fragment whose scan must fail.
	 *
	 * @return  void
	 */
	public function fail_scans_targeting( string $needle ): void {
		$this->failing_scan_needles[] = $needle;
	}

	/**
	 * Disarms every matched update failure so modeled writes win again.
	 *
	 * @return  void
	 */
	public function stop_failing_updates(): void {
		$this->failing_update_keys = array();
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
	 * Makes the next read return false through one Core query leg without setting last_error.
	 *
	 * @param   'not_ready'|'query_filtered'|'reconnect_failed' $leg Core query failure leg.
	 *
	 * @return  void
	 */
	public function fail_next_read_at( string $leg ): void {
		if ( ! \in_array( $leg, array( 'not_ready', 'query_filtered', 'reconnect_failed' ), true ) ) {
			throw new \InvalidArgumentException( 'WpdbLockSpy requires a modeled Core query failure leg.' );
		}

		$this->next_read_failure_leg = $leg;
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
	 * Executes a modeled option-table query.
	 *
	 * @param   mixed $query Prepared statement.
	 *
	 * @return  int|bool
	 */
	#[\Override]
	public function query( $query ): int|bool {
		if ( ! \is_string( $query ) ) {
			throw new \InvalidArgumentException( 'WpdbLockSpy queries require a prepared query string.' );
		}

		$statement = $this->statement( $query );
		if ( \str_starts_with( $statement['template'], 'SELECT ' ) ) {
			return $this->execute_read( $statement, $query );
		}

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

		if ( 'update' === $operation && $this->targets_failing_key( $statement['args'] ) ) {
			$this->rows_affected = 0;

			return 0;
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
	 * Returns option names matching one prepared escaped-prefix scan.
	 *
	 * @param   mixed $query Prepared statement.
	 * @param   mixed $x     Column offset.
	 *
	 * @return  list<mixed>
	 */
	#[\Override]
	public function get_col( $query = null, $x = 0 ): array {
		if ( null !== $query ) {
			$this->query( $query );
		}
		if ( ! \is_int( $x ) ) {
			throw new \InvalidArgumentException( 'WpdbLockSpy column offsets must be integers.' );
		}

		return \array_values( \array_map( static fn ( \stdClass $row ): mixed => \array_values( \get_object_vars( $row ) )[ $x ] ?? null, $this->last_result ?? array() ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Executes a modeled authoritative read and populates Core's public result buffer.
	 *
	 * @phpstan-param array{template: string, args: list<mixed>} $statement
	 *
	 * @param   array  $statement Prepared statement and arguments.
	 * @param   string $query     Prepared query.
	 *
	 * @return  int|false
	 */
	private function execute_read( array $statement, string $query ): int|false {
		$operation = match ( true ) {
			\str_starts_with( $statement['template'], 'SELECT `option_name` FROM ' )            => 'scan',
			\str_starts_with( $statement['template'], 'SELECT `option_value` FROM ' ),
			\str_starts_with( $statement['template'], 'SELECT `option_name`, `option_value` ' ) => 'select',
			default => throw new \UnexpectedValueException( 'WpdbLockSpy models only option-row and option-name SELECT statements.' ),
		};
		$this->recorded_queries[] = $query;

		$failure_leg                 = $this->next_read_failure_leg;
		$this->next_read_failure_leg = null;
		if ( 'not_ready' === $failure_leg || 'query_filtered' === $failure_leg ) {
			return false;
		}

		$this->last_error  = '';
		$this->last_result = array();
		if ( 'reconnect_failed' === $failure_leg ) {
			return false;
		}
		if ( 'scan' === $operation && $this->targets_failing_scan( $statement['args'] ) ) {
			$this->last_error = 'scripted targeted option-name scan failure';

			return false;
		}

		$this->run_before( $operation );
		if ( '' !== $this->last_error ) {
			return false;
		}

		$this->last_result = match ( $operation ) {
			'select' => $this->selected_rows( $statement ),
			default  => $this->selected_names( $statement ),
		};

		return \count( $this->last_result );
	}

	/**
	 * Returns modeled rows for an exact-name read.
	 *
	 * @phpstan-param array{template: string, args: list<mixed>} $statement
	 *
	 * @param   array $statement Prepared statement and arguments.
	 *
	 * @return  list<\stdClass>
	 */
	private function selected_rows( array $statement ): array {
		if ( null !== $this->option_row_results ) {
			return $this->option_row_results;
		}

		$args = self::without_table( $statement['args'] );
		$rows = array();
		foreach ( $args as $key ) {
			if ( ! \is_string( $key ) ) {
				continue;
			}

			$raw = $this->raw_value( $key );
			if ( null === $raw ) {
				continue;
			}

			if ( \str_starts_with( $statement['template'], 'SELECT `option_value` ' ) ) {
				$rows[] = (object) array( 'option_value' => $raw );
			} else {
				$rows[] = (object) array(
					'option_name'  => $key,
					'option_value' => $raw,
				);
			}
		}

		return $rows;
	}

	/**
	 * Returns modeled rows for a prefix scan.
	 *
	 * @phpstan-param array{template: string, args: list<mixed>} $statement
	 *
	 * @param   array $statement Prepared statement and arguments.
	 *
	 * @return  list<\stdClass>
	 */
	private function selected_names( array $statement ): array {
		$args        = self::without_table( $statement['args'] );
		$pattern     = $args[0] ?? null;
		$has_cursor  = \str_contains( $statement['template'], 'BINARY `option_name` > BINARY %s' );
		$cursor      = $has_cursor ? ( $args[1] ?? null ) : null;
		$limit_index = $has_cursor ? 2 : 1;
		$limit       = $args[ $limit_index ] ?? null;
		if ( ! \is_string( $pattern ) || ! \str_ends_with( $pattern, '%' ) || ( null !== $cursor && ! \is_string( $cursor ) ) || ( null !== $limit && ! \is_int( $limit ) ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy option scans require a trailing-wildcard pattern and valid bounds.' );
		}

		// A keyset template whose cursor argument never arrived would silently drop the cursor predicate below.
		if ( $has_cursor && null === $cursor ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy keyset option scans require a string cursor.' );
		}

		$escaped_prefix = \substr( $pattern, 0, -1 );
		$prefix         = \preg_replace( '/\\\\([\\\\_%])/', '$1', $escaped_prefix );
		$options        = $GLOBALS['a8csp_bgje_test_options'] ?? array();
		if ( ! \is_string( $prefix ) || ! \is_array( $options ) ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy could not model the option-name scan.' );
		}

		if ( null !== $this->option_name_results ) {
			return \array_map( static fn ( mixed $name ): \stdClass => (object) array( 'option_name' => $name ), $this->option_name_results );
		}

		$names = \array_unique( array( ...\array_keys( $this->rows ), ...\array_keys( $options ) ) );
		$names = \array_values( \array_filter( $names, static fn ( mixed $name ): bool => \is_string( $name ) && 0 === \strncasecmp( $name, $prefix, \strlen( $prefix ) ) && ( null === $cursor || 0 < \strcmp( $name, $cursor ) ) ) );
		\sort( $names, \SORT_STRING );
		if ( null !== $limit ) {
			$names = \array_slice( $names, 0, $limit );
		}

		return \array_map( static fn ( mixed $name ): \stdClass => (object) array( 'option_name' => $name ), $names );
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
	 * Reports whether an update targets an option name armed to lose.
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   array $args Prepared statement arguments including the table.
	 *
	 * @return  bool
	 */
	private function targets_failing_key( array $args ): bool {
		if ( array() === $this->failing_update_keys ) {
			return false;
		}

		$key = self::without_table( $args )[1] ?? null;
		if ( ! \is_string( $key ) ) {
			return false;
		}

		foreach ( $this->failing_update_keys as $needle ) {
			if ( \str_contains( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reports whether an option-name scan carries an argument armed to fail.
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   array $args Prepared statement arguments including the table.
	 *
	 * @return  bool
	 */
	private function targets_failing_scan( array $args ): bool {
		return \array_any(
			$this->failing_scan_needles,
			static fn ( string $needle ): bool => \array_any( $args, static fn ( mixed $arg ): bool => \is_string( $arg ) && \str_contains( $arg, $needle ) )
		);
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

	// endregion.
}
