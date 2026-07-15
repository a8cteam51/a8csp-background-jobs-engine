<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Task;

\defined( 'ABSPATH' ) || exit;

/**
 * Marks an exception as a permanent failure that bypasses remaining retry attempts.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface NonRetryableExceptionInterface {}
