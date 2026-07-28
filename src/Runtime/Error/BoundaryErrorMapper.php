<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;

\defined( 'ABSPATH' ) || exit;

/**
 * Translates internal operation failures into the sole public failure value.
 *
 * @internal Public facade boundary only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class BoundaryErrorMapper {
	// region FIELDS AND CONSTANTS

	/**
	 * Diagnostic fields reciprocally shared by classified failures and public `WP_Error` data.
	 *
	 * A key belongs here only when its values are engine-authored primitives; a field carrying text or
	 * payloads from outside the engine stays out however useful it reads. `storage_error` and
	 * `wp_error` are excluded on exactly that ground — they relay a database driver and WordPress cron
	 * verbatim, while the engine-authored message already states the remedy.
	 *
	 * Public verbs expose only these keys, and every key has a live producer on a mapped route. Failure
	 * contexts also arrive through computed keys and variables built up before the call, so searching
	 * for a literal key understates the live set. Logger context follows its own normalization and
	 * never consults this list.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, true>
	 */
	private const array SAFE_CONTEXT_KEYS = array(
		'action_scheduler_function'          => true,
		'action_scheduler_functions_exist'   => true,
		'action_scheduler_init_fired'        => true,
		'action_scheduler_version_supported' => true,
		'actual_bytes'                       => true,
		'current_timestamp'                  => true,
		'first_run_timestamp'                => true,
		'hook'                               => true,
		'identity'                           => true,
		'interval'                           => true,
		'kind'                               => true,
		'limit_bytes'                        => true,
		'maximum_depth'                      => true,
		'maximum_json_length'                => true,
		'missing_function'                   => true,
		'option_name'                        => true,
		'priority'                           => true,
		'run_at'                             => true,
		'run_id'                             => true,
		'schedule'                           => true,
		'scope'                              => true,
		'status'                             => true,
		'storage_operation'                  => true,
		'timestamp'                          => true,
		'wp_init_fired'                      => true,
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
	 * @phpstan-return ($result is AbstractResult<true, TError> ? AbstractResult<true, BoundaryError> : AbstractResult<TValue, BoundaryError>)
	 *
	 * @return  AbstractResult<TValue, BoundaryError>
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
	 * @return  BoundaryError
	 */
	private static function error( EngineError|SchedulingError $error ): BoundaryError {
		$code = $error instanceof SchedulingError
			? $error->reason->api_code()
			: self::engine_code( $error );

		return new BoundaryError( $code, $error->message, self::safe_context( $error->context ) );
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
	 * @return  ErrorCode
	 */
	private static function engine_code( EngineError $error ): ErrorCode {
		if ( null === $error->reason ) {
			throw new \LogicException( 'An internal engine failure reached the API boundary without a public classification.' );
		}

		return match ( $error->reason ) {
			EngineErrorReason::EngineUnavailable    => ErrorCode::EngineUnavailable,
			EngineErrorReason::UnknownJob           => ErrorCode::UnknownJob,
			EngineErrorReason::UnknownSchedule      => ErrorCode::UnknownSchedule,
			EngineErrorReason::OverlapHeld          => ErrorCode::OverlapHeld,
			EngineErrorReason::PayloadRejected      => ErrorCode::PayloadRejected,
			EngineErrorReason::StorageFailure       => ErrorCode::StorageFailed,
			EngineErrorReason::RunNotRetained       => ErrorCode::RunNotRetained,
			EngineErrorReason::RunNotCancellable    => ErrorCode::RunNotCancellable,
			EngineErrorReason::UnsupportedOperation => ErrorCode::UnsupportedOperation,
			EngineErrorReason::ExecutionFailed      => ErrorCode::ExecutionFailed,
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
