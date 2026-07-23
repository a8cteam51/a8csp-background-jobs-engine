<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext as RunContextContract;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;

\defined( 'ABSPATH' ) || exit;

/**
 * Carries immutable invocation context for one job or chunked job run.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RunContext implements RunContextContract {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 */
	public function __construct(
		private string $run_id,
		private array $start_args,
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function get_run_id(): RunId {
		return RunId::from( $this->run_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function get_start_args(): array {
		return $this->start_args;
	}

	// endregion
}
