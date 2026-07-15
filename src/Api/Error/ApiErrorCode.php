<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Error;

\defined( 'ABSPATH' ) || exit;

/**
 * Stable machine-readable classification for consumer-visible engine failures.
 *
 * Minor releases may add cases. Consumers treat unknown backing values as generic failures.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum ApiErrorCode: string {
	// region FIELDS AND CONSTANTS

	case EngineUnavailable    = 'engine_unavailable';
	case UnknownWork          = 'unknown_work';
	case UnknownSchedule      = 'unknown_schedule';
	case OverlapHeld          = 'overlap_held';
	case PayloadRejected      = 'payload_rejected';
	case BackendUnavailable   = 'backend_unavailable';
	case BackendRejected      = 'backend_rejected';
	case StorageFailure       = 'storage_failure';
	case RunNotRetained       = 'run_not_retained';
	case RunNotCancellable    = 'run_not_cancellable';
	case UnsupportedOperation = 'unsupported_operation';
	case ExecutionFailed      = 'execution_failed';

	// endregion
}
