<?php declare( strict_types=1 );

/**
 * Declares the job and schedule inspected by isolated WP-CLI requests.
 *
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;

\WP_CLI::add_hook(
	'after_wp_load',
	static function (): void {
		$operations = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( 'integration-cli-inspection-owner' );
		$operations->register( ( new RecordingJob( 'integration-cli-inspection-job' ) )->definition() );
		$result = $operations->sync(
			array(
				new Schedule( 'inspection-schedule', Recurrence::every( 300 ), 'integration-cli-inspection-job', array( 'source' => 'schedule' ) ),
			)
		);
		if ( $result->is_failure() ) {
			\WP_CLI::error( 'The CLI inspection fixture could not synchronize its schedule.' );
		}
	}
);
