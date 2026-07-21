<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\CliHarness;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;

require_once __DIR__ . '/../bootstrap.php';

EngineRig::bootstrap();
require_once __DIR__ . '/WpCliRuntimeStub.php';

$scenario = $argv[1] ?? null;
if ( ! \is_string( $scenario ) ) {
	throw new \InvalidArgumentException( 'A CLI worker scenario is required.' );
}

$now = 86_400;
$rig = EngineRig::set_up( $now );
CliHarness::set_up();

try {
	switch ( $scenario ) {
		case 'schedules':
		case 'schedules-dormant':
			foreach ( array( 'consumer-plugin', 'other-plugin' ) as $owner ) {
				$client = $rig->client( $owner );
				$client->jobs()->register( new RecordingJob( 'refresh' ) );
				$result = $client->schedules()->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh' ) ) );
				if ( ! $result instanceof Success ) {
					throw new \LogicException( 'The CLI worker could not register its schedule fixture.' );
				}
			}
			if ( 'schedules-dormant' === $scenario ) {
				$rig->backend()->ready = false;
			}
			$result = CliHarness::run( 'schedules', array( 'list' ), array( 'format' => 'csv' ) );
			break;

		case 'runs':
			$client = $rig->client( 'consumer-plugin' );
			$client->jobs()->register( new RecordingJob( 'email-digest' ) );
			$enqueued = $client->jobs()->enqueue( 'email-digest' );
			if ( ! $enqueued instanceof Success ) {
				throw new \LogicException( 'The CLI worker could not register its run fixture.' );
			}
			$result = CliHarness::run( 'runs', array( 'list', 'consumer-plugin:email-digest' ), array( 'format' => 'csv' ) );
			break;

		case 'schedules-remove-declined':
			$client = $rig->client( 'consumer-plugin' );
			$client->jobs()->register( new RecordingJob( 'refresh' ) );
			$synced = $client->schedules()->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh' ) ) );
			if ( ! $synced instanceof Success ) {
				throw new \LogicException( 'The CLI worker could not seed schedule-removal fixtures.' );
			}
			$before = array(
				'wpdb'    => $rig->wpdb()->rows,
				'options' => $GLOBALS['a8csp_bgje_test_options'],
				'pending' => $rig->backend()->pending_actions,
			);

			$probe = \fopen( 'php://fd/3', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- File descriptor 3 is the parent's isolated test probe.
			if ( false === $probe ) {
				throw new \RuntimeException( 'The CLI worker probe stream is unavailable.' );
			}
			\register_shutdown_function(
				static function () use ( $rig, $before, $probe ): void {
					$after   = array(
						'wpdb'    => $rig->wpdb()->rows,
						'options' => $GLOBALS['a8csp_bgje_test_options'],
						'pending' => $rig->backend()->pending_actions,
					);
					$encoded = \wp_json_encode(
						array(
							'before' => $before,
							'after'  => $after,
						),
						\JSON_THROW_ON_ERROR
					);
					if ( ! \is_string( $encoded ) ) {
						throw new \RuntimeException( 'The CLI worker probe could not encode its mutation evidence.' );
					}
					\fwrite( $probe, $encoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- File descriptor 3 carries test-only mutation evidence.
					\fclose( $probe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- File descriptor 3 is a native process resource.
				}
			);
			$result = CliHarness::run( 'schedules', array( 'remove', 'consumer-plugin' ) );
			break;

		case 'failed-runs':
			$client = $rig->client( 'consumer-plugin' );
			$client->jobs()->register( new RecordingJob( 'email-digest' ) );
			foreach ( array( 'consumer-plugin:email-digest', 'consumer-plugin:email_digest-2' ) as $identity ) {
				$failure        = new RunFailure( identity: $identity, run_id: 'run-1', attempts: 2, stage: RunFailureStage::Execution, code: ErrorCode::ExecutionFailed, summary: 'Handler failed.', failed_chunk: null );
				[ $name, $raw ] = StoreFixtureBuilder::for_identity( $identity )->failed( $now - 60, array( 'site_id' => 7 ), $failure, new EngineError( 'Handler failed.', \RuntimeException::class ) );
				$rig->wpdb()->put( $name, $raw );
			}
			$result = CliHarness::run( 'failed-runs', array( 'list' ), array( 'format' => 'csv' ) );
			break;

		case 'reset-declined':
			$client = $rig->client( 'reset-tests' );
			$client->jobs()->register( new RecordingJob( 'refresh' ) );
			$enqueued = $client->jobs()->enqueue( 'refresh', array( 'site_id' => 7 ) );
			$synced   = $client->schedules()->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh' ) ) );
			if ( ! $enqueued instanceof Success || ! $synced instanceof Success ) {
				throw new \LogicException( 'The CLI worker could not seed reset fixtures.' );
			}
			$rig->backend()->pending_actions[ ActionDeliveries::RUN_JOB_HOOK ]  = 2;
			$rig->backend()->pending_actions[ ActionDeliveries::CONTINUE_HOOK ] = 3;
			$before = array(
				'wpdb'    => $rig->wpdb()->rows,
				'options' => $GLOBALS['a8csp_bgje_test_options'],
				'pending' => $rig->backend()->pending_actions,
			);

			$probe = \fopen( 'php://fd/3', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- File descriptor 3 is the parent's isolated test probe.
			if ( false === $probe ) {
				throw new \RuntimeException( 'The CLI worker probe stream is unavailable.' );
			}
			\register_shutdown_function(
				static function () use ( $rig, $before, $probe ): void {
					$after   = array(
						'wpdb'    => $rig->wpdb()->rows,
						'options' => $GLOBALS['a8csp_bgje_test_options'],
						'pending' => $rig->backend()->pending_actions,
					);
					$encoded = \wp_json_encode(
						array(
							'before' => $before,
							'after'  => $after,
						),
						\JSON_THROW_ON_ERROR
					);
					if ( ! \is_string( $encoded ) ) {
						throw new \RuntimeException( 'The CLI worker probe could not encode its mutation evidence.' );
					}
					\fwrite( $probe, $encoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- File descriptor 3 carries test-only mutation evidence.
					\fclose( $probe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- File descriptor 3 is a native process resource.
				}
			);
			$result = CliHarness::run( 'reset' );
			break;

		default:
			throw new \InvalidArgumentException( \sprintf( 'Unknown CLI worker scenario "%s".', $scenario ) );
	}

	\fwrite( STDOUT, $result->stdout ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The parent process captures this native WP-CLI stream.
	\fwrite( STDERR, $result->stderr ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The parent process captures this native WP-CLI stream.
} finally {
	$rig->tear_down();
}

exit( $result->exit_code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An integer exit status is process metadata, not rendered output.
