<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Run;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;

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
	 * @param   \Closure(string): string                                   $identity     Owner-qualified identity composer.
	 * @param   \Closure(string, string): AbstractResult<string, ApiError> $retry_failed Failed-run retry delegate.
	 * @param   \Closure(string, string): AbstractResult<string, ApiError> $cancel       Run cancellation delegate.
	 */
	public function __construct(
		private \Closure $identity,
		private \Closure $retry_failed,
		private \Closure $cancel,
	) {}

	// endregion

	// region METHODS

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
		return ( $this->retry_failed )( $this->identity( $name ), $run_id );
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
		return ( $this->cancel )( $this->identity( $name ), $run_id );
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
