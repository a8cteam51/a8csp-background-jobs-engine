<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\OwnerOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises chunked job admission and manual retry through owner-bound facades.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Dispatcher::class )]
final class DispatcherChunkedJobTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS           = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const string FAILED_RUN_ID = '00000000001699999999-0000000000000000041';
	private const string IDENTITY      = self::OWNER . ':' . self::NAME;
	private const string NAME          = 'catalog-sync';
	private const int NOW              = 1_700_000_000;
	private const string OWNER         = 'runs-tests';
	private const string RUN_ID        = '00000000001700000000-0000000000000000042';

	private RecordingChunkedJob $chunked_job;
	private OwnerOperations $client;
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
	 * Boots one chunked job execution fixture against deterministic interface fakes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig                   = EngineRig::set_up( self::NOW );
		$this->client                = $this->rig->operations( self::OWNER );
		$this->chunked_job           = new RecordingChunkedJob( self::NAME );
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
	 * Starting a chunked job retains its identity, priority, arguments, and real start delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_creates_a_run_and_schedules_the_internal_start_action(): void {
		$this->register_chunked_job();
		$result = $this->client->dispatch( self::NAME, self::ARGS, priority: 23 );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame( 23, $this->single_start_call()['args']['priority'] ?? null );
		$run = \get_option( $this->run_option_name() );
		self::assertIsArray( $run );
		self::assertSame( 'chunked_job', $run['kind'] ?? null );
		$this->rig->backend()->assert_scheduled( self::IDENTITY );

		$this->rig->run_due();

		self::assertSame( array( self::ARGS ), $this->chunked_job->generate_calls );
		$started = $this->rig->hooks()->fired( 'a8csp_bgje/started/' . self::IDENTITY );
		$run_id  = $started[0][0] ?? null;
		self::assertInstanceOf( RunId::class, $run_id );
		self::assertSame( self::RUN_ID, (string) $run_id );
		self::assertSame( array( array( $run_id, self::ARGS ) ), $started );
	}

	/**
	 * A delayed chunked job retains a timed start action at the requested fire time.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_with_delay_persists_a_timed_start_action(): void {
		$this->register_chunked_job();

		$result = $this->client->dispatch( self::NAME, self::ARGS, delay: 120, priority: 23 );

		self::assertInstanceOf( Success::class, $result );
		$calls = \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => 'schedule_single' === $call['verb'] ) );
		self::assertCount( 1, $calls );
		$call = $calls[0];
		self::assertSame( 'schedule_single', $call['verb'] );
		self::assertSame( self::NOW + 120, $call['args']['timestamp'] ?? null );
		self::assertSame( 23, $call['args']['priority'] ?? null );
		$run = \get_option( $this->run_option_name() );
		self::assertIsArray( $run );
		self::assertSame(
			array(
				'stage'    => 'start',
				'mode'     => 'single',
				'fire_at'  => self::NOW + 120,
				'priority' => 23,
			),
			$run['pending'] ?? null
		);
		$this->rig->run_due();
		self::assertSame( array( self::ARGS ), $this->chunked_job->generate_calls );
	}

	/**
	 * An immediate chunked job retains an asynchronous start action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_without_delay_persists_an_async_start_action(): void {
		$this->register_chunked_job();

		$result = $this->client->dispatch( self::NAME, self::ARGS, priority: 23 );

		self::assertInstanceOf( Success::class, $result );
		$run = \get_option( $this->run_option_name() );
		self::assertIsArray( $run );
		self::assertSame(
			array(
				'stage'    => 'start',
				'mode'     => 'async',
				'fire_at'  => null,
				'priority' => 23,
			),
			$run['pending'] ?? null
		);
	}

	/**
	 * Negative delay is rejected before chunked-job admission reaches a boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_rejects_a_negative_delay_for_a_chunked_job_before_every_boundary(): void {
		$this->register_chunked_job();
		$before = $this->boundary_snapshot();

		try {
			(void) $this->client->dispatch( self::NAME, self::ARGS, delay: -1 );
			self::fail( 'Negative delay must throw before chunked-job admission.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( 'Background-work "catalog-sync" delay -1 is invalid; pass a non-negative number of seconds.', $exception->getMessage() );
			self::assertSame( $before, $this->boundary_snapshot() );
		}
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
	public function test_dispatch_chunked_job_rejects_priority_outside_the_engine_range( int $priority ): void {
		$this->register_chunked_job();
		$before = $this->boundary_snapshot();

		try {
			(void) $this->client->dispatch( self::NAME, self::ARGS, priority: $priority );
			self::fail( 'Invalid priority must throw before chunked job admission.' );
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
	 * Manual retry starts the retained chunked job arguments once and consumes the source entry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_restarts_a_chunked_job_and_removes_the_failed_entry(): void {
		$this->register_chunked_job();
		$failure = new RunFailure( identity: self::IDENTITY, run_id: RunId::from( self::FAILED_RUN_ID ), attempts: 2, stage: RunFailureStage::execution(), code: ErrorCode::ExecutionFailed, summary: 'Chunk processing exploded.', details: array( 'failed_chunk' => array( 'chunk' => 1 ) ) );
		$this->put_fixture( $this->fixtures->failed( self::NOW - 1, self::ARGS, $failure ) );
		$this->rig->clock()->timestamp = self::NOW + 100;

		$result = $this->client->retry_failed( self::NAME, self::FAILED_RUN_ID );

		self::assertInstanceOf( Success::class, $result );
		$this->rig->run_due();
		self::assertSame( array( self::ARGS ), $this->chunked_job->generate_calls );
		$consumed = $this->client->retry_failed( self::NAME, self::FAILED_RUN_ID );
		$this->assert_failure_code( $consumed, ErrorCode::RunNotRetained );
	}

	/**
	 * Manual retry refuses to replace a live matching chunked job even when its definition declares Replace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_refuses_to_replace_a_live_chunked_job(): void {
		$this->register_chunked_job( new JobOptions( overlap: OverlapPolicy::Replace ) );
		$failure = new RunFailure( identity: self::IDENTITY, run_id: RunId::from( self::FAILED_RUN_ID ), attempts: 2, stage: RunFailureStage::execution(), code: ErrorCode::ExecutionFailed, summary: 'Chunk processing exploded.', details: array( 'failed_chunk' => array( 'chunk' => 1 ) ) );
		$this->put_fixture( $this->fixtures->failed( self::NOW - 1, self::ARGS, $failure ) );
		$incumbent = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $incumbent );
		self::assertIsString( $incumbent->value );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$refused = $this->client->retry_failed( self::NAME, self::FAILED_RUN_ID );

		$error = $this->assert_failure_code( $refused, ErrorCode::OverlapHeld );
		self::assertSame( $incumbent->value, $error->context['run_id'] ?? null );
		$cancelled = $this->client->cancel( self::NAME, $incumbent->value );
		self::assertInstanceOf( Success::class, $cancelled );
		$this->rig->clock()->timestamp = self::NOW + 2;

		$retried = $this->client->retry_failed( self::NAME, self::FAILED_RUN_ID );
		self::assertInstanceOf( Success::class, $retried );
	}

	/**
	 * Manual retry admits an Allow chunked job under a fresh per-run overlap lane.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_admits_an_allow_chunked_job(): void {
		$this->register_chunked_job( new JobOptions( overlap: OverlapPolicy::Allow ) );

		$incumbent = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $incumbent );
		self::assertIsString( $incumbent->value );
		$failure = new RunFailure( identity: self::IDENTITY, run_id: RunId::from( self::FAILED_RUN_ID ), attempts: 2, stage: RunFailureStage::execution(), code: ErrorCode::ExecutionFailed, summary: 'Chunk processing exploded.', details: array( 'failed_chunk' => array( 'chunk' => 1 ) ) );
		$this->put_fixture( $this->fixtures->failed( self::NOW - 1, self::ARGS, $failure ) );
		$this->rig->clock()->timestamp = self::NOW + 100;

		$retried = $this->client->retry_failed( self::NAME, self::FAILED_RUN_ID );

		self::assertInstanceOf( Success::class, $retried );
		self::assertIsString( $retried->value );
		self::assertNotSame( $incumbent->value, $retried->value );
	}

	/**
	 * A JobOptions Allow declaration admits concurrent matching starts without a caller policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_honors_its_declared_allow_policy(): void {
		$this->register_chunked_job( new JobOptions( overlap: OverlapPolicy::Allow ) );
		$first = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $first );
		self::assertIsString( $first->value );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$second = $this->client->dispatch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $second );
		self::assertIsString( $second->value );
		self::assertNotSame( $first->value, $second->value );
		self::assertCount( 2, $this->start_calls() );
	}

	/**
	 * A definition overlap key groups differing start arguments under one Reject lane.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_uses_its_argument_aware_overlap_key(): void {
		$this->register_chunked_job( new JobOptions( overlap_key: static fn ( array $start_args ): string => 'catalog' ) );
		$first = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $first );
		self::assertIsString( $first->value );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$duplicate = $this->client->dispatch(
			self::NAME,
			array(
				'site_id' => 8,
				'mode'    => 'incremental',
			)
		);

		$error = $this->assert_failure_code( $duplicate, ErrorCode::OverlapHeld );
		self::assertSame( $first->value, $error->context['run_id'] ?? null );
		self::assertCount( 1, $this->start_calls() );
	}

	/**
	 * A definition overlap key outside the byte boundary produces a contained payload rejection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $overlap_key Invalid overlap key.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_overlap_keys' )]
	public function test_dispatch_chunked_job_rejects_an_invalid_overlap_key( string $overlap_key ): void {
		$this->register_chunked_job( new JobOptions( overlap_key: static fn ( array $start_args ): string => $overlap_key ) );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ErrorCode::PayloadRejected );
		self::assertSame( array(), $this->start_calls() );
	}

	/**
	 * Supplies overlap keys outside the closed byte-length boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{overlap_key: string}>
	 */
	public static function invalid_overlap_keys(): array {
		return array(
			'empty'    => array( 'overlap_key' => '' ),
			'65 bytes' => array( 'overlap_key' => \str_repeat( 'a', 65 ) ),
		);
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
	public function test_dispatch_chunked_job_surfaces_scheduling_failure_and_removes_active_state(): void {
		$this->register_chunked_job();
		$this->rig->backend()->results['enqueue_async'] = $this->scheduling_failure_result();

		$failed = $this->client->dispatch( self::NAME, self::ARGS );
		$this->assert_failure_code( $failed, ErrorCode::BackendRejected );
		$this->rig->assert_no_delivery( self::IDENTITY );
		unset( $this->rig->backend()->results['enqueue_async'] );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$readmitted = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $readmitted );
		self::assertSame( array(), $this->chunked_job->generate_calls );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_bgje/failed' ) );
	}

	/**
	 * An incomplete scheduling rollback warns that maintenance may redeliver its retained row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $failure Incomplete rollback boundary.
	 *
	 * @return  void
	 */
	#[DataProvider( 'incomplete_scheduling_rollback_failures' )]
	public function test_dispatch_chunked_job_warns_when_scheduling_rollback_cannot_be_confirmed( string $failure ): void {
		$this->register_chunked_job();
		$this->rig->backend()->results['enqueue_async'] = $this->scheduling_failure_result();
		$this->script_scheduling_rollback_failure( $failure );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ErrorCode::BackendRejected );
		$record = $this->scheduling_rollback_warning();
		self::assertSame( 'warning', $record['level'] ?? null );
		self::assertSame( self::IDENTITY, $record['context']['identity'] ?? null );
		self::assertSame( self::RUN_ID, $record['context']['run_id'] ?? null );
		self::assertSame( 'lock_release' !== $failure, $record['context']['lock_release_confirmed'] ?? null );
		self::assertSame( 'run_delete' !== $failure, $record['context']['run_deleted'] ?? null );
	}

	/**
	 * Returns incomplete scheduling-rollback boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{string}>
	 */
	public static function incomplete_scheduling_rollback_failures(): array {
		return array(
			'lock release' => array( 'lock_release' ),
			'run delete'   => array( 'run_delete' ),
		);
	}

	/**
	 * Reject leaves a fixture-built foreign owner in place without admitting work.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The foreign lock is the production overlap fence; the public refusal and unchanged owner prove Reject cannot displace it.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_rejects_a_held_overlap_without_stopping_the_previous_run(): void {
		$this->register_chunked_job();
		$this->seed_running_lock();

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::OverlapHeld );
		self::assertSame( \sprintf( 'chunked_job "%s" is already running as run "run-running"; wait for that run to finish before dispatching the same arguments or overlap key.', self::IDENTITY ), $error->message );
		self::assertSame( 'run-running', $error->context['run_id'] ?? null );
		self::assertSame( 'run-running', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->start_calls() );
	}

	/**
	 * Reject fails closed when the authoritative foreign-owner read fails.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The second lock read fails after contention is established; unchanged fixture bytes prove admission performs no replacement write.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_rejects_when_the_lock_owner_read_fails(): void {
		$this->register_chunked_job();
		$this->seed_running_lock();
		$before = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->before_next( 'select', static function (): void {} );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient chunked job owner read failure';
			}
		);

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::StorageFailed );
		self::assertSame( \sprintf( 'chunked_job "%s" could not confirm the owner of a contended overlap lock; repair database reads and retry the dispatch.', self::IDENTITY ), $error->message );
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
	public function test_dispatch_chunked_job_rejects_when_the_held_lock_no_longer_names_an_owner(): void {
		$this->register_chunked_job();
		$this->rig->wpdb()->script_result( 'insert', false );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::OverlapHeld );
		self::assertSame( \sprintf( 'chunked_job "%s" could not confirm the owner of a contended overlap lock; retry the dispatch against the current lock state.', self::IDENTITY ), $error->message );
		self::assertSame( array(), $this->start_calls() );
	}

	/**
	 * Reject attributes a held overlap to the lock owner when no latest pointer survives.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The fixture-built lock is authoritative when the bounded latest pointer is absent, so the refusal must still identify its owner.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_names_the_lock_owner_when_a_rejected_held_overlap_has_no_latest_pointer(): void {
		$this->register_chunked_job();
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), 'run-running', self::NOW, self::NOW ) );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::OverlapHeld );
		self::assertSame( 'run-running', $error->context['run_id'] ?? null );
		self::assertSame( 'run-running', $this->lock()['run_id'] ?? null );
	}

	/**
	 * Reject attributes a held overlap to the lock owner when the latest pointer lags.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Divergent production-built lock and pointer fixtures prove the lock owner, not stale history, controls the refusal payload.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_names_the_lock_owner_when_a_rejected_held_overlap_has_a_stale_latest_pointer(): void {
		$this->register_chunked_job();
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

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::OverlapHeld );
		self::assertSame( 'run-running', $error->context['run_id'] ?? null );
		self::assertSame( 'run-running', $this->lock()['run_id'] ?? null );
	}

	/**
	 * Replace transfers the fixture-built foreign fence to one real successor delivery.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The public dispatch must atomically replace a live foreign owner before its accepted delivery may enter chunked job code.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_replaces_a_held_incumbent(): void {
		$this->register_chunked_job( new JobOptions( overlap: OverlapPolicy::Replace ) );
		$this->seed_running_lock();

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		self::assertSame( self::RUN_ID, $this->lock()['run_id'] ?? null );
		$this->rig->run_due();
		self::assertSame( array( self::ARGS ), $this->chunked_job->generate_calls );
	}

	/**
	 * Replace trusts the foreign lock when its bounded latest pointer is absent.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A fixture-built lock without a latest pointer proves replacement ownership does not depend on evictable pointer history.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_replaces_a_held_incumbent_after_its_latest_pointer_is_evicted(): void {
		$this->register_chunked_job( new JobOptions( overlap: OverlapPolicy::Replace ) );
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), 'run-running', self::NOW, self::NOW ) );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $this->lock()['run_id'] ?? null );
		self::assertCount( 1, $this->start_calls() );
	}

	/**
	 * A failed replacement delivery releases its successor without resurrecting the foreign owner.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Once replacement ownership transfers, restoring the incumbent after scheduling failure would revive a generation already superseded.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_does_not_restore_the_incumbent_after_replacement_scheduling_fails(): void {
		$this->register_chunked_job( new JobOptions( overlap: OverlapPolicy::Replace ) );
		$this->seed_running_lock();
		$this->rig->backend()->results['enqueue_async'] = $this->scheduling_failure_result();

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ErrorCode::BackendRejected );
		self::assertNull( $this->lock() );
		$this->rig->assert_no_delivery( self::IDENTITY );
	}

	/**
	 * A corrupt successor-row collision leaves the fixture-built foreign fence untouched.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Replacement state must persist before the foreign lock CAS, otherwise a storage collision could strand ownership on a run with no state.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_persists_replacement_state_before_taking_the_incumbent_lock(): void {
		$this->register_chunked_job( new JobOptions( overlap: OverlapPolicy::Replace ) );
		$this->seed_running_lock();
		$options = $GLOBALS['a8csp_bgje_test_options'] ?? null;
		self::assertIsArray( $options );
		$options[ $this->run_option_name() ] = array( 'collision' => true );
		$GLOBALS['a8csp_bgje_test_options']  = $options;

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ErrorCode::StorageFailed );
		self::assertSame( 'run-running', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->start_calls() );
	}

	/**
	 * A lost replacement CAS removes provisional state and preserves the concurrent owner.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A production-built concurrent lock generation interleaves at the replacement CAS, proving compensation cannot delete the winner.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_removes_provisional_state_when_replacement_ownership_changes(): void {
		$this->register_chunked_job( new JobOptions( overlap: OverlapPolicy::Replace ) );
		$this->seed_running_lock();
		$concurrent = $this->fixtures->lock( $this->args_hash(), 'run-concurrent-owner', self::NOW, self::NOW );
		$this->rig->wpdb()->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ) use ( $concurrent ): void {
				$wpdb->put( $concurrent[0], $concurrent[1] );
			}
		);

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::OverlapHeld );
		self::assertSame( \sprintf( 'chunked_job "%s" lock ownership changed while the replacement was claiming it; retry the dispatch against the current owner.', self::IDENTITY ), $error->message );
		self::assertSame( 'run-concurrent-owner', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->start_calls() );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Registers the chunked execution fixture with its declared policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobOptions|null $options Optional policy declaration.
	 *
	 * @return  void
	 */
	private function register_chunked_job( ?JobOptions $options = null ): void {
		$this->client->register( $this->chunked_job->definition( $options ) );
	}

	/**
	 * Returns a deterministic scheduling failure for one lifecycle action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function scheduling_failure_result(): Failure {
		return new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore the scheduler before retrying this chunked job.' ) );
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
		$raw   = $this->rig->wpdb()->rows[ OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash() ] ?? null;
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
		return 'a8csp_bgje_active_run_' . self::IDENTITY . '_' . self::RUN_ID;
	}

	/**
	 * Scripts one incomplete scheduling-rollback boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $failure Incomplete rollback boundary.
	 *
	 * @return  void
	 */
	private function script_scheduling_rollback_failure( string $failure ): void {
		if ( 'lock_release' === $failure ) {
			$this->rig->wpdb()->before_next( 'select', static function (): void {} );
			$this->rig->wpdb()->before_next(
				'select',
				static function ( WpdbLockSpy $wpdb ): void {
					$wpdb->last_error = 'scripted rollback lock read failure';
				}
			);

			return;
		}

		if ( 'run_delete' !== $failure ) {
			throw new \InvalidArgumentException( 'Unknown scheduling rollback failure.' );
		}

		$GLOBALS['a8csp_bgje_test_delete_option_results'] = array( $this->run_option_name() => false );
	}

	/**
	 * Returns the scheduling-rollback diagnostic by its outcome context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{level: mixed, message: string, context: array<array-key, mixed>}
	 */
	private function scheduling_rollback_warning(): array {
		foreach ( $this->rig->logger()->records as $record ) {
			if ( \array_key_exists( 'lock_release_confirmed', $record['context'] ) && \array_key_exists( 'run_deleted', $record['context'] ) ) {
				return $record;
			}
		}

		throw new \LogicException( 'Expected a scheduling rollback warning.' );
	}

	/**
	 * Returns accepted chunked-job-start backend calls.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function start_calls(): array {
		return \array_values(
			\array_filter(
				$this->rig->backend()->calls,
				static function ( array $call ): bool {
					$args = $call['args']['args'] ?? null;

					return \is_array( $args )
						&& 'enqueue_async' === $call['verb']
						&& 'a8csp_bgje/internal/deliver' === ( $call['args']['hook'] ?? null )
						&& self::IDENTITY === ( $args[0] ?? null );
				}
			)
		);
	}

	/**
	 * Returns the only accepted chunked-job-start backend call.
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
	 * @param   ErrorCode $code   Expected public code.
	 *
	 * @return  BoundaryError
	 */
	private function assert_failure_code( mixed $result, ErrorCode $code ): BoundaryError {
		self::assertInstanceOf( Failure::class, $result );
		$error = $result->error;
		self::assertInstanceOf( BoundaryError::class, $error );
		self::assertSame( $code, $error->code );

		return $error;
	}

	// endregion.
}
