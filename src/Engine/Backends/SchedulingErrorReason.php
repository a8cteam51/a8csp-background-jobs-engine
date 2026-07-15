<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends;

\defined( 'ABSPATH' ) || exit;

/**
 * Machine-readable reason a scheduling request could not be accepted.
 *
 * Carried by {@see Errors\SchedulingError} so callers can branch on the cause without parsing
 * prose; the backing values remain stable when included in log context.
 *
 * InvalidInterval covers invalid time inputs, including intervals and timestamps.
 * PayloadTooLarge covers payloads unfit for portable storage because of size or shape.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum SchedulingErrorReason: string {
	case BackendNotReady       = 'backend_not_ready';
	case UnsupportedGroup      = 'unsupported_group';
	case UnsupportedRecurrence = 'unsupported_recurrence';
	case InvalidInterval       = 'invalid_interval';
	case PayloadTooLarge       = 'payload_too_large';
	case ScheduleFailed        = 'schedule_failed';
}
