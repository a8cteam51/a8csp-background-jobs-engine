<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\ExistingRunPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises batch admission and manual retry through owner-bound facades.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Dispatcher::class )]
final class DispatcherBatchTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const ARGS     = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const IDENTITY = self::OWNER . ':' . self::NAME;
	private const NAME     = 'catalog-sync';
	private const NOW      = 1_700_000_000;
	private const OWNER    = 'runs-tests';
	private const RUN_ID   = '00000000001700000000-0000000000000000042';

	private RecordingBatch $batch;
	private Consumer $consumer;
	private StoreFixtureBuilder $fixtures;
	private EngineRig $rig;

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
	 * Boots one registered batch against deterministic interface fakes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig      = EngineRig::set_up( self::NOW );
		$this->consumer = $this->rig->consumer( self::OWNER );
		$this->batch    = new RecordingBatch( self::NAME );
		$this->consumer->batches()->register( $this->batch );
		$this->fixtures              = StoreFixtureBuilder::for_identity( self::IDENTITY );
		$this->rig->backend()->calls = array();
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
	 * Starting a batch retains its identity, priority, arguments, and real start delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_batch_creates_a_run_and_schedules_the_internal_start_action(): void {
		$result = $this->consumer->batches()->start( self::NAME, self::ARGS, ExistingRunPolicy::Reject, 23 );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame( 23, $this->single_start_call()['args']['priority'] ?? null );
		$this->rig->backend()->assert_scheduled( self::IDENTITY );

		$this->rig->run_due();

		self::assertSame( array( self::ARGS ), $this->batch->generate_calls );
		self::assertSame( array( array( self::RUN_ID, self::ARGS ) ), $this->rig->hooks()->fired( 'a8csp_background_tasks/started/' . self::IDENTITY ) );
	}

	/**
	 * Public priority validation rejects both values immediately outside the engine range.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $priority Invalid priority.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_priorities' )]
	public function test_start_batch_rejects_priority_outside_the_engine_range( int $priority ): void {
		$before = $this->boundary_snapshot();

		try {
			(void) $this->consumer->batches()->start( self::NAME, self::ARGS, priority: $priority );
			self::fail( 'Invalid priority must throw before batch admission.' );
		} catch ( \InvalidArgumentException ) {
			self::assertSame( $before, $this->boundary_snapshot() );
		}
	}

	/**
	 * Supplies values immediately outside both inclusive priority boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{priority: int}>
	 */
	public static function invalid_priorities(): array {
		return array(
			'below minimum' => array( 'priority' => -1 ),
			'above maximum' => array( 'priority' => 256 ),
		);
	}

	/**
	 * Manual retry starts the retained batch arguments once and consumes the source entry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_restarts_a_batch_and_removes_the_failed_entry(): void {
		$failure = new RunFailure( identity: self::IDENTITY, run_id: 'failed-run', attempts: 2, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'Chunk processing exploded.', failed_chunk: array( 'chunk' => 1 ) );
		$this->put_fixture( $this->fixtures->failed( self::NOW - 1, self::ARGS, $failure ) );
		$this->rig->clock()->timestamp = self::NOW + 100;

		$result = $this->consumer->runs()->retry_failed( self::NAME, 'failed-run' );

		self::assertInstanceOf( Success::class, $result );
		$this->rig->run_due();
		self::assertSame( array( self::ARGS ), $this->batch->generate_calls );
		$consumed = $this->consumer->runs()->retry_failed( self::NAME, 'failed-run' );
		$this->assert_failure_code( $consumed, ApiErrorCode::RunNotRetained );
	}

	/**
	 * An initial scheduling failure is mapped and compensated before readmission.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The failed initial write must retain no delivery, while a second real admission proves its provisional ownership fences were compensated.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_batch_surfaces_scheduling_failure_and_removes_active_state(): void {
		$this->rig->backend()->results['enqueue_async'] = $this->scheduling_failure_result();

		$failed = $this->consumer->batches()->start( self::NAME, self::ARGS );
		$this->assert_failure_code( $failed, ApiErrorCode::BackendRejected );
		$this->rig->assert_no_delivery( self::IDENTITY );
		unset( $this->rig->backend()->results['enqueue_async'] );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$readmitted = $this->consumer->batches()->start( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $readmitted );
		self::assertSame( array(), $this->batch->generate_calls );
		self::assertSame( array(), $this->batch->failed_calls );
	}

	/**
	 * Reject leaves a fixture-built foreign owner in place without admitting work.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The foreign lock is the production overlap fence; the public refusal and unchanged owner prove Reject cannot displace it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_batch_rejects_a_held_overlap_without_stopping_the_previous_run(): void {
		$this->seed_running_lock();

		$result = $this->consumer->batches()->start( self::NAME, self::ARGS, ExistingRunPolicy::Reject );

		$error = $this->assert_failure_code( $result, ApiErrorCode::OverlapHeld );
		self::assertSame( 'run-running', $error->context['run_id'] ?? null );
		self::assertSame( 'run-running', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->start_calls() );
	}

	/**
	 * Reject fails closed when the authoritative foreign-owner read fails.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The second lock read fails after contention is established; unchanged fixture bytes prove admission performs no replacement write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_batch_rejects_when_the_lock_owner_read_fails(): void {
		$this->seed_running_lock();
		$before = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->before_next( 'select', static function (): void {} );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient batch owner read failure';
			}
		);

		$result = $this->consumer->batches()->start( self::NAME, self::ARGS, ExistingRunPolicy::Reject );

		$this->assert_failure_code( $result, ApiErrorCode::StorageFailure );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->start_calls() );
	}

	/**
	 * A contended claim with no readable owner refuses admission without scheduling.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A failed insert with no retained row models the claim/read race where the contending owner disappears before attribution.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_batch_rejects_when_the_held_lock_no_longer_names_an_owner(): void {
		$this->rig->wpdb()->script_result( 'insert', false );

		$result = $this->consumer->batches()->start( self::NAME, self::ARGS, ExistingRunPolicy::Reject );

		$this->assert_failure_code( $result, ApiErrorCode::OverlapHeld );
		self::assertSame( array(), $this->start_calls() );
	}

	/**
	 * Reject attributes a held overlap to the lock owner when no latest pointer survives.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The fixture-built lock is authoritative when the bounded latest pointer is absent, so the refusal must still identify its owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_batch_names_the_lock_owner_when_a_rejected_held_overlap_has_no_latest_pointer(): void {
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), 'run-running', self::NOW, self::NOW ) );

		$result = $this->consumer->batches()->start( self::NAME, self::ARGS, ExistingRunPolicy::Reject );

		$error = $this->assert_failure_code( $result, ApiErrorCode::OverlapHeld );
		self::assertSame( 'run-running', $error->context['run_id'] ?? null );
		self::assertSame( 'run-running', $this->lock()['run_id'] ?? null );
	}

	/**
	 * Reject attributes a held overlap to the lock owner when the latest pointer lags.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Divergent production-built lock and pointer fixtures prove the lock owner, not stale history, controls the refusal payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_batch_names_the_lock_owner_when_a_rejected_held_overlap_has_a_stale_latest_pointer(): void {
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), 'run-running', self::NOW, self::NOW ) );
		$this->put_fixture(
			$this->fixtures->latest(
				array(
					array(
						'run_id'    => 'run-stale',
						'args_hash' => $this->args_hash(),
					),
				)
			)
		);

		$result = $this->consumer->batches()->start( self::NAME, self::ARGS, ExistingRunPolicy::Reject );

		$error = $this->assert_failure_code( $result, ApiErrorCode::OverlapHeld );
		self::assertSame( 'run-running', $error->context['run_id'] ?? null );
		self::assertSame( 'run-running', $this->lock()['run_id'] ?? null );
	}

	/**
	 * Replace transfers the fixture-built foreign fence to one real successor delivery.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The public start must atomically replace a live foreign owner before its accepted delivery may enter batch code.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_batch_replaces_a_held_incumbent(): void {
		$this->seed_running_lock();

		$result = $this->consumer->batches()->start( self::NAME, self::ARGS, ExistingRunPolicy::Replace );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame( self::RUN_ID, $this->lock()['run_id'] ?? null );
		$this->rig->run_due();
		self::assertSame( array( self::ARGS ), $this->batch->generate_calls );
	}

	/**
	 * Replace trusts the foreign lock when its bounded latest pointer is absent.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A fixture-built lock without a latest pointer proves replacement ownership does not depend on evictable pointer history.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_batch_replaces_a_held_incumbent_after_its_latest_pointer_is_evicted(): void {
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), 'run-running', self::NOW, self::NOW ) );

		$result = $this->consumer->batches()->start( self::NAME, self::ARGS, ExistingRunPolicy::Replace );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $this->lock()['run_id'] ?? null );
		self::assertCount( 1, $this->start_calls() );
	}

	/**
	 * A failed replacement delivery releases its successor without resurrecting the foreign owner.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Once replacement ownership transfers, restoring the incumbent after scheduling failure would revive a generation already superseded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_batch_does_not_restore_the_incumbent_after_replacement_scheduling_fails(): void {
		$this->seed_running_lock();
		$this->rig->backend()->results['enqueue_async'] = $this->scheduling_failure_result();

		$result = $this->consumer->batches()->start( self::NAME, self::ARGS, ExistingRunPolicy::Replace );

		$this->assert_failure_code( $result, ApiErrorCode::BackendRejected );
		self::assertNull( $this->lock() );
		$this->rig->assert_no_delivery( self::IDENTITY );
	}

	/**
	 * A corrupt successor-row collision leaves the fixture-built foreign fence untouched.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Replacement state must persist before the foreign lock CAS, otherwise a storage collision could strand ownership on a run with no state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_batch_persists_replacement_state_before_taking_the_incumbent_lock(): void {
		$this->seed_running_lock();
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		$options[ $this->run_option_name() ] = array( 'collision' => true );
		$GLOBALS['a8csp_bgte_test_options']  = $options;

		$result = $this->consumer->batches()->start( self::NAME, self::ARGS, ExistingRunPolicy::Replace );

		$this->assert_failure_code( $result, ApiErrorCode::StorageFailure );
		self::assertSame( 'run-running', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->start_calls() );
	}

	/**
	 * A lost replacement CAS removes provisional state and preserves the concurrent owner.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A production-built concurrent lock generation interleaves at the replacement CAS, proving compensation cannot delete the winner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_batch_removes_provisional_state_when_replacement_ownership_changes(): void {
		$this->seed_running_lock();
		$concurrent = $this->fixtures->lock( $this->args_hash(), 'run-concurrent-owner', self::NOW, self::NOW );
		$this->rig->wpdb()->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ) use ( $concurrent ): void {
				$wpdb->put( $concurrent[0], $concurrent[1] );
			}
		);

		$result = $this->consumer->batches()->start( self::NAME, self::ARGS, ExistingRunPolicy::Replace );

		$this->assert_failure_code( $result, ApiErrorCode::OverlapHeld );
		self::assertSame( 'run-concurrent-owner', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->start_calls() );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a deterministic scheduling failure for one lifecycle action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function scheduling_failure_result(): Failure {
		return new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore the scheduler before retrying this batch.' ) );
	}

	/**
	 * Stores a fresh foreign lock and matching latest pointer through production encoders.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function seed_running_lock(): void {
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), 'run-running', self::NOW, self::NOW ) );
		$this->put_fixture(
			$this->fixtures->latest(
				array(
					array(
						'run_id'    => 'run-running',
						'args_hash' => $this->args_hash(),
					),
				)
			)
		);
		$this->rig->backend()->calls = array();
	}

	/**
	 * Stores one production-built raw fixture in the active database.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{string, string} $fixture Option name and raw value.
	 *
	 * @return  void
	 */
	private function put_fixture( array $fixture ): void {
		$this->rig->wpdb()->put( $fixture[0], $fixture[1] );
	}

	/**
	 * Returns the canonical argument identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function args_hash(): string {
		return $this->fixtures->args_hash( self::ARGS );
	}

	/**
	 * Returns the current decoded overlap lock.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>|null
	 */
	private function lock(): ?array {
		$raw   = $this->rig->wpdb()->rows[ 'a8csp_bgte_lock_' . self::IDENTITY . '_' . $this->args_hash() ] ?? null;
		$value = \is_string( $raw ) ? \maybe_unserialize( $raw ) : null;

		return \is_array( $value ) ? $value : null;
	}

	/**
	 * Returns the deterministic successor option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function run_option_name(): string {
		return 'a8csp_bgte_run_' . self::IDENTITY . '_' . self::RUN_ID;
	}

	/**
	 * Returns accepted batch-start backend calls.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function start_calls(): array {
		return \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => 'enqueue_async' === $call['verb'] && 'a8csp_background_tasks/start' === ( $call['args']['hook'] ?? null ) ) );
	}

	/**
	 * Returns the only accepted batch-start backend call.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{verb: string, args: array<string, mixed>}
	 */
	private function single_start_call(): array {
		$calls = $this->start_calls();
		self::assertCount( 1, $calls );

		return $calls[0];
	}

	/**
	 * Captures every boundary public priority validation must leave untouched.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array{backend: array<array-key, mixed>, rows: array<array-key, mixed>, queries: array<array-key, mixed>, hooks: array<array-key, mixed>}
	 */
	private function boundary_snapshot(): array {
		return array(
			'backend' => $this->rig->backend()->calls,
			'rows'    => $this->rig->wpdb()->rows,
			'queries' => $this->rig->wpdb()->recorded_queries,
			'hooks'   => $this->rig->hooks()->sequence(),
		);
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
