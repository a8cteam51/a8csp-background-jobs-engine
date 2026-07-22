<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Internal\Run;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\JobIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound facade for retained background-work runs.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Runs {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string              $owner  Client plugin owner.
	 * @param   RunsEngineInterface $engine Run engine operations.
	 */
	public function __construct(
		private string $owner,
		private RunsEngineInterface $engine,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns one retained run's observable lifecycle status.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   string $run_id Retained run identifier.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid or the run_id is malformed.
	 *
	 * @return  AbstractResult<\A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus|null, ApiError>
	 */
	#[\NoDiscard( 'a run-inspection result must be handled, not dropped' )]
	public function inspect( string $name, string $run_id ): AbstractResult {
		return $this->engine->inspect_run( JobIdentity::compose( $this->owner, $name ), $run_id );
	}

	/**
	 * Returns the most recently recorded completed run ID retained for one background-work name.
	 *
	 * The lookup covers only the retained history window. Each history buffer retains at most the
	 * positive `a8csp_jobs_engine/history_size` filter value, 30 by default. A completed run
	 * older than that window returns `Success(null)` as if absent. Clients needing indefinite
	 * retention keep their own pointer from `on_completed()` or the completed lifecycle hook. History
	 * is recorded after those notifications, so a lookup inside either observes the previous retained
	 * completion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local job or chunked job name.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid.
	 *
	 * @return  AbstractResult<string|null, ApiError>
	 */
	#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
	public function last_completed_run_id( string $name ): AbstractResult {
		return $this->engine->last_completed_run_id( JobIdentity::compose( $this->owner, $name ) );
	}

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * The registered Job recomputes its argument-aware overlap key, while retry always rejects a
	 * matching live run regardless of the Job's declared overlap policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   string $run_id Retained failed-run identifier.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid or the run_id is malformed.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $name, string $run_id ): AbstractResult {
		return $this->engine->retry_failed( JobIdentity::compose( $this->owner, $name ), $run_id );
	}

	/**
	 * Cancels one retained run that is not executing or pending chunked job cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local job or chunked job name.
	 * @param   string $run_id Retained run identifier.
	 *
	 * @throws  \InvalidArgumentException When the owner/name identity is invalid or the run_id is malformed.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( string $name, string $run_id ): AbstractResult {
		return $this->engine->cancel( JobIdentity::compose( $this->owner, $name ), $run_id );
	}

	// endregion
}
