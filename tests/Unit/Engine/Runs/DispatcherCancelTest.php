<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\Runs;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
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

	private const ARGS           = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const BATCH_IDENTITY = self::OWNER . ':' . self::BATCH_NAME;
	private const BATCH_NAME     = 'catalog-sync';
	private const NOW            = 1_700_000_000;
	private const OWNER          = 'runs-tests';
	private const RUN_ID         = '00000000001700000000-0000000000000000042';
	private const TASK_IDENTITY  = self::OWNER . ':' . self::TASK_NAME;
	private const TASK_NAME      = 'email-digest';

	private RecordingBatch $batch;
	private Consumer $consumer;
	private EngineRig $rig;
	private RecordingTask $task;
	private StoreFixtureBuilder $task_fixtures;

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
	 * Boots registered task and batch work against deterministic boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig      = EngineRig::set_up( self::NOW, 2 );
		$this->consumer = $this->rig->consumer( self::OWNER );
		$this->task     = new RecordingTask( self::TASK_NAME );
		$this->batch    = new RecordingBatch( self::BATCH_NAME );
		$this->consumer->tasks()->register( $this->task );
		$this->consumer->batches()->register( $this->batch );
		$this->task_fixtures = StoreFixtureBuilder::for_identity( self::TASK_IDENTITY );
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
	 * A pending task cancels through public Results, hooks, and one group clear.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_pending_task_records_the_outcome_hooks_and_group_clear(): void {
		$run_id = $this->enqueue_task();
		$this->reset_backend_observations();

		$result = $this->consumer->runs()->cancel( self::TASK_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::TASK_IDENTITY, $run_id );
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
		$run_id = $this->enqueue_task();
		$this->reset_backend_observations();
		$this->rig->backend()->results['unschedule'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Repair scheduling.' ) );

		$result = $this->consumer->runs()->cancel( self::TASK_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::TASK_IDENTITY, $run_id );
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

		$result = $this->consumer->runs()->cancel( self::TASK_NAME, 'missing-run' );

		$this->assert_failure_code( $result, ApiErrorCode::RunNotRetained );
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
		$this->rig->wpdb()->put( $this->run_option_name( self::TASK_IDENTITY, 'corrupt-run' ), 'corrupt' );
		$before = $this->cancellation_effects();

		$result = $this->consumer->runs()->cancel( self::TASK_NAME, 'corrupt-run' );

		$this->assert_failure_code( $result, ApiErrorCode::RunNotRetained );
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
		$run_id = $this->enqueue_task();
		$this->put_task_state( RunStatus::Completed, false, 0, 1, self::NOW, null );
		$before = $this->cancellation_effects();

		$result = $this->consumer->runs()->cancel( self::TASK_NAME, $run_id );

		$error = $this->assert_failure_code( $result, ApiErrorCode::RunNotCancellable );
		self::assertSame( 'completed', $error->context['status'] ?? null );
		self::assertSame( $before, $this->cancellation_effects() );
	}

	/**
	 * An executing marker refuses cancellation before any write.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A fixture-built executing generation is the durable fence; exact pre/post rows prove cancellation cannot mutate or clear it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_an_executing_run_before_any_write(): void {
		$run_id = $this->enqueue_task();
		$this->put_task_state( RunStatus::Running, true, 0, 1, self::NOW + 300, null );
		$before = $this->rig->wpdb()->rows;

		$result = $this->consumer->runs()->cancel( self::TASK_NAME, $run_id );

		$this->assert_failure_code( $result, ApiErrorCode::RunNotCancellable );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->backend_calls( 'unschedule' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/cancelled' ) );
	}

	/**
	 * A non-marker state change produces the neutral lost-CAS refusal.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The heartbeat generation changes at the terminal CAS boundary; fixture-built replacement bytes prove cancellation preserves the winner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_reports_a_neutral_failure_after_losing_its_terminal_cas(): void {
		$run_id = $this->enqueue_task();
		$this->rig->wpdb()->before_next(
			'update',
			function (): void {
				$this->put_task_state( RunStatus::Running, false, 0, 1, self::NOW + 1, PendingAction::async( 'run', 10 ) );
			}
		);

		$result = $this->consumer->runs()->cancel( self::TASK_NAME, $run_id );

		$this->assert_failure_code( $result, ApiErrorCode::RunNotCancellable );
		self::assertSame( self::NOW + 1, $this->decoded_task_state()['heartbeat_at'] ?? null );
		self::assertSame( array(), $this->backend_calls( 'unschedule' ) );
	}

	/**
	 * A delivery marker that wins the shared-row CAS changes cancellation to executing.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built executing bytes replace the inspected generation at the cancellation CAS, modeling the marker winner without bypassing production cancellation logic.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_reports_executing_when_the_delivery_marker_wins_the_race(): void {
		$run_id = $this->enqueue_task();
		$this->rig->wpdb()->before_next(
			'update',
			function (): void {
				$this->put_task_state( RunStatus::Running, true, 0, 1, self::NOW + 300, null );
			}
		);

		$result = $this->consumer->runs()->cancel( self::TASK_NAME, $run_id );

		$this->assert_failure_code( $result, ApiErrorCode::RunNotCancellable );
		self::assertTrue( $this->decoded_task_state()['executing'] ?? false );
		self::assertSame( array(), $this->task->calls );
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
		$run_id        = $this->enqueue_task();
		$cancel_result = null;
		$this->rig->wpdb()->before_next(
			'update',
			function () use ( &$cancel_result, $run_id ): void {
				$cancel_result = $this->consumer->runs()->cancel( self::TASK_NAME, $run_id );
			}
		);

		$this->rig->run_due();

		self::assertInstanceOf( Success::class, $cancel_result );
		$this->assert_successful_cancel( $cancel_result, self::TASK_IDENTITY, $run_id );
		self::assertSame( array(), $this->task->calls );
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

		$result = $this->consumer->runs()->cancel( 'unknown', 'run-1' );

		$this->assert_failure_code( $result, ApiErrorCode::UnknownWork );
		self::assertSame( $before, $this->cancellation_effects() );
	}

	/**
	 * An unmaterialized batch remains cancellable before its start delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_accepts_a_pre_start_batch_with_an_empty_queue(): void {
		$run_id = $this->start_batch();
		$this->reset_backend_observations();

		$result = $this->consumer->runs()->cancel( self::BATCH_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::BATCH_IDENTITY, $run_id );
	}

	/**
	 * A zero-chunk batch preserves its accepted cleanup delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_a_zero_chunk_batch_pending_cleanup(): void {
		$run_id = $this->start_batch();
		$this->rig->run_due();
		$this->reset_backend_observations();

		$result = $this->consumer->runs()->cancel( self::BATCH_NAME, $run_id );

		$this->assert_failure_code( $result, ApiErrorCode::RunNotCancellable );
		$this->rig->backend()->assert_scheduled( self::BATCH_IDENTITY );
		self::assertSame( array(), $this->backend_calls( 'unschedule' ) );
	}

	/**
	 * A retained next chunk makes a between-chunks batch cancellable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_accepts_a_batch_between_chunks(): void {
		$this->batch->queue = array( array( 'chunk' => 'next' ) );
		$run_id             = $this->start_batch();
		$this->rig->run_due();
		$this->reset_backend_observations();

		$result = $this->consumer->runs()->cancel( self::BATCH_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::BATCH_IDENTITY, $run_id );
	}

	/**
	 * A task in retry backoff remains cancellable between deliveries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_accepts_a_task_in_retry_backoff(): void {
		$this->task->throwable = new \RuntimeException( 'Retry this attempt.' );
		$run_id                = $this->enqueue_task();
		$this->rig->run_due();
		$this->rig->assert_retry_scheduled();
		$this->reset_backend_observations();

		$result = $this->consumer->runs()->cancel( self::TASK_NAME, $run_id );

		$this->assert_successful_cancel( $result, self::TASK_IDENTITY, $run_id );
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
		$run_id = $this->enqueue_task();
		$first  = $this->consumer->runs()->cancel( self::TASK_NAME, $run_id );
		self::assertInstanceOf( Success::class, $first );
		$this->reset_backend_observations();

		$second = $this->consumer->runs()->cancel( self::TASK_NAME, $run_id );

		$this->assert_failure_code( $second, ApiErrorCode::RunNotRetained );
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
		$run_id = $this->enqueue_task();
		$before = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient run-state read failure';
			}
		);

		$result = $this->consumer->runs()->cancel( self::TASK_NAME, $run_id );

		$this->assert_failure_code( $result, ApiErrorCode::StorageFailure );
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
	 * Enqueues the deterministic task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function enqueue_task(): string {
		$result = $this->consumer->tasks()->enqueue( self::TASK_NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		return $result->value;
	}

	/**
	 * Starts the deterministic batch.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function start_batch(): string {
		$result = $this->consumer->batches()->start( self::BATCH_NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		return $result->value;
	}

	/**
	 * Stores one production-serialized task generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunStatus          $status          Run status.
	 * @param   bool               $executing       Execution marker.
	 * @param   int                $failed_attempts Consumed attempts.
	 * @param   int                $action_seq      Delivery sequence.
	 * @param   int                $heartbeat_at    Liveness timestamp.
	 * @param   PendingAction|null $pending         Pending delivery.
	 *
	 * @return  void
	 */
	private function put_task_state( RunStatus $status, bool $executing, int $failed_attempts, int $action_seq, int $heartbeat_at, ?PendingAction $pending ): void {
		$state   = new RunState( status: $status, executing: $executing, start_args: self::ARGS, args_hash: $this->task_fixtures->args_hash( self::ARGS ), queue: array( self::ARGS ), failed_attempts: $failed_attempts, action_seq: $action_seq, created_at: self::NOW, heartbeat_at: $heartbeat_at, pending: $pending );
		$fixture = $this->task_fixtures->run( self::RUN_ID, $state );
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
		self::assertSame( array( array( $run_id, self::ARGS ) ), $this->rig->hooks()->fired( 'a8csp_background_tasks/cancelled/' . $identity ) );
		self::assertSame( array( array( $identity, $run_id, self::ARGS ) ), $this->rig->hooks()->fired( 'a8csp_background_tasks/cancelled' ) );
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
	 * Returns the decoded deterministic task state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>
	 */
	private function decoded_task_state(): array {
		$raw = $this->rig->wpdb()->rows[ $this->run_option_name( self::TASK_IDENTITY, self::RUN_ID ) ] ?? null;
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
		return 'a8csp_bgte_run_' . $identity . '_' . $run_id;
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
			'hooks'   => $this->rig->hooks()->fired( 'a8csp_background_tasks/cancelled' ),
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
	 * @param   ApiErrorCode $code   Expected public code.
	 *
	 * @return  ApiError
	 */
	private function assert_failure_code( mixed $result, ApiErrorCode $code ): ApiError {
		self::assertInstanceOf( Failure::class, $result );
		$error = $result->error;
		self::assertInstanceOf( ApiError::class, $error );
		self::assertSame( $code, $error->code );

		return $error;
	}

	// endregion.
}
