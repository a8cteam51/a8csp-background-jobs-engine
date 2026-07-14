<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

\defined( 'ABSPATH' ) || exit;

/**
 * Typed held-lock outcome for an internal scheduled-task dispatch.
 *
 * @internal Dispatcher outcome consumed by public enqueue and schedule APIs.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class TaskDispatchSkipped {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $running_run_id Discoverable incumbent run identifier.
	 * @param   EngineError $error          Caller-facing held-lock failure for the consuming API.
	 */
	public function __construct(
		public string $running_run_id,
		public EngineError $error,
	) {}

	// endregion
}
