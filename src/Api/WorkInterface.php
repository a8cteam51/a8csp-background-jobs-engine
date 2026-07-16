<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api;

\defined( 'ABSPATH' ) || exit;

/**
 * Shared callback-runtime contract for registered background work.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface WorkInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * Default ceiling for one consumer callback invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int DEFAULT_MAX_CALLBACK_RUNTIME = 300;

	// endregion

	// region METHODS

	/**
	 * Returns the declared ceiling in seconds for one consumer callback invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	public function max_callback_runtime(): int;

	// endregion
}
