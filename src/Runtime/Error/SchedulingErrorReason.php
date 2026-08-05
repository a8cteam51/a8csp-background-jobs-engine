<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error;

use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;

\defined( 'ABSPATH' ) || exit;

/**
 * Machine-readable reason a scheduling request could not be accepted.
 *
 * Carried by {@see SchedulingError} so callers can branch on the cause without parsing
 * prose. A value an operator can observe never changes meaning, so it is safe in log
 * context; a case whose producer is gone leaves with it, because nothing can have logged it.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum SchedulingErrorReason: string {
	// region FIELDS AND CONSTANTS

	case BackendNotReady = 'backend_not_ready';

	/**
	 * A scheduling interval or timestamp is outside the supported positive range.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	case InvalidTimeInput = 'invalid_time_input';

	/**
	 * A scheduling payload has an unsupported size, shape, value, or nesting depth.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	case InvalidPayload = 'invalid_payload';

	case ScheduleFailed = 'schedule_failed';
	case StorageFailure = 'storage_failed';

	// endregion

	// region METHODS

	/**
	 * Returns the client-visible classification for this scheduling failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  ErrorCode
	 */
	public function api_code(): ErrorCode {
		return match ( $this ) {
			self::BackendNotReady => ErrorCode::BackendUnavailable,
			self::InvalidTimeInput,
			self::InvalidPayload  => ErrorCode::PayloadRejected,
			self::ScheduleFailed  => ErrorCode::BackendRejected,
			self::StorageFailure  => ErrorCode::StorageFailed,
		};
	}

	// endregion
}
