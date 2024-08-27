<?php

defined( 'ABSPATH' ) || exit;

use A8C\SpecialProjects\BackgroundTasks\Plugin;

// region

/**
 * Returns the plugin's main class instance.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Plugin
 */
function a8csp_background_tasks_get_plugin_instance(): Plugin {
	return Plugin::get_instance();
}

/**
 * Returns the plugin's slug.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  string
 */
function a8csp_background_tasks_get_plugin_slug(): string {
	return sanitize_key( A8CSP_BACKGROUND_TASKS_METADATA['TextDomain'] );
}

// endregion
