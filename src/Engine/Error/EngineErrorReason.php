<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error;

\defined( 'ABSPATH' ) || exit;

/**
 * Machine-readable cause for an engine failure that can reach public command admission.
 *
 * Terminal-only {@see EngineError} values do not require an admission reason.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum EngineErrorReason: string {
	// region FIELDS AND CONSTANTS

	case EngineUnavailable    = 'engine_unavailable';
	case UnknownWork          = 'unknown_work';
	case UnknownSchedule      = 'unknown_schedule';
	case OverlapHeld          = 'overlap_held';
	case PayloadRejected      = 'payload_rejected';
	case StorageFailure       = 'storage_failure';
	case RunNotRetained       = 'run_not_retained';
	case RunNotCancellable    = 'run_not_cancellable';
	case UnsupportedOperation = 'unsupported_operation';
	case ExecutionFailed      = 'execution_failed';

	// endregion
}
