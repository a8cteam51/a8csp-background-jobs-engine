<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;

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

	// region METHODS

	/**
	 * Returns the public held-lock task failure without relying on message inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $task_name      Stable task name.
	 * @param   string $running_run_id Discoverable incumbent run identifier.
	 *
	 * @return  self
	 */
	public static function held_task( string $task_name, string $running_run_id ): self {
		return new self(
			\sprintf(
				'Task "%1$s" is already running as run "%2$s"; wait for that run to finish before dispatching the same arguments.',
				$task_name,
				$running_run_id
			)
		);
	}

	/**
	 * Converts a failed lifecycle schedule into terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch'                     $work_type Work contract type.
	 * @param   string                             $name      Stable task or batch name.
	 * @param   'continue'|'run'|'cleanup'|'retry' $stage     Internal action that was not scheduled.
	 * @param   SchedulingError                    $error     Scheduling failure.
	 *
	 * @return  self
	 */
	public static function scheduling(
		string $work_type,
		string $name,
		string $stage,
		SchedulingError $error
	): self {
		return new self(
			\sprintf(
				'%1$s "%2$s" could not schedule the %3$s action: %4$s',
				$work_type,
				$name,
				$stage,
				$error->message
			),
			SchedulingError::class
		);
	}

	/**
	 * Converts one callback throwable into engine failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Throwable $throwable Callback failure.
	 *
	 * @return  self
	 */
	public static function from_throwable( \Throwable $throwable ): self {
		return new self( $throwable->getMessage(), $throwable::class );
	}

	/**
	 * Returns a failure that names the global background-work identity correction.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Ambiguous task and batch name.
	 *
	 * @return  self
	 */
	public static function ambiguous_name( string $name ): self {
		return new self(
			\sprintf(
				'Background-work name "%s" is registered as both a task and a batch; rename one registration so each name identifies exactly one type.',
				$name
			)
		);
	}

	/**
	 * Converts a retry-policy boundary throwable into terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 * @param   string         $name      Stable task or batch name.
	 * @param   \Throwable     $throwable Retry-policy provider or filter failure.
	 *
	 * @return  self
	 */
	public static function retry_policy( string $work_type, string $name, \Throwable $throwable ): self {
		return new self(
			\sprintf(
				'%1$s "%2$s" could not resolve the retry policy: %3$s Fix the retry policy provider or filter before retrying the failed run manually.',
				$work_type,
				$name,
				$throwable->getMessage()
			),
			$throwable::class
		);
	}

	/**
	 * Converts one retry-preparation throwable into terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 * @param   string         $name      Stable task or batch name.
	 * @param   \Throwable     $throwable Retry-policy, randomness, hook, or scheduler failure.
	 *
	 * @return  self
	 */
	public static function retry_preparation( string $work_type, string $name, \Throwable $throwable ): self {
		return new self(
			\sprintf(
				'%1$s "%2$s" could not prepare the retry action: %3$s Fix the retry policy, randomness source, retrying hook, or scheduler before retrying the failed run manually.',
				$work_type,
				$name,
				$throwable->getMessage()
			),
			$throwable::class
		);
	}

	// endregion
}
