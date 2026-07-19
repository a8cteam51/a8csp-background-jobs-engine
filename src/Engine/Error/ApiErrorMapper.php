<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;

\defined( 'ABSPATH' ) || exit;

/**
 * Translates internal operation failures into the sole public failure value.
 *
 * @internal Public facade boundary only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ApiErrorMapper {
	// region FIELDS AND CONSTANTS

	/**
	 * Internal diagnostic fields whose values are constrained to redaction-safe primitives.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, true>
	 */
	private const array SAFE_CONTEXT_KEYS = array(
		'action_scheduler_function'        => true,
		'action_scheduler_functions_exist' => true,
		'action_scheduler_init_fired'      => true,
		'current_timestamp'                => true,
		'delay'                            => true,
		'first_run_timestamp'              => true,
		'group'                            => true,
		'hook'                             => true,
		'interval'                         => true,
		'maximum_depth'                    => true,
		'maximum_json_length'              => true,
		'missing_function'                 => true,
		'name'                             => true,
		'option_name'                      => true,
		'owner'                            => true,
		'priority'                         => true,
		'run_id'                           => true,
		'schedule'                         => true,
		'status'                           => true,
		'timestamp'                        => true,
		'work_type'                        => true,
		'wp_init_fired'                    => true,
	);

	// endregion

	// region METHODS

	/**
	 * Maps a result at the public facade boundary while preserving successful values exactly.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @template TValue
	 * @template TError of EngineError|SchedulingError
	 *
	 * @param   AbstractResult<TValue, TError> $result Internal command outcome.
	 *
	 * @phpstan-return ($result is AbstractResult<true, TError> ? AbstractResult<true, ApiError> : AbstractResult<TValue, ApiError>)
	 *
	 * @return  AbstractResult<TValue, ApiError>
	 */
	#[\NoDiscard( 'a mapped API failure must be handled, not dropped' )]
	public static function map( AbstractResult $result ): AbstractResult {
		if ( $result->is_failure() ) {
			return new Failure( self::error( $result->error ) );
		}

		return $result;
	}

	// endregion

	// region HELPERS

	/**
	 * Maps one supported internal operation failure without inspecting its message.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   EngineError|SchedulingError $error Internal operation failure.
	 *
	 * @throws  \LogicException When an engine failure lacks an API classification.
	 *
	 * @return  ApiError
	 */
	private static function error( EngineError|SchedulingError $error ): ApiError {
		$code = $error instanceof SchedulingError
			? self::scheduling_code( $error->reason )
			: self::engine_code( $error );

		return new ApiError( $code, $error->message, self::safe_context( $error->context ) );
	}

	/**
	 * Returns one engine failure's public classification.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   EngineError $error Internal engine failure.
	 *
	 * @throws  \LogicException When the failure lacks an API classification.
	 *
	 * @return  ApiErrorCode
	 */
	private static function engine_code( EngineError $error ): ApiErrorCode {
		if ( null === $error->reason ) {
			throw new \LogicException( 'An internal engine failure reached the API boundary without a public classification.' );
		}

		return match ( $error->reason ) {
			EngineErrorReason::EngineUnavailable    => ApiErrorCode::EngineUnavailable,
			EngineErrorReason::UnknownWork          => ApiErrorCode::UnknownWork,
			EngineErrorReason::UnknownSchedule      => ApiErrorCode::UnknownSchedule,
			EngineErrorReason::OverlapHeld          => ApiErrorCode::OverlapHeld,
			EngineErrorReason::PayloadRejected      => ApiErrorCode::PayloadRejected,
			EngineErrorReason::StorageFailure       => ApiErrorCode::StorageFailure,
			EngineErrorReason::RunNotRetained       => ApiErrorCode::RunNotRetained,
			EngineErrorReason::RunNotCancellable    => ApiErrorCode::RunNotCancellable,
			EngineErrorReason::UnsupportedOperation => ApiErrorCode::UnsupportedOperation,
			EngineErrorReason::ExecutionFailed      => ApiErrorCode::ExecutionFailed,
		};
	}

	/**
	 * Returns one scheduling failure's public classification.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   SchedulingErrorReason $reason Internal scheduling failure reason.
	 *
	 * @return  ApiErrorCode
	 */
	private static function scheduling_code( SchedulingErrorReason $reason ): ApiErrorCode {
		return match ( $reason ) {
			SchedulingErrorReason::BackendNotReady => ApiErrorCode::BackendUnavailable,
			SchedulingErrorReason::UnsupportedGroup => ApiErrorCode::UnsupportedOperation,
			SchedulingErrorReason::InvalidTimeInput,
			SchedulingErrorReason::InvalidPayload        => ApiErrorCode::PayloadRejected,
			SchedulingErrorReason::ScheduleFailed        => ApiErrorCode::BackendRejected,
			SchedulingErrorReason::StorageFailure        => ApiErrorCode::StorageFailure,
		};
	}

	/**
	 * Removes internal diagnostic fields that can contain arbitrary external text or payloads.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<string, mixed> $context Internal diagnostic detail.
	 *
	 * @return  array<string, mixed>
	 */
	private static function safe_context( array $context ): array {
		return \array_intersect_key( $context, self::SAFE_CONTEXT_KEYS );
	}

	// endregion
}
