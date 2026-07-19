<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\Jobs;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises job admission and failed-run retry through owner-bound facades.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Dispatcher::class )]
final class DispatcherTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS              = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const string IDENTITY         = self::OWNER . ':' . self::NAME;
	private const string NAME             = 'email-digest';
	private const int NOW                 = 1_700_000_000;
	private const string OTHER_RUN_ID     = '00000000001700000001-0000000000000000043';
	private const string OWNER            = 'runs-tests';
	private const string RUN_ID           = '00000000001700000000-0000000000000000042';
	private const string UNKNOWN_NAME     = 'unknown';
	private const string UNKNOWN_IDENTITY = self::OWNER . ':' . self::UNKNOWN_NAME;

	private Client $client;
	private StoreFixtureBuilder $fixtures;
	private EngineRig $rig;
	private RecordingJob $job;

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
	 * Boots one registered job against deterministic interface fakes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig    = EngineRig::set_up( self::NOW );
		$this->client = $this->rig->client( self::OWNER );
		$this->job    = new RecordingJob( self::NAME );
		$this->client->jobs()->register( $this->job );
		$this->fixtures = StoreFixtureBuilder::for_identity( self::IDENTITY );
		$this->reset_observations();
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
	 * A null key dispatches the original arguments with the requested priority.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_with_null_dedup_key_dispatches_the_original_arguments(): void {
		$result = $this->client->jobs()->enqueue( self::NAME, self::ARGS, dedup_key: null, priority: 23 );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$call = $this->single_run_delivery_call();
		self::assertSame( 23, $call['args']['priority'] ?? null );
		$run = \get_option( $this->run_option_name() );
		self::assertIsArray( $run );
		self::assertSame( 'Job', $run['kind'] ?? null );
		$this->rig->backend()->assert_scheduled( self::IDENTITY );
		self::assertSame( array( array( self::RUN_ID, self::ARGS ) ), $this->rig->hooks()->fired( 'a8csp_jobs_engine/started/' . self::IDENTITY ) );
		$this->rig->run_due();
		self::assertSame( array( self::ARGS ), $this->job->calls );
	}

	/**
	 * An opaque key cannot alias the canonical argument identity with the same bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dedup_key_cannot_collide_with_the_argument_identity_domain(): void {
		$argument_identity = $this->client->jobs()->enqueue( self::NAME );
		self::assertInstanceOf( Success::class, $argument_identity );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$dedup_identity = $this->client->jobs()->enqueue( self::NAME, dedup_key: '[]' );

		self::assertInstanceOf( Success::class, $dedup_identity );
		self::assertNotSame( $argument_identity->value, $dedup_identity->value );
		self::assertCount( 2, $this->run_delivery_calls() );
	}

	/**
	 * Different opaque keys admit independent runs even when their arguments match.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_treats_different_dedup_keys_as_distinct_single_flight_identities(): void {
		$first = $this->client->jobs()->enqueue( self::NAME, self::ARGS, dedup_key: 'site-7-full' );
		self::assertInstanceOf( Success::class, $first );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$second = $this->client->jobs()->enqueue( self::NAME, self::ARGS, dedup_key: 'site-8-full' );

		self::assertInstanceOf( Success::class, $second );
		self::assertNotSame( $first->value, $second->value );
		self::assertCount( 2, $this->run_delivery_calls() );
	}

	/**
	 * Cancellation clears only the accepted run's scheduler group.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_clears_the_run_scheduler_group(): void {
		$run_id = $this->enqueue_job();
		$this->reset_observations();

		$result = $this->client->runs()->cancel( self::NAME, $run_id );

		self::assertInstanceOf( Success::class, $result );
		$unschedule = $this->backend_calls( 'unschedule' );
		self::assertCount( 1, $unschedule );
		self::assertSame( self::IDENTITY . '|' . $run_id, $unschedule[0]['args']['group'] ?? null );
	}

	/**
	 * A throwing started listener fails the accepted run through public hooks and Results.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_terminalizes_when_a_started_listener_throws(): void {
		$GLOBALS['a8csp_bgje_test_action_throwables'] = array( 'a8csp_jobs_engine/started/' . self::IDENTITY => new \RuntimeException( 'Started listener exploded.' ) );

		$result = $this->client->jobs()->enqueue( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ApiErrorCode::ExecutionFailed );
		$this->rig->assert_failed( ApiErrorCode::ExecutionFailed );
		self::assertSame(
			array(
				'a8csp_jobs_engine/started/' . self::IDENTITY,
				'a8csp_jobs_engine/started',
				'a8csp_jobs_engine/failed/' . self::IDENTITY,
				'a8csp_jobs_engine/failed',
			),
			$this->rig->hooks()->sequence()
		);
	}

	/**
	 * Real lock outcomes preserve every default, filtered, and delay-floored boundary.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Production-built foreign lock bytes distinguish the exact fresh/stale edge that controls whether admission may replace an incumbent.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int|null $staleness_filter Filtered staleness window.
	 * @param   int|null $continue_filter  Filtered continuation delay.
	 * @param   int      $heartbeat_age    Incumbent heartbeat age.
	 * @param   bool     $is_reclaimed     Whether admission should reclaim.
	 *
	 * @return  void
	 */
	#[DataProvider( 'lock_window_boundaries' )]
	public function test_enqueue_resolves_the_exact_lock_staleness_window( ?int $staleness_filter, ?int $continue_filter, int $heartbeat_age, bool $is_reclaimed ): void {
		if ( null !== $staleness_filter ) {
			$this->set_filter_value( 'a8csp_jobs_engine/lock_staleness/' . self::IDENTITY, $staleness_filter );
		}
		if ( null !== $continue_filter ) {
			$this->set_filter_value( 'a8csp_jobs_engine/continue_delay', $continue_filter );
		}
		$this->seed_running_lock( $heartbeat_age );

		$result = $this->client->jobs()->enqueue( self::NAME, self::ARGS );

		if ( $is_reclaimed ) {
			self::assertInstanceOf( Success::class, $result );
			self::assertSame( self::RUN_ID, $result->value );
			$this->rig->backend()->assert_scheduled( self::IDENTITY );
			return;
		}

		$error = $this->assert_failure_code( $result, ApiErrorCode::OverlapHeld );
		self::assertSame( 'run-running', $error->context['run_id'] ?? null );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * A failed contended-owner read refuses admission without changing any persisted byte.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The second authoritative lock read fails after the held claim, so exact row equality proves the refusal is fail-closed and write-free.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_fails_closed_when_the_contended_owner_read_fails(): void {
		$this->seed_running_lock( 0 );
		$before = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->before_next( 'select', static function (): void {} );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient owner read failure';
			}
		);

		$result = $this->client->jobs()->enqueue( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ApiErrorCode::StorageFailure );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * The identity-specific lock-staleness filter receives its documented payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_passes_the_documented_lock_staleness_filter_arguments(): void {
		$filter_args = null;
		$this->set_filter_value(
			'a8csp_jobs_engine/lock_staleness/' . self::IDENTITY,
			static function ( int $default_staleness ) use ( &$filter_args ): int {
				$filter_args = array(
					'arity' => \func_num_args(),
					'args'  => \func_get_args(),
				);

				return $default_staleness;
			}
		);

		$result = $this->client->jobs()->enqueue( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				'arity' => 1,
				'args'  => array( 15 * \MINUTE_IN_SECONDS ),
			),
			$filter_args
		);
	}

	/**
	 * Positive delay selects single scheduling at the clock-relative timestamp.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_with_delay_routes_to_single_scheduling(): void {
		$result = $this->client->jobs()->enqueue( self::NAME, self::ARGS, delay: 120, priority: 31 );

		self::assertInstanceOf( Success::class, $result );
		$calls = $this->backend_calls( 'schedule_single' );
		self::assertCount( 1, $calls );
		self::assertSame( 'a8csp_jobs_engine/run_job', $calls[0]['args']['hook'] ?? null );
		self::assertSame( self::NOW + 120, $calls[0]['args']['timestamp'] ?? null );
		self::assertSame( 31, $calls[0]['args']['priority'] ?? null );
		$this->rig->run_due();
		self::assertSame( self::NOW + 120, $this->rig->clock()->timestamp );
		self::assertSame( array( self::ARGS ), $this->job->calls );
	}

	/**
	 * A failed delayed-heartbeat write releases the provisional lock and run.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A storage write error occurs after provisional state exists; a second public enqueue proves compensation released both fences.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_with_delay_releases_its_lock_when_heartbeat_write_fails(): void {
		$this->rig->wpdb()->script_result( 'update', false );

		$failed = $this->client->jobs()->enqueue( self::NAME, self::ARGS, delay: 120 );
		$this->assert_failure_code( $failed, ApiErrorCode::StorageFailure );
		self::assertSame( array(), $this->run_delivery_calls() );

		$retried = $this->client->jobs()->enqueue( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $retried );
	}

	/**
	 * An indeterminate delayed heartbeat aborts scheduling and removes provisional state.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale An authoritative read fails after lock claim and run creation; successful re-admission proves the fail-closed cleanup left no fence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_with_delay_aborts_when_heartbeat_read_is_indeterminate(): void {
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient heartbeat read failure';
			}
		);

		$failed = $this->client->jobs()->enqueue( self::NAME, self::ARGS, delay: 120 );
		$this->assert_failure_code( $failed, ApiErrorCode::StorageFailure );
		self::assertSame( array(), $this->run_delivery_calls() );

		$retried = $this->client->jobs()->enqueue( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $retried );
	}

	/**
	 * A failed delayed-state transition releases an explicit key for immediate reuse.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The second run-state write fails after the lock heartbeat; reusing the opaque key proves both provisional generations were compensated.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_with_delay_releases_dedup_key_when_state_transition_fails(): void {
		$dedup_key = 'delayed-site-digest';
		$this->rig->wpdb()->before_next( 'update', static function (): void {} );
		$this->rig->wpdb()->before_next( 'update', static fn ( WpdbLockSpy $wpdb ) => $wpdb->script_result( 'update', false ) );

		$failed = $this->client->jobs()->enqueue( self::NAME, self::ARGS, delay: 120, dedup_key: $dedup_key );
		$this->assert_failure_code( $failed, ApiErrorCode::StorageFailure );
		self::assertSame( array(), $this->run_delivery_calls() );

		$this->rig->clock()->timestamp = self::NOW + 1;
		$reused                        = $this->client->jobs()->enqueue( self::NAME, self::ARGS, delay: 120, dedup_key: $dedup_key );
		self::assertInstanceOf( Success::class, $reused );
	}

	/**
	 * One opaque key supplies the single-flight identity across differing arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_uses_the_dedup_key_as_the_single_flight_identity(): void {
		$dedup_key = "logical-account\0\xFF";
		$first     = $this->client->jobs()->enqueue( self::NAME, self::ARGS, dedup_key: $dedup_key );
		self::assertInstanceOf( Success::class, $first );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$duplicate = $this->client->jobs()->enqueue( self::NAME, array( 'site_id' => 8 ), dedup_key: $dedup_key );

		$error = $this->assert_failure_code( $duplicate, ApiErrorCode::OverlapHeld );
		self::assertSame( $first->value, $error->context['run_id'] ?? null );
		self::assertCount( 1, $this->run_delivery_calls() );
	}

	/**
	 * An unknown job fails before scheduling or lifecycle hooks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_an_unknown_job_without_boundary_effects(): void {
		$before = $this->public_effects_snapshot();

		$result = $this->client->jobs()->enqueue( self::UNKNOWN_NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ApiErrorCode::UnknownWork );
		self::assertSame( self::UNKNOWN_IDENTITY, $error->context['name'] ?? null );
		self::assertSame( $before, $this->public_effects_snapshot() );
	}

	/**
	 * Public priority validation rejects values before an engine boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $priority Invalid priority.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_priorities' )]
	public function test_enqueue_rejects_priority_outside_the_public_range( int $priority ): void {
		$before = $this->public_effects_snapshot();

		try {
			(void) $this->client->jobs()->enqueue( self::NAME, self::ARGS, priority: $priority );
			self::fail( 'Invalid priority must throw before dispatch.' );
		} catch ( \InvalidArgumentException ) {
			self::assertSame( $before, $this->public_effects_snapshot() );
		}
	}

	/**
	 * A scheduling failure is mapped and active admission is compensated.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_maps_backend_failure_and_allows_readmission(): void {
		$this->rig->backend()->results['enqueue_async'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore scheduling.' ) );

		$failed = $this->client->jobs()->enqueue( self::NAME, self::ARGS );
		$this->assert_failure_code( $failed, ApiErrorCode::BackendRejected );
		unset( $this->rig->backend()->results['enqueue_async'] );

		$retried = $this->client->jobs()->enqueue( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $retried );
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
	public function test_enqueue_warns_when_scheduling_rollback_cannot_be_confirmed( string $failure ): void {
		$this->rig->backend()->results['enqueue_async'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore scheduling.' ) );
		$this->script_scheduling_rollback_failure( $failure );

		$result = $this->client->jobs()->enqueue( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ApiErrorCode::BackendRejected );
		$record = $this->scheduling_rollback_warning();
		self::assertSame( 'warning', $record['level'] ?? null );
		self::assertSame( self::IDENTITY, $record['context']['name'] ?? null );
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
	 * Non-portable input reaches no clock, randomizer, storage, hook, or scheduler boundary.
	 *
	 * @load-bearing security
	 * @pin-rationale The opaque object is rejected by the public payload validator; exact boundary equality proves it cannot be serialized, logged, or passed to a backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_non_portable_input_before_every_boundary(): void {
		$before = $this->security_boundary_snapshot();

		try {
			(void) $this->client->jobs()->enqueue( self::NAME, array( 'private-payload' => new \stdClass() ), dedup_key: 'non-portable-payload' );
			self::fail( 'Non-portable payload must throw before dispatch.' );
		} catch ( \InvalidArgumentException ) {
			self::assertSame( $before, $this->security_boundary_snapshot() );
		}
	}

	/**
	 * Delay overflow returns a typed public payload rejection without scheduling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_a_delay_that_overflows_unix_seconds(): void {
		$this->rig->clock()->timestamp = \PHP_INT_MAX - 5;

		$result = $this->client->jobs()->enqueue( self::NAME, self::ARGS, delay: 10 );

		$this->assert_failure_code( $result, ApiErrorCode::PayloadRejected );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * The public enqueue contract declares its Result non-discardable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_declares_no_discard_on_the_public_facade(): void {
		$method = new \ReflectionMethod( Jobs::class, 'enqueue' );

		self::assertCount( 1, $method->getAttributes( \NoDiscard::class ) );
	}

	/**
	 * Failed-run retry rejects a malformed run identifier at the engine boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_rejects_a_malformed_run_identifier(): void {
		try {
			(void) $this->client->runs()->retry_failed( self::NAME, 'malformed_run_id' );
			self::fail( 'A malformed retry identifier must be rejected before storage lookup.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( 'Run identifier is malformed; pass a run ID the engine returned.', $exception->getMessage() );
		}
	}

	/**
	 * Manual retry schedules the failed job's original arguments and consumes the entry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_reenqueues_original_arguments_and_consumes_the_entry(): void {
		$this->seed_failed_run( self::RUN_ID, self::ARGS, 2 );
		$this->rig->clock()->timestamp = self::NOW + 100;

		$result = $this->client->runs()->retry_failed( self::NAME, self::RUN_ID );

		self::assertInstanceOf( Success::class, $result );
		$this->rig->run_due();
		self::assertSame( array( self::ARGS ), $this->job->calls );
		$consumed = $this->client->runs()->retry_failed( self::NAME, self::RUN_ID );
		$this->assert_failure_code( $consumed, ApiErrorCode::RunNotRetained );
	}

	/**
	 * A failed retained-entry removal leaves a successful retry and keeps the entry retryable.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The failed-run store's exact removal write fails after the fresh run is accepted; cancelling that run and retrying again proves the source entry was not consumed.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_keeps_the_entry_when_consumption_cas_fails(): void {
		$this->seed_failed_run( self::RUN_ID, self::ARGS, 2 );
		$this->rig->clock()->timestamp = self::NOW + 100;
		$this->rig->wpdb()->script_result( 'update', false );

		$first = $this->client->runs()->retry_failed( self::NAME, self::RUN_ID );
		self::assertInstanceOf( Success::class, $first );
		self::assertIsString( $first->value );
		$cancelled = $this->client->runs()->cancel( self::NAME, $first->value );
		self::assertInstanceOf( Success::class, $cancelled );
		$this->rig->clock()->timestamp = self::NOW + 101;

		$second = $this->client->runs()->retry_failed( self::NAME, self::RUN_ID );
		self::assertInstanceOf( Success::class, $second );
	}

	/**
	 * A deliberately duplicated retained identifier retries the first stored payload.
	 * Production failed-run recording deduplicates run IDs, so this corrupt row cannot be builder-produced.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_uses_the_first_payload_for_a_corrupt_duplicate_identifier(): void {
		$raw = \maybe_serialize(
			array(
				$this->failed_entry( self::RUN_ID, array( 'ordinal' => 'first' ), 2, self::NOW - 2 ),
				$this->failed_entry( self::RUN_ID, array( 'ordinal' => 'second' ), 2, self::NOW - 1 ),
			)
		);
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( 'a8csp_bgje_failed_runs_' . self::IDENTITY, $raw );
		$this->rig->clock()->timestamp = self::NOW + 100;

		$result = $this->client->runs()->retry_failed( self::NAME, self::RUN_ID );

		self::assertInstanceOf( Success::class, $result );
		$this->rig->run_due();
		self::assertSame( array( array( 'ordinal' => 'first' ) ), $this->job->calls );
	}

	/**
	 * An unreadable failed-run store rejects retry before fresh admission.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The authoritative failed-store read fails before dispatch; unchanged fixture bytes and an empty backend ledger prove fail-closed behavior.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_rejects_an_authoritative_store_read_failure(): void {
		$this->seed_failed_run( self::RUN_ID, self::ARGS, 2 );
		$before = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted retry store read failure';
			}
		);

		$result = $this->client->runs()->retry_failed( self::NAME, self::RUN_ID );

		$this->assert_failure_code( $result, ApiErrorCode::StorageFailure );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * A missing identifier is refused while an actually retained run remains retryable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_rejects_a_missing_entry_without_consuming_existing_work(): void {
		$this->seed_failed_run( self::RUN_ID, self::ARGS, 2 );

		$missing = $this->client->runs()->retry_failed( self::NAME, self::OTHER_RUN_ID );
		$error   = $this->assert_failure_code( $missing, ApiErrorCode::RunNotRetained );
		self::assertSame( self::OTHER_RUN_ID, $error->context['run_id'] ?? null );

		$retained = $this->client->runs()->retry_failed( self::NAME, self::RUN_ID );
		self::assertInstanceOf( Success::class, $retained );
	}

	/**
	 * A delegated scheduling failure leaves the failed job entry retryable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_retains_the_entry_when_enqueue_fails(): void {
		$this->seed_failed_run( self::RUN_ID, self::ARGS, 2 );
		$this->rig->backend()->results['enqueue_async'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore scheduling.' ) );

		$failed = $this->client->runs()->retry_failed( self::NAME, self::RUN_ID );
		$this->assert_failure_code( $failed, ApiErrorCode::BackendRejected );
		unset( $this->rig->backend()->results['enqueue_async'] );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$retried = $this->client->runs()->retry_failed( self::NAME, self::RUN_ID );
		self::assertInstanceOf( Success::class, $retried );
	}

	/**
	 * Supplies fresh and stale edges for every staleness-resolution path.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{staleness_filter: int|null, continue_filter: int|null, heartbeat_age: int, is_reclaimed: bool}>
	 */
	public static function lock_window_boundaries(): array {
		return array(
			'default boundary remains fresh'      => array(
				'staleness_filter' => null,
				'continue_filter'  => null,
				'heartbeat_age'    => 900,
				'is_reclaimed'     => false,
			),
			'default boundary plus one is stale'  => array(
				'staleness_filter' => null,
				'continue_filter'  => null,
				'heartbeat_age'    => 901,
				'is_reclaimed'     => true,
			),
			'filtered boundary remains fresh'     => array(
				'staleness_filter' => 300,
				'continue_filter'  => null,
				'heartbeat_age'    => 300,
				'is_reclaimed'     => false,
			),
			'filtered boundary plus one is stale' => array(
				'staleness_filter' => 300,
				'continue_filter'  => null,
				'heartbeat_age'    => 301,
				'is_reclaimed'     => true,
			),
			'floor boundary remains fresh'        => array(
				'staleness_filter' => 1,
				'continue_filter'  => 75,
				'heartbeat_age'    => 150,
				'is_reclaimed'     => false,
			),
			'floor boundary plus one is stale'    => array(
				'staleness_filter' => 1,
				'continue_filter'  => 75,
				'heartbeat_age'    => 151,
				'is_reclaimed'     => true,
			),
			'zero-delay filtered fresh edge'      => array(
				'staleness_filter' => 1,
				'continue_filter'  => 0,
				'heartbeat_age'    => 1,
				'is_reclaimed'     => false,
			),
			'zero-delay filtered stale edge'      => array(
				'staleness_filter' => 1,
				'continue_filter'  => 0,
				'heartbeat_age'    => 2,
				'is_reclaimed'     => true,
			),
			'zero-delay default fresh edge'       => array(
				'staleness_filter' => null,
				'continue_filter'  => 0,
				'heartbeat_age'    => 900,
				'is_reclaimed'     => false,
			),
			'zero-delay default stale edge'       => array(
				'staleness_filter' => null,
				'continue_filter'  => 0,
				'heartbeat_age'    => 901,
				'is_reclaimed'     => true,
			),
		);
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

	// endregion.

	// region HELPERS.

	/**
	 * Enqueues the deterministic job and returns its run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function enqueue_job(): string {
		$result = $this->client->jobs()->enqueue( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );

		return $result->value;
	}

	/**
	 * Stores a production-built foreign lock and latest pointer.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $heartbeat_age Existing heartbeat age.
	 *
	 * @return  void
	 */
	private function seed_running_lock( int $heartbeat_age ): void {
		$heartbeat = self::NOW - $heartbeat_age;
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), 'run-running', $heartbeat, $heartbeat ) );
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
		$this->reset_observations();
	}

	/**
	 * Stores one production-built retained failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Failed run identifier.
	 * @param   array<array-key, mixed> $start_args Original arguments.
	 * @param   int                     $attempts   Attempts consumed.
	 *
	 * @return  void
	 */
	private function seed_failed_run( string $run_id, array $start_args, int $attempts ): void {
		$failure = new RunFailure( identity: self::IDENTITY, run_id: $run_id, attempts: $attempts, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'Database unavailable.', failed_chunk: null );
		$this->put_fixture( $this->fixtures->failed( self::NOW - 1, $start_args, $failure ) );
		$this->reset_observations();
	}

	/**
	 * Returns one deliberately duplicated failed-store entry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Failed run identifier.
	 * @param   array<array-key, mixed> $start_args Original arguments.
	 * @param   int                     $attempts   Attempts consumed.
	 * @param   int                     $failed_at  Failure timestamp.
	 *
	 * @return array{run_id: string, failed_at: int, start_args: array<array-key, mixed>, attempts: int, error: array{class: null, message: string, stage: string, code: string}}
	 */
	private function failed_entry( string $run_id, array $start_args, int $attempts, int $failed_at ): array {
		return array(
			'run_id'     => $run_id,
			'failed_at'  => $failed_at,
			'start_args' => $start_args,
			'attempts'   => $attempts,
			'error'      => array(
				'class'   => null,
				'message' => 'Database unavailable.',
				'stage'   => RunFailureStage::Execution->value,
				'code'    => ApiErrorCode::ExecutionFailed->value,
			),
		);
	}

	/**
	 * Stores one production-built raw fixture.
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
	 * Returns the active run option for the deterministic admission.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function run_option_name(): string {
		return 'a8csp_bgje_run_' . self::IDENTITY . '_' . self::RUN_ID;
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
	 * Returns the only accepted job-run call.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{verb: string, args: array<string, mixed>}
	 */
	private function single_run_delivery_call(): array {
		$calls = $this->run_delivery_calls();
		self::assertCount( 1, $calls );

		return $calls[0];
	}

	/**
	 * Returns accepted job-run backend calls.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function run_delivery_calls(): array {
		return \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => \in_array( $call['verb'], array( 'enqueue_async', 'schedule_single' ), true ) && 'a8csp_jobs_engine/run_job' === ( $call['args']['hook'] ?? null ) ) );
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
		return \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => $verb === $call['verb'] ) );
	}

	/**
	 * Captures the public effects visible to ordinary admission refusals.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array{backend: array<array-key, mixed>, hooks: array<array-key, mixed>}
	 */
	private function public_effects_snapshot(): array {
		return array(
			'backend' => $this->rig->backend()->calls,
			'hooks'   => $this->rig->hooks()->sequence(),
		);
	}

	/**
	 * Captures every boundary that must reject a non-portable payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array{backend: array<array-key, mixed>, rows: array<array-key, mixed>, queries: array<array-key, mixed>, hooks: array<array-key, mixed>, options: array<array-key, mixed>}
	 */
	private function security_boundary_snapshot(): array {
		$options = $GLOBALS['a8csp_bgje_test_option_calls'] ?? null;
		self::assertIsArray( $options );

		return array(
			'backend' => $this->rig->backend()->calls,
			'rows'    => $this->rig->wpdb()->rows,
			'queries' => $this->rig->wpdb()->recorded_queries,
			'hooks'   => $this->rig->hooks()->sequence(),
			'options' => $options,
		);
	}

	/**
	 * Clears observations without changing retained state or backend outcomes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function reset_observations(): void {
		$this->rig->backend()->calls             = array();
		$this->rig->wpdb()->recorded_queries     = array();
		$GLOBALS['a8csp_bgje_test_option_calls'] = array();
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

	/**
	 * Scripts one legitimate WordPress filter seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook_name Filter hook name.
	 * @param   mixed  $value     Filter value or callback.
	 *
	 * @return  void
	 */
	private function set_filter_value( string $hook_name, mixed $value ): void {
		$filters = $GLOBALS['a8csp_bgje_test_filter_values'] ?? null;
		self::assertIsArray( $filters );
		$filters[ $hook_name ]                    = $value;
		$GLOBALS['a8csp_bgje_test_filter_values'] = $filters;
	}

	// endregion.
}
