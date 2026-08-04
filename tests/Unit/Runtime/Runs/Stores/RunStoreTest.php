<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs\Stores;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\ScopeOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Detects unsafe class construction while corrupt run storage is inspected. */
final class RunStoreWakeupProbe {
	// region FIELDS AND CONSTANTS.

	public static int $wakeups = 0;

	// endregion.

	// region MAGIC METHODS.

	/** Records an unsafe native object construction. */
	public function __wakeup(): void {
		++self::$wakeups;
	}

	// endregion.
}

/**
 * Exercises live run state through lifecycle inspection and retains exact-row CAS proofs.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunStore::class )]
final class RunStoreTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS             = array(
		'scope'   => 'all',
		'site_id' => 7,
	);
	private const string IDENTITY        = self::SCOPE . ':' . self::NAME;
	private const string NAME            = 'reports';
	private const int NOW                = 1_700_000_000;
	private const string SCOPE           = 'runs-tests';
	private const string PREVIOUS_RUN_ID = '00000000001699999999-0000000000000000041';
	private const string RUN_ID          = '00000000001700000000-0000000000000000042';

	private ScopeOperations $client;
	private StoreFixtureBuilder $fixtures;
	private Identity $identity;
	private EngineRig $rig;
	private OptionRows $rows;
	private RecordingJob $job;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded production files before the graph is built.
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

		$this->rig      = EngineRig::set_up( self::NOW );
		$this->client   = $this->rig->operations( self::SCOPE );
		$this->identity = Identity::compose( self::SCOPE, self::NAME );
		$this->job      = new RecordingJob( self::NAME );
		$this->client->register( $this->job->definition( new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 30 ) ) ) );
		$this->fixtures = StoreFixtureBuilder::for_identity( self::IDENTITY );
		$this->rows     = new OptionRows( $this->rig->wpdb() );
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

	// region BEHAVIOR.

	/**
	 * Enqueue, execution admission, and completion expose the real live-state lifecycle.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_live_state_is_visible_while_running_and_disappears_after_completion(): void {
		$during_execution     = null;
		$this->job->on_handle = function () use ( &$during_execution ): void {
			$during_execution = $this->single_live_run();
		};
		$result               = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );

		$queued = $this->single_live_run();
		self::assertSame( self::RUN_ID, $queued['run_id'] );
		self::assertFalse( $queued['executing'] );
		self::assertSame( 0, $queued['attempts'] );
		self::assertSame( self::NOW, $queued['heartbeat_at'] );

		$this->rig->run_due();

		self::assertIsArray( $during_execution );
		self::assertTrue( $during_execution['executing'] );
		self::assertGreaterThanOrEqual( self::NOW, $during_execution['heartbeat_at'] );
		$snapshot = $this->rig->inspection()->runs( $this->identity );
		self::assertSame( array(), $snapshot['live'] );
		self::assertSame( 'completed', $snapshot['history'][0]['outcome'] ?? null );
		self::assertSame( self::RUN_ID, $snapshot['history'][0]['run_id'] ?? null );
	}

	/**
	 * A retry transition exposes the consumed attempt and clears execution ownership between deliveries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_transition_is_visible_through_live_run_inspection(): void {
		$this->job->throwable           = new \RuntimeException( 'Transient failure.' );
		$this->rig->randomizer()->value = 7;
		$result                         = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );

		$this->rig->run_due();

		$retrying = $this->single_live_run();
		self::assertSame( 1, $retrying['attempts'] );
		self::assertFalse( $retrying['executing'] );
		self::assertGreaterThanOrEqual( self::NOW, $retrying['heartbeat_at'] );
		$this->rig->run_due();
		self::assertSame( array(), $this->rig->inspection()->runs( $this->identity )['live'] );
		$this->rig->assert_failed( ErrorCode::ExecutionFailed );
	}

	/**
	 * Missing, corrupt, and legacy live rows are skipped instead of becoming fatal inspection data.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_live_rows_are_tolerated_by_inspection(): void {
		self::assertSame( array(), $this->rig->inspection()->runs( $this->identity )['live'] );
		$this->rig->wpdb()->put( $this->run_option_name(), 'legacy-corrupt-run-row' );

		$snapshot = $this->rig->inspection()->runs( $this->identity );

		self::assertSame( array(), $snapshot['live'] );
		self::assertNull( $snapshot['live_error'] );
		self::assertSame( 1, $snapshot['live_scanned'] );
	}

	/**
	 * A run row without its persisted work kind is corrupt rather than legacy-compatible state.
	 *
	 * @return  void
	 */
	public function test_missing_kind_never_hydrates(): void {
		$fixture = $this->fixtures->run( self::RUN_ID, $this->state() );
		$stored  = \maybe_unserialize( $fixture[1] );
		self::assertIsArray( $stored );
		unset( $stored['kind'] );
		$raw = \maybe_serialize( $stored );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( $fixture[0], $raw );

		$inspected = $this->store()->inspect( self::RUN_ID );

		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertSame( $raw, $inspected->value['raw'] ?? null );
		self::assertNull( $inspected->value['state'] ?? null );
	}

	/**
	 * A negative persisted action sequence is unreadable state rather than a hydration exception.
	 *
	 * @return  void
	 */
	public function test_negative_action_sequence_never_hydrates(): void {
		$fixture = $this->fixtures->run( self::RUN_ID, $this->state() );
		$stored  = \maybe_unserialize( $fixture[1] );
		self::assertIsArray( $stored );
		$stored['action_sequence'] = -1;
		$raw                       = \maybe_serialize( $stored );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( $fixture[0], $raw );

		$inspected = $this->store()->inspect( self::RUN_ID );

		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertSame( $raw, $inspected->value['raw'] ?? null );
		self::assertNull( $inspected->value['state'] ?? null );
	}

	/**
	 * Lane liveness reads rows in bounded batches and retains only the maintenance projection.
	 *
	 * @load-bearing performance
	 * @pin-rationale A high-fan-out identity must not materialize its complete active-run value set in one query or retain raw-scale fields for lock classification.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_inspect_lane_liveness_projects_rows_in_bounded_batches(): void {
		$args_hash = $this->fixtures->args_hash( self::ARGS );
		$valid     = $this->fixtures->run( self::RUN_ID, $this->state() );
		for ( $index = 0; $index < 11; ++$index ) {
			$run_id = \sprintf( '00000000001700000000-%019d', $index );
			$this->rig->wpdb()->put( RunIdentity::option_name( $this->identity, $run_id ), $valid[1] );
		}

		$corrupt = \maybe_unserialize( $valid[1] );
		self::assertIsArray( $corrupt );
		$corrupt['args_hash'] = \str_repeat( 'a', 100_000 );
		$corrupt_raw          = \maybe_serialize( $corrupt );
		self::assertIsString( $corrupt_raw );
		$corrupt_run_id = '00000000001700000000-0000000000000000011';
		$this->rig->wpdb()->put( RunIdentity::option_name( $this->identity, $corrupt_run_id ), $corrupt_raw );
		$foreign_identity = self::IDENTITY . '_other';
		$foreign          = StoreFixtureBuilder::for_identity( $foreign_identity )->run( self::RUN_ID, $this->state( args_hash: \str_repeat( 'f', 64 ) ) );
		$this->put_fixture( $foreign );
		$this->rig->wpdb()->recorded_queries = array();

		$inspected = $this->store()->inspect_lane_liveness();

		self::assertInstanceOf( Success::class, $inspected );
		self::assertSame(
			array(
				'unreadable'    => true,
				'running_lanes' => array( $args_hash => true ),
			),
			$inspected->value
		);
		$batch_reads = \array_values( \array_filter( $this->rig->wpdb()->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, 'SELECT `option_name`, `option_value` FROM ' ) ) );
		self::assertCount( 2, $batch_reads );
		foreach ( $batch_reads as $query ) {
			self::assertLessThanOrEqual( 10, \substr_count( $query, RunStore::OPTION_PREFIX ) );
		}
	}

	/**
	 * Lane-liveness inspection propagates an authoritative batch-read failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_inspect_lane_liveness_fails_when_any_batch_read_is_indeterminate(): void {
		$this->put_fixture( $this->fixtures->run( self::RUN_ID, $this->state() ) );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'candidate read failed';
			}
		);

		$inspected = $this->store()->inspect_lane_liveness();

		self::assertInstanceOf( Failure::class, $inspected );
		self::assertInstanceOf( EngineError::class, $inspected->error );
		self::assertSame( EngineErrorReason::StorageFailure, $inspected->error->reason );
	}

	/**
	 * Lane-liveness inspection propagates an authoritative name-enumeration failure.
	 *
	 * @return  void
	 */
	public function test_inspect_lane_liveness_fails_when_name_enumeration_is_indeterminate(): void {
		$this->rig->wpdb()->before_next(
			'scan',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'name enumeration failed';
			}
		);

		$inspected = $this->store()->inspect_lane_liveness();

		self::assertInstanceOf( Failure::class, $inspected );
		self::assertInstanceOf( EngineError::class, $inspected->error );
		self::assertSame( EngineErrorReason::StorageFailure, $inspected->error->reason );
	}

	/**
	 * A grammar-valid unregistered kind hydrates as opaque run state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unknown_grammar_valid_kind_hydrates(): void {
		$fixture = $this->fixtures->run( self::RUN_ID, $this->state() );
		$stored  = \maybe_unserialize( $fixture[1] );
		self::assertIsArray( $stored );
		$stored['kind'] = 'acme.export';
		$raw            = \maybe_serialize( $stored );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( $fixture[0], $raw );

		$inspected = $this->store()->inspect( self::RUN_ID );

		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertSame( $raw, $inspected->value['raw'] ?? null );
		self::assertInstanceOf( RunState::class, $inspected->value['state'] );
		self::assertSame( 'acme.export', $inspected->value['state']->kind );
	}

	/**
	 * Lexically malformed stored kind keys remain corrupt raw evidence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_kind_never_hydrates(): void {
		foreach ( array( '', 'Job', 'Job!', 'acme.export.daily' ) as $kind ) {
			$fixture = $this->fixtures->run( self::RUN_ID, $this->state() );
			$stored  = \maybe_unserialize( $fixture[1] );
			self::assertIsArray( $stored );
			$stored['kind'] = $kind;
			$raw            = \maybe_serialize( $stored );
			self::assertIsString( $raw );
			$this->rig->wpdb()->put( $fixture[0], $raw );

			$inspected = $this->store()->inspect( self::RUN_ID );

			self::assertInstanceOf( Success::class, $inspected );
			self::assertIsArray( $inspected->value );
			self::assertSame( $raw, $inspected->value['raw'] ?? null );
			self::assertNull( $inspected->value['state'] ?? null );
		}
	}

	/**
	 * A complete persisted row immediately below the storage ceiling is accepted.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_full_row_below_persisted_byte_ceiling_is_accepted(): void {
		// These literals derive from the row and kind-state ceilings to pin the below, exact, and over boundaries.
		$start_args = array( 'payload' => \str_repeat( 'x', 16_006 ) );
		$kind_state = array( 'payload' => \str_repeat( 'x', 983_584 ) );
		$store      = $this->store();

		$result = $store->create( self::RUN_ID, 'acme.export', $start_args, $this->fixtures->args_hash( $start_args ), $kind_state );

		self::assertInstanceOf( RunState::class, $result );
		$inspected = $store->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		$raw = $inspected->value['raw'] ?? null;
		self::assertIsString( $raw );
		self::assertSame( 999_999, \strlen( $raw ) );
	}

	/**
	 * A complete persisted row exactly at the storage ceiling is accepted.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_full_row_at_persisted_byte_ceiling_is_accepted(): void {
		$start_args = array( 'payload' => \str_repeat( 'x', 16_007 ) );
		$kind_state = array( 'payload' => \str_repeat( 'x', 983_584 ) );
		$store      = $this->store();

		$result = $store->create( self::RUN_ID, 'acme.export', $start_args, $this->fixtures->args_hash( $start_args ), $kind_state );

		self::assertInstanceOf( RunState::class, $result );
		$inspected = $store->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		$raw = $inspected->value['raw'] ?? null;
		self::assertIsString( $raw );
		self::assertSame( 1_000_000, \strlen( $raw ) );
	}

	/**
	 * A complete persisted row over the storage ceiling is rejected with both byte counts.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_full_row_over_persisted_byte_ceiling_is_rejected_before_create(): void {
		$start_args = array( 'payload' => \str_repeat( 'x', 16_008 ) );
		$kind_state = array( 'payload' => \str_repeat( 'x', 983_584 ) );

		$result = $this->store()->create( self::RUN_ID, 'acme.export', $start_args, $this->fixtures->args_hash( $start_args ), $kind_state );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( EngineErrorReason::PayloadRejected, $result->error->reason );
		self::assertSame( 'Run state contains 1000001 persisted serialization bytes; the limit is 1000000 bytes.', $result->error->message );
		self::assertSame(
			array(
				'actual_bytes' => 1_000_001,
				'limit_bytes'  => 1_000_000,
			),
			$result->error->context
		);
		self::assertArrayNotHasKey( $this->run_option_name(), $this->rig->wpdb()->rows );
		$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();
		self::assertIsArray( $options );
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
	}

	/**
	 * An oversized replacement is rejected without changing the exact persisted generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_full_row_over_persisted_byte_ceiling_is_rejected_before_replace(): void {
		$expected = $this->state( 'acme.export' );
		$fixture  = $this->fixtures->run( self::RUN_ID, $expected );
		$this->put_fixture( $fixture );

		$start_args  = array( 'payload' => \str_repeat( 'x', 16_008 ) );
		$replacement = new RunState( status: RunStatus::Running, kind: 'acme.export', executing: false, start_args: $start_args, args_hash: $this->fixtures->args_hash( $start_args ), kind_state: array( 'payload' => \str_repeat( 'x', 983_584 ) ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW );

		$result = $this->store()->replace_if_state_matches( self::RUN_ID, $expected, $replacement );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( EngineErrorReason::PayloadRejected, $result->error->reason );
		self::assertSame(
			array(
				'actual_bytes' => 1_000_001,
				'limit_bytes'  => 1_000_000,
			),
			$result->error->context
		);
		self::assertSame( $fixture[1], $this->raw_row() );
	}

	/**
	 * A failed terminal replacement above both byte ceilings remains persistable for immediate effect settlement.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_terminal_replacement_above_persisted_byte_ceiling_is_accepted(): void {
		$start_args = array( 'payload' => \str_repeat( 'x', 16_026 ) );
		$running    = new RunState( status: RunStatus::Running, kind: 'acme.export', executing: false, start_args: $start_args, args_hash: $this->fixtures->args_hash( $start_args ), kind_state: array( 'payload' => \str_repeat( 'x', 990_000 ) ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW );
		$terminal   = $running->with_status( RunStatus::Failed )->with_error(
			array(
				'class'   => \RuntimeException::class,
				'message' => \str_repeat( 'x', 4_096 ),
				'stage'   => 'execution',
				'code'    => ErrorCode::ExecutionFailed->value,
			)
		);
		$fixture    = $this->fixtures->run( self::RUN_ID, $running->with_kind_state( array() ) );
		$stored     = \maybe_unserialize( $fixture[1] );
		self::assertIsArray( $stored );
		$stored['kind_state'] = $running->kind_state;
		$running_raw          = \maybe_serialize( $stored );
		self::assertIsString( $running_raw );
		$this->rig->wpdb()->put( $fixture[0], $running_raw );

		$result = $this->store()->replace_if_state_matches( self::RUN_ID, $running, $terminal );

		self::assertIsString( $result );
		self::assertGreaterThan( 1_000_000, \strlen( $result ) );
		self::assertSame( $result, $this->raw_row() );
	}

	/**
	 * A completed terminal replacement above both byte ceilings shares the terminal exemption.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_terminal_replacement_above_persisted_byte_ceiling_is_accepted(): void {
		$start_args = array( 'payload' => \str_repeat( 'x', 16_026 ) );
		$running    = new RunState( status: RunStatus::Running, kind: 'acme.export', executing: false, start_args: $start_args, args_hash: $this->fixtures->args_hash( $start_args ), kind_state: array( 'payload' => \str_repeat( 'x', 990_000 ) ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW );
		$terminal   = $running->with_status( RunStatus::Completed );
		$fixture    = $this->fixtures->run( self::RUN_ID, $running->with_kind_state( array() ) );
		$stored     = \maybe_unserialize( $fixture[1] );
		self::assertIsArray( $stored );
		$stored['kind_state'] = $running->kind_state;
		$running_raw          = \maybe_serialize( $stored );
		self::assertIsString( $running_raw );
		$this->rig->wpdb()->put( $fixture[0], $running_raw );

		$result = $this->store()->replace_if_state_matches( self::RUN_ID, $running, $terminal );

		self::assertIsString( $result );
		self::assertGreaterThan( 1_000_000, \strlen( $result ) );
		self::assertSame( $result, $this->raw_row() );
	}

	/**
	 * A terminal-effect append above both byte ceilings remains persistable before row deletion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_terminal_effect_append_above_persisted_byte_ceiling_is_accepted(): void {
		$start_args = array( 'payload' => \str_repeat( 'x', 16_026 ) );
		$running    = new RunState( status: RunStatus::Running, kind: 'acme.export', executing: false, start_args: $start_args, args_hash: $this->fixtures->args_hash( $start_args ), kind_state: array( 'payload' => \str_repeat( 'x', 990_000 ) ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW );
		$error      = array(
			'class'   => \RuntimeException::class,
			'message' => \str_repeat( 'x', 4_096 ),
			'stage'   => 'execution',
			'code'    => ErrorCode::ExecutionFailed->value,
		);
		$terminal   = $running->with_status( RunStatus::Failed )->with_error( $error );
		$fixture    = $this->fixtures->run( self::RUN_ID, $running->with_kind_state( array() ) );
		$stored     = \maybe_unserialize( $fixture[1] );
		self::assertIsArray( $stored );
		$stored['status']     = RunStatus::Failed->value;
		$stored['kind_state'] = $running->kind_state;
		$stored['error']      = $error;
		$terminal_raw         = \maybe_serialize( $stored );
		self::assertIsString( $terminal_raw );
		self::assertGreaterThan( 1_000_000, \strlen( $terminal_raw ) );
		// Direct raw setup isolates the effect-append boundary from terminal claim persistence.
		$this->rig->wpdb()->put( $fixture[0], $terminal_raw );

		$result = $this->store()->append_terminal_effect( self::RUN_ID, $terminal, $terminal_raw, 'hooks' );

		self::assertIsArray( $result );
		self::assertSame( array( 'hooks' ), $result['state']->effects );
		self::assertGreaterThan( \strlen( $terminal_raw ), \strlen( $result['raw'] ) );
		self::assertSame( $result['raw'], $this->raw_row() );
	}

	/**
	 * Kind-owned state above its producer budget is rejected before any row is written.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_oversized_kind_state_is_rejected_before_persistence(): void {
		$kind_state = array( 'payload' => \str_repeat( 'x', 990_000 ) );

		$result = $this->store()->create( self::RUN_ID, 'acme.export', self::ARGS, $this->fixtures->args_hash( self::ARGS ), $kind_state );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( EngineErrorReason::PayloadRejected, $result->error->reason );
		self::assertSame( 'Run kind state contains 990032 persisted serialization bytes; the limit is 983616 bytes.', $result->error->message );
		self::assertSame(
			array(
				'actual_bytes' => 990_032,
				'limit_bytes'  => 983_616,
			),
			$result->error->context
		);
		self::assertArrayNotHasKey( $this->run_option_name(), $this->rig->wpdb()->rows );
		$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();
		self::assertIsArray( $options );
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
	}

	/**
	 * An existing exact option row returns the shared persistence failure without replacing its bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_create_returns_storage_failure_when_the_exact_option_row_exists(): void {
		$this->rig->wpdb()->put( $this->run_option_name(), 'incumbent-row' );

		$result = $this->store()->create( self::RUN_ID, 'acme.export', self::ARGS, $this->fixtures->args_hash( self::ARGS ), array() );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( 'Run "' . self::RUN_ID . '" for acme.export "' . self::IDENTITY . '" could not be persisted; remove the conflicting run option before retrying.', $result->error->message );
		self::assertSame( EngineErrorReason::StorageFailure, $result->error->reason );
		self::assertSame(
			array(
				'identity' => self::IDENTITY,
				'run_id'   => self::RUN_ID,
				'kind'     => 'acme.export',
			),
			$result->error->context
		);
		self::assertSame( 'incumbent-row', $this->raw_row() );
	}

	/**
	 * An indeterminate exact-row insert returns the same persistence failure without creating a row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_create_returns_storage_failure_when_the_exact_row_insert_fails(): void {
		$this->rig->wpdb()->script_result( 'insert', false );

		$result = $this->store()->create( self::RUN_ID, 'acme.export', self::ARGS, $this->fixtures->args_hash( self::ARGS ), array() );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( 'Run "' . self::RUN_ID . '" for acme.export "' . self::IDENTITY . '" could not be persisted; remove the conflicting run option before retrying.', $result->error->message );
		self::assertSame( EngineErrorReason::StorageFailure, $result->error->reason );
		self::assertSame(
			array(
				'identity' => self::IDENTITY,
				'run_id'   => self::RUN_ID,
				'kind'     => 'acme.export',
			),
			$result->error->context
		);
		self::assertArrayNotHasKey( $this->run_option_name(), $this->rig->wpdb()->rows );
		$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();
		self::assertIsArray( $options );
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
	}

	/**
	 * Kind-owned state that cannot survive guarded hydration is rejected before persistence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_nonportable_kind_state_is_rejected_before_persistence(): void {
		$result = $this->store()->create( self::RUN_ID, 'acme.export', self::ARGS, $this->fixtures->args_hash( self::ARGS ), array( 'payload' => new \stdClass() ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( EngineErrorReason::PayloadRejected, $result->error->reason );
		self::assertArrayNotHasKey( $this->run_option_name(), $this->rig->wpdb()->rows );
		$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();
		self::assertIsArray( $options );
		self::assertArrayNotHasKey( $this->run_option_name(), $options );
	}

	/**
	 * Terminal writes retain the guarded-hydration shape invariant while bypassing byte budgets.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_terminal_replacement_rejects_nonportable_kind_state(): void {
		$running = $this->state();
		$fixture = $this->fixtures->run( self::RUN_ID, $running );
		$this->put_fixture( $fixture );
		$terminal = $running
			->with_status( RunStatus::Failed )
			->with_kind_state( array( 'payload' => new \stdClass() ) );

		$result = $this->store()->replace_if_state_matches( self::RUN_ID, $running, $terminal );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertSame( EngineErrorReason::PayloadRejected, $result->error->reason );
		self::assertSame( $fixture[1], $this->raw_row() );
	}

	// endregion.

	// region KEEP CAS MICRO-SUITE.

	/**
	 * Exact terminal replacement and deletion accept only the observed raw generation.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built running and terminal rows prove both transitions compare binary option bytes and reject stale replays after the generation changes.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_exact_raw_transition_and_delete_are_generation_conditioned(): void {
		$running  = $this->state();
		$terminal = $running->with_status( RunStatus::Completed );
		$before   = $this->fixtures->run( self::RUN_ID, $running );
		$after    = $this->fixtures->run( self::RUN_ID, $terminal );
		$this->put_fixture( $before );
		$this->rig->wpdb()->recorded_queries = array();
		$store                               = $this->store();

		self::assertSame( $after[1], $store->replace_if_raw_matches( self::RUN_ID, $before[1], $terminal ) );
		self::assertSame( $after[1], $this->raw_row() );
		self::assertNull( $store->replace_if_raw_matches( self::RUN_ID, $before[1], $terminal ) );
		self::assertTrue( $store->delete_exact( self::RUN_ID, $after[1] ) );
		self::assertFalse( $store->delete_exact( self::RUN_ID, $after[1] ) );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $this->queries_starting_with( 'UPDATE ' )[0] );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $this->queries_starting_with( 'DELETE ' )[0] );
	}

	/**
	 * Typed exact deletion removes only the generation represented by the supplied state.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built bytes prove typed serialization deletes a matching row and rejects a stale typed generation after a rival advances it.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_delete_if_unchanged_is_conditioned_on_the_expected_typed_generation(): void {
		$expected = $this->state();
		$rival    = $expected->with_action_sequence( 2 );
		$matching = $this->fixtures->run( self::RUN_ID, $expected );
		$winner   = $this->fixtures->run( self::RUN_ID, $rival );
		$store    = $this->store();
		$this->put_fixture( $matching );

		self::assertTrue( $store->delete_if_unchanged( self::RUN_ID, $expected ) );
		self::assertArrayNotHasKey( $matching[0], $this->rig->wpdb()->rows );

		$this->put_fixture( $winner );

		self::assertFalse( $store->delete_if_unchanged( self::RUN_ID, $expected ) );
		self::assertSame( $winner[1], $this->raw_row() );
	}

	/**
	 * A stale state writer cannot overwrite an interleaved newer generation.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The rival bytes are produced by RunStore serialization and installed at the exact update boundary, so a null result proves lost-CAS fencing rather than a scripted pass-through.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_lost_state_cas_preserves_the_fixture_built_rival_generation(): void {
		$running = $this->state();
		$rival   = $running->with_action_sequence( 2 );
		$caller  = $running->with_action_sequence( 3 );
		$before  = $this->fixtures->run( self::RUN_ID, $running );
		$winner  = $this->fixtures->run( self::RUN_ID, $rival );
		$this->put_fixture( $before );
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $winner ): void {
				$wpdb->put( $winner[0], $winner[1] );
			}
		);

		self::assertNull( $this->store()->replace_if_state_matches( self::RUN_ID, $running, $caller ) );
		self::assertSame( $winner[1], $this->raw_row() );
	}

	/**
	 * An exact-row update failure preserves the unclassified state replacement's null outcome.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_replace_if_state_matches_returns_null_when_the_exact_row_update_fails(): void {
		$running     = $this->state();
		$replacement = $running->with_action_sequence( 2 );
		$fixture     = $this->fixtures->run( self::RUN_ID, $running );
		$this->put_fixture( $fixture );
		$this->rig->wpdb()->script_result( 'update', false );

		$result = $this->store()->replace_if_state_matches( self::RUN_ID, $running, $replacement );

		self::assertNull( $result );
		self::assertSame( $fixture[1], $this->raw_row() );
		self::assertCount( 1, $this->queries_starting_with( 'UPDATE ' ) );
	}

	/**
	 * Different interleaved terminal effects converge in append order on one exact snapshot.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The rival append executes real production logic at the outer CAS boundary, proving retry merges monotonic effect progress instead of replacing it.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_interleaved_terminal_effects_converge_on_one_exact_snapshot(): void {
		$terminal = $this->state()->with_status( RunStatus::Completed );
		$fixture  = $this->fixtures->run( self::RUN_ID, $terminal );
		$expected = $this->fixtures->run( self::RUN_ID, $terminal->with_effects( array( 'hooks', 'history' ) ) );
		$this->put_fixture( $fixture );
		$store = $this->store();
		$this->rig->wpdb()->before_next(
			'update',
			static function () use ( $fixture, $store, $terminal ): void {
				self::assertNotNull( $store->append_terminal_effect( self::RUN_ID, $terminal, $fixture[1], 'hooks' ) );
			}
		);

		$appended = $store->append_terminal_effect( self::RUN_ID, $terminal, $fixture[1], 'history' );

		self::assertIsArray( $appended );
		self::assertSame( array( 'hooks', 'history' ), $appended['state']->effects );
		self::assertSame( $expected[1], $appended['raw'] );
		self::assertSame( $expected[1], $this->raw_row() );
	}

	/**
	 * Terminal-effect persistence stops after five consecutive comparison losses.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The exact five-attempt bound (TERMINAL_EFFECT_ATTEMPTS=5) is the liveness contract; an unbounded loop under permanent contention would hang delivery.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_terminal_effect_append_stops_after_five_consecutive_cas_losses(): void {
		$terminal = $this->state()->with_status( RunStatus::Completed );
		$fixture  = $this->fixtures->run( self::RUN_ID, $terminal );
		$this->put_fixture( $fixture );
		$this->rig->wpdb()->recorded_queries = array();

		$last_rival = $fixture;
		for ( $action_sequence = 2; $action_sequence <= 6; ++$action_sequence ) {
			$rival      = $this->fixtures->run( self::RUN_ID, $terminal->with_action_sequence( $action_sequence ) );
			$last_rival = $rival;
			$this->rig->wpdb()->before_next(
				'update',
				static function ( WpdbLockSpy $wpdb ) use ( $rival ): void {
					$wpdb->put( $rival[0], $rival[1] );
				}
			);
		}
		$store = $this->store();

		self::assertNull( $store->append_terminal_effect( self::RUN_ID, $terminal, $fixture[1], 'hooks' ) );
		self::assertCount( 5, $this->queries_starting_with( 'UPDATE ' ) );
		self::assertSame( $last_rival[1], $this->raw_row() );
		$persisted = $store->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $persisted );
		self::assertIsArray( $persisted->value );
		self::assertInstanceOf( RunState::class, $persisted->value['state'] );
		self::assertSame( array(), $persisted->value['state']->effects );
	}

	/**
	 * Terminal-effect keys must be non-empty.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_terminal_effect_append_rejects_an_empty_effect_key(): void {
		$terminal = $this->state()->with_status( RunStatus::Completed );
		$fixture  = $this->fixtures->run( self::RUN_ID, $terminal );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'A terminal effect key cannot be empty.' );

		(void) $this->store()->append_terminal_effect( self::RUN_ID, $terminal, $fixture[1], '' );
	}

	/**
	 * A completed run round-trips its frozen predecessor through production serialization.
	 *
	 * @load-bearing durability
	 * @pin-rationale The predecessor must survive the raw terminal row because terminal-effect replay occurs after the completion claim that freezes it.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_state_round_trips_its_previous_completed_run_id(): void {
		$terminal = $this->state()->with_status( RunStatus::Completed )->with_previous_completed_run_id( self::PREVIOUS_RUN_ID );
		$fixture  = $this->fixtures->run( self::RUN_ID, $terminal );
		$this->put_fixture( $fixture );

		$stored = \maybe_unserialize( $fixture[1] );
		self::assertIsArray( $stored );
		self::assertSame( self::PREVIOUS_RUN_ID, $stored['previous_completed_run_id'] ?? null );
		$inspected = $this->store()->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertInstanceOf( RunState::class, $inspected->value['state'] );
		self::assertSame( self::PREVIOUS_RUN_ID, $inspected->value['state']->previous_completed_run_id );
	}

	/**
	 * A malformed frozen predecessor is dropped without discarding its completed state.
	 *
	 * @load-bearing durability
	 * @pin-rationale The malformed predecessor is persisted below the typed store boundary; retaining the completed state allows terminal effects to deliver with a null predecessor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_state_drops_a_malformed_previous_completed_run_id_during_hydration(): void {
		$terminal = $this->state()->with_status( RunStatus::Completed )->with_previous_completed_run_id( 'malformed-run-id' );
		$fixture  = $this->fixtures->run( self::RUN_ID, $terminal );
		$this->put_fixture( $fixture );

		$inspected = $this->store()->inspect( self::RUN_ID );

		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertInstanceOf( RunState::class, $inspected->value['state'] );
		self::assertNull( $inspected->value['state']->previous_completed_run_id );
	}

	/**
	 * Corrupt object-bearing rows remain raw evidence without constructing their classes.
	 *
	 * @load-bearing security
	 * @pin-rationale The deliberately corrupt serialized row bypasses the fixture builder so hardened inspection can prove it rejects nested objects before PHP wakeup hooks run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_object_bearing_rows_are_skipped_without_constructing_classes(): void {
		$raw = \maybe_serialize(
			array(
				'status'          => 'running',
				'kind'            => 'job',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash-a',
				'kind_state'      => array( 'payload' => new RunStoreWakeupProbe() ),
				'failed_attempts' => 0,
				'action_sequence' => 1,
				'created_at'      => self::NOW,
				'heartbeat_at'    => self::NOW,
			)
		);
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( $this->run_option_name(), $raw );
		RunStoreWakeupProbe::$wakeups = 0;

		self::assertSame( array(), $this->rig->inspection()->runs( $this->identity )['live'] );
		self::assertSame( 0, RunStoreWakeupProbe::$wakeups );
		$inspected = $this->store()->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertSame( $raw, $inspected->value['raw'] ?? null );
		self::assertNull( $inspected->value['state'] ?? null );
		self::assertSame( 0, RunStoreWakeupProbe::$wakeups );
	}

	/**
	 * A scheduled start successor survives production serialization and hydration.
	 *
	 * @load-bearing durability
	 * @pin-rationale Start-stage single deliveries must remain recoverable from persisted run state; fixture-built bytes exercise the writer and guarded hydrator together.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_single_pending_action_round_trips_through_run_storage(): void {
		$fire_at = self::NOW + 60;
		$fixture = $this->fixtures->run( self::RUN_ID, $this->state( 'chunked_job' )->with_pending( PendingAction::single( 'start', $fire_at, 10 ) ) );
		$this->put_fixture( $fixture );

		$stored = \maybe_unserialize( $fixture[1] );
		self::assertIsArray( $stored );
		self::assertSame( 'chunked_job', $stored['kind'] ?? null );
		self::assertSame(
			array(
				'stage'    => 'start',
				'mode'     => 'single',
				'fire_at'  => $fire_at,
				'priority' => 10,
			),
			$stored['pending'] ?? null
		);

		$inspected = $this->store()->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertInstanceOf( RunState::class, $inspected->value['state'] );
		self::assertSame( 'chunked_job', $inspected->value['state']->kind );
		self::assertInstanceOf( PendingAction::class, $inspected->value['state']->pending );
		self::assertSame( 'start', $inspected->value['state']->pending->stage );
		self::assertSame( 'single', $inspected->value['state']->pending->mode );
		self::assertSame( $fire_at, $inspected->value['state']->pending->fire_at );
		self::assertSame( 10, $inspected->value['state']->pending->priority );
	}

	/**
	 * Priority provenance uses the pending descriptor or one pendingless top-level field, never both.
	 *
	 * @load-bearing durability
	 * @pin-rationale A single canonical representation keeps typed-state compare-and-swap bytes stable while preserving priority after successor removal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_priority_provenance_uses_one_canonical_wire_location(): void {
		$pending_state   = $this->state()->with_pending( PendingAction::async( 'run', 42 ) );
		$pending_fixture = $this->fixtures->run( self::RUN_ID, $pending_state );
		$pending_row     = \maybe_unserialize( $pending_fixture[1] );
		self::assertIsArray( $pending_row );
		self::assertArrayNotHasKey( 'priority', $pending_row );
		$stored_pending = $pending_row['pending'] ?? null;
		self::assertIsArray( $stored_pending );
		self::assertSame( 42, $stored_pending['priority'] ?? null );
		$this->put_fixture( $pending_fixture );
		$pending_inspected = $this->store()->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $pending_inspected );
		self::assertIsArray( $pending_inspected->value );
		self::assertInstanceOf( RunState::class, $pending_inspected->value['state'] );
		self::assertSame( 42, $pending_inspected->value['state']->priority );

		$pendingless_fixture = $this->fixtures->run( self::RUN_ID, $pending_state->with_pending( null ) );
		$pendingless_row     = \maybe_unserialize( $pendingless_fixture[1] );
		self::assertIsArray( $pendingless_row );
		self::assertArrayNotHasKey( 'pending', $pendingless_row );
		self::assertSame( 42, $pendingless_row['priority'] ?? null );
		$this->put_fixture( $pendingless_fixture );
		$pendingless_inspected = $this->store()->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $pendingless_inspected );
		self::assertIsArray( $pendingless_inspected->value );
		self::assertInstanceOf( RunState::class, $pendingless_inspected->value['state'] );
		self::assertSame( 42, $pendingless_inspected->value['state']->priority );
	}

	/**
	 * A pending descriptor and top-level priority together are corrupt raw evidence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_redundant_priority_representation_never_hydrates(): void {
		$pending_fixture = $this->fixtures->run( self::RUN_ID, $this->state()->with_pending( PendingAction::async( 'run', 42 ) ) );
		$pending_row     = \maybe_unserialize( $pending_fixture[1] );
		self::assertIsArray( $pending_row );
		$pending_row['priority'] = 42;
		$raw                     = \maybe_serialize( $pending_row );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( $pending_fixture[0], $raw );

		$inspected = $this->store()->inspect( self::RUN_ID );

		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertSame( $raw, $inspected->value['raw'] ?? null );
		self::assertNull( $inspected->value['state'] ?? null );
	}

	/**
	 * A pendingless explicit engine-default priority hydrates as admitted provenance.
	 *
	 * @load-bearing durability
	 * @pin-rationale An explicit priority equal to the engine default is admitted provenance rather than corrupt raw evidence.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_pendingless_explicit_engine_default_priority_hydrates(): void {
		$fixture = $this->fixtures->run( self::RUN_ID, $this->state()->with_pending( null ) );
		$this->put_fixture( $fixture );

		$inspected = $this->store()->inspect( self::RUN_ID );

		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertSame( $fixture[1], $inspected->value['raw'] ?? null );
		self::assertInstanceOf( RunState::class, $inspected->value['state'] );
		self::assertSame( 10, $inspected->value['state']->priority );
	}

	/**
	 * A pendingless engine-default state writes explicit priority provenance.
	 *
	 * @load-bearing durability
	 * @pin-rationale Priority provenance stays readable after successor removal because the wire location never depends on the default's value.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_pendingless_engine_default_priority_is_written_explicitly(): void {
		$fixture = $this->fixtures->run( self::RUN_ID, $this->state()->with_pending( null ) );
		$stored  = \maybe_unserialize( $fixture[1] );
		self::assertIsArray( $stored );

		self::assertArrayNotHasKey( 'pending', $stored );
		self::assertArrayHasKey( 'priority', $stored );
		self::assertSame( 10, $stored['priority'] );
	}

	/**
	 * Grammar-valid stages round-trip independently of scheduler mode.
	 *
	 * @load-bearing durability
	 * @pin-rationale Storage validates the lexical extension seam while retaining only mode/fire-time coupling.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_grammar_valid_pending_stages_round_trip_in_both_modes(): void {
		$pending_actions = array(
			PendingAction::async( 'queue_generation', 10 ),
			PendingAction::single( 'cleanup', self::NOW + 30, 11 ),
			PendingAction::single( 'acme.export', self::NOW + 60, 12 ),
		);

		foreach ( $pending_actions as $pending ) {
			$fixture = $this->fixtures->run( self::RUN_ID, $this->state()->with_pending( $pending ) );
			$this->put_fixture( $fixture );

			$inspected = $this->store()->inspect( self::RUN_ID );
			self::assertInstanceOf( Success::class, $inspected );
			self::assertIsArray( $inspected->value );
			self::assertInstanceOf( RunState::class, $inspected->value['state'] );
			self::assertInstanceOf( PendingAction::class, $inspected->value['state']->pending );
			self::assertSame( $pending->stage, $inspected->value['state']->pending->stage );
			self::assertSame( $pending->mode, $inspected->value['state']->pending->mode );
			self::assertSame( $pending->fire_at, $inspected->value['state']->pending->fire_at );
			self::assertSame( $pending->priority, $inspected->value['state']->pending->priority );
		}
	}

	/**
	 * Pending descriptors accept only the lexical stage grammar, mode/fire-time pairings, and field set.
	 *
	 * @load-bearing security
	 * @pin-rationale Inline corrupt rows cover the invalid pairings that production serialization cannot emit, preventing legacy or injected shapes from becoming executable pending actions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_pending_descriptors_never_become_live_runs(): void {
		$invalid = array(
			array(
				'stage'    => 'run',
				'mode'     => 'later',
				'fire_at'  => 2,
				'priority' => 10,
			),
			array(
				'stage'    => 'run',
				'mode'     => 'async',
				'fire_at'  => 2,
				'priority' => 10,
			),
			array(
				'stage'    => 'run',
				'mode'     => 'single',
				'fire_at'  => null,
				'priority' => 10,
			),
			array(
				'stage'    => 'run',
				'mode'     => 'async',
				'fire_at'  => null,
				'priority' => '10',
			),
			array(
				'stage'    => 'run',
				'mode'     => 'async',
				'fire_at'  => null,
				'priority' => 10,
				'extra'    => true,
			),
			array(
				'stage'    => 'Run',
				'mode'     => 'single',
				'fire_at'  => 2,
				'priority' => 10,
			),
			array(
				'stage'    => '',
				'mode'     => 'async',
				'fire_at'  => null,
				'priority' => 10,
			),
			array(
				'stage'    => 'acme.export.daily',
				'mode'     => 'async',
				'fire_at'  => null,
				'priority' => 10,
			),
		);

		foreach ( $invalid as $pending ) {
			$this->put_corrupt_state( array( 'pending' => $pending ) );
			self::assertSame( array(), $this->rig->inspection()->runs( $this->identity )['live'] );
		}
	}

	/**
	 * A grammar-valid extension failure stage hydrates as opaque terminal metadata.
	 *
	 * @load-bearing durability
	 * @pin-rationale An extension stage in active-run storage must survive hydration so terminal effects can reconstruct the public failure value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_grammar_valid_failure_stage_hydrates(): void {
		$error = array(
			'class'   => null,
			'message' => 'Failure.',
			'stage'   => 'acme.export_sync',
			'code'    => ErrorCode::ExecutionFailed->value,
		);
		$state = $this->state()->with_status( RunStatus::Failed )->with_pending( null )->with_error( $error );
		$this->put_fixture( $this->fixtures->run( self::RUN_ID, $state ) );

		$inspected = $this->store()->inspect( self::RUN_ID );

		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertInstanceOf( RunState::class, $inspected->value['state'] );
		self::assertSame( $error, $inspected->value['state']->error );
	}

	/**
	 * Additive portable error metadata survives hydration and exact persisted-byte replacement.
	 *
	 * @load-bearing durability
	 * @pin-rationale Typed terminal state retains portable extension data so exact-state writes preserve its authoritative generation.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unknown_portable_error_field_survives_a_full_persisted_byte_round_trip(): void {
		$error   = array(
			'class'   => null,
			'message' => 'Failure.',
			'stage'   => 'execution',
			'code'    => ErrorCode::ExecutionFailed->value,
		);
		$state   = $this->state()->with_status( RunStatus::Failed )->with_pending( null )->with_error( $error );
		$fixture = $this->fixtures->run( self::RUN_ID, $state );
		$stored  = \maybe_unserialize( $fixture[1] );
		self::assertIsArray( $stored );
		self::assertIsArray( $stored['error'] ?? null );
		$error['diagnostics'] = array(
			'provider'  => 'acme',
			'retryable' => false,
		);
		$stored['error']      = $error;
		$raw                  = \maybe_serialize( $stored );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( $fixture[0], $raw );

		$inspected = $this->store()->inspect( self::RUN_ID );

		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertInstanceOf( RunState::class, $inspected->value['state'] );
		self::assertSame( $error, $inspected->value['state']->error );

		$round_trip = $this->store()->replace_if_state_matches( self::RUN_ID, $inspected->value['state'], $inspected->value['state'] );

		self::assertSame( $raw, $round_trip );
		self::assertSame( $raw, $this->raw_row() );
	}

	/**
	 * Error details at the portable depth boundary survive exact persisted bytes.
	 *
	 * @load-bearing durability
	 * @pin-rationale Error-envelope validation preserves the complete portable depth available to the canonical details payload.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_error_details_at_the_portable_depth_limit_survive_persisted_bytes(): void {
		$error   = array(
			'class'   => null,
			'message' => 'Failure.',
			'stage'   => 'execution',
			'code'    => ErrorCode::ExecutionFailed->value,
			'details' => $this->maximum_portable_values(),
		);
		$state   = $this->state()->with_status( RunStatus::Failed )->with_pending( null )->with_error( $error );
		$fixture = $this->fixtures->run( self::RUN_ID, $state );
		$this->put_fixture( $fixture );

		$inspected = $this->store()->inspect( self::RUN_ID );

		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertInstanceOf( RunState::class, $inspected->value['state'] );
		self::assertSame( $error, $inspected->value['state']->error );

		$round_trip = $this->store()->replace_if_state_matches( self::RUN_ID, $inspected->value['state'], $inspected->value['state'] );

		self::assertSame( $fixture[1], $round_trip );
		self::assertSame( $fixture[1], $this->raw_row() );
	}

	/**
	 * A non-portable additive error value remains corrupt raw evidence.
	 *
	 * @load-bearing security
	 * @pin-rationale Error extension values cross the typed boundary only when their complete value tree is portable.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_nonportable_unknown_error_value_never_hydrates(): void {
		$error   = array(
			'class'   => null,
			'message' => 'Failure.',
			'stage'   => 'execution',
			'code'    => ErrorCode::ExecutionFailed->value,
		);
		$state   = $this->state()->with_status( RunStatus::Failed )->with_pending( null )->with_error( $error );
		$fixture = $this->fixtures->run( self::RUN_ID, $state );
		$stored  = \maybe_unserialize( $fixture[1] );
		self::assertIsArray( $stored );
		self::assertIsArray( $stored['error'] ?? null );
		$stored['error']['diagnostics'] = new \stdClass();
		$raw                            = \maybe_serialize( $stored );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( $fixture[0], $raw );

		$inspected = $this->store()->inspect( self::RUN_ID );

		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		self::assertSame( $raw, $inspected->value['raw'] ?? null );
		self::assertNull( $inspected->value['state'] ?? null );
	}

	/**
	 * Optional terminal error and effect metadata accept only readable, portable shapes.
	 *
	 * @load-bearing security
	 * @pin-rationale Inline malformed metadata cannot be produced by StoreFixtureBuilder and proves a hydratable terminal state has
	 *                readable, portable error metadata, status-compatible predecessor and error fields, and non-empty unique effect keys.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_noncanonical_terminal_metadata_never_hydrates(): void {
		$invalid = array(
			array( 'error' => null ),
			array( 'previous_completed_run_id' => null ),
			array( 'previous_completed_run_id' => 42 ),
			array(
				'status'                    => 'failed',
				'previous_completed_run_id' => 'previous-run-id',
			),
			array( 'error' => array( 'message' => 'Failure.' ) ),
			array(
				'error' => array(
					'class'   => false,
					'message' => 'Failure.',
				),
			),
			array(
				'error' => array(
					'class'   => null,
					'message' => false,
				),
			),
			array(
				'error' => array(
					'message' => 'Failure.',
					'stage'   => 'execution',
					'code'    => ErrorCode::ExecutionFailed->value,
				),
			),
			array(
				'error' => array(
					'class' => null,
					'stage' => 'execution',
					'code'  => ErrorCode::ExecutionFailed->value,
				),
			),
			array(
				'error' => array(
					'class'   => null,
					'message' => 'Failure.',
					'code'    => ErrorCode::ExecutionFailed->value,
				),
			),
			array(
				'error' => array(
					'class'   => null,
					'message' => 'Failure.',
					'stage'   => 'execution',
				),
			),
			array(
				'error' => array(
					'class'   => null,
					'message' => 'Failure.',
					'stage'   => 'execution',
					'code'    => ErrorCode::ExecutionFailed->value,
					'details' => false,
				),
			),
			array(
				'error' => array(
					'class'   => null,
					'message' => 'Failure.',
					'stage'   => 'execution',
					'code'    => ErrorCode::ExecutionFailed->value,
					'details' => array( new \stdClass() ),
				),
			),
			array(
				'error' => array(
					'class'   => null,
					'message' => 'Failure.',
					'stage'   => 'acme.invalid-stage',
					'code'    => ErrorCode::ExecutionFailed->value,
				),
			),
			array( 'effects' => array() ),
			array( 'effects' => array( 'key' => 'hooks' ) ),
			array( 'effects' => array( 1 ) ),
			array( 'effects' => array( '' ) ),
			array( 'effects' => array( 'hooks', 'hooks' ) ),
			array(
				'status'  => 'running',
				'effects' => array( 'hooks' ),
			),
			array(
				'status' => 'completed',
				'error'  => array(
					'class'   => null,
					'message' => 'Failure.',
				),
			),
		);

		foreach ( $invalid as $metadata ) {
			$this->put_corrupt_state( $metadata );
			$inspected = $this->store()->inspect( self::RUN_ID );
			self::assertInstanceOf( Success::class, $inspected );
			self::assertIsArray( $inspected->value );
			self::assertNull( $inspected->value['state'] ?? null );
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns the only inspected live run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{run_id: string, kind: string, status: string, executing: bool, attempts: int, queue_depth: int|null, queue_known: bool, heartbeat_at: int, stale: bool}
	 */
	private function single_live_run(): array {
		$live = $this->rig->inspection()->runs( $this->identity )['live'];
		self::assertCount( 1, $live );

		return $live[0];
	}

	/**
	 * Returns the shared valid fixture state for one work kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $kind      Opaque admitted kind key.
	 * @param   string|null $args_hash Stable single-flight identity, or null to derive it from shared arguments.
	 *
	 * @return  RunState
	 */
	private function state( string $kind = 'job', ?string $args_hash = null ): RunState {
		$pending_stage = 'job' === $kind ? 'run' : 'start';

		return new RunState( status: RunStatus::Running, kind: $kind, executing: false, start_args: self::ARGS, args_hash: $args_hash ?? $this->fixtures->args_hash( self::ARGS ), kind_state: array(), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( $pending_stage, 10 ) );
	}

	/**
	 * Builds the deepest values accepted by the portable-arguments boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>
	 */
	private function maximum_portable_values(): array {
		$values = array( null );
		while ( PortableArguments::is_valid( array( $values ) ) ) {
			$values = array( $values );
		}

		return $values;
	}

	/**
	 * Returns a run store bound to the active authoritative rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RunStore
	 */
	private function store(): RunStore {
		return new RunStore( $this->identity, $this->rig->clock(), $this->rows );
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
	 * Stores one deliberately corrupt terminal state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $metadata Invalid fields under test.
	 *
	 * @return  void
	 */
	private function put_corrupt_state( array $metadata ): void {
		$value = array(
			'status'          => 'failed',
			'kind'            => 'job',
			'executing'       => false,
			'start_args'      => array(),
			'args_hash'       => 'hash-a',
			'kind_state'      => array(),
			'failed_attempts' => 0,
			'action_sequence' => 1,
			'created_at'      => self::NOW,
			'heartbeat_at'    => self::NOW,
			...$metadata,
		);
		$raw   = \maybe_serialize( $value );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( $this->run_option_name(), $raw );
	}

	/**
	 * Returns the active run's authoritative raw bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function raw_row(): string {
		$raw = $this->rig->wpdb()->rows[ $this->run_option_name() ] ?? null;
		self::assertIsString( $raw );

		return $raw;
	}

	/**
	 * Returns the deterministic active-run option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function run_option_name(): string {
		return RunIdentity::option_name( $this->identity, self::RUN_ID );
	}

	/**
	 * Returns recorded statements carrying one literal prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $prefix Statement prefix.
	 *
	 * @return  list<string>
	 */
	private function queries_starting_with( string $prefix ): array {
		return \array_values( \array_filter( $this->rig->wpdb()->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, $prefix ) ) );
	}

	// endregion.
}
