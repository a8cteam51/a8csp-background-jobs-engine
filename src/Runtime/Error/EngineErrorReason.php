<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error;

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

	case UnknownJob        = 'unknown_job';
	case UnknownSchedule   = 'unknown_schedule';
	case OverlapHeld       = 'overlap_held';
	case AdmissionConflict = 'admission_conflict';
	case PayloadRejected   = 'payload_rejected';
	case StorageFailure    = 'storage_failed';
	case RunNotRetained    = 'run_not_retained';
	case RunNotCancellable = 'run_not_cancellable';
	case ExecutionFailed   = 'execution_failed';

	// endregion
}
