<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Maintenance;

use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\Schedules;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Synchronizes the engine's maintenance schedule against the boot-time site.
 *
 * @internal Engine wiring only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class MaintenanceSchedule {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Schedules       $schedules Client schedule API with the reserved-owner service entry.
	 * @param   LoggerInterface $logger    Log event sink.
	 */
	public function __construct(
		private Schedules $schedules,
		private LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers the deferred engine-maintenance synchronization.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function register_hooks(): void {
		// Post-init boot must synchronize immediately because a callback registered for completed init can never run.
		if ( 0 < \did_action( 'init' ) && ! \doing_action( 'init' ) ) {
			$this->sync_maintenance_schedule();
			return;
		}

		// The deferred sync fires on whichever site is selected when its lifecycle hook runs; the
		// registration belongs to the boot-time site.
		$boot_blog_id = \get_current_blog_id();
		$hook_name    = \doing_action( 'init' ) ? 'wp_loaded' : 'init';
		\add_action(
			$hook_name,
			function () use ( $boot_blog_id ): void {
				$this->sync_maintenance_schedule( $boot_blog_id );
			}
		);
	}

	/**
	 * Synchronizes the engine's own maintenance registration.
	 *
	 * @internal Engine wiring only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int|null $blog_id Site the registration belongs to; null keeps the current site.
	 *
	 * @return  void
	 */
	public function sync_maintenance_schedule( ?int $blog_id = null ): void {
		$switched = null !== $blog_id && \is_multisite() && \get_current_blog_id() !== $blog_id;
		if ( $switched ) {
			\switch_to_blog( $blog_id );
		}

		try {
			$owner                = JobIdentity::ENGINE_OWNER;
			$schedule_identity    = JobIdentity::compose( $owner, MaintenanceJob::NAME, true );
			$maintenance_schedule = new Schedule( MaintenanceJob::NAME, Recurrence::every( \HOUR_IN_SECONDS ), MaintenanceJob::NAME, array(), CatchUpPolicy::RunOnce );
			$result               = $this->schedules->sync_owner(
				$owner,
				array(
					$schedule_identity => array(
						'schedule' => $maintenance_schedule,
						'job'      => $schedule_identity,
					),
				)
			);
			if ( $result->is_failure() ) {
				$this->logger->error( 'Engine maintenance schedule could not be synchronized: {error}', array( 'error' => $result->error->message ) );
			}
		} finally {
			if ( $switched ) {
				\restore_current_blog();
			}
		}
	}

	// endregion
}
