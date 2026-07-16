<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ErrorInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Internal failure detail retained while the engine terminalizes a run.
 *
 * The message describes the failure, and the optional exception class preserves the throwable
 * category without retaining the throwable.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class EngineError implements ErrorInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                 $message         Human-readable failure detail.
	 * @param   string|null            $exception_class Exception class associated with the failure.
	 * @param   EngineErrorReason|null $reason          Machine-readable admission cause, or null for terminal-only detail.
	 * @param   array<string, mixed>   $context         Structured internal diagnostic detail.
	 */
	public function __construct(
		public string $message,
		public ?string $exception_class = null,
		public ?EngineErrorReason $reason = null,
		public array $context = array(),
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns the public held-lock task failure without relying on message inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $task_name      Complete owner-qualified task identity.
	 * @param   string $running_run_id Discoverable incumbent run identifier.
	 *
	 * @return  self
	 */
	public static function held_task( string $task_name, string $running_run_id ): self {
		return new self( \sprintf( 'Task "%1$s" is already running as run "%2$s"; wait for that run to finish before dispatching the same arguments or deduplication key.', $task_name, $running_run_id ), reason: EngineErrorReason::OverlapHeld, context: array( 'run_id' => $running_run_id ), );
	}

	/**
	 * Converts a failed lifecycle schedule into terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch'                     $work_type Work contract type.
	 * @param   string                             $identity  Complete owner-qualified task or batch identity.
	 * @param   'continue'|'run'|'cleanup'|'retry' $stage     Internal action that was not scheduled.
	 * @param   SchedulingError                    $error     Scheduling failure.
	 *
	 * @return  self
	 */
	public static function scheduling( string $work_type, string $identity, string $stage, SchedulingError $error ): self {
		return new self( \sprintf( '%1$s "%2$s" could not schedule the %3$s action: %4$s', $work_type, $identity, $stage, $error->message ), SchedulingError::class );
	}

	/**
	 * Maps a scheduling failure to its consumer-visible availability classification.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   SchedulingError $error Scheduling failure.
	 *
	 * @return  ApiErrorCode
	 */
	public static function api_code_for_scheduling( SchedulingError $error ): ApiErrorCode {
		if ( SchedulingErrorReason::BackendNotReady === $error->reason ) {
			return ApiErrorCode::BackendUnavailable;
		}

		// Registry persistence failures classify as storage regardless of which path surfaces them.
		if ( SchedulingErrorReason::StorageFailure === $error->reason ) {
			return ApiErrorCode::StorageFailure;
		}

		return ApiErrorCode::BackendRejected;
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
		$exception_type = \get_debug_type( $throwable );

		return new self( \sprintf( 'Background-work execution failed because %s was thrown.', $exception_type ), $exception_type );
	}

	/**
	 * Converts a retry-policy boundary throwable into terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 * @param   string         $identity  Complete owner-qualified task or batch identity.
	 * @param   \Throwable     $throwable Retry-policy provider or filter failure.
	 *
	 * @return  self
	 */
	public static function retry_policy( string $work_type, string $identity, \Throwable $throwable ): self {
		$exception_type = \get_debug_type( $throwable );

		return new self( \sprintf( '%1$s "%2$s" could not resolve the retry policy because %3$s was thrown. Fix the retry policy provider or filter before retrying the failed run manually.', $work_type, $identity, $exception_type ), $exception_type );
	}

	/**
	 * Converts one retry-state construction throwable into terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 * @param   string         $identity  Complete owner-qualified task or batch identity.
	 * @param   \Throwable     $throwable Retry-state construction failure.
	 *
	 * @return  self
	 */
	public static function retry_state( string $work_type, string $identity, \Throwable $throwable ): self {
		$exception_type = \get_debug_type( $throwable );

		return new self( \sprintf( '%1$s "%2$s" could not construct the retry state because %3$s was thrown. Restore the engine before retrying the failed run manually.', $work_type, $identity, $exception_type ), $exception_type );
	}

	/**
	 * Converts one retry-preparation throwable into terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'Task'|'Batch' $work_type Work contract type.
	 * @param   string         $identity  Complete owner-qualified task or batch identity.
	 * @param   \Throwable     $throwable Retry-policy, randomness, hook, or scheduler failure.
	 *
	 * @return  self
	 */
	public static function retry_preparation( string $work_type, string $identity, \Throwable $throwable ): self {
		$exception_type = \get_debug_type( $throwable );

		return new self( \sprintf( '%1$s "%2$s" could not prepare the retry action because %3$s was thrown. Fix the retry policy, randomness source, retrying hook, or scheduler before retrying the failed run manually.', $work_type, $identity, $exception_type ), $exception_type );
	}

	// endregion
}
