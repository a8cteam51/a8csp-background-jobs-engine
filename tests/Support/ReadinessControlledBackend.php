<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\BackendInterface;

/**
 * Readiness-controlled delegate over the live Action Scheduler backend.
 *
 * Scheduling and absence behavior remain owned by the production adapter while tests stage facade
 * routing through the mutable readiness flag.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ReadinessControlledBackend implements BackendInterface {
	// region FIELDS AND CONSTANTS.

	/** Live Action Scheduler adapter receiving every delegated operation. */
	private readonly ActionSchedulerBackend $backend;

	/** Whether the backend reports itself ready. */
	public bool $ready = true;

	// endregion.

	// region MAGIC METHODS.

	/**
	 * Creates a readiness-controlled live adapter.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function __construct() {
		$this->backend = new ActionSchedulerBackend();
	}

	// endregion.

	// region METHODS.

	/** {@inheritDoc} */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function schedule_recurring( string $hook, int $interval, array $args = array(), ?int $first_run_timestamp = null, string $group = '', int $priority = 10 ): AbstractResult {
		return $this->backend->schedule_recurring( $hook, $interval, $args, $first_run_timestamp, $group, $priority );
	}

	/** {@inheritDoc} */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function schedule_single( string $hook, int $timestamp, array $args = array(), string $group = '', int $priority = 10 ): AbstractResult {
		return $this->backend->schedule_single( $hook, $timestamp, $args, $group, $priority );
	}

	/** {@inheritDoc} */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function enqueue_async( string $hook, array $args = array(), string $group = '', int $priority = 10 ): AbstractResult {
		return $this->backend->enqueue_async( $hook, $args, $group, $priority );
	}

	/** {@inheritDoc} */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule( string $hook, array $args = array(), string $group = '' ): AbstractResult {
		return $this->backend->unschedule( $hook, $args, $group );
	}

	/** {@inheritDoc} */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule_run( string $hook, string $identity, string $run_id ): AbstractResult {
		return $this->backend->unschedule_run( $hook, $identity, $run_id );
	}

	/** {@inheritDoc} */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule_hooks( array $hooks ): AbstractResult {
		return $this->backend->unschedule_hooks( $hooks );
	}

	/** {@inheritDoc} */
	#[\Override]
	public function scheduled_chains( string $hook, array $identities ): array {
		return $this->backend->scheduled_chains( $hook, $identities );
	}

	/** {@inheritDoc} */
	#[\Override]
	public function is_scheduled( string $hook, array $args = array(), string $group = '' ): bool {
		return $this->backend->is_scheduled( $hook, $args, $group );
	}

	/** {@inheritDoc} */
	#[\Override]
	public function get_next_scheduled( string $hook, array $args = array(), string $group = '' ): ?int {
		return $this->backend->get_next_scheduled( $hook, $args, $group );
	}

	/** {@inheritDoc} */
	#[\Override]
	public function is_ready(): bool {
		return $this->ready;
	}

	/** {@inheritDoc} */
	#[\Override]
	public function is_absent(): bool {
		return $this->backend->is_absent();
	}

	/** {@inheritDoc} */
	#[\Override]
	public function register_hooks(): void {
		$this->backend->register_hooks();
	}

	// endregion.
}
