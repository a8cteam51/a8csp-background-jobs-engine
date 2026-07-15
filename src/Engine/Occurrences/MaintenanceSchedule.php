<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\Schedules;
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
	 * @param   Schedules       $schedules Consumer schedule API with the reserved-owner service entry.
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
		// Action Scheduler becomes ready at init:1, while WP_Hook does not visit callbacks appended
		// to the priority bucket it is currently traversing.
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
			$result = $this->schedules->sync_owner(
				'a8csp-bgte',
				array(
					new Schedule(
						'maintenance',
						Recurrence::every( \HOUR_IN_SECONDS ),
						MaintenanceTask::NAME,
						array(),
						OverlapPolicy::Skip,
						CatchUpPolicy::RunOnce
					),
				)
			);
			if ( $result->is_failure() ) {
				$this->logger->error(
					'Engine maintenance schedule could not be synchronized: {error}',
					array( 'error' => $result->error->message )
				);
			}
		} finally {
			if ( $switched ) {
				\restore_current_blog();
			}
		}
	}

	// endregion
}
