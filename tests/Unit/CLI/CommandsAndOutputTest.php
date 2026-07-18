<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\CLI;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Commands\RunsCommand;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Commands\SchedulesCommand;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Output\FailedRunOutput;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Output\RunOutput;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Output\ScheduleOutput;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\CliHarness;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the registered WP-CLI surface against the production engine graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Component::class )]
#[CoversClass( RunsCommand::class )]
#[CoversClass( SchedulesCommand::class )]
#[CoversClass( FailedRunOutput::class )]
#[CoversClass( RunOutput::class )]
#[CoversClass( ScheduleOutput::class )]
final class CommandsAndOutputTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const int NOW       = 86_400;
	private const string RUN_ID = 'run-1';

	private EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads the engine and WP-CLI boundary fakes before command registration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
		require_once \dirname( __DIR__, 2 ) . '/Support/WpCliRuntimeStub.php';
	}

	/**
	 * Boots one production graph and captures the real command registrations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig = EngineRig::set_up( self::NOW );
		CliHarness::set_up();
	}

	/**
	 * Releases request-local engine state after each command scenario.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function tearDown(): void {
		try {
			$this->rig->tear_down();
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * The CLI component contributes every spoken subcommand through its canonical root.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_component_registers_the_complete_command_surface(): void {
		self::assertSame( array( 'failed-runs', 'reset', 'runs', 'schedules' ), CliHarness::registered_subcommands() );
	}

	/**
	 * Every supported schedule format executes the registered command and renders its contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 * @param   string               $format     Effective output format.
	 *
	 * @return  void
	 */
	#[DataProvider( 'valid_schedule_requests' )]
	public function test_registered_schedule_command_accepts_every_documented_form( array $assoc_args, string $format ): void {
		if ( 'csv' === $format ) {
			$result = CliHarness::run_csv( 'schedules' );
		} else {
			$this->register_schedules();
			$result = CliHarness::run( 'schedules', array( 'list' ), $assoc_args );
		}

		self::assertSame( 0, $result->exit_code );
		self::assertSame( '', $result->stderr );
		self::assertNotSame( '', $result->stdout );
		if ( 'csv' === $format ) {
			self::assertSame( 'owner,identity,recurrence,next_due,last_fired,misfire_skips,overlap_skips,occurrence_visible,lock', \strtok( $result->stdout, "\n" ) );
		}
		if ( 'count' === $format ) {
			self::assertSame( isset( $assoc_args['owner'] ) ? '1' : '3', \trim( $result->stdout ) );
		}
		if ( isset( $assoc_args['owner'] ) ) {
			self::assertStringNotContainsString( 'other-plugin:nightly', $result->stdout );
		}
	}

	/**
	 * A dormant-backend warning stays on STDERR for every format.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $format Requested output format.
	 *
	 * @return  void
	 */
	#[DataProvider( 'dormant_schedule_formats' )]
	public function test_registered_schedule_command_warns_about_dormant_backends_for_every_format( string $format ): void {
		if ( 'csv' === $format ) {
			$result = CliHarness::run_csv( 'schedules-dormant' );
		} else {
			$this->register_schedules();
			$this->rig->backend()->ready = false;
			$result                      = CliHarness::run( 'schedules', array( 'list' ), array( 'format' => $format ) );
		}

		self::assertSame( 0, $result->exit_code );
		self::assertSame( 'Warning: a scheduling backend is not ready; dormant occurrences are not visible.' . "\n", $result->stderr );
		self::assertStringNotContainsString( 'dormant occurrences are not visible', $result->stdout );
	}

	/**
	 * An empty table still reports that dormant occurrences may exist.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_schedule_command_warns_about_dormant_backends_for_an_empty_table(): void {
		$this->rig->backend()->ready = false;

		$result = CliHarness::run( 'schedules', array( 'list' ), array( 'owner' => 'missing-owner' ) );

		self::assertSame( 0, $result->exit_code );
		self::assertSame( "No schedule registrations are persisted for owner \"missing-owner\".\n", $result->stdout );
		self::assertSame( 'Warning: a scheduling backend is not ready; dormant occurrences are not visible.' . "\n", $result->stderr );
	}

	/**
	 * Every invalid schedule row exits non-zero with its corrective rendered error.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array                $args       Positional arguments.
	 * @param   array<string, mixed> $assoc_args Named arguments.
	 * @param   string               $message    Corrective message.
	 *
	 * @return  void
	 *
	 * @phpstan-param list<string> $args
	 */
	#[DataProvider( 'invalid_schedule_requests' )]
	public function test_registered_schedule_command_rejects_every_invalid_form( array $args, array $assoc_args, string $message ): void {
		$result = CliHarness::run( 'schedules', $args, $assoc_args );

		self::assertSame( 1, $result->exit_code );
		self::assertSame( '', $result->stdout );
		self::assertSame( 'Error: ' . $message . "\n", $result->stderr );
	}

	/**
	 * Every supported run format renders a real waiting run from inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 * @param   string               $format     Effective output format.
	 *
	 * @return  void
	 */
	#[DataProvider( 'valid_run_formats' )]
	public function test_registered_runs_command_renders_every_documented_format( array $assoc_args, string $format ): void {
		if ( 'csv' === $format ) {
			$result = CliHarness::run_csv( 'runs' );
		} else {
			$client = $this->rig->client( 'consumer-plugin' );
			$client->tasks()->register( new RecordingTask( 'email-digest' ) );
			self::assertInstanceOf( Success::class, $client->tasks()->enqueue( 'email-digest' ) );
			$result = CliHarness::run( 'runs', array( 'list', 'consumer-plugin:email-digest' ), $assoc_args );
		}

		self::assertSame( 0, $result->exit_code );
		self::assertSame( '', $result->stderr );
		self::assertNotSame( '', $result->stdout );
		if ( 'csv' === $format ) {
			self::assertSame( 'run_id,status,phase,attempts,queue,heartbeat', \strtok( $result->stdout, "\n" ) );
		} elseif ( 'count' === $format ) {
			self::assertSame( '1', \trim( $result->stdout ) );
		} elseif ( 'table' === $format ) {
			self::assertStringContainsString( 'live runs', $result->stdout );
			foreach ( array( 'run_id', 'status', 'phase', 'attempts', 'queue', 'heartbeat' ) as $field ) {
				self::assertStringContainsString( $field, $result->stdout );
			}
		} elseif ( 'json' === $format ) {
			$rows = \json_decode( $result->stdout, true, 512, \JSON_THROW_ON_ERROR );
			self::assertIsArray( $rows );
			$row = $rows[0] ?? null;
			self::assertIsArray( $row );
			self::assertSame( array( 'run_id', 'status', 'phase', 'attempts', 'queue', 'heartbeat' ), \array_keys( $row ) );
		} elseif ( 'yaml' === $format ) {
			foreach ( array( 'run_id:', 'status: running', 'phase: waiting', 'attempts:', 'queue:', 'heartbeat:' ) as $row ) {
				self::assertStringContainsString( $row, $result->stdout );
			}
		}
	}

	/**
	 * The registered schedule command preserves every discriminated lock label.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedule_lock_labels_are_discriminated(): void {
		$client        = $this->rig->client( 'lock-tests' );
		$schedules     = array(
			'allow'   => new Schedule( 'allow', Recurrence::every( 300 ), 'allow-task', array( 'case' => 'allow' ), OverlapPolicy::Allow ),
			'failed'  => new Schedule( 'failed', Recurrence::every( 300 ), 'failed-task', array( 'case' => 'failed' ) ),
			'free'    => new Schedule( 'free', Recurrence::every( 300 ), 'free-task', array( 'case' => 'free' ) ),
			'invalid' => new Schedule( 'invalid', Recurrence::every( 300 ), 'invalid-task', array( 'case' => 'invalid' ) ),
		);
		$declarations  = array();
		$registrations = array(
			'lock-tests:orphaned' => array(
				'fingerprint'   => 'orphaned',
				'next_due'      => self::NOW + 300,
				'last_fired'    => null,
				'misfire_skips' => 0,
				'overlap_skips' => 0,
			),
		);
		foreach ( $schedules as $name => $schedule ) {
			$client->tasks()->register( new RecordingTask( $schedule->task ) );
			$declarations[ 'lock-tests:' . $name ]  = array(
				'schedule' => $schedule,
				'task'     => 'lock-tests:' . $schedule->task,
			);
			$registrations[ 'lock-tests:' . $name ] = array(
				'fingerprint'   => $schedule->fingerprint(),
				'next_due'      => self::NOW + 300,
				'last_fired'    => null,
				'misfire_skips' => 0,
				'overlap_skips' => 0,
			);
		}
		self::assertInstanceOf( Success::class, $client->schedules()->sync( \array_values( $schedules ) ) );
		$fixture = StoreFixtureBuilder::for_identity( 'lock-tests:invalid-task' );
		$this->put(
			$fixture->schedule_registration(
				array(
					'owner'         => 'lock-tests',
					'declarations'  => $declarations,
					'registrations' => $registrations,
				)
			)
		);
		unset( $this->rig->wpdb()->rows[ ScheduleRegistry::option_name( 'a8csp-bgte' ) ] );
		$this->rig->wpdb()->put( 'a8csp_bgte_overlap_lock_lock-tests:invalid-task_' . $fixture->args_hash( array( 'case' => 'invalid' ) ), 'not-a-lock-row' );
		$this->rig->wpdb()->before_next( 'select', static function (): void {} );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted lock read failure';
			}
		);

		$result = CliHarness::run( 'schedules', array( 'list' ) );

		self::assertSame( 0, $result->exit_code );
		self::assertSame( '', $result->stderr );
		foreach ( array( 'not blocking (overlap allowed)', 'unknown (lock read failed)', 'free', 'unknown (invalid lock row)', 'unknown (not declared this request)' ) as $label ) {
			self::assertStringContainsString( $label, $result->stdout );
		}
	}

	/**
	 * Every invalid runs row exits non-zero with its corrective rendered error.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array                $args       Positional arguments.
	 * @param   array<string, mixed> $assoc_args Named arguments.
	 * @param   string               $message    Corrective message.
	 *
	 * @return  void
	 *
	 * @phpstan-param list<string> $args
	 */
	#[DataProvider( 'invalid_run_requests' )]
	public function test_registered_runs_command_rejects_every_invalid_form( array $args, array $assoc_args, string $message ): void {
		$result = CliHarness::run( 'runs', $args, $assoc_args );

		self::assertSame( 1, $result->exit_code );
		self::assertSame( 'Error: ' . $message . "\n", $result->stderr );
	}

	/**
	 * Cancellation executes the real engine facade and reports the terminal outcome.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_runs_cancel_action_terminalizes_a_real_run(): void {
		$client = $this->rig->client( 'consumer-plugin' );
		$client->tasks()->register( new RecordingTask( 'email-digest' ) );
		$enqueued = $client->tasks()->enqueue( 'email-digest' );
		self::assertInstanceOf( Success::class, $enqueued );
		if ( ! \is_string( $enqueued->value ) ) {
			throw new \LogicException( 'A successful enqueue must publish a run identifier.' );
		}

		$result = CliHarness::run( 'runs', array( 'cancel', 'consumer-plugin:email-digest', $enqueued->value ) );

		self::assertSame( 0, $result->exit_code );
		self::assertSame( 'Success: Cancelled run ' . $enqueued->value . ' of "consumer-plugin:email-digest".' . "\n", $result->stdout );
		self::assertSame( 'cancelled', $this->rig->inspection()->runs( 'consumer-plugin:email-digest' )['history'][0]['outcome'] ?? null );

		$history_result = CliHarness::run( 'runs', array( 'list', 'consumer-plugin:email-digest' ), array( 'format' => 'json' ) );
		$history_rows   = \json_decode( $history_result->stdout, true, 512, \JSON_THROW_ON_ERROR );
		self::assertSame( 0, $history_result->exit_code );
		self::assertSame( '', $history_result->stderr );
		self::assertIsArray( $history_rows );
		$history_row = $history_rows[0] ?? null;
		self::assertIsArray( $history_row );
		self::assertSame( array( 'run_id', 'outcome', 'failed_store' ), \array_keys( $history_row ) );
		self::assertSame( '—', $history_row['failed_store'] );
	}

	/**
	 * Every malformed cancel row exits non-zero through the registered command.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array                $args       Positional arguments.
	 * @param   array<string, mixed> $assoc_args Named arguments.
	 *
	 * @return  void
	 *
	 * @phpstan-param list<string> $args
	 */
	#[DataProvider( 'invalid_cancel_requests' )]
	public function test_registered_runs_cancel_action_rejects_every_invalid_form( array $args, array $assoc_args ): void {
		$result = CliHarness::run( 'runs', \array_merge( array( 'cancel' ), $args ), $assoc_args );

		self::assertSame( 1, $result->exit_code );
		self::assertStringStartsWith( 'Error: ', $result->stderr );
	}

	/**
	 * Every valid failed-run action reaches real storage and renders a process contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array                $args       Positional arguments.
	 * @param   array<string, mixed> $assoc_args Named arguments.
	 * @param   string               $action     Validated action.
	 *
	 * @return  void
	 *
	 * @phpstan-param list<string> $args
	 */
	#[DataProvider( 'valid_failed_run_requests' )]
	public function test_registered_failed_runs_command_executes_every_documented_form( array $args, array $assoc_args, string $action ): void {
		if ( 'csv' === ( $assoc_args['format'] ?? null ) ) {
			$result = CliHarness::run_csv( 'failed-runs' );
		} else {
			$this->seed_failed_runs();
			$result = CliHarness::run( 'failed-runs', $args, $assoc_args );
		}

		self::assertSame( 0, $result->exit_code );
		self::assertSame( '', $result->stderr );
		self::assertNotSame( '', $result->stdout );
		$format = $assoc_args['format'] ?? 'table';
		if ( 'csv' === $format ) {
			self::assertSame( 'owner,identity,run_id,failed_at,attempts,stage,code,error_class,error_message', \strtok( $result->stdout, "\n" ) );
		} elseif ( 'list' === $action && 'table' === $format ) {
			self::assertStringContainsString( 'stage', $result->stdout );
			self::assertStringContainsString( 'code', $result->stdout );
			self::assertStringNotContainsString( 'failed_chunk', $result->stdout );
		} elseif ( 'list' === $action && 'json' === $format ) {
			$rows = \json_decode( $result->stdout, true, 512, \JSON_THROW_ON_ERROR );
			self::assertIsArray( $rows );
			$row = $rows[0] ?? null;
			self::assertIsArray( $row );
			self::assertSame( array( 'owner', 'identity', 'run_id', 'failed_at', 'attempts', 'stage', 'code', 'error_class', 'error_message', 'failed_chunk' ), \array_keys( $row ) );
			self::assertSame( 'execution', $row['stage'] );
			self::assertSame( 'execution_failed', $row['code'] );
			self::assertNull( $row['failed_chunk'] );
			$chunk_row = $rows[1] ?? null;
			self::assertIsArray( $chunk_row );
			self::assertSame( array( 'post_id' => 42 ), $chunk_row['failed_chunk'] );
		} elseif ( 'list' === $action && 'yaml' === $format ) {
			self::assertStringContainsString( 'stage: execution', $result->stdout );
			self::assertStringContainsString( 'code: execution_failed', $result->stdout );
			self::assertStringContainsString( 'failed_chunk: null', $result->stdout );
			self::assertStringContainsString( 'post_id: 42', $result->stdout );
		}
		if ( 'retry' === $action ) {
			self::assertStringContainsString( 'Retried failed run "run-1"', $result->stdout );
		}
		if ( 'purge' === $action ) {
			self::assertStringContainsString( 'Purged ', $result->stdout );
		}
	}

	/**
	 * Every invalid failed-run row exits non-zero with its corrective rendered error.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array                $args       Positional arguments.
	 * @param   array<string, mixed> $assoc_args Named arguments.
	 * @param   string               $message    Corrective message.
	 *
	 * @return  void
	 *
	 * @phpstan-param list<string> $args
	 */
	#[DataProvider( 'invalid_failed_run_requests' )]
	public function test_registered_failed_runs_command_rejects_every_invalid_form( array $args, array $assoc_args, string $message ): void {
		$result = CliHarness::run( 'failed-runs', $args, $assoc_args );

		self::assertSame( 1, $result->exit_code );
		self::assertSame( 'Error: ' . $message . "\n", $result->stderr );
	}

	/**
	 * Relative heartbeat boundaries are rendered through live production inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int    $heartbeat_at Persisted heartbeat timestamp.
	 * @param   string $expected     Rendered relative age.
	 *
	 * @return  void
	 */
	#[DataProvider( 'heartbeat_boundaries' )]
	public function test_registered_runs_command_renders_every_heartbeat_boundary( int $heartbeat_at, string $expected ): void {
		$this->rig->clock()->timestamp = $heartbeat_at;
		$client                        = $this->rig->client( 'clock-tests' );
		$client->tasks()->register( new RecordingTask( 'heartbeat' ) );
		self::assertInstanceOf( Success::class, $client->tasks()->enqueue( 'heartbeat' ) );
		$this->rig->clock()->timestamp = self::NOW;

		$result = CliHarness::run( 'runs', array( 'list', 'clock-tests:heartbeat' ) );

		self::assertSame( 0, $result->exit_code );
		self::assertStringContainsString( $expected, $result->stdout );
	}

	/**
	 * Future and integer-extreme heartbeats remain safe through the registered command.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_runs_command_handles_clock_skew_and_integer_extremes(): void {
		$this->rig->clock()->timestamp = self::NOW + 1;
		$client                        = $this->rig->client( 'clock-skew' );
		$client->tasks()->register( new RecordingTask( 'future' ) );
		self::assertInstanceOf( Success::class, $client->tasks()->enqueue( 'future' ) );
		$this->rig->clock()->timestamp = self::NOW;

		$future = CliHarness::run( 'runs', array( 'list', 'clock-skew:future' ) );

		self::assertSame( 0, $future->exit_code );
		self::assertStringContainsString( '0s ago', $future->stdout );

		$identity = 'clock-skew:extreme';
		$this->put( StoreFixtureBuilder::for_identity( $identity )->run( self::run_id( 1 ), self::state( 'extreme', \PHP_INT_MIN ) ) );
		$extreme = CliHarness::run( 'runs', array( 'list', $identity ) );

		self::assertSame( 0, $extreme->exit_code );
		self::assertStringContainsString( \intdiv( \PHP_INT_MAX, 86_400 ) . 'd ago (stale)', $extreme->stdout );
	}

	/**
	 * Schedule due rows render UTC timestamps with future, exact, and overdue direction.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_schedule_command_renders_due_boundaries_in_utc(): void {
		$client = $this->rig->client( 'due-tests' );
		$client->tasks()->register( new RecordingTask( 'refresh' ) );
		$schedules = array(
			'future'  => new Schedule( 'future', Recurrence::every( 300 ), 'refresh' ),
			'now'     => new Schedule( 'now', Recurrence::every( 300 ), 'refresh' ),
			'overdue' => new Schedule( 'overdue', Recurrence::every( 300 ), 'refresh' ),
		);
		self::assertInstanceOf( Success::class, $client->schedules()->sync( \array_values( $schedules ) ) );
		$declarations = array();
		foreach ( $schedules as $name => $schedule ) {
			$declarations[ 'due-tests:' . $name ] = array(
				'schedule' => $schedule,
				'task'     => 'due-tests:refresh',
			);
		}
		$this->put(
			StoreFixtureBuilder::for_identity( 'due-tests:refresh' )->schedule_registration(
				array(
					'owner'         => 'due-tests',
					'declarations'  => $declarations,
					'registrations' => array(
						'due-tests:future'  => array(
							'fingerprint'   => $schedules['future']->fingerprint(),
							'next_due'      => 86_460,
							'last_fired'    => null,
							'misfire_skips' => 0,
							'overlap_skips' => 0,
						),
						'due-tests:now'     => array(
							'fingerprint'   => $schedules['now']->fingerprint(),
							'next_due'      => 86_400,
							'last_fired'    => null,
							'misfire_skips' => 0,
							'overlap_skips' => 0,
						),
						'due-tests:overdue' => array(
							'fingerprint'   => $schedules['overdue']->fingerprint(),
							'next_due'      => 82_800,
							'last_fired'    => null,
							'misfire_skips' => 0,
							'overlap_skips' => 0,
						),
					),
				)
			)
		);

		$result = CliHarness::run( 'schedules', array( 'list' ) );

		self::assertSame( 0, $result->exit_code );
		self::assertStringContainsString( '1970-01-02T00:01:00+00:00 (in 1m)', $result->stdout );
		self::assertStringContainsString( '1970-01-02T00:00:00+00:00 (due now)', $result->stdout );
		self::assertStringContainsString( '1970-01-01T23:00:00+00:00 (overdue 1h)', $result->stdout );
	}

	/**
	 * Compromised and truncated live listings remain explicit through the registered command.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_runs_command_renders_live_listing_honesty_messages(): void {
		$this->rig->wpdb()->before_next(
			'scan',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'enumeration failed';
			}
		);
		$enumeration = CliHarness::run( 'runs', array( 'list', 'honesty:enumeration' ) );
		self::assertSame( 1, $enumeration->exit_code );
		self::assertSame( "Error: Live-run state is unknown (run enumeration failed); resolve the database error and try again.\n", $enumeration->stderr );

		$row_identity = 'honesty:row';
		$this->put( StoreFixtureBuilder::for_identity( $row_identity )->run( self::run_id( 1 ), self::state( 'row' ) ) );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'row failed';
			}
		);
		$row = CliHarness::run( 'runs', array( 'list', $row_identity ) );
		self::assertSame( 1, $row->exit_code );
		self::assertSame( "Error: Live-run state is unknown (run read failed); resolve the database error and try again.\n", $row->stderr );

		$many_identity = 'honesty:many';
		$fixtures      = StoreFixtureBuilder::for_identity( $many_identity );
		for ( $sequence = 1; $sequence <= 24; ++$sequence ) {
			$this->put( $fixtures->run( self::run_id( $sequence ), self::state( 'many-' . $sequence ) ) );
		}
		$truncated = CliHarness::run( 'runs', array( 'list', $many_identity ) );
		self::assertSame( 0, $truncated->exit_code );
		self::assertSame( "Warning: Showing first 20 matching run rows; 4 more were not inspected.\n", $truncated->stderr );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Registers two owner-distinct schedules through public facades.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function register_schedules(): void {
		foreach ( array( 'consumer-plugin', 'other-plugin' ) as $owner ) {
			$client = $this->rig->client( $owner );
			$client->tasks()->register( new RecordingTask( 'refresh' ) );
			self::assertInstanceOf( Success::class, $client->schedules()->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh' ) ) ) );
		}
	}

	/**
	 * Seeds valid failed-run rows through production encoders.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function seed_failed_runs(): void {
		$client = $this->rig->client( 'consumer-plugin' );
		$client->tasks()->register( new RecordingTask( 'email-digest' ) );
		foreach ( array( 'consumer-plugin:email-digest', 'consumer-plugin:email_digest-2' ) as $identity ) {
			$failed_chunk   = 'consumer-plugin:email_digest-2' === $identity ? array( 'post_id' => 42 ) : null;
			$failure        = new RunFailure( identity: $identity, run_id: self::RUN_ID, attempts: 2, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'Handler failed.', failed_chunk: $failed_chunk );
			[ $name, $raw ] = StoreFixtureBuilder::for_identity( $identity )->failed( self::NOW - 60, array( 'site_id' => 7 ), $failure, new EngineError( 'Handler failed.', \RuntimeException::class ) );
			$this->rig->wpdb()->put( $name, $raw );
		}
	}

	/**
	 * Returns one canonical fixed-width run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $sequence Per-timestamp run sequence.
	 *
	 * @return  string
	 */
	private static function run_id( int $sequence ): string {
		return \sprintf( '%020d-%019d', self::NOW, $sequence );
	}

	/**
	 * Returns one production-valid running state with an explicit heartbeat.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $args_hash    Persisted argument hash.
	 * @param   int    $heartbeat_at Latest heartbeat timestamp.
	 *
	 * @return  RunState
	 */
	private static function state( string $args_hash, int $heartbeat_at = self::NOW ): RunState {
		return new RunState( status: RunStatus::Running, kind: 'Task', executing: false, start_args: array(), args_hash: $args_hash, queue: array( array() ), failed_attempts: 0, action_seq: 1, created_at: self::NOW, heartbeat_at: $heartbeat_at );
	}

	/**
	 * Persists one production-encoded option fixture.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{string, string} $fixture Encoded option name and value.
	 *
	 * @return  void
	 */
	private function put( array $fixture ): void {
		$this->rig->wpdb()->put( $fixture[0], $fixture[1] );
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Supplies every supported schedule-list invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{assoc_args: array<string, mixed>, format: string}>
	 */
	public static function valid_schedule_requests(): array {
		return array(
			'default' => array(
				'assoc_args' => array(),
				'format'     => 'table',
			),
			'owner'   => array(
				'assoc_args' => array( 'owner' => 'consumer-plugin' ),
				'format'     => 'table',
			),
			'csv'     => array(
				'assoc_args' => array( 'format' => 'csv' ),
				'format'     => 'csv',
			),
			'json'    => array(
				'assoc_args' => array( 'format' => 'json' ),
				'format'     => 'json',
			),
			'count'   => array(
				'assoc_args' => array( 'format' => 'count' ),
				'format'     => 'count',
			),
			'yaml'    => array(
				'assoc_args' => array( 'format' => 'yaml' ),
				'format'     => 'yaml',
			),
		);
	}

	/**
	 * Supplies every schedule-list format for dormant-backend warnings.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{format: string}>
	 */
	public static function dormant_schedule_formats(): array {
		return array(
			'table' => array( 'format' => 'table' ),
			'csv'   => array( 'format' => 'csv' ),
			'json'  => array( 'format' => 'json' ),
			'count' => array( 'format' => 'count' ),
			'yaml'  => array( 'format' => 'yaml' ),
		);
	}

	/**
	 * Supplies every rejected schedule-list invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{args: list<string>, assoc_args: array<string, mixed>, message: string}>
	 */
	public static function invalid_schedule_requests(): array {
		$usage = 'Schedule list accepts only --owner and --format; use wp background-tasks schedules list [--owner=<owner>] [--format=<format>].';
		return array(
			'missing action'   => array(
				'args'       => array(),
				'assoc_args' => array(),
				'message'    => 'A schedule action is required; use list.',
			),
			'unknown action'   => array(
				'args'       => array( 'show' ),
				'assoc_args' => array(),
				'message'    => 'Schedule action "show" is invalid; use list.',
			),
			'extra positional' => array(
				'args'       => array( 'list', 'extra' ),
				'assoc_args' => array(),
				'message'    => $usage,
			),
			'stray flag'       => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => $usage,
			),
			'negated owner'    => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'owner' => false ),
				'message'    => 'Schedule list owner is invalid; pass a value with --owner=<owner>.',
			),
			'invalid owner'    => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'owner' => 'Consumer-Plugin' ),
				'message'    => 'Schedule list owner is invalid; pass a canonical owner with --owner=<owner>.',
			),
			'invalid format'   => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'ids' ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
			'negated format'   => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => false ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
		);
	}

	/**
	 * Supplies every supported run-list format.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{assoc_args: array<string, mixed>, format: string}>
	 */
	public static function valid_run_formats(): array {
		return array(
			'table' => array(
				'assoc_args' => array(),
				'format'     => 'table',
			),
			'csv'   => array(
				'assoc_args' => array( 'format' => 'csv' ),
				'format'     => 'csv',
			),
			'json'  => array(
				'assoc_args' => array( 'format' => 'json' ),
				'format'     => 'json',
			),
			'count' => array(
				'assoc_args' => array( 'format' => 'count' ),
				'format'     => 'count',
			),
			'yaml'  => array(
				'assoc_args' => array( 'format' => 'yaml' ),
				'format'     => 'yaml',
			),
		);
	}

	/**
	 * Supplies every rejected run-list invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{args: list<string>, assoc_args: array<string, mixed>, message: string}>
	 */
	public static function invalid_run_requests(): array {
		$usage = 'Run list requires exactly one identity and accepts only --format; use wp background-tasks runs list <identity> [--format=<format>].';
		return array(
			'missing action' => array(
				'args'       => array(),
				'assoc_args' => array(),
				'message'    => 'A run action is required; use list <identity> or cancel <identity> <run_id>.',
			),
			'unknown action' => array(
				'args'       => array( 'show', 'consumer-plugin:email-digest' ),
				'assoc_args' => array(),
				'message'    => 'Run action "show" is invalid; use list or cancel.',
			),
			'missing name'   => array(
				'args'       => array( 'list' ),
				'assoc_args' => array(),
				'message'    => $usage,
			),
			'extra name'     => array(
				'args'       => array( 'list', 'consumer-plugin:email-digest', 'extra' ),
				'assoc_args' => array(),
				'message'    => $usage,
			),
			'stray flag'     => array(
				'args'       => array( 'list', 'consumer-plugin:email-digest' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => $usage,
			),
			'invalid name'   => array(
				'args'       => array( 'list', 'email-digest' ),
				'assoc_args' => array(),
				'message'    => 'Run identity is invalid; use a composed {owner}:{name} identity.',
			),
			'invalid format' => array(
				'args'       => array( 'list', 'consumer-plugin:email-digest' ),
				'assoc_args' => array( 'format' => 'ids' ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
			'negated format' => array(
				'args'       => array( 'list', 'consumer-plugin:email-digest' ),
				'assoc_args' => array( 'format' => false ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
		);
	}

	/**
	 * Supplies every relative-heartbeat rendering boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{heartbeat_at: int, expected: string}>
	 */
	public static function heartbeat_boundaries(): array {
		return array(
			'zero'         => array(
				'heartbeat_at' => 86_400,
				'expected'     => '0s ago',
			),
			'last second'  => array(
				'heartbeat_at' => 86_341,
				'expected'     => '59s ago',
			),
			'first minute' => array(
				'heartbeat_at' => 86_340,
				'expected'     => '1m ago',
			),
			'last minute'  => array(
				'heartbeat_at' => 82_801,
				'expected'     => '59m ago',
			),
			'first hour'   => array(
				'heartbeat_at' => 82_800,
				'expected'     => '1h ago',
			),
			'last hour'    => array(
				'heartbeat_at' => 1,
				'expected'     => '23h ago',
			),
			'first day'    => array(
				'heartbeat_at' => 0,
				'expected'     => '1d ago',
			),
		);
	}

	/**
	 * Supplies every rejected cancellation invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{args: list<string>, assoc_args: array<string, mixed>}>
	 */
	public static function invalid_cancel_requests(): array {
		return array(
			'missing identity' => array(
				'args'       => array(),
				'assoc_args' => array(),
			),
			'missing run_id'   => array(
				'args'       => array( 'email-digest' ),
				'assoc_args' => array(),
			),
			'extra positional' => array(
				'args'       => array( 'email-digest', 'run-1', 'extra' ),
				'assoc_args' => array(),
			),
			'stray flag'       => array(
				'args'       => array( 'email-digest', 'run-1' ),
				'assoc_args' => array( 'force' => true ),
			),
			'negated flag'     => array(
				'args'       => array( 'email-digest', 'run-1' ),
				'assoc_args' => array( 'force' => false ),
			),
		);
	}

	/**
	 * Supplies every supported failed-run invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{args: list<string>, assoc_args: array<string, mixed>, action: string}>
	 */
	public static function valid_failed_run_requests(): array {
		return array(
			'list default' => array(
				'args'       => array( 'list' ),
				'assoc_args' => array(),
				'action'     => 'list',
			),
			'list owner'   => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'owner' => 'consumer-plugin' ),
				'action'     => 'list',
			),
			'list csv'     => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'csv' ),
				'action'     => 'list',
			),
			'list json'    => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'json' ),
				'action'     => 'list',
			),
			'list count'   => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'count' ),
				'action'     => 'list',
			),
			'list yaml'    => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'yaml' ),
				'action'     => 'list',
			),
			'retry'        => array(
				'args'       => array( 'retry', 'consumer-plugin:email-digest', 'run-1' ),
				'assoc_args' => array(),
				'action'     => 'retry',
			),
			'purge name'   => array(
				'args'       => array( 'purge', 'consumer-plugin:email_digest-2' ),
				'assoc_args' => array(),
				'action'     => 'purge',
			),
			'purge all'    => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array( 'all' => true ),
				'action'     => 'purge',
			),
		);
	}

	/**
	 * Supplies every rejected failed-run invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{args: list<string>, assoc_args: array<string, mixed>, message: string}>
	 */
	public static function invalid_failed_run_requests(): array {
		$list_usage  = 'List accepts only --owner and --format; use wp background-tasks failed-runs list [--owner=<owner>] [--format=<format>].';
		$retry_usage = 'Retry requires exactly an identity and run_id; use wp background-tasks failed-runs retry <identity> <run_id>.';
		$purge_usage = 'Purge requires exactly one identity or --all; use wp background-tasks failed-runs purge <identity> or purge --all.';
		return array(
			'missing action'         => array(
				'args'       => array(),
				'assoc_args' => array(),
				'message'    => 'A failed-run action is required; use list, retry <identity> <run_id>, purge <identity>, or purge --all.',
			),
			'unknown action'         => array(
				'args'       => array( 'remove' ),
				'assoc_args' => array(),
				'message'    => 'Failed-run action "remove" is invalid; use list, retry, or purge.',
			),
			'list positional'        => array(
				'args'       => array( 'list', 'consumer-plugin:email-digest' ),
				'assoc_args' => array(),
				'message'    => $list_usage,
			),
			'list flag'              => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => $list_usage,
			),
			'list owner type'        => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'owner' => false ),
				'message'    => 'List owner is invalid; pass a value with --owner=<owner>.',
			),
			'list invalid owner'     => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'owner' => 'Consumer-Plugin' ),
				'message'    => 'List owner is invalid; pass a canonical owner with --owner=<owner>.',
			),
			'list format'            => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'ids' ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
			'list format type'       => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => true ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
			'retry missing run_id'   => array(
				'args'       => array( 'retry', 'consumer-plugin:email-digest' ),
				'assoc_args' => array(),
				'message'    => $retry_usage,
			),
			'retry flag'             => array(
				'args'       => array( 'retry', 'consumer-plugin:email-digest', 'run-1' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => $retry_usage,
			),
			'retry invalid identity' => array(
				'args'       => array( 'retry', 'email-digest', 'run-1' ),
				'assoc_args' => array(),
				'message'    => 'Retry identity is invalid; use a composed {owner}:{name} identity.',
			),
			'bare purge'             => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array(),
				'message'    => $purge_usage,
			),
			'purge name and all'     => array(
				'args'       => array( 'purge', 'consumer-plugin:email-digest' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => $purge_usage,
			),
			'purge negated all'      => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array( 'all' => false ),
				'message'    => $purge_usage,
			),
			'purge string all'       => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array( 'all' => 'false' ),
				'message'    => $purge_usage,
			),
			'purge name stray all'   => array(
				'args'       => array( 'purge', 'consumer-plugin:email-digest' ),
				'assoc_args' => array( 'all' => false ),
				'message'    => $purge_usage,
			),
			'purge extra name'       => array(
				'args'       => array( 'purge', 'consumer-plugin:email-digest', 'other' ),
				'assoc_args' => array(),
				'message'    => $purge_usage,
			),
			'purge invalid identity' => array(
				'args'       => array( 'purge', 'email-digest' ),
				'assoc_args' => array(),
				'message'    => 'Purge identity is invalid; use a composed {owner}:{name} identity.',
			),
			'purge flag'             => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array( 'format' => 'json' ),
				'message'    => 'Purge accepts only --all; use wp background-tasks failed-runs purge <identity> or purge --all.',
			),
		);
	}

	// endregion.
}
