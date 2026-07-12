<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

/**
 * Supplies the runtime surface that static analysis receives from wordpress-stubs.
 */
class WpdbRuntimeStub {
	/** Options table name. */
	public string $options = '';

	/** Rows affected by the latest write. */
	public int $rows_affected = 0;

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

	/**
	 * Prepares a query.
	 *
	 * @param   mixed $query Query template.
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
}
