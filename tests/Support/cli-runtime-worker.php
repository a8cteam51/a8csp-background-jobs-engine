<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
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
				$operations = $rig->operations( $owner );
				$operations->register( ( new RecordingJob( 'refresh' ) )->definition() );
				$result = $operations->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh' ) ) );
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
			$operations = $rig->operations( 'consumer-plugin' );
			$operations->register( ( new RecordingJob( 'email-digest' ) )->definition() );
			$enqueued = $operations->dispatch( 'email-digest' );
			if ( ! $enqueued instanceof Success ) {
				throw new \LogicException( 'The CLI worker could not register its run fixture.' );
			}
			$result = CliHarness::run( 'runs', array( 'list', 'consumer-plugin:email-digest' ), array( 'format' => 'csv' ) );
			break;

		case 'locks':
			$identity  = 'repair-tests:reports';
			$args_hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
			$rig->wpdb()->put( OverlapGuard::OPTION_PREFIX . $identity . '_' . $args_hash, 'malformed-lock' );
			$result = CliHarness::run( 'locks', array( 'list' ), array( 'format' => 'csv' ) );
			break;

		case 'locks-repair-declined':
			$identity  = 'repair-tests:reports';
			$args_hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
			$run_id    = '00000000000000086400-0000000000000000001';
			$fixtures  = StoreFixtureBuilder::for_identity( $identity );
			$rig->wpdb()->put( OverlapGuard::OPTION_PREFIX . $identity . '_' . $args_hash, 'malformed-lock' );
			[ $run_name, $run_raw ] = $fixtures->run(
				$run_id,
				new RunState( status: RunStatus::Running, kind: 'job', executing: false, start_args: array(), args_hash: $args_hash, kind_state: array(), failed_attempts: 0, action_sequence: 1, created_at: $now, heartbeat_at: $now, pending: PendingAction::async( 'run', 10 ) )
			);
			$rig->wpdb()->put( $run_name, $run_raw );
			$before = array(
				'wpdb'    => $rig->wpdb()->rows,
				'options' => $GLOBALS['a8csp_bgje_test_options'],
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
			$result = CliHarness::run( 'locks', array( 'repair', $identity ) );
			break;

		case 'schedules-remove-declined':
			$operations = $rig->operations( 'consumer-plugin' );
			$operations->register( ( new RecordingJob( 'refresh' ) )->definition() );
			$synced = $operations->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh' ) ) );
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
			$operations = $rig->operations( 'consumer-plugin' );
			$operations->register( ( new RecordingJob( 'email-digest' ) )->definition() );
			foreach ( array( 'consumer-plugin:email-digest', 'consumer-plugin:email_digest-2' ) as $identity ) {
				$failure        = new RunFailure( identity: $identity, run_id: RunId::from( '00000000000000086400-0000000000000000001' ), attempts: 2, stage: RunFailureStage::execution(), code: ErrorCode::ExecutionFailed, summary: 'Handler failed.', details: null );
				[ $name, $raw ] = StoreFixtureBuilder::for_identity( $identity )->failed( $now - 60, array( 'site_id' => 7 ), $failure, new EngineError( 'Handler failed.', \RuntimeException::class ) );
				$rig->wpdb()->put( $name, $raw );
			}
			$result = CliHarness::run( 'failed-runs', array( 'list' ), array( 'format' => 'csv' ) );
			break;

		case 'reset-declined':
			$operations = $rig->operations( 'reset-tests' );
			$operations->register( ( new RecordingJob( 'refresh' ) )->definition() );
			$enqueued = $operations->dispatch( 'refresh', array( 'site_id' => 7 ) );
			$synced   = $operations->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh' ) ) );
			if ( ! $enqueued instanceof Success || ! $synced instanceof Success ) {
				throw new \LogicException( 'The CLI worker could not seed reset fixtures.' );
			}
			$rig->backend()->pending_actions[ ActionDeliveries::DELIVER_HOOK ] = 5;
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
