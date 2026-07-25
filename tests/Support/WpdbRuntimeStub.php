<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

/**
 * Supplies the runtime surface that static analysis receives from wordpress-stubs.
 */
class WpdbRuntimeStub {
	// region FIELDS AND CONSTANTS.

	/** Options table name. */
	public string $options = '';

	/** Rows affected by the latest write. */
	public int $rows_affected = 0;

	/** Last database error reported by the modeled query boundary. */
	public string $last_error = '';

	// endregion.

	// region MAGIC METHODS.

	/**
	 * Creates a disconnected runtime stub.
	 *
	 * @param mixed ...$connection Connection arguments.
	 */
	public function __construct( mixed ...$connection ) {
		if ( 4 !== \count( $connection ) ) {
			throw new \InvalidArgumentException( 'The wpdb runtime stub requires four connection arguments.' );
		}
	}

	// endregion.

	// region METHODS.

	/**
	 * Prepares a query.
	 *
	 * @param   mixed $query   Query template.
	 * @param   mixed ...$args Query arguments.
	 *
	 * @return  string
	 */
	public function prepare( mixed $query, mixed ...$args ): string {
		return throw new \BadMethodCallException( 'Use WpdbLockSpy in unit tests.' );
	}

	/**
	 * Executes a write query.
	 *
	 * @param   mixed $query Prepared query.
	 *
	 * @return  int|bool
	 */
	public function query( mixed $query ): int|bool {
		return throw new \BadMethodCallException( 'Use WpdbLockSpy in unit tests.' );
	}

	/**
	 * Escapes SQL LIKE wildcard bytes.
	 *
	 * @param   string $text Literal LIKE fragment.
	 *
	 * @return  string
	 */
	public function esc_like( string $text ): string {
		return \addcslashes( $text, '_%\\' );
	}

	/**
	 * Returns one selected column.
	 *
	 * @param   mixed $query Prepared query.
	 * @param   mixed $x     Column offset.
	 *
	 * @return  list<mixed>
	 */
	public function get_col( mixed $query = null, mixed $x = 0 ): array {
		return throw new \BadMethodCallException( 'Use WpdbLockSpy in unit tests.' );
	}

	/**
	 * Returns one selected row.
	 *
	 * @param   mixed $query  Prepared query.
	 * @param   mixed $output Output shape.
	 * @param   mixed $y      Row offset.
	 *
	 * @return  array<array-key, mixed>|\stdClass|null
	 */
	public function get_row( mixed $query = null, mixed $output = 'OBJECT', mixed $y = 0 ): array|\stdClass|null {
		return throw new \BadMethodCallException( 'Use WpdbLockSpy in unit tests.' );
	}

	/**
	 * Returns selected rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $query  Prepared query.
	 * @param   mixed $output Output shape.
	 *
	 * @return  array<array-key, mixed>|object|null
	 */
	public function get_results( mixed $query = null, mixed $output = 'OBJECT' ): array|object|null {
		return throw new \BadMethodCallException( 'Use WpdbLockSpy in unit tests.' );
	}

	// endregion.
}
