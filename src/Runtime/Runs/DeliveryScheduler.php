<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use Psr\Clock\ClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Schedules lifecycle deliveries from their persisted pending-action descriptors.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class DeliveryScheduler {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   SchedulerFacade $scheduler Scheduling facade boundary.
	 * @param   ClockInterface  $clock     Timestamp source.
	 */
	public function __construct(
		private SchedulerFacade $scheduler,
		private ClockInterface $clock,
	) {}

	// endregion

	// region METHODS

	/**
	 * Schedules one persisted lifecycle delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity      $identity        Complete scope-qualified work identity.
	 * @param   string        $run_id          Run identifier.
	 * @param   int           $action_sequence Persisted delivery sequence.
	 * @param   PendingAction $pending         Persisted delivery descriptor.
	 *
	 * @throws  \LogicException When a single-action descriptor has no integer fire time.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\NoDiscard( 'a lifecycle-delivery scheduling failure must be handled, not dropped' )]
	public function schedule( Identity $identity, string $run_id, int $action_sequence, PendingAction $pending ): AbstractResult {
		$wire_identity = (string) $identity;
		$args          = array( $wire_identity, $run_id, $action_sequence );
		$group         = $wire_identity;
		if ( 'async' === $pending->mode ) {
			return $this->scheduler->enqueue_async( ActionDeliveries::DELIVER_HOOK, $args, $group, $pending->priority );
		}

		$fire_at = $pending->fire_at;
		if ( ! \is_int( $fire_at ) ) {
			throw new \LogicException( 'Pending single-action delivery requires an integer fire time.' );
		}

		// Redelivery may replay a descriptor after its scheduled time has elapsed.
		return $this->scheduler->schedule_single( ActionDeliveries::DELIVER_HOOK, \max( $this->clock->now()->getTimestamp(), $fire_at ), $args, $group, $pending->priority );
	}

	/**
	 * Unschedules every pending lifecycle delivery for one run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified work identity.
	 * @param   string   $run_id   Run identifier.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule( Identity $identity, string $run_id ): AbstractResult {
		return $this->scheduler->unschedule_run( ActionDeliveries::DELIVER_HOOK, (string) $identity, $run_id );
	}

	// endregion
}
