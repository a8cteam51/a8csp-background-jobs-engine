<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

\defined( 'ABSPATH' ) || exit;

/**
 * Failure detail passed to a run's failure callback.
 *
 * The message describes the failure, and the optional exception class preserves the throwable
 * category without retaining the throwable.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class EngineError {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $message         Human-readable failure detail.
	 * @param   string|null $exception_class Exception class associated with the failure.
	 */
	public function __construct(
		public string $message,
		public ?string $exception_class = null,
	) {}

	// endregion
}
