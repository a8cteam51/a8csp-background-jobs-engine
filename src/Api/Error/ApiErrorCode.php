<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Error;

\defined( 'ABSPATH' ) || exit;

/**
 * Stable machine-readable classification for client-visible engine failures.
 *
 * Minor releases may add cases. Clients treat unknown backing values as generic failures.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum ApiErrorCode: string {
	// region FIELDS AND CONSTANTS

	/** The engine cannot prepare or continue the requested operation. */
	case EngineUnavailable = 'engine_unavailable';

	/** The requested task or batch is not registered. */
	case UnknownWork = 'unknown_work';

	/** The requested schedule is unsynchronized, inactive, or stale. */
	case UnknownSchedule = 'unknown_schedule';

	/** An overlap lock or occurrence decision is already held. */
	case OverlapHeld = 'overlap_held';

	/** The supplied arguments or scheduling payload cannot be admitted. */
	case PayloadRejected = 'payload_rejected';

	/** No scheduling backend is ready to accept the operation. */
	case BackendUnavailable = 'backend_unavailable';

	/** A ready scheduling backend failed to accept the operation. */
	case BackendRejected = 'backend_rejected';

	/** A required durable read or write failed. */
	case StorageFailure = 'storage_failure';

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
