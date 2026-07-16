<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Task;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;

\defined( 'ABSPATH' ) || exit;

/**
 * Supplies default callback-runtime and retry policies for task implementations.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class AbstractTask implements TaskInterface {
	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function max_callback_runtime(): int {
		return self::DEFAULT_MAX_CALLBACK_RUNTIME;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function get_retry_policy(): RetryPolicy {
		return new RetryPolicy();
	}

	// endregion
}
