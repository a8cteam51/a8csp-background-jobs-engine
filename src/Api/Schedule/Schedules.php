<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound facade for declarative recurring task schedules.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Schedules {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Closure(string): string                                                                         $identity     Owner-qualified identity composer.
	 * @param   \Closure(array<string, array{schedule: Schedule, task: string}>): AbstractResult<true, ApiError> $sync         Schedule synchronization delegate.
	 * @param   \Closure(string): AbstractResult<string, ApiError>                                               $dispatch_now Immediate schedule delegate.
	 */
	public function __construct(
		private \Closure $identity,
		private \Closure $sync,
		private \Closure $dispatch_now,
	) {}

	// endregion

	// region METHODS

	/**
	 * Synchronizes the bound owner's complete declared schedule set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<Schedule> $schedules Complete schedule declaration for the bound owner.
	 *
	 * @throws  \InvalidArgumentException When an entry, name, target, or declaration uniqueness is invalid.
	 *
	 * @return  AbstractResult<true, ApiError>
	 */
	#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
	public function sync( array $schedules ): AbstractResult {
		$declarations = array();
		foreach ( $schedules as $schedule ) {
			if ( ! $schedule instanceof Schedule ) {
				throw new \InvalidArgumentException( 'Schedule sync accepts only Schedule value objects; construct each declaration with new Schedule(...).' );
			}

			$identity = $this->identity( $schedule->name );
			if ( isset( $declarations[ $identity ] ) ) {
				throw new \InvalidArgumentException( 'Schedule sync accepts each owner-local schedule name exactly once.' );
			}

			$declarations[ $identity ] = array(
				'schedule' => $schedule,
				'task'     => $this->identity( $schedule->task ),
			);
		}

		return ( $this->sync )( $declarations );
	}

	/**
	 * Immediately dispatches one declared schedule target without changing its recurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local schedule name.
	 *
	 * @throws  \InvalidArgumentException When the local name violates the canonical grammar.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
	public function dispatch_now( string $name ): AbstractResult {
		return ( $this->dispatch_now )( $this->identity( $name ) );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the complete identity for one owner-local name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local name.
	 *
	 * @return  string
	 */
	private function identity( string $name ): string {
		return ( $this->identity )( $name );
	}

	// endregion
}
