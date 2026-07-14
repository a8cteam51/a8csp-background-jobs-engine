<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Retry\RetryPolicy;

\defined( 'ABSPATH' ) || exit;

/**
 * Supplies the default retry policy for task implementations.
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
	public function get_retry_policy(): RetryPolicy {
		return new RetryPolicy();
	}

	// endregion
}
