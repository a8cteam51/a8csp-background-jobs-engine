<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Run;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound facade for retained background-work runs.
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
	 * @param   string              $owner  Consumer plugin owner.
	 * @param   RunsEngineInterface $engine Run engine operations.
	 */
	public function __construct(
		private string $owner,
		private RunsEngineInterface $engine,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns the most recently recorded completed run ID retained for one background-work name.
	 *
	 * The lookup covers only the retained history window. Each history buffer retains at most the
	 * positive `a8csp_background_tasks/history_size` filter value, 30 by default. A completed run
	 * older than that window returns `Success(null)` as if absent. Consumers needing indefinite
	 * retention keep their own pointer from `on_completed()` or the completed lifecycle hook. History
	 * is recorded after those notifications, so a lookup inside either observes the previous retained
	 * completion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local task or batch name.
	 *
	 * @throws  \InvalidArgumentException When the local name violates the canonical grammar.
	 *
	 * @return  AbstractResult<string|null, ApiError>
	 */
	#[\NoDiscard( 'a last-completed-run result must be handled, not dropped' )]
	public function last_completed_run_id( string $name ): AbstractResult {
		return $this->engine->last_completed_run_id( WorkIdentity::compose( $this->owner, $name ) );
	}

	/**
	 * Starts a fresh run from one retained failed run's original arguments.
	 *
	 * A retried run does not re-acquire its original deduplication key or existing-run policy: it is
	 * re-admitted under its argument identity, so it does not collapse against a concurrent enqueue
	 * carrying the failed run's key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local task or batch name.
	 * @param   string $run_id Retained failed-run identifier.
	 *
	 * @throws  \InvalidArgumentException When the local name violates the canonical grammar.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'a failed-run retry result must be handled, not dropped' )]
	public function retry_failed( string $name, string $run_id ): AbstractResult {
		return $this->engine->retry_failed( WorkIdentity::compose( $this->owner, $name ), $run_id );
	}

	/**
	 * Cancels one retained run that is not executing or pending batch cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Owner-local task or batch name.
	 * @param   string $run_id Retained run identifier.
	 *
	 * @throws  \InvalidArgumentException When the local name violates the canonical grammar.
	 *
	 * @return  AbstractResult<string, ApiError>
	 */
	#[\NoDiscard( 'a run-cancel result must be handled, not dropped' )]
	public function cancel( string $name, string $run_id ): AbstractResult {
		return $this->engine->cancel( WorkIdentity::compose( $this->owner, $name ), $run_id );
	}

	// endregion
}
