<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkedJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\ChunkedJob\ChunkedJobsEngineInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Run\RunsEngineInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Schedule\SchedulesEngineInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\AbstractJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Job\JobsEngineInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\ApiErrorMapper;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\Schedules;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Dispatcher;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound adapter from supported API ports to internal engine operations.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ApiAdapter implements JobsEngineInterface, ChunkedJobsEngineInterface, SchedulesEngineInterface, RunsEngineInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $owner      Client plugin owner.
	 * @param   JobRegistry $work       Registered job and chunked job instances.
	 * @param   Schedules   $schedules  Schedule engine operations.
	 * @param   Dispatcher  $dispatcher Background-work admission coordinator.
	 * @param   Inspection  $inspection Read-only run inspection.
	 */
	public function __construct(
		private string $owner,
		private JobRegistry $work,
		private Schedules $schedules,
		private Dispatcher $dispatcher,
		private Inspection $inspection,
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one job under its complete owner-qualified identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $identity Complete owner-qualified job identity.
	 * @param   AbstractJob $job      Job to register.
	 *
	 * @throws  \InvalidArgumentException When the identity and job name disagree, or a chunked job owns the identity.
	 * @throws  \LogicException           When the job identity is already registered.
	 *
	 * @return  void
	 */
	#[\Override]
	public function register_job( string $identity, AbstractJob $job ): void {
		$this->work->register_job( $identity, $job );
	}

	/**
	 * Registers one chunked job under its complete owner-qualified identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string              $identity Complete owner-qualified chunked job identity.
	 * @param   ChunkedJobInterface $chunked_job    Chunked Job to register.
	 *
	 * @throws  \InvalidArgumentException When the identity and chunked job name disagree, or a job owns the identity.
	 * @throws  \LogicException           When the chunked job identity is already registered.
	 *
	 * @return  void
	 */
	#[\Override]
	public function register_chunked_job( string $identity, ChunkedJobInterface $chunked_job ): void {
		$this->work->register_chunked_job( $identity, $chunked_job );
	}

	/**
	 * Creates and schedules one run for a registered job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity  Complete owner-qualified job identity.
	 * @param   array<array-key, mixed> $args      Job arguments.
	 * @param   int                     $delay     Scheduling delay in seconds.
	 * @param   int                     $priority  Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError>
	 */
	#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
	#[\Override]
	public function enqueue( string $identity, array $args, int $delay, int $priority ): AbstractResult {
		return ApiErrorMapper::map( $this->dispatcher->enqueue( $identity, $args, $delay, $priority ) );
	}

	/**
	 * Creates and schedules one run for a registered chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity   Complete owner-qualified chunked job identity.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @return  AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError>
	 */
	#[\NoDiscard( 'a chunked-job-start failure must be handled, not dropped' )]
	#[\Override]
	public function start( string $identity, array $start_args, int $priority ): AbstractResult {
		return ApiErrorMapper::map( $this->dispatcher->start_chunked_job( $identity, $start_args, $priority ) );
	}

	/**
	 * Synchronizes the bound owner's complete declared schedule set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, array{schedule: \A8C\SpecialProjects\BackgroundJobsEngine\Internal\Schedule\Schedule, job: string}> $declarations
	 *
	 * @param   array $declarations Complete schedule declaration keyed by owner-qualified identity.
	 *
	 * @return  AbstractResult<true, \A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError>
	 */
	#[\NoDiscard( 'a schedule-sync failure must be handled, not dropped' )]
	#[\Override]
	public function sync( array $declarations ): AbstractResult {
		return ApiErrorMapper::map( $this->schedules->sync( $this->owner, $declarations ) );
	}

	/**
	 * Immediately dispatches one declared schedule target without changing its recurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified schedule identity.
	 *
	 * @return  AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError>
	 */
	#[\NoDiscard( 'a schedule dispatch-now failure must be handled, not dropped' )]
	#[\Override]
	public function dispatch_now( string $identity ): AbstractResult {
		return ApiErrorMapper::map( $this->schedules->dispatch_now( $identity ) );
	}

	/**
	 * Returns one retained run's observable lifecycle status.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 * @param   string $run_id   Retained run identifier.
	 *
	 * @throws  \InvalidArgumentException When the run identifier is malformed.
	 *
	 * @return  AbstractResult<\A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunStatus|null, \A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError>
	 */
	#[\NoDiscard( 'a run-inspection result must be handled, not dropped' )]
	#[\Override]
	public function inspect_run( string $identity, string $run_id ): AbstractResult {
		return ApiErrorMapper::map( $this->inspection->run_status( $identity, $run_id ) );
	}

	/**
	 * Returns the most recently recorded completed run ID retained for one identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  AbstractResult<string|null, \A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError>
	 */
	#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
	#[\Override]
	public function last_completed_run_id( string $identity ): AbstractResult {
		return ApiErrorMapper::map( $this->inspection->last_completed_run_id( $identity ) );
	}

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 * @param   string $run_id   Retained failed-run identifier.
	 *
	 * @return  AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError>
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	#[\Override]
	public function retry_failed( string $identity, string $run_id ): AbstractResult {
		return ApiErrorMapper::map( $this->dispatcher->retry_failed( $identity, $run_id ) );
	}

	/**
	 * Cancels one retained run that is not executing or pending chunked job cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 * @param   string $run_id   Retained run identifier.
	 *
	 * @return  AbstractResult<string, \A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError>
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	#[\Override]
	public function cancel( string $identity, string $run_id ): AbstractResult {
		return ApiErrorMapper::map( $this->dispatcher->cancel( $identity, $run_id ) );
	}

	// endregion
}
