<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error;

\defined( 'ABSPATH' ) || exit;

/**
 * Machine-readable reason a scheduling request could not be accepted.
 *
 * Carried by {@see SchedulingError} so callers can branch on the cause without parsing
 * prose; the backing values remain stable once released, so they are safe in log context.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum SchedulingErrorReason: string {
	case BackendNotReady  = 'backend_not_ready';
	case UnsupportedGroup = 'unsupported_group';

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
	case StorageFailure = 'storage_failure';
}
