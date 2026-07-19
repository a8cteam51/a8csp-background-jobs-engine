<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

/**
 * Records every engine action observed through the unit-test WordPress hook boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class HookRecorder {
	// region FIELDS AND CONSTANTS.

	private const string PREFIX = 'a8csp_jobs_engine/';

	/**
	 * Engine actions in fire order.
	 *
	 * @var list<array{hook: string, args: list<mixed>}>
	 */
	private array $events = array();

	// endregion.

	// region MAGIC METHODS.

	/**
	 * Subscribes to the shared action observer exposed by the WordPress test stub.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function __construct() {
		$observers = $GLOBALS['a8csp_bgje_test_action_observers'] ?? array();
		if ( ! \is_array( $observers ) ) {
			throw new \UnexpectedValueException( 'Initialize the action-observer test ledger as an array.' );
		}

		$observers[]                                 = function ( string $hook, array $args ): void {
			if ( ! \str_starts_with( $hook, self::PREFIX ) ) {
				return;
			}

			$this->events[] = array(
				'hook' => $hook,
				'args' => \array_values( $args ),
			);
		};
		$GLOBALS['a8csp_bgje_test_action_observers'] = $observers;
	}

	// endregion.

	// region GETTERS.

	/**
	 * Returns every argument list observed for one exact hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook Exact engine hook name.
	 *
	 * @return  list<list<mixed>>
	 */
	public function fired( string $hook ): array {
		return \array_values( \array_map( static fn ( array $event ): array => $event['args'], \array_filter( $this->events, static fn ( array $event ): bool => $hook === $event['hook'] ) ) );
	}

	/**
	 * Returns engine hook names in exact fire order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>
	 */
	public function sequence(): array {
		return \array_column( $this->events, 'hook' );
	}

	// endregion.
}
