<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Run\Runs;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises cancellation fencing and outcomes through the owner-bound run facade.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Dispatcher::class )]
final class DispatcherCancelTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS                  = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const string CHUNKED_JOB_IDENTITY = self::OWNER . ':' . self::CHUNKED_JOB_NAME;
	private const string CHUNKED_JOB_NAME     = 'catalog-sync';
	private const int NOW                     = 1_700_000_000;
	private const string OWNER                = 'runs-tests';
	private const string RUN_ID               = '00000000001700000000-0000000000000000042';
	private const string JOB_IDENTITY         = self::OWNER . ':' . self::JOB_NAME;
	private const string JOB_NAME             = 'email-digest';

	private RecordingChunkedJob $chunked_job;
	private Client $client;
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
		$this->client      = $this->rig->client( self::OWNER );
		$this->job         = new RecordingJob( self::JOB_NAME );
		$this->chunked_job = new RecordingChunkedJob( self::CHUNKED_JOB_NAME );
		$this->client->jobs()->register( $this->job );
		$this->client->chunked_jobs()->register( $this->chunked_job );
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
	 * A pending job cancels through public Results, hooks, and one group clear.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_pending_job_records_the_outcome_hooks_and_group_clear(): void {
		$run_id = $this->enqueue_job();
		$this->reset_backend_observations();

		$result = $this->client->runs()->cancel( self::JOB_NAME, $run_id );

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
	public function test_cancel_finishes_after_a_group_clear_failure(): void {
		$run_id = $this->enqueue_job();
		$this->reset_backend_observations();
		$this->rig->backend()->results['unschedule'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Repair scheduling.' ) );

		$result = $this->client->runs()->cancel( self::JOB_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::JOB_IDENTITY, $run_id );
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
		$GLOBALS['a8csp_bgje_test_action_throwables'] = array( 'a8csp_jobs_engine/cancelled/' . self::JOB_IDENTITY => $throwable );

		$result = $this->client->runs()->cancel( self::JOB_NAME, $run_id );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $run_id, $result->value );
		self::assertSame( 'cancelled', $this->decoded_job_state()['status'] ?? null );
		self::assertSame( array( 'history' ), $this->decoded_job_state()['effects'] ?? null );
		$records = \array_values( \array_filter( $this->rig->logger()->records, static fn ( array $candidate ): bool => ( $candidate['context']['exception'] ?? null ) === $throwable ) );
		self::assertCount( 1, $records );
		$record = $records[0];
		self::assertSame( 'error', $record['level'] ?? null );
		$this->assert_group_clear( self::JOB_IDENTITY . '|' . $run_id );
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
			(void) $this->client->runs()->cancel( self::JOB_NAME, 'malformed_run_id' );
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

		$result = $this->client->runs()->cancel( self::JOB_NAME, self::RUN_ID );

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

		$result = $this->client->runs()->cancel( self::JOB_NAME, self::RUN_ID );

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

		$result = $this->client->runs()->cancel( self::JOB_NAME, $run_id );

		$error = $this->assert_failure_code( $result, ErrorCode::RunNotCancellable );
		self::assertSame( 'completed', $error->context['status'] ?? null );
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

		$result = $this->client->runs()->cancel( self::JOB_NAME, $run_id );

		$this->assert_failure_code( $result, ErrorCode::RunNotCancellable );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->backend_calls( 'unschedule' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/cancelled' ) );
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

		$result = $this->client->runs()->cancel( self::JOB_NAME, $run_id );

		$this->assert_failure_code( $result, ErrorCode::RunNotCancellable );
		self::assertSame( self::NOW + 1, $this->decoded_job_state()['heartbeat_at'] ?? null );
		self::assertSame( array(), $this->backend_calls( 'unschedule' ) );
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

		$result = $this->client->runs()->cancel( self::JOB_NAME, $run_id );

		$this->assert_failure_code( $result, ErrorCode::RunNotCancellable );
		self::assertTrue( $this->decoded_job_state()['executing'] ?? false );
		self::assertSame( array(), $this->job->calls );
		self::assertSame( array(), $this->backend_calls( 'unschedule' ) );
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
				$cancel_result = $this->client->runs()->cancel( self::JOB_NAME, $run_id );
			}
		);

		$this->rig->run_due();

		self::assertInstanceOf( Success::class, $cancel_result );
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

		$result = $this->client->runs()->cancel( 'unknown', self::RUN_ID );

		$this->assert_failure_code( $result, ErrorCode::UnknownWork );
		self::assertSame( $before, $this->cancellation_effects() );
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

		$result = $this->client->runs()->cancel( self::CHUNKED_JOB_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::CHUNKED_JOB_IDENTITY, $run_id );
	}

	/**
	 * A zero-chunk chunked job preserves its accepted cleanup delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_a_zero_chunk_chunked_job_pending_cleanup(): void {
		$run_id = $this->start();
		$this->rig->run_due();
		$this->reset_backend_observations();

		$result = $this->client->runs()->cancel( self::CHUNKED_JOB_NAME, $run_id );

		$this->assert_failure_code( $result, ErrorCode::RunNotCancellable );
		$this->rig->backend()->assert_scheduled( self::CHUNKED_JOB_IDENTITY );
		self::assertSame( array(), $this->backend_calls( 'unschedule' ) );
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

		$result = $this->client->runs()->cancel( self::CHUNKED_JOB_NAME, $run_id );

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

		$result = $this->client->runs()->cancel( self::JOB_NAME, $run_id );

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
		$first  = $this->client->runs()->cancel( self::JOB_NAME, $run_id );
		self::assertInstanceOf( Success::class, $first );
		$this->reset_backend_observations();

		$second = $this->client->runs()->cancel( self::JOB_NAME, $run_id );

		$this->assert_failure_code( $second, ErrorCode::RunNotRetained );
		self::assertSame( array(), $this->backend_calls( 'unschedule' ) );
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

		$result = $this->client->runs()->cancel( self::JOB_NAME, $run_id );

		$this->assert_failure_code( $result, ErrorCode::StorageFailure );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->backend_calls( 'unschedule' ) );
	}

	/**
	 * The public cancel contract declares its Result non-discardable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_declares_no_discard_on_the_public_facade(): void {
		$method = new \ReflectionMethod( Runs::class, 'cancel' );

		self::assertCount( 1, $method->getAttributes( \NoDiscard::class ) );
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
		$result = $this->client->jobs()->enqueue( self::JOB_NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		return $result->value;
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
		$result = $this->client->chunked_jobs()->start( self::CHUNKED_JOB_NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		return $result->value;
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
	 * @param   int                $action_sequence      Delivery sequence.
	 * @param   int                $heartbeat_at    Liveness timestamp.
	 * @param   PendingAction|null $pending         Pending delivery.
	 *
	 * @return  void
	 */
	private function put_job_state( RunStatus $status, bool $executing, int $failed_attempts, int $action_sequence, int $heartbeat_at, ?PendingAction $pending ): void {
		$state   = new RunState( status: $status, kind: 'job', executing: $executing, start_args: self::ARGS, args_hash: $this->job_fixtures->args_hash( self::ARGS ), queue: array( self::ARGS ), failed_attempts: $failed_attempts, action_sequence: $action_sequence, created_at: self::NOW, heartbeat_at: $heartbeat_at, pending: $pending );
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
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $run_id, $result->value );
		$named_cancelled = $this->rig->hooks()->fired( 'a8csp_jobs_engine/cancelled/' . $identity );
		$public_run_id   = $named_cancelled[0][0] ?? null;
		self::assertInstanceOf( RunId::class, $public_run_id );
		self::assertSame( $run_id, (string) $public_run_id );
		self::assertSame( array( array( $public_run_id, self::ARGS ) ), $named_cancelled );
		self::assertSame( array( array( $identity, $public_run_id, self::ARGS ) ), $this->rig->hooks()->fired( 'a8csp_jobs_engine/cancelled' ) );
		$this->assert_group_clear( $identity . '|' . $run_id );
		$this->rig->assert_cancelled();
	}

	/**
	 * Asserts every ready backend received the same group-only clear.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $group Per-run scheduler group.
	 *
	 * @return  void
	 */
	private function assert_group_clear( string $group ): void {
		foreach ( $this->rig->backends() as $backend ) {
			$calls = \array_values( \array_filter( $backend->calls, static fn ( array $call ): bool => 'unschedule' === $call['verb'] ) );
			self::assertCount( 1, $calls );
			self::assertSame( $group, $calls[0]['args']['group'] ?? null );
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
		return 'a8csp_bgje_run_' . $identity . '_' . $run_id;
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
			'hooks'   => $this->rig->hooks()->fired( 'a8csp_jobs_engine/cancelled' ),
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
	 * @param   mixed        $result Facade result.
	 * @param   ErrorCode $code   Expected public code.
	 *
	 * @return  ApiError
	 */
	private function assert_failure_code( mixed $result, ErrorCode $code ): ApiError {
		self::assertInstanceOf( Failure::class, $result );
		$error = $result->error;
		self::assertInstanceOf( ApiError::class, $error );
		self::assertSame( $code, $error->code );

		return $error;
	}

	// endregion.
}
