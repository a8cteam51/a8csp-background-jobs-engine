<?php declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use A8C\SpecialProjects\BackgroundTasks\Plugin;

// region META

/**
 * Returns the plugin's main class instance.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @return  Plugin
 */
function a8csp_bgt_get_plugin_instance(): Plugin {
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
function a8csp_bgt_get_plugin_slug(): string {
	return sanitize_key( A8CSP_BGT_METADATA['TextDomain'] );
}

/**
 * Returns the task instance for the provided task name.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $task_name The name of the task to return the instance for.
 *
 * @return  A8CSP_Abstract_Background_Task|null
 */
function a8csp_bgt_get_task( string $task_name ): ?A8CSP_Abstract_Background_Task {
	$tasks = apply_filters( 'a8csp/background_tasks', array() );
	return $tasks[ $task_name ] ?? null;
}

/**
 * Returns the task scheduler adapter for the provided task name.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string $task_name The name of the task to return the scheduler adapter for.
 *
 * @return  A8CSP_Task_Scheduler_Adapter_Interface|null
 */
function a8csp_bgt_get_task_scheduler( string $task_name ): ?A8CSP_Task_Scheduler_Adapter_Interface {
	$task = a8csp_bgt_get_task( $task_name );
	return $task ? $task::get_scheduler() : null;
}

// endregion

// region OTHERS

foreach ( glob( A8CSP_BGT_PATH . 'includes/*.php' ) as $a8csp_bgt_filename ) {
	if ( preg_match( '#/includes/_#i', $a8csp_bgt_filename ) ) {
		continue; // Ignore files prefixed with an underscore.
	}

	include $a8csp_bgt_filename;
}

// endregion
