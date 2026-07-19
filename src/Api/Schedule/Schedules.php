<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;

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
	 * @param   string                   $owner  Client plugin owner.
	 * @param   SchedulesEngineInterface $engine Schedule engine operations.
	 */
	public function __construct(
		private string $owner,
		private SchedulesEngineInterface $engine,
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
	 * @throws  \InvalidArgumentException When an entry, owner/name identity, owner/target identity, or declaration uniqueness is invalid.
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

			$identity = WorkIdentity::compose( $this->owner, $schedule->name );
			if ( isset( $declarations[ $identity ] ) ) {
				throw new \InvalidArgumentException( 'Schedule sync accepts each owner-local schedule name exactly once.' );
			}

			$declarations[ $identity ] = array(
				'schedule' => $schedule,
				'task'     => WorkIdentity::compose( $this->owner, $schedule->task ),
			);
		}

		return $this->engine->sync( $declarations );
	}

	/**
	 * Immediately dispatches one declared schedule target without changing its recurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local schedule name.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
	public function dispatch_now( string $name ): AbstractResult {
		return $this->engine->dispatch_now( WorkIdentity::compose( $this->owner, $name ) );
	}

	// endregion
}
