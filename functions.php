<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundTasksEngine\Engine;
use A8C\SpecialProjects\BackgroundTasksEngine\EngineComponent;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Plugin;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;

\defined( 'ABSPATH' ) || exit;

// region META

/**
 * Returns the plugin instance, booting it on first access.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Plugin
 */
function a8csp_bgte_plugin(): Plugin {
	/**
	 * Boot-once shared instance.
	 *
	 * @var Plugin|null $plugin
	 */
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new Plugin();
		$plugin->boot();
	}

	return $plugin;
}

/**
 * Returns the consumer engine after its component has initialized.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Engine|null
 */
function a8csp_bgte_engine(): ?Engine {
	if ( 0 === did_action( 'plugins_loaded' ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			'Call a8csp_bgte_engine() after plugins_loaded, when the engine has booted.',
			'1.0.0'
		);

		return null;
	}

	return EngineComponent::get_engine();
}

/**
 * Returns the shared engine-unavailable failure for the procedural wrappers.
 *
 * @internal Wrapper fallback seam.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Failure<EngineError>
 */
function a8csp_bgte_engine_unavailable_failure(): Failure {
	return new Failure( new EngineError( 'The background tasks engine is unavailable; call after the engine boots on plugins_loaded.' ) );
}

// endregion

// region OTHER

require_once __DIR__ . '/includes/task-functions.php';
require_once __DIR__ . '/includes/batch-functions.php';
require_once __DIR__ . '/includes/schedule-functions.php';

// endregion
