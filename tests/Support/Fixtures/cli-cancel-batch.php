<?php declare( strict_types=1 );

/**
 * Registers the batch identity required by the isolated WP-CLI completeness request.
 *
 * @package A8C\SpecialProjects\BackgroundTasksEngine
 */

use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;

\WP_CLI::add_hook(
	'after_wp_load',
	static function (): void {
		\a8csp_bgte( 'integration-cli-command' )->batches()->register( new RecordingBatch( 'integration-cli-command-cancel-batch' ) );
	}
);
