<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\ScopeOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises chunked job admission and manual retry through scope-bound facades.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Dispatcher::class )]
final class DispatcherChunkedJobTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS              = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const string FAILED_RUN_ID    = '00000000001699999999-0000000000000000041';
	private const string IDENTITY         = self::SCOPE . ':' . self::NAME;
	private const string INCUMBENT_RUN_ID = '00000000001699999998-0000000000000000040';
	private const string NAME             = 'catalog-sync';
	private const int NOW                 = 1_700_000_000;
	private const string SCOPE            = 'runs-tests';
	private const string RUN_ID           = '00000000001700000000-0000000000000000042';

	private RecordingChunkedJob $chunked_job;
	private ScopeOperations $client;
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
		$this->client                = $this->rig->operations( self::SCOPE );
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
		self::assertInstanceOf( Run::class, $result->value );
		self::assertSame( self::RUN_ID, (string) $result->value->id );
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
	 * A chunked job with a future fire time retains a timed start action at the requested timestamp.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_with_future_fire_time_persists_a_timed_start_action(): void {
		$this->register_chunked_job();

		$result = $this->client->dispatch( self::NAME, self::ARGS, fire_at: self::NOW + 120, priority: 23 );

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
	public function test_immediate_dispatch_of_a_chunked_job_persists_an_async_start_action(): void {
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
		$this->put_fixture( $this->fixtures->failed( self::NOW - 1, self::ARGS, $failure, kind: 'chunked_job' ) );
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
		$this->put_fixture( $this->fixtures->failed( self::NOW - 1, self::ARGS, $failure, kind: 'chunked_job' ) );
		$incumbent = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $incumbent );
		self::assertInstanceOf( Run::class, $incumbent->value );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$refused = $this->client->retry_failed( self::NAME, self::FAILED_RUN_ID );

		$error = $this->assert_failure_code( $refused, ErrorCode::OverlapHeld );
		self::assertSame( (string) $incumbent->value->id, $error->context['run_id'] ?? null );
		$cancelled = $this->client->cancel( self::NAME, (string) $incumbent->value->id );
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
		self::assertInstanceOf( Run::class, $incumbent->value );
		$failure = new RunFailure( identity: self::IDENTITY, run_id: RunId::from( self::FAILED_RUN_ID ), attempts: 2, stage: RunFailureStage::execution(), code: ErrorCode::ExecutionFailed, summary: 'Chunk processing exploded.', details: array( 'failed_chunk' => array( 'chunk' => 1 ) ) );
		$this->put_fixture( $this->fixtures->failed( self::NOW - 1, self::ARGS, $failure, kind: 'chunked_job' ) );
		$this->rig->clock()->timestamp = self::NOW + 100;

		$retried = $this->client->retry_failed( self::NAME, self::FAILED_RUN_ID );

		self::assertInstanceOf( Success::class, $retried );
		self::assertInstanceOf( Run::class, $retried->value );
		self::assertNotSame( (string) $incumbent->value->id, (string) $retried->value->id );
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
		self::assertInstanceOf( Run::class, $first->value );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$second = $this->client->dispatch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $second );
		self::assertInstanceOf( Run::class, $second->value );
		self::assertNotSame( (string) $first->value->id, (string) $second->value->id );
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
		self::assertInstanceOf( Run::class, $first->value );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$duplicate = $this->client->dispatch(
			self::NAME,
			array(
				'site_id' => 8,
				'mode'    => 'incremental',
			)
		);

		$error = $this->assert_failure_code( $duplicate, ErrorCode::OverlapHeld );
		self::assertSame( (string) $first->value->id, $error->context['run_id'] ?? null );
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
		self::assertFalse( $record['context']['lock_release_confirmed'] ?? null );
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
		self::assertSame( \sprintf( 'chunked_job "%1$s" is already running as run "%2$s"; wait for that run to finish before dispatching the same arguments or overlap key.', self::IDENTITY, self::INCUMBENT_RUN_ID ), $error->message );
		self::assertSame( self::INCUMBENT_RUN_ID, $error->context['run_id'] ?? null );
		self::assertSame( self::INCUMBENT_RUN_ID, $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->start_calls() );
	}

	/**
	 * Reject fails closed when the authoritative lock selection is indeterminate.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The claim read fails after the losing insert; unchanged fixture bytes prove admission performs no replacement write.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_rejects_when_lock_selection_is_indeterminate(): void {
		$this->register_chunked_job();
		$this->seed_running_lock();
		$before = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient chunked job lock selection failure';
			}
		);

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::StorageFailed );
		self::assertSame( \sprintf( 'Run "%1$s" for chunked_job "%2$s" could not read a valid authoritative overlap lock row; repair overlap-lock storage and retry.', self::RUN_ID, self::IDENTITY ), $error->message );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->start_calls() );
	}

	/**
	 * A lost insert followed by an absent authoritative row is indeterminate.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A failed insert with no retained row models the claim/read race where the contending owner disappears before attribution.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_maps_an_absent_post_contention_row_to_storage_failure(): void {
		$this->register_chunked_job();
		$this->rig->wpdb()->script_result( 'insert', false );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::StorageFailed );
		self::assertSame( \sprintf( 'Run "%1$s" for chunked_job "%2$s" could not read a valid authoritative overlap lock row; repair overlap-lock storage and retry.', self::RUN_ID, self::IDENTITY ), $error->message );
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
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), self::INCUMBENT_RUN_ID, self::NOW, self::NOW ) );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::OverlapHeld );
		self::assertSame( self::INCUMBENT_RUN_ID, $error->context['run_id'] ?? null );
		self::assertSame( self::INCUMBENT_RUN_ID, $this->lock()['run_id'] ?? null );
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
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), self::INCUMBENT_RUN_ID, self::NOW, self::NOW ) );
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
		self::assertSame( self::INCUMBENT_RUN_ID, $error->context['run_id'] ?? null );
		self::assertSame( self::INCUMBENT_RUN_ID, $this->lock()['run_id'] ?? null );
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
		self::assertInstanceOf( Run::class, $result->value );
		self::assertSame( self::RUN_ID, (string) $result->value->id );
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
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), self::INCUMBENT_RUN_ID, self::NOW, self::NOW ) );
		$this->put_running_state();

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
		self::assertSame( self::INCUMBENT_RUN_ID, $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->start_calls() );
	}

	/**
	 * A replacement write failure removes provisional state and reports failed storage.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A failed overlap CAS is observably different from a lost CAS, so callers must repair storage instead of retrying against an alleged rival.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_chunked_job_reports_storage_failure_when_replacement_lock_write_fails(): void {
		$this->register_chunked_job( new JobOptions( overlap: OverlapPolicy::Replace ) );
		$this->seed_running_lock();
		$this->rig->wpdb()->before_next( 'update', static function (): void {} );
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->script_result( 'update', false );
			}
		);

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::StorageFailed );
		self::assertSame( \sprintf( 'Run "%1$s" for chunked_job "%2$s" could not transfer overlap lock ownership because storage failed; repair option writes before retrying.', self::RUN_ID, self::IDENTITY ), $error->message );
		self::assertSame(
			array(
				'identity' => self::IDENTITY,
				'run_id'   => self::RUN_ID,
				'kind'     => 'chunked_job',
			),
			$error->context
		);
		self::assertFalse( \get_option( $this->run_option_name() ) );
		self::assertSame( self::INCUMBENT_RUN_ID, $this->lock()['run_id'] ?? null );
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
		// No admission attempt may transfer the lane, or a later one would admit and hide the
		// provisional-state removal this test exists to observe.
		$this->rig->wpdb()->fail_updates_targeting( OverlapGuard::OPTION_PREFIX );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::AdmissionConflict );
		self::assertSame( \sprintf( 'chunked_job "%s" lock ownership changed while the replacement was claiming it; retry the dispatch against the current owner.', self::IDENTITY ), $error->message );
		self::assertFalse( \get_option( $this->run_option_name() ) );
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
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), self::INCUMBENT_RUN_ID, self::NOW, self::NOW ) );
		$this->put_running_state();
		$this->put_fixture(
			$this->fixtures->latest(
				array(
					array(
						'run_id'    => self::INCUMBENT_RUN_ID,
						'args_hash' => $this->args_hash(),
					),
				)
			)
		);
		$this->rig->backend()->calls = array();
	}

	/** Stores the Running row named by the incumbent lock. */
	private function put_running_state(): void {
		$this->put_fixture(
			$this->fixtures->run(
				self::INCUMBENT_RUN_ID,
				new RunState(
					status: RunStatus::Running,
					kind: 'chunked_job',
					executing: false,
					start_args: self::ARGS,
					args_hash: $this->args_hash(),
					kind_state: array(),
					failed_attempts: 0,
					action_sequence: 1,
					created_at: self::NOW,
					heartbeat_at: self::NOW,
				)
			)
		);
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
			// The admitted-state confirmation reads once before scheduling, so the scripted failure targets the read after it.
			$this->rig->wpdb()->before_next( 'select', static function (): void {} );
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

		$this->rig->wpdb()->script_result( 'delete', false );
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
	 * Asserts one mapped facade failure code.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed     $result Facade result.
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
