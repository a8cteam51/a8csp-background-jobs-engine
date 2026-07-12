<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

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
	 * Runs a callback immediately before the next matching operation reaches storage.
	 *
	 * @param   'insert'|'select'|'update'|'delete' $operation Query operation.
	 * @param   callable(self): void                $callback  Interleaving callback.
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
		$parts     = \preg_split( '/(%[si])/', $query, -1, \PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			throw new \UnexpectedValueException( 'WpdbLockSpy could not parse the prepared statement.' );
		}

		$prepared = '';
		$index    = 0;
		foreach ( $parts as $part ) {
			if ( '%s' !== $part && '%i' !== $part ) {
				$prepared .= $part;
				continue;
			}

			if ( ! \array_key_exists( $index, $arguments ) ) {
				throw new \InvalidArgumentException( 'WpdbLockSpy requires one value for every placeholder.' );
			}

			$prepared .= '%i' === $part
				? self::quote_table( $arguments[ $index ] )
				: self::quote( $arguments[ $index ] );
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

		$lifecycle_events = $GLOBALS['a8csp_bgte_test_lifecycle_events'] ?? null;
		if ( \is_array( $lifecycle_events ) ) {
			$lifecycle_events[] = array(
				'type'      => 'lock',
				'operation' => $operation,
			);

			$GLOBALS['a8csp_bgte_test_lifecycle_events'] = $lifecycle_events;
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

		$this->run_before( 'select' );
		$this->recorded_queries[] = $query;

		$args = self::without_table( $statement['args'] );
		$key  = $args[0] ?? null;
		if ( ! \is_string( $key ) || ! \array_key_exists( $key, $this->rows ) ) {
			return null;
		}

		$row = array( 'option_value' => $this->rows[ $key ] );

		return match ( $output ) {
			'ARRAY_A' => $row,
			'ARRAY_N' => \array_values( $row ),
			default   => (object) $row,
		};
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
		if ( \array_key_exists( $key, $this->rows ) ) {
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

		if ( ! \array_key_exists( $key, $this->rows ) || $expected_raw !== $this->rows[ $key ] ) {
			return 0;
		}

		if ( $new_raw === $this->rows[ $key ] ) {
			return 0;
		}

		$this->rows[ $key ] = $new_raw;

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
		if ( ! \array_key_exists( $key, $this->rows ) || $expected_raw !== $this->rows[ $key ] ) {
			return 0;
		}

		unset( $this->rows[ $key ], $this->autoload[ $key ] );

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
