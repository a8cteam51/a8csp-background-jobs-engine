<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\OwnerOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\ErrorInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins schedule-only overlap dispatch through the public schedule front door.
 *
 */
#[CoversClass( Dispatcher::class )]
final class DispatcherScheduleDispatchTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS              = array( 'site_id' => 7 );
	private const string CHUNKED_IDENTITY = self::OWNER . ':' . self::CHUNKED_NAME;
	private const string CHUNKED_NAME     = 'email-digest-chunked';
	private const string CHUNKED_SCHEDULE = 'email-digest-chunked-schedule';
	private const string IDENTITY         = self::OWNER . ':' . self::NAME;
	private const string NAME             = 'email-digest';
	private const int NOW                 = 1_700_000_000;
	private const string OWNER            = 'runs-tests';
	private const string RUN_ID           = '00000000001700000000-0000000000000000042';
	private const string SCHEDULE         = 'email-digest-schedule';

	private OwnerOperations $client;
	private RecordingChunkedJob $chunked_job;
	private EngineRig $rig;
	private StoreFixtureBuilder $fixtures;
	private RecordingJob $job;

	// endregion.

	// region LIFECYCLE.

	/** Loads guarded WordPress seams before the production graph is built. */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
	}

	/** Boots one registered job against deterministic interface fakes. */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig         = EngineRig::set_up( self::NOW );
		$this->client      = $this->rig->operations( self::OWNER );
		$this->job         = new RecordingJob( self::NAME );
		$this->chunked_job = new RecordingChunkedJob( self::CHUNKED_NAME );
		$this->fixtures    = StoreFixtureBuilder::for_identity( self::IDENTITY );
	}

	/** Releases request-local engine state after each scenario. */
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
	 * Every policy dispatches idempotently against an open lock.
	 *
	 * @param   string $policy_value Job overlap-policy value.
	 *
	 * @return  void
	 */
	#[DataProvider( 'open_lock_policies' )]
	public function test_policy_dispatch_enqueues_against_an_open_lock( string $policy_value ): void {
		$this->sync_schedule( OverlapPolicy::from( $policy_value ), 23 );

		$result = $this->client->dispatch_now( self::SCHEDULE );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				'identity' => self::IDENTITY,
				'run_id'   => self::RUN_ID,
			),
			$result->value
		);
		$calls = $this->run_delivery_calls();
		self::assertCount( 1, $calls );
		self::assertSame( 23, $calls[0]['args']['priority'] ?? null );
		self::assertArrayNotHasKey( 'unique', $calls[0]['args'] );
		$this->rig->backend()->assert_scheduled( self::IDENTITY );
		$this->rig->backend()->assert_no_duplicate();
	}

	/**
	 * A scheduled chunked target routes to the internal start action instead of unknown work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_now_routes_a_chunked_schedule_target_to_the_internal_start_action(): void {
		$this->sync_chunked_schedule( OverlapPolicy::Allow, 23 );

		$result = $this->client->dispatch_now( self::CHUNKED_SCHEDULE );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				'identity' => self::CHUNKED_IDENTITY,
				'run_id'   => self::RUN_ID,
			),
			$result->value
		);
		$calls = $this->chunked_start_calls();
		self::assertCount( 1, $calls );
		self::assertSame( 23, $calls[0]['args']['priority'] ?? null );
		$this->rig->backend()->assert_scheduled( self::CHUNKED_IDENTITY );
	}

	/**
	 * Supplies every Job overlap policy.
	 *
	 * @return array<string, array{policy_value: string}>
	 */
	public static function open_lock_policies(): array {
		return array(
			'allow'   => array( 'policy_value' => 'allow' ),
			'reject'  => array( 'policy_value' => 'reject' ),
			'replace' => array( 'policy_value' => 'replace' ),
		);
	}

	/**
	 * The history row and started hook are absent at the first dispatch database update.
	 *
	 * @return  void
	 */
	public function test_accepted_callback_runs_before_history_and_started_hooks(): void {
		$this->sync_schedule( OverlapPolicy::Allow );
		$observed = false;
		$this->rig->wpdb()->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ) use ( &$observed ): void {
				$observed = true;
				self::assertArrayNotHasKey( RunHistory::OPTION_PREFIX . self::IDENTITY, $wpdb->rows );
				self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/started' ) );
			}
		);

		$result = $this->client->dispatch_now( self::SCHEDULE );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $observed );
		self::assertSame(
			array(
				'a8csp_jobs_engine/started/' . self::IDENTITY,
				'a8csp_jobs_engine/started',
			),
			$this->rig->hooks()->sequence()
		);
	}

	/**
	 * Chunked schedule acceptance persists occurrence state before started run history.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The first schedule-registration update is entered by the acceptance callback, so the absent history row at that boundary pins lease release and cadence persistence before chunked started history.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_chunked_accepted_callback_runs_before_started_history(): void {
		$this->sync_chunked_schedule( OverlapPolicy::Allow );
		$observed = false;
		$this->rig->wpdb()->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ) use ( &$observed ): void {
				$observed = true;
				self::assertArrayNotHasKey( RunHistory::OPTION_PREFIX . self::CHUNKED_IDENTITY, $wpdb->rows );
				self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/started/' . self::CHUNKED_IDENTITY ) );
			}
		);

		$result = $this->client->dispatch_now( self::CHUNKED_SCHEDULE );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $observed );
		self::assertArrayHasKey( RunHistory::OPTION_PREFIX . self::CHUNKED_IDENTITY, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/started/' . self::CHUNKED_IDENTITY ) );

		$this->rig->run_due();

		self::assertSame( array( self::ARGS ), $this->chunked_job->generate_calls );
		$started = $this->rig->hooks()->fired( 'a8csp_jobs_engine/started/' . self::CHUNKED_IDENTITY );
		$run_id  = $started[0][0] ?? null;
		self::assertInstanceOf( RunId::class, $run_id );
		self::assertSame( self::RUN_ID, (string) $run_id );
		self::assertSame( array( array( $run_id, self::ARGS ) ), $started );
	}

	/**
	 * Allow salts the fence identity while retaining the original job arguments.
	 *
	 * @return  void
	 */
	public function test_allow_dispatch_does_not_contend_with_a_held_shared_identity(): void {
		$this->sync_schedule( OverlapPolicy::Allow );
		$this->seed_held_lock();

		$result = $this->client->dispatch_now( self::SCHEDULE );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				'identity' => self::IDENTITY,
				'run_id'   => self::RUN_ID,
			),
			$result->value
		);
		self::assertSame( 'run-incumbent', $this->lock_owner( $this->args_hash() ) );
		$run = $this->option( 'a8csp_bgje_run_' . self::IDENTITY . '_' . self::RUN_ID );
		self::assertIsArray( $run );
		self::assertSame( self::ARGS, $run['start_args'] ?? null );
		self::assertSame( array(), $run['kind_state'] ?? null );
		$salted_hash = $run['args_hash'] ?? null;
		self::assertIsString( $salted_hash );
		self::assertNotSame( $this->args_hash(), $salted_hash );
		self::assertSame( self::RUN_ID, $this->lock_owner( $salted_hash ) );
	}

	/**
	 * A forced Allow run-id collision reaches the duplicate per-run identity failure.
	 *
	 * @return  void
	 */
	public function test_allow_dispatch_reports_a_forced_run_id_collision(): void {
		$this->sync_schedule( OverlapPolicy::Allow );
		$first = $this->client->dispatch_now( self::SCHEDULE );
		self::assertInstanceOf( Success::class, $first );
		$run = $this->option( 'a8csp_bgje_run_' . self::IDENTITY . '_' . self::RUN_ID );
		self::assertIsArray( $run );
		$salted_hash = $run['args_hash'] ?? null;
		self::assertIsString( $salted_hash );
		$this->put_lock( $salted_hash, 'collision-rival' );

		$collision = $this->client->dispatch_now( self::SCHEDULE );

		self::assertInstanceOf( Failure::class, $collision );
		$error = $this->boundary_error( $collision );
		self::assertSame( ErrorCode::OverlapHeld, $error->code );
		self::assertStringContainsString( 'duplicate per-run overlap identity', $error->message );
		self::assertCount( 1, $this->run_delivery_calls() );
	}

	/**
	 * An unconfirmed occurrence-lease write maps to the public storage-failure code.
	 *
	 * @return  void
	 */
	public function test_dispatch_now_reports_occurrence_lease_storage_failure(): void {
		$this->sync_schedule( OverlapPolicy::Allow );
		$this->rig->wpdb()->script_result( 'insert', false );

		$result = $this->client->dispatch_now( self::SCHEDULE );

		self::assertInstanceOf( Failure::class, $result );
		$error = $this->boundary_error( $result );
		self::assertSame( ErrorCode::StorageFailure, $error->code );
		self::assertStringContainsString( 'repair WordPress option reads and writes', $error->message );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * Reject returns a typed overlap failure and leaves the incumbent untouched.
	 *
	 * @return  void
	 */
	public function test_reject_dispatch_returns_a_typed_held_outcome(): void {
		$this->sync_schedule( OverlapPolicy::Reject );
		$this->seed_held_lock();
		$latest_pointer = 'a8csp_bgje_latest_run_' . self::IDENTITY;
		unset( $this->rig->wpdb()->rows[ $latest_pointer ], $this->rig->wpdb()->autoload[ $latest_pointer ] );

		$result = $this->client->dispatch_now( self::SCHEDULE );

		self::assertInstanceOf( Failure::class, $result );
		$error = $this->boundary_error( $result );
		self::assertSame( ErrorCode::OverlapHeld, $error->code );
		self::assertSame( 'run-incumbent', $error->context['run_id'] ?? null );
		self::assertSame( array(), $this->run_delivery_calls() );
		self::assertSame( 'run-incumbent', $this->lock_owner( $this->args_hash() ) );
		self::assertNull( $this->option( RunStore::OPTION_PREFIX . self::IDENTITY . '_' . self::RUN_ID ) );
	}

	/**
	 * Reject fails closed when contention cannot be tied to an authoritative owner.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The nested insert interception forces a failed overlap-lock claim after the provisional run row exists, a mid-claim database race the public schedule facade cannot stage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_reject_dispatch_does_not_consume_an_unconfirmed_held_outcome(): void {
		$this->sync_schedule( OverlapPolicy::Reject );
		// Two insert interceptions are required because the overlap-lock claim follows the provisional run-row insert.
		$this->rig->wpdb()->before_next( 'insert', static fn ( WpdbLockSpy $wpdb ) => $wpdb->before_next( 'insert', static fn ( WpdbLockSpy $database ) => $database->script_result( 'insert', false ) ) );

		$result = $this->client->dispatch_now( self::SCHEDULE );

		self::assertInstanceOf( Failure::class, $result );
		$error = $this->boundary_error( $result );
		self::assertSame( ErrorCode::OverlapHeld, $error->code );
		self::assertStringContainsString( 'could not confirm the owner', $error->message );
		self::assertSame( array(), $this->run_delivery_calls() );
		self::assertNull( $this->option( RunStore::OPTION_PREFIX . self::IDENTITY . '_' . self::RUN_ID ) );
	}

	/**
	 * Replace takes the held shared-identity lock before dispatching the replacement run.
	 *
	 * @return  void
	 */
	public function test_replace_dispatch_takes_over_a_held_lock(): void {
		$this->sync_schedule( OverlapPolicy::Replace );
		$this->seed_held_lock();

		$result = $this->client->dispatch_now( self::SCHEDULE );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				'identity' => self::IDENTITY,
				'run_id'   => self::RUN_ID,
			),
			$result->value
		);
		self::assertSame( self::RUN_ID, $this->lock_owner( $this->args_hash() ) );
		self::assertCount( 1, $this->run_delivery_calls() );
	}

	/**
	 * A lost Replace CAS removes provisional state and leaves the new winner untouched.
	 *
	 * @return  void
	 */
	public function test_replace_dispatch_compensates_when_lock_ownership_changes_during_takeover(): void {
		$this->sync_schedule( OverlapPolicy::Replace );
		$this->seed_held_lock();
		$this->rig->wpdb()->before_next( 'update', fn ( WpdbLockSpy $wpdb ) => $this->put_lock( $this->args_hash(), 'run-rival' ) );

		$result = $this->client->dispatch_now( self::SCHEDULE );

		self::assertInstanceOf( Failure::class, $result );
		self::assertSame( 'run-rival', $this->lock_owner( $this->args_hash() ) );
		self::assertNull( $this->option( RunStore::OPTION_PREFIX . self::IDENTITY . '_' . self::RUN_ID ) );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * A scheduling failure after Replace takeover leaves no replacement lock or run state.
	 *
	 * @return  void
	 */
	public function test_replace_dispatch_releases_takeover_when_scheduling_fails(): void {
		$this->sync_schedule( OverlapPolicy::Replace );
		$this->seed_held_lock();
		$this->rig->backend()->results['enqueue_async'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore the scheduler before dispatching the replacement.' ) );

		$result = $this->client->dispatch_now( self::SCHEDULE );

		self::assertInstanceOf( Failure::class, $result );
		$error = $this->boundary_error( $result );
		self::assertSame( ErrorCode::BackendRejected, $error->code );
		self::assertNull( $this->lock_owner( $this->args_hash() ) );
		self::assertNull( $this->option( RunStore::OPTION_PREFIX . self::IDENTITY . '_' . self::RUN_ID ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Synchronizes one declaration through the owner-bound schedule facade.
	 *
	 * @param   OverlapPolicy $policy   Job overlap policy.
	 * @param   int           $priority Delivery priority.
	 */
	private function sync_schedule( OverlapPolicy $policy, int $priority = 10 ): void {
		$this->client->register( $this->job->definition( new JobOptions( overlap: $policy ) ) );
		$result = $this->client->sync( array( new Schedule( self::SCHEDULE, Recurrence::every( 300 ), self::NAME, self::ARGS, priority: $priority ) ) );
		self::assertInstanceOf( Success::class, $result );
	}

	/**
	 * Synchronizes one chunked-target declaration through the owner-bound schedule facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OverlapPolicy $policy   Chunked Job overlap policy.
	 * @param   int           $priority Delivery priority.
	 *
	 * @return  void
	 */
	private function sync_chunked_schedule( OverlapPolicy $policy, int $priority = 10 ): void {
		$this->client->register( $this->chunked_job->definition( new JobOptions( overlap: $policy ) ) );
		$result = $this->client->sync( array( new Schedule( self::CHUNKED_SCHEDULE, Recurrence::every( 300 ), self::CHUNKED_NAME, self::ARGS, priority: $priority ) ) );
		self::assertInstanceOf( Success::class, $result );
	}

	/** Stores one valid incumbent lock and latest pointer. */
	private function seed_held_lock(): void {
		$this->put_lock( $this->args_hash(), 'run-incumbent' );
		[ $option_name, $raw ] = $this->fixtures->latest(
			array(
				array(
					'run_id'    => 'run-incumbent',
					'args_hash' => $this->args_hash(),
				),
			)
		);
		$this->rig->wpdb()->put( $option_name, $raw );
	}

	/**
	 * Stores one valid overlap lock row.
	 *
	 * @param   string $args_hash Argument identity.
	 * @param   string $run_id    Lock owner.
	 */
	private function put_lock( string $args_hash, string $run_id ): void {
		$raw = \maybe_serialize(
			array(
				'run_id'       => $run_id,
				'claimed_at'   => self::NOW,
				'heartbeat_at' => self::NOW,
			)
		);
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $args_hash, $raw );
	}

	/** Returns the production argument identity for the pilot arguments. */
	private function args_hash(): string {
		return $this->fixtures->args_hash( self::ARGS );
	}

	/**
	 * Returns one current lock owner.
	 *
	 * @param   string $args_hash Argument identity.
	 */
	private function lock_owner( string $args_hash ): ?string {
		$raw  = $this->rig->wpdb()->rows[ OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $args_hash ] ?? null;
		$lock = \is_string( $raw ) ? \maybe_unserialize( $raw ) : null;

		return \is_array( $lock ) && \is_string( $lock['run_id'] ?? null ) ? $lock['run_id'] : null;
	}

	/**
	 * Returns one in-memory option value.
	 *
	 * @param   string $name Option name.
	 */
	private function option( string $name ): mixed {
		$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();
		self::assertIsArray( $options );

		return $options[ $name ] ?? null;
	}

	/**
	 * Returns backend calls that schedule job-run delivery.
	 *
	 * @return list<array{verb: string, args: array<string, mixed>}>
	 */
	private function run_delivery_calls(): array {
		return \array_values(
			\array_filter(
				$this->rig->backend()->calls,
				static function ( array $call ): bool {
					$args = $call['args']['args'] ?? null;

					return \is_array( $args )
						&& 'enqueue_async' === $call['verb']
						&& 'a8csp_jobs_engine/deliver' === ( $call['args']['hook'] ?? null )
						&& self::IDENTITY === ( $args[0] ?? null );
				}
			)
		);
	}

	/**
	 * Returns backend calls that schedule chunked-job start delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function chunked_start_calls(): array {
		return \array_values(
			\array_filter(
				$this->rig->backend()->calls,
				static function ( array $call ): bool {
					$args = $call['args']['args'] ?? null;

					return \is_array( $args )
						&& 'enqueue_async' === $call['verb']
						&& 'a8csp_jobs_engine/deliver' === ( $call['args']['hook'] ?? null )
						&& self::CHUNKED_IDENTITY === ( $args[0] ?? null );
				}
			)
		);
	}

	/**
	 * Returns one facade-mapped API error.
	 *
	 * @phpstan-param Failure<ErrorInterface> $result
	 *
	 * @param Failure $result Failed facade result.
	 */
	private function boundary_error( Failure $result ): BoundaryError {
		$error = $result->error;
		self::assertInstanceOf( BoundaryError::class, $error );

		return $error;
	}

	// endregion.
}
