<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Stable machine-readable classification for client-visible engine failures.
 *
 * Minor releases may add cases. Clients treat unknown backing values as generic failures.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum ErrorCode: string {
	// region FIELDS AND CONSTANTS

	/** The supplied public argument violates the operation contract. */
	case InvalidArgument = 'invalid_argument';

	/** The requested job identity is already registered for this request. */
	case AlreadyRegistered = 'already_registered';

	/** The engine cannot prepare or continue the requested operation. */
	case EngineUnavailable = 'engine_unavailable';

	/** The requested job or chunked job is not registered. */
	case UnknownJob = 'unknown_job';

	/** The requested schedule is unsynchronized, inactive, or stale. */
	case UnknownSchedule = 'unknown_schedule';

	/** An overlap lock or occurrence decision is held by a run that is still going; skip or wait. */
	case OverlapHeld = 'overlap_held';

	/** Admission stayed contended across every attempt and admitted nothing; nothing holds the lane. */
	case AdmissionConflict = 'admission_conflict';

	/** The supplied arguments or scheduling payload cannot be admitted. */
	case PayloadRejected = 'payload_rejected';

	/** No scheduling backend is ready to accept the operation. */
	case BackendUnavailable = 'backend_unavailable';

	/** A ready scheduling backend failed to accept the operation. */
	case BackendRejected = 'backend_rejected';

	/** A required durable read or write failed. */
	case StorageFailed = 'storage_failed';

	/** The requested run is absent from recoverable storage. */
	case RunNotRetained = 'run_not_retained';

	/** The requested run is terminal, executing, or otherwise beyond cancellation. */
	case RunNotCancellable = 'run_not_cancellable';

	/** The selected backend or engine version does not support the requested operation. */
	case UnsupportedOperation = 'unsupported_operation';

	/** Execution, queue generation, retry preparation, or crash recovery failed the run. */
	case ExecutionFailed = 'execution_failed';

	// endregion
}
