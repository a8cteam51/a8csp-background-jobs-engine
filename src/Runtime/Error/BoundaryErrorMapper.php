<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;

\defined( 'ABSPATH' ) || exit;

/**
 * Translates internal operation outcomes into public boundary values.
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
	 * Adding a diagnostic field anywhere upstream means adding it here too. Nothing forces the pair:
	 * an unlisted key is dropped silently, the failure still reaches the consumer, and no gate reports
	 * the missing detail.
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
	 * Maps a result at the public facade boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @template TValue
	 *
	 * @param   AbstractResult<TValue, EngineError|SchedulingError> $result Internal command outcome.
	 *
	 * @return  TValue|\WP_Error
	 */
	#[\NoDiscard( 'a mapped API failure must be handled, not dropped' )]
	public static function map( AbstractResult $result ): mixed {
		if ( $result->is_failure() ) {
			return self::error( $result->error );
		}

		return $result->value;
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
	 * @return  \WP_Error
	 */
	private static function error( EngineError|SchedulingError $error ): \WP_Error {
		$code = $error instanceof SchedulingError
			? $error->reason->api_code()
			: self::engine_code( $error );

		return new \WP_Error( $code->value, $error->message, self::safe_context( $error->context ) );
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
			EngineErrorReason::UnknownJob        => ErrorCode::UnknownJob,
			EngineErrorReason::UnknownSchedule   => ErrorCode::UnknownSchedule,
			EngineErrorReason::OverlapHeld       => ErrorCode::OverlapHeld,
			EngineErrorReason::AdmissionConflict => ErrorCode::AdmissionConflict,
			EngineErrorReason::PayloadRejected   => ErrorCode::PayloadRejected,
			EngineErrorReason::StorageFailure    => ErrorCode::StorageFailed,
			EngineErrorReason::RunNotRetained    => ErrorCode::RunNotRetained,
			EngineErrorReason::RunNotCancellable => ErrorCode::RunNotCancellable,
			EngineErrorReason::ExecutionFailed   => ErrorCode::ExecutionFailed,
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
