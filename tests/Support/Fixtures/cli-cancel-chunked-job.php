<?php declare( strict_types=1 );

/**
 * Registers the chunked job identity required by the isolated WP-CLI completeness request.
 *
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;

\WP_CLI::add_hook(
	'after_wp_load',
	static function (): void {
		\a8csp_bgje( 'integration-cli-command' )->chunked_jobs()->register( new RecordingChunkedJob( 'integration-cli-command-cancel-chunked-job' ) );
	}
);
