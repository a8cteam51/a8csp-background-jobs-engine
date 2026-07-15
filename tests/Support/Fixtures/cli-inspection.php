<?php declare( strict_types=1 );

/**
 * Declares the task and schedule inspected by isolated WP-CLI requests.
 *
 * @package A8C\SpecialProjects\BackgroundTasksEngine
 */

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;

\WP_CLI::add_hook(
	'after_wp_load',
	static function (): void {
		$consumer = \a8csp_bgte( 'integration-cli-inspection-owner' );
		$consumer->tasks()->register( new RecordingTask( 'integration-cli-inspection-task' ) );
		$result = $consumer->schedules()->sync(
			array(
				new Schedule(
					'inspection-schedule',
					Recurrence::every( 300 ),
					'integration-cli-inspection-task',
					array( 'source' => 'schedule' )
				),
			)
		);
		if ( $result->is_failure() ) {
			\WP_CLI::error( 'The CLI inspection fixture could not synchronize its schedule.' );
		}
	}
);
