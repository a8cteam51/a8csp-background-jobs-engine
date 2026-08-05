<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\ChunkedJobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\ScopeOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises cancellation fencing and outcomes through the scope-bound run facade.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Dispatcher::class )]
#[CoversClass( ChunkedJobKindHandler::class )]
final class DispatcherCancelTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS                  = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const string CHUNKED_JOB_IDENTITY = self::SCOPE . ':' . self::CHUNKED_JOB_NAME;
	private const string CHUNKED_JOB_NAME     = 'catalog-sync';
	private const int NOW                     = 1_700_000_000;
	private const string SCOPE                = 'runs-tests';
	private const string RUN_ID               = '00000000001700000000-0000000000000000042';
	private const string JOB_IDENTITY         = self::SCOPE . ':' . self::JOB_NAME;
	private const string JOB_NAME             = 'email-digest';

	private RecordingChunkedJob $chunked_job;
	private ScopeOperations $client;
	private EngineRig $rig;
	private RecordingJob $job;
	private StoreFixtureBuilder $job_fixtures;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress seams before the production graph is built.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
	}

	/**
	 * Boots registered job and chunked job work against deterministic boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig         = EngineRig::set_up( self::NOW, 2 );
		$this->client      = $this->rig->operations( self::SCOPE );
		$this->job         = new RecordingJob( self::JOB_NAME );
		$this->chunked_job = new RecordingChunkedJob( self::CHUNKED_JOB_NAME );
		$this->client->register( $this->job->definition() );
		$this->client->register( $this->chunked_job->definition() );
		$this->job_fixtures = StoreFixtureBuilder::for_identity( self::JOB_IDENTITY );
		$this->reset_backend_observations();
	}

	/**
	 * Releases request-local engine state after each scenario.
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
	 * A pending job cancels through public Results, hooks, and one run clear.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_pending_job_records_the_outcome_hooks_and_run_clear(): void {
		$run_id = $this->enqueue_job();
		$this->reset_backend_observations();

		$result = $this->client->cancel( self::JOB_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::JOB_IDENTITY, $run_id );
	}

	/**
	 * A backend clear failure cannot change the already-fenced cancellation outcome.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_finishes_after_a_run_clear_failure(): void {
		$run_id = $this->enqueue_job();
		$this->reset_backend_observations();
		$this->rig->backend()->results['unschedule_run'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Repair scheduling.' ) );

		$result = $this->client->cancel( self::JOB_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::JOB_IDENTITY, $run_id );
		$warnings = \array_values( \array_filter( $this->rig->logger()->records, static fn ( array $record ): bool => 'warning' === $record['level'] ) );
		self::assertCount( 1, $warnings );
		self::assertSame( 'Cancelled-run pending deliveries could not be cleared; the terminal state fences any leftover delivery.', $warnings[0]['message'] ?? null );
		self::assertSame( self::JOB_IDENTITY, $warnings[0]['context']['identity'] ?? null );
		self::assertSame( $run_id, $warnings[0]['context']['run_id'] ?? null );
		self::assertSame( 'Repair scheduling.', $warnings[0]['context']['error'] ?? null );
	}

	/**
	 * A throwing cancelled listener cannot overturn the committed cancellation result.
	 *
	 * @load-bearing durability
	 * @pin-rationale The public success cannot expose intermediate terminal-effect progress; the retained production row proves the failed hook remains eligible for maintenance replay.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_contains_a_throwing_cancelled_listener_and_retains_the_pending_hook_effect(): void {
		$run_id    = $this->enqueue_job();
		$throwable = new \RuntimeException( 'Cancelled listener exploded.' );
		$this->put_job_state( RunStatus::Running, false, 0, 1, self::NOW, PendingAction::async( 'run', 10 ) );
		$this->reset_backend_observations();
		$GLOBALS['a8csp_bgje_test_action_throwables'] = array( 'a8csp_bgje/cancelled/' . self::JOB_IDENTITY => $throwable );

		$result = $this->client->cancel( self::JOB_NAME, $run_id );

		self::assertInstanceOf( Run::class, $result );
		self::assertInstanceOf( RunId::class, $result->id );
		self::assertSame( $run_id, (string) $result->id );
		self::assertSame( 'cancelled', $this->decoded_job_state()['status'] ?? null );
		self::assertSame( array( 'history' ), $this->decoded_job_state()['effects'] ?? null );
		$records = \array_values( \array_filter( $this->rig->logger()->records, static fn ( array $candidate ): bool => ( $candidate['context']['exception'] ?? null ) === $throwable ) );
		self::assertCount( 1, $records );
		$record = $records[0];
		self::assertSame( 'error', $record['level'] ?? null );
		$this->assert_run_clear( self::JOB_IDENTITY, $run_id );
	}

	/**
	 * Cancellation rejects a malformed run identifier at the engine boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_a_malformed_run_identifier(): void {
		try {
			(void) $this->client->cancel( self::JOB_NAME, 'malformed_run_id' );
			self::fail( 'A malformed cancellation identifier must be rejected before storage lookup.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( 'Run identifier is malformed; pass a run ID the engine returned.', $exception->getMessage() );
		}
	}

	/**
	 * A missing run returns the stable not-retained classification without effects.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_a_missing_run(): void {
		$before = $this->cancellation_effects();

		$result = $this->client->cancel( self::JOB_NAME, self::RUN_ID );

		$this->assert_failure_code( $result, ErrorCode::RunNotRetained );
		self::assertSame( $before, $this->cancellation_effects() );
	}

	/**
	 * A deliberately corrupt run is indistinguishable from absent retained state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_a_corrupt_run(): void {
		$this->rig->wpdb()->put( $this->run_option_name( self::JOB_IDENTITY, self::RUN_ID ), 'corrupt' );
		$before = $this->cancellation_effects();

		$result = $this->client->cancel( self::JOB_NAME, self::RUN_ID );

		$this->assert_failure_code( $result, ErrorCode::RunNotRetained );
		self::assertSame( $before, $this->cancellation_effects() );
	}

	/**
	 * A retained terminal snapshot refuses another transition.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_an_already_terminal_run(): void {
		$run_id = $this->enqueue_job();
		$this->put_job_state( RunStatus::Completed, false, 0, 1, self::NOW, null );
		$before = $this->cancellation_effects();

		$result = $this->client->cancel( self::JOB_NAME, $run_id );

		$error = $this->assert_failure_code( $result, ErrorCode::RunNotCancellable );
		$data  = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'completed', $data['status'] ?? null );
		self::assertSame( $before, $this->cancellation_effects() );
	}

	/**
	 * An executing marker refuses cancellation before any write.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A fixture-built executing generation is the durable fence; exact pre/post rows prove cancellation cannot mutate or clear it.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_an_executing_run_before_any_write(): void {
		$run_id = $this->enqueue_job();
		$this->put_job_state( RunStatus::Running, true, 0, 1, self::NOW + 300, null );
		$before = $this->rig->wpdb()->rows;

		$result = $this->client->cancel( self::JOB_NAME, $run_id );

		$this->assert_failure_code( $result, ErrorCode::RunNotCancellable );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->backend_calls( 'unschedule_run' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_bgje/cancelled' ) );
	}

	/**
	 * A non-marker state change produces the neutral lost-CAS refusal.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The heartbeat generation changes at the terminal CAS boundary; fixture-built replacement bytes prove cancellation preserves the winner.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_reports_a_neutral_failure_after_losing_its_terminal_cas(): void {
		$run_id = $this->enqueue_job();
		$this->rig->wpdb()->before_next(
			'update',
			function (): void {
				$this->put_job_state( RunStatus::Running, false, 0, 1, self::NOW + 1, PendingAction::async( 'run', 10 ) );
			}
		);

		$result = $this->client->cancel( self::JOB_NAME, $run_id );

		$error = $this->assert_failure_code( $result, ErrorCode::RunNotCancellable );
		self::assertStringContainsString( 're-inspect the run before retrying', $error->get_error_message() );
		self::assertSame( self::NOW + 1, $this->decoded_job_state()['heartbeat_at'] ?? null );
		self::assertSame( array(), $this->backend_calls( 'unschedule_run' ) );
	}

	/**
	 * A failed cancellation write remains distinguishable from a lost terminal compare-and-swap.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_reports_storage_failure_when_its_terminal_write_fails(): void {
		$run_id = $this->enqueue_job();
		$before = $this->cancellation_effects();
		$this->rig->wpdb()->script_result( 'update', false );

		$result = $this->client->cancel( self::JOB_NAME, $run_id );

		$this->assert_failure_code( $result, ErrorCode::StorageFailed );
		self::assertSame( $before, $this->cancellation_effects() );
	}

	/**
	 * A cancellation storage failure advises storage repair instead of reporting changed state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_storage_failure_recommends_storage_repair_instead_of_reinspection(): void {
		$run_id = $this->enqueue_job();
		$this->rig->wpdb()->script_result( 'update', false );

		$result = $this->client->cancel( self::JOB_NAME, $run_id );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertStringContainsString( 'repair option writes', $result->get_error_message() );
		self::assertStringNotContainsString( 're-inspect', $result->get_error_message() );
		self::assertStringNotContainsString( 'changed state', $result->get_error_message() );
	}

	/**
	 * A delivery marker that wins the shared-row CAS changes cancellation to executing.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built executing bytes replace the inspected generation at the cancellation CAS, modeling the marker winner without bypassing production cancellation logic.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_reports_executing_when_the_delivery_marker_wins_the_race(): void {
		$run_id = $this->enqueue_job();
		$this->rig->wpdb()->before_next(
			'update',
			function (): void {
				$this->put_job_state( RunStatus::Running, true, 0, 1, self::NOW + 300, null );
			}
		);

		$result = $this->client->cancel( self::JOB_NAME, $run_id );

		$this->assert_failure_code( $result, ErrorCode::RunNotCancellable );
		self::assertTrue( $this->decoded_job_state()['executing'] ?? false );
		self::assertSame( array(), $this->job->calls );
		self::assertSame( array(), $this->backend_calls( 'unschedule_run' ) );
	}

	/**
	 * Cancellation that wins first deletes the row before delivery can expose user code.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The real registered delivery attempts its marker CAS while a public cancel runs at the database interleaving, proving only one fence winner reaches effects.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_delivery_admission_drops_when_cancel_wins_the_marker_race(): void {
		$run_id        = $this->enqueue_job();
		$cancel_result = null;
		$this->rig->wpdb()->before_next(
			'update',
			function () use ( &$cancel_result, $run_id ): void {
				$cancel_result = $this->client->cancel( self::JOB_NAME, $run_id );
			}
		);

		$this->rig->run_due();

		self::assertInstanceOf( Run::class, $cancel_result );
		$this->assert_successful_cancel( $cancel_result, self::JOB_IDENTITY, $run_id );
		self::assertSame( array(), $this->job->calls );
	}

	/**
	 * An unregistered name returns the cancel-specific public classification.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_an_unregistered_name(): void {
		$before = $this->cancellation_effects();

		$result = $this->client->cancel( 'unknown', self::RUN_ID );

		$this->assert_failure_code( $result, ErrorCode::UnknownJob );
		self::assertSame( $before, $this->cancellation_effects() );
	}

	/**
	 * A retained run without a live registration remains untouched.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_a_retained_run_without_a_live_registration(): void {
		$identity = self::SCOPE . ':unknown';
		$fixtures = StoreFixtureBuilder::for_identity( $identity );
		$state    = new RunState( status: RunStatus::Running, kind: 'job', executing: false, start_args: self::ARGS, args_hash: $fixtures->args_hash( self::ARGS ), kind_state: array(), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( 'run', 10 ) );
		$fixture  = $fixtures->run( self::RUN_ID, $state );
		$this->rig->wpdb()->put( $fixture[0], $fixture[1] );
		$before = $this->rig->wpdb()->rows;

		$result = $this->client->cancel( 'unknown', self::RUN_ID );

		$error = $this->assert_failure_code( $result, ErrorCode::UnknownJob );
		$data  = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'job', $data['kind'] ?? null );
		self::assertStringContainsString( 'is not registered', $error->get_error_message() );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->backend_calls( 'unschedule_run' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_bgje/cancelled' ) );
	}

	/**
	 * Cancellation refuses a live registration whose kind differs from the retained run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_a_live_registration_with_a_different_kind_from_the_retained_run(): void {
		$run_id = $this->enqueue_job();
		$this->put_job_state( RunStatus::Running, false, 0, 1, self::NOW, PendingAction::async( 'run', 10 ), 'chunked_job' );
		$this->reset_backend_observations();
		$before = $this->rig->wpdb()->rows;

		$result = $this->client->cancel( self::JOB_NAME, $run_id );

		$error = $this->assert_failure_code( $result, ErrorCode::UnknownJob );
		$data  = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 'chunked_job', $data['kind'] ?? null );
		self::assertStringContainsString( 'persisted as "chunked_job"', $error->get_error_message() );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->backend_calls( 'unschedule_run' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_bgje/cancelled' ) );
	}

	/**
	 * An unmaterialized chunked job remains cancellable before its start delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_accepts_a_pre_start_chunked_job_with_an_empty_queue(): void {
		$run_id = $this->start();
		$this->reset_backend_observations();

		$result = $this->client->cancel( self::CHUNKED_JOB_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::CHUNKED_JOB_IDENTITY, $run_id );
	}

	/**
	 * A zero-chunk chunked job preserves its accepted continuation delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_a_zero_chunk_chunked_job_pending_continuation(): void {
		$run_id = $this->start();
		$this->rig->run_due();
		$this->reset_backend_observations();

		$result = $this->client->cancel( self::CHUNKED_JOB_NAME, $run_id );

		$error = $this->assert_failure_code( $result, ErrorCode::RunNotCancellable );
		self::assertSame( 'Run "' . $run_id . '" has no chunks left to process; the pending continuation completes it.', $error->get_error_message() );
		$this->rig->backend()->assert_scheduled( self::CHUNKED_JOB_IDENTITY );
		self::assertSame( array(), $this->backend_calls( 'unschedule_run' ) );
	}

	/**
	 * A retained next chunk makes a between-chunks chunked job cancellable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_accepts_a_chunked_job_between_chunks(): void {
		$this->chunked_job->queue = array( array( 'chunk' => 'next' ) );
		$run_id                   = $this->start();
		$this->rig->run_due();
		$this->reset_backend_observations();

		$result = $this->client->cancel( self::CHUNKED_JOB_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::CHUNKED_JOB_IDENTITY, $run_id );
	}

	/**
	 * A job in retry backoff remains cancellable between deliveries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_accepts_a_job_in_retry_backoff(): void {
		$this->job->throwable = new \RuntimeException( 'Retry this attempt.' );
		$run_id               = $this->enqueue_job();
		$this->rig->run_due();
		$this->rig->assert_retry_scheduled();
		$this->reset_backend_observations();

		$result = $this->client->cancel( self::JOB_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::JOB_IDENTITY, $run_id );
	}

	/**
	 * A sequential second cancellation observes the first winner's deletion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_second_cancel_reports_that_the_run_is_not_retained(): void {
		$run_id = $this->enqueue_job();
		$first  = $this->client->cancel( self::JOB_NAME, $run_id );
		self::assertInstanceOf( Run::class, $first );
		$this->reset_backend_observations();

		$second = $this->client->cancel( self::JOB_NAME, $run_id );

		$this->assert_failure_code( $second, ErrorCode::RunNotRetained );
		self::assertSame( array(), $this->backend_calls( 'unschedule_run' ) );
		$this->rig->assert_cancelled();
	}

	/**
	 * A run-state read failure aborts cancellation with a retryable storage failure.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The authoritative run read fails before a terminal claim; unchanged production bytes and no scheduler write prove fail-closed cancellation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_reports_a_retryable_failure_when_the_run_state_read_fails(): void {
		$run_id = $this->enqueue_job();
		$before = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient run-state read failure';
			}
		);

		$result = $this->client->cancel( self::JOB_NAME, $run_id );

		$this->assert_failure_code( $result, ErrorCode::StorageFailed );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->backend_calls( 'unschedule_run' ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Enqueues the deterministic job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function enqueue_job(): string {
		$result = $this->client->dispatch( self::JOB_NAME, self::ARGS );
		self::assertInstanceOf( Run::class, $result );
		self::assertInstanceOf( RunId::class, $result->id );
		self::assertSame( self::RUN_ID, (string) $result->id );

		return (string) $result->id;
	}

	/**
	 * Starts the deterministic chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function start(): string {
		$result = $this->client->dispatch( self::CHUNKED_JOB_NAME, self::ARGS );
		self::assertInstanceOf( Run::class, $result );
		self::assertInstanceOf( RunId::class, $result->id );
		self::assertSame( self::RUN_ID, (string) $result->id );

		return (string) $result->id;
	}

	/**
	 * Stores one production-serialized job generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunStatus          $status          Run status.
	 * @param   bool               $executing       Execution marker.
	 * @param   int                $failed_attempts Consumed attempts.
	 * @param   int                $action_sequence Delivery sequence.
	 * @param   int                $heartbeat_at    Liveness timestamp.
	 * @param   PendingAction|null $pending         Pending delivery.
	 * @param   string             $kind            Persisted kind key.
	 *
	 * @return  void
	 */
	private function put_job_state( RunStatus $status, bool $executing, int $failed_attempts, int $action_sequence, int $heartbeat_at, ?PendingAction $pending, string $kind = 'job' ): void {
		$state   = new RunState( status: $status, kind: $kind, executing: $executing, start_args: self::ARGS, args_hash: $this->job_fixtures->args_hash( self::ARGS ), kind_state: array(), failed_attempts: $failed_attempts, action_sequence: $action_sequence, created_at: self::NOW, heartbeat_at: $heartbeat_at, pending: $pending );
		$fixture = $this->job_fixtures->run( self::RUN_ID, $state );
		$this->rig->wpdb()->put( $fixture[0], $fixture[1] );
	}

	/**
	 * Asserts the observable result of a winning cancellation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed  $result   Cancellation result.
	 * @param   string $identity Complete work identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  void
	 */
	private function assert_successful_cancel( mixed $result, string $identity, string $run_id ): void {
		self::assertInstanceOf( Run::class, $result );
		self::assertInstanceOf( RunId::class, $result->id );
		self::assertSame( $run_id, (string) $result->id );
		$named_cancelled = $this->rig->hooks()->fired( 'a8csp_bgje/cancelled/' . $identity );
		$public_run_id   = $named_cancelled[0][0] ?? null;
		self::assertInstanceOf( RunId::class, $public_run_id );
		self::assertSame( $run_id, (string) $public_run_id );
		self::assertSame( array( array( $public_run_id, self::ARGS ) ), $named_cancelled );
		self::assertSame( array( array( $identity, $public_run_id, self::ARGS ) ), $this->rig->hooks()->fired( 'a8csp_bgje/cancelled' ) );
		$this->assert_run_clear( $identity, $run_id );
		$this->rig->assert_cancelled();
	}

	/**
	 * Asserts every ready backend received the same run clear.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete work identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  void
	 */
	private function assert_run_clear( string $identity, string $run_id ): void {
		foreach ( $this->rig->backends() as $backend ) {
			$calls = \array_values( \array_filter( $backend->calls, static fn ( array $call ): bool => 'unschedule_run' === $call['verb'] ) );
			self::assertCount( 1, $calls );
			self::assertSame(
				array(
					'hook'     => 'a8csp_bgje/internal/deliver',
					'identity' => $identity,
					'run_id'   => $run_id,
				),
				$calls[0]['args']
			);
		}
	}

	/**
	 * Returns the decoded deterministic job state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>
	 */
	private function decoded_job_state(): array {
		$raw = $this->rig->wpdb()->rows[ $this->run_option_name( self::JOB_IDENTITY, self::RUN_ID ) ] ?? null;
		self::assertIsString( $raw );
		$value = \maybe_unserialize( $raw );
		self::assertIsArray( $value );

		return $value;
	}

	/**
	 * Returns one run option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete work identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  string
	 */
	private function run_option_name( string $identity, string $run_id ): string {
		return 'a8csp_bgje_active_run_' . $identity . '_' . $run_id;
	}

	/**
	 * Returns backend calls for one verb.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $verb Backend verb.
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function backend_calls( string $verb ): array {
		$calls = array();
		foreach ( $this->rig->backends() as $backend ) {
			$calls = array( ...$calls, ...\array_filter( $backend->calls, static fn ( array $call ): bool => $verb === $call['verb'] ) );
		}

		return \array_values( $calls );
	}

	/**
	 * Captures cancellation effects visible at public boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array{backend: array<array-key, mixed>, hooks: array<array-key, mixed>}
	 */
	private function cancellation_effects(): array {
		return array(
			'backend' => \array_map( static fn ( $backend ): array => $backend->calls, $this->rig->backends() ),
			'hooks'   => $this->rig->hooks()->fired( 'a8csp_bgje/cancelled' ),
		);
	}

	/**
	 * Clears backend observations without changing pending deliveries or outcomes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function reset_backend_observations(): void {
		foreach ( $this->rig->backends() as $backend ) {
			$backend->calls = array();
		}
	}

	/**
	 * Asserts one mapped facade failure code.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed     $result Facade result.
	 * @param   ErrorCode $code   Expected public code.
	 *
	 * @return  \WP_Error
	 */
	private function assert_failure_code( mixed $result, ErrorCode $code ): \WP_Error {
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $code->value, $result->get_error_code() );

		return $result;
	}

	// endregion.
}
