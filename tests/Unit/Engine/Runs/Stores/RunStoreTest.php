<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\PortableArguments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/** Detects whether run-state decoding constructs a serialized class. */
final class RunStoreWakeupProbe {
	public static int $wakeups = 0;

	/** Records an unsafe object construction during unserialization. */
	public function __wakeup(): void {
		++self::$wakeups;
	}
}

/**
 * Pins consolidated run-option persistence and typed read-modify-write state.
 *
 */
#[CoversClass( RunStore::class )]
#[UsesClass( PendingAction::class )]
#[UsesClass( RunState::class )]
#[UsesClass( RunStatus::class )]
#[UsesClass( RawOptionDecoder::class )]
#[UsesClass( PortableArguments::class )]
final class RunStoreTest extends TestCase {
	private const OWNER = 'runs-tests';

	private OptionRows $rows;
	private WpdbLockSpy $wpdb;

	/**
	 * Loads guarded WordPress option functions before the store is autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 3 ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__, 3 ) . '/wp-lock-stubs.php';
	}

	/**
	 * Resets request-local option state.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']         = array();
		$GLOBALS['a8csp_bgte_test_option_calls']    = array();
		$GLOBALS['a8csp_bgte_test_option_autoload'] = array();
		$GLOBALS['a8csp_bgte_test_blog_id']         = 1;
		$GLOBALS['a8csp_bgte_test_cache']           = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']     = array();
		$this->wpdb                                 = new WpdbLockSpy();
		$this->rows                                 = new OptionRows( $this->wpdb );
	}

	/** Clears the opt-in get_option seam so it cannot leak into later test classes. */
	#[\Override]
	protected function tearDown(): void {
		unset( $GLOBALS['a8csp_bgte_test_get_option'] );

		parent::tearDown();
	}

	/**
	 * Returns one owner-qualified test work identity.
	 *
	 * @param   string $name Owner-local work name.
	 *
	 * @return  string
	 */
	private static function identity( string $name ): string {
		return self::OWNER . ':' . $name;
	}

	/**
	 * Creation persists the exact schema and hydrates every typed field unchanged.
	 *
	 * @return  void
	 */
	public function test_create_get_round_trip_pins_schema_key_and_clock_stamps(): void {
		$clock = new FixedClock( 1_700_000_100 );
		$store = new RunStore( self::identity( 'email-digest' ), $clock, $this->rows );
		$state = $store->create(
			run_id: 'run-123',
			start_args: array( 'site_id' => 7 ),
			args_hash: 'hash-a',
			queue: array(
				array( 'page' => 1 ),
				array( 'page' => 2 ),
			),
		);
		self::assertNotNull( $state );

		$stored = $store->get( 'run-123' );

		self::assertNotNull( $stored );
		self::assert_state_same( $state, $stored );
		self::assertSame( RunStatus::Running, $stored->status );
		self::assertFalse( $stored->executing );
		self::assertSame( 1, $stored->action_seq );
		self::assertSame( 1_700_000_100, $stored->created_at );
		self::assertSame( 1_700_000_100, $stored->heartbeat_at );
		self::assertNull( $stored->pending );
		self::assertNull( $stored->error );
		self::assertSame( array(), $stored->effects );
		$expected = array(
			'status'          => 'running',
			'executing'       => false,
			'start_args'      => array( 'site_id' => 7 ),
			'args_hash'       => 'hash-a',
			'queue'           => array(
				array( 'page' => 1 ),
				array( 'page' => 2 ),
			),
			'failed_attempts' => 0,
			'action_seq'      => 1,
			'created_at'      => 1_700_000_100,
			'heartbeat_at'    => 1_700_000_100,
		);
		self::assertSame( $expected, $this->option( 'a8csp_bgte_run_runs-tests:email-digest_run-123' ) );
		$inspection = $store->inspect( 'run-123' );
		if ( $inspection->is_failure() ) {
			self::fail( $inspection->error->message );
		}
		self::assertSame( \maybe_serialize( $expected ), $inspection->value['raw'] ?? null );
		self::assertSame( false, $this->autoload_flag( 'a8csp_bgte_run_runs-tests:email-digest_run-123' ) );

		$add_calls = $this->option_calls( 'add_option' );
		self::assertCount( 1, $add_calls );
		self::assertSame( 'a8csp_bgte_run_runs-tests:email-digest_run-123', $add_calls[0]['args'][0] );
		self::assertSame( '', $add_calls[0]['args'][2] );
		self::assertSame( false, $add_calls[0]['args'][3] );
	}

	/**
	 * Pending descriptors round-trip exactly and null restores the legacy raw shape.
	 *
	 * @return  void
	 */
	public function test_pending_descriptor_round_trips_and_serializes_only_while_present(): void {
		$store          = new RunStore( self::identity( 'email-digest' ), new FixedClock( 1_700_000_100 ), $this->rows );
		$pending        = PendingAction::single( 'run', 1_700_000_220, 31 );
		$pending_option = array(
			'stage'    => 'run',
			'mode'     => 'single',
			'fire_at'  => 1_700_000_220,
			'priority' => 31,
		);
		$state          = $store->create( 'run-pending', array( 'site_id' => 7 ), 'hash-a', array( array( 'site_id' => 7 ) ), $pending );
		self::assertNotNull( $state );

		self::assertEquals( $pending, $store->get( 'run-pending' )?->pending );
		$stored = $this->option( 'a8csp_bgte_run_runs-tests:email-digest_run-pending' );
		self::assertIsArray( $stored );
		self::assertSame( $pending_option, $stored['pending'] ?? null );

		$replacement     = $state->with_pending( null );
		$replacement_raw = $store->replace_if_state_matches( 'run-pending', $state, $replacement );
		self::assertIsString( $replacement_raw );
		$stored = $this->option( 'a8csp_bgte_run_runs-tests:email-digest_run-pending' );
		self::assertIsArray( $stored );
		$legacy_shape = array(
			'status'          => 'running',
			'executing'       => false,
			'start_args'      => array( 'site_id' => 7 ),
			'args_hash'       => 'hash-a',
			'queue'           => array( array( 'site_id' => 7 ) ),
			'failed_attempts' => 0,
			'action_seq'      => 1,
			'created_at'      => 1_700_000_100,
			'heartbeat_at'    => 1_700_000_100,
		);
		self::assertSame( $legacy_shape, $stored );
		self::assertArrayNotHasKey( 'pending', $stored );
		self::assertSame( \maybe_serialize( $legacy_shape ), $replacement_raw );
		self::assertNull( $store->get( 'run-pending' )?->pending );
	}

	/** Terminal failure detail and effect progress round-trip only while present. */
	public function test_terminal_metadata_round_trips_and_serializes_only_while_present(): void {
		$store = new RunStore( self::identity( 'terminal-metadata' ), new FixedClock( 100 ), $this->rows );
		$state = $store->create( 'run-terminal', array( 'scope' => 'all' ), 'hash-a', array() );
		self::assertNotNull( $state );
		$error    = array(
			'class'   => \RuntimeException::class,
			'message' => 'Database unavailable.',
			'stage'   => 'execution',
			'code'    => ApiErrorCode::ExecutionFailed->value,
		);
		$terminal = $state->with_status( RunStatus::Failed )->with_error( $error )->with_effects( array( 'retention', 'callbacks' ) );

		$terminal_raw = $store->replace_if_state_matches( 'run-terminal', $state, $terminal );
		$expected     = array(
			'status'          => 'failed',
			'executing'       => false,
			'start_args'      => array( 'scope' => 'all' ),
			'args_hash'       => 'hash-a',
			'queue'           => array(),
			'failed_attempts' => 0,
			'action_seq'      => 1,
			'created_at'      => 100,
			'heartbeat_at'    => 100,
			'error'           => $error,
			'effects'         => array( 'retention', 'callbacks' ),
		);
		self::assertSame( \maybe_serialize( $expected ), $terminal_raw );
		$stored = $store->get( 'run-terminal' );
		self::assertNotNull( $stored );
		self::assertSame( $error, $stored->error );
		self::assertSame( array( 'retention', 'callbacks' ), $stored->effects );

		$cleared     = $terminal->with_error( null )->with_effects( array() );
		$cleared_raw = $store->replace_if_state_matches( 'run-terminal', $terminal, $cleared );
		unset( $expected['error'], $expected['effects'] );
		self::assertSame( \maybe_serialize( $expected ), $cleared_raw );
	}

	/** Appending a terminal effect returns the exact replacement snapshot. */
	public function test_append_terminal_effect_returns_the_exact_replacement_snapshot(): void {
		$store = new RunStore( self::identity( 'effect-append' ), new FixedClock( 100 ), $this->rows );
		$state = $store->create( 'run-effect', array(), 'hash-a', array() );
		self::assertNotNull( $state );
		$terminal = $state
			->with_status( RunStatus::Failed )
			->with_error(
				array(
					'class'   => null,
					'message' => 'Failed.',
					'stage'   => 'crash-reclaim',
					'code'    => ApiErrorCode::EngineUnavailable->value,
				)
			);
		$raw      = $store->replace_if_state_matches( 'run-effect', $state, $terminal );
		self::assertIsString( $raw );

		$appended = $store->append_terminal_effect( 'run-effect', $terminal, $raw, 'consumer-effect' );

		self::assertNotNull( $appended );
		self::assertSame( array( 'consumer-effect' ), $appended['state']->effects );
		self::assertSame( $terminal->error, $appended['state']->error );
		$inspection = $store->inspect( 'run-effect' );
		if ( $inspection->is_failure() ) {
			self::fail( $inspection->error->message );
		}
		self::assertSame( $appended['raw'], $inspection->value['raw'] ?? null );
		self::assertSame( array( 'consumer-effect' ), $inspection->value['state']?->effects );
	}

	/** A rival append of the same terminal effect converges on one current snapshot. */
	public function test_append_terminal_effect_accepts_a_rival_append_of_the_same_key(): void {
		$store = new RunStore( self::identity( 'effect-same-rival' ), new FixedClock( 100 ), $this->rows );
		$state = $store->create( 'run-effect', array(), 'hash-a', array() );
		self::assertNotNull( $state );
		$terminal = $state->with_status( RunStatus::Completed );
		$raw      = $store->replace_if_state_matches( 'run-effect', $state, $terminal );
		self::assertIsString( $raw );
		$rival = null;
		$this->wpdb->before_next(
			'update',
			static function () use ( &$rival, $raw, $store, $terminal ): void {
				$rival = $store->append_terminal_effect( 'run-effect', $terminal, $raw, 'hooks' );
			}
		);

		$appended = $store->append_terminal_effect( 'run-effect', $terminal, $raw, 'hooks' );

		self::assertNotNull( $rival );
		self::assertNotNull( $appended );
		self::assertSame( $rival['raw'], $appended['raw'] );
		self::assertSame( array( 'hooks' ), $appended['state']->effects );
	}

	/** A rival terminal effect is preserved before retrying the requested append. */
	public function test_append_terminal_effect_retries_from_a_fresh_rival_snapshot(): void {
		$store = new RunStore( self::identity( 'effect-different-rival' ), new FixedClock( 100 ), $this->rows );
		$state = $store->create( 'run-effect', array(), 'hash-a', array() );
		self::assertNotNull( $state );
		$terminal = $state->with_status( RunStatus::Completed );
		$raw      = $store->replace_if_state_matches( 'run-effect', $state, $terminal );
		self::assertIsString( $raw );
		$this->wpdb->before_next(
			'update',
			static function () use ( $raw, $store, $terminal ): void {
				self::assertNotNull( $store->append_terminal_effect( 'run-effect', $terminal, $raw, 'callbacks' ) );
			}
		);

		$appended = $store->append_terminal_effect( 'run-effect', $terminal, $raw, 'hooks' );

		self::assertNotNull( $appended );
		self::assertSame( array( 'callbacks', 'hooks' ), $appended['state']->effects );
		self::assertSame( array( 'callbacks', 'hooks' ), $store->get( 'run-effect' )?->effects );
	}

	/** Empty terminal effect keys are not persistable identities. */
	public function test_append_terminal_effect_rejects_an_empty_key(): void {
		$store = new RunStore( self::identity( 'effect-empty' ), new FixedClock( 100 ), $this->rows );
		$state = $store->create( 'run-effect', array(), 'hash-a', array() );
		self::assertNotNull( $state );
		$inspection = $store->inspect( 'run-effect' );
		if ( $inspection->is_failure() ) {
			self::fail( $inspection->error->message );
		}
		$raw = $inspection->value['raw'] ?? null;
		self::assertIsString( $raw );

		$this->expectException( \InvalidArgumentException::class );
		(void) $store->append_terminal_effect( 'run-effect', $state, $raw, '' );
	}

	/** Terminal effect appends stop after five failed exact writes. */
	public function test_append_terminal_effect_exhausts_its_bounded_cas_attempts(): void {
		$store = new RunStore( self::identity( 'effect-exhaustion' ), new FixedClock( 100 ), $this->rows );
		$state = $store->create( 'run-effect', array(), 'hash-a', array() );
		self::assertNotNull( $state );
		$inspection = $store->inspect( 'run-effect' );
		if ( $inspection->is_failure() ) {
			self::fail( $inspection->error->message );
		}
		$raw = $inspection->value['raw'] ?? null;
		self::assertIsString( $raw );
		$this->wpdb->recorded_queries = array();
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$this->wpdb->script_result( 'update', 0 );
		}

		$appended = $store->append_terminal_effect( 'run-effect', $state, $raw, 'hooks' );

		self::assertNull( $appended );
		self::assertCount( 5, \array_filter( $this->wpdb->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, 'UPDATE ' ) ) );
		self::assertSame( array(), $store->get( 'run-effect' )?->effects );
	}

	/**
	 * Creation failure leaves an existing run option untouched and returns no state.
	 *
	 * @return  void
	 */
	public function test_create_reports_failure_without_overwriting_an_existing_option(): void {
		$clock = new FixedClock( 123 );

		$key = 'a8csp_bgte_run_runs-tests:reports_run-existing';

		$GLOBALS['a8csp_bgte_test_options'] = array( $key => 'existing value' );

		$store = new RunStore( self::identity( 'reports' ), $clock, $this->rows );

		$state = $store->create( 'run-existing', array(), 'hash', array() );

		self::assertNull( $state );
		self::assertSame( 'existing value', $this->option( $key ) );
		self::assertSame( false, $this->option_calls( 'add_option' )[0]['args'][3] );
	}

	/**
	 * Queue, retry, status, execution, and heartbeat copies remain observable after every exact state transition.
	 *
	 * @return  void
	 */
	public function test_state_transitions_round_trip_every_read_modify_write_mutation(): void {
		$clock = new FixedClock( 100 );
		$store = new RunStore( self::identity( 'reports' ), $clock, $this->rows );
		$state = $store->create( 'run-rmw', array( 'scope' => 'all' ), 'hash-rmw', array( array( 'page' => 1 ), array( 'page' => 2 ) ), );
		self::assertNotNull( $state );

		$replacement = $state->with_queue( \array_slice( $state->queue, 1 ) );
		self::assertIsString( $store->replace_if_state_matches( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( array( array( 'page' => 2 ) ), $this->stored_state( $store, 'run-rmw' )->queue );

		$queue       = $state->queue;
		$queue[]     = array( 'page' => 3 );
		$replacement = $state->with_queue( $queue );
		self::assertIsString( $store->replace_if_state_matches( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( array( array( 'page' => 2 ), array( 'page' => 3 ) ), $this->stored_state( $store, 'run-rmw' )->queue );

		$queue = $state->queue;
		\array_unshift( $queue, array( 'page' => 0 ) );
		$replacement = $state->with_queue( $queue );
		self::assertIsString( $store->replace_if_state_matches( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( array( array( 'page' => 0 ), array( 'page' => 2 ), array( 'page' => 3 ) ), $this->stored_state( $store, 'run-rmw' )->queue );

		$replacement = $state->with_failed_attempts( $state->failed_attempts + 1 );
		self::assertIsString( $store->replace_if_state_matches( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( 1, $this->stored_state( $store, 'run-rmw' )->failed_attempts );

		$replacement = $state->with_failed_attempts( 0 );
		self::assertIsString( $store->replace_if_state_matches( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( 0, $this->stored_state( $store, 'run-rmw' )->failed_attempts );

		$replacement = $state->with_action_seq( 2 );
		self::assertIsString( $store->replace_if_state_matches( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( 2, $this->stored_state( $store, 'run-rmw' )->action_seq );

		$replacement = $state->with_executing( true );
		self::assertIsString( $store->replace_if_state_matches( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertTrue( $this->stored_state( $store, 'run-rmw' )->executing );

		$replacement = $state->with_executing( false );
		self::assertIsString( $store->replace_if_state_matches( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertFalse( $this->stored_state( $store, 'run-rmw' )->executing );

		$pending     = PendingAction::async( 'continue', 10 );
		$replacement = $state->with_pending( $pending );
		self::assertIsString( $store->replace_if_state_matches( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertEquals( $pending, $this->stored_state( $store, 'run-rmw' )->pending );

		$replacement = $state->with_status( RunStatus::Failed );
		self::assertIsString( $store->replace_if_state_matches( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( RunStatus::Failed, $this->stored_state( $store, 'run-rmw' )->status );

		$clock->timestamp = 200;
		$state            = $store->mark_executing_with_heartbeat( 'run-rmw', $state );
		self::assertNotNull( $state );
		self::assertTrue( $this->stored_state( $store, 'run-rmw' )->executing );
		self::assertSame( 200, $this->stored_state( $store, 'run-rmw' )->heartbeat_at );
		self::assertSame( 100, $this->stored_state( $store, 'run-rmw' )->created_at );

		self::assertSame( array(), $this->option_calls( 'update_option' ) );
	}

	/** A stale live-state writer loses after an exact transition or terminal deletion. */
	public function test_state_transition_never_recreates_or_overwrites_a_lost_snapshot(): void {
		$store = new RunStore( self::identity( 'fenced-live' ), new FixedClock( 200 ), $this->rows );
		$state = $store->create( 'run-live', array(), 'hash', array() );
		self::assertNotNull( $state );

		$newer = $state->with_action_seq( 2 );
		self::assertIsString( $store->replace_if_state_matches( 'run-live', $state, $newer ) );
		self::assertNull( $store->replace_if_state_matches( 'run-live', $state, $state->with_action_seq( 3 ) ) );
		self::assertSame( 2, $store->get( 'run-live' )?->action_seq );

		$inspection = $store->inspect( 'run-live' );
		if ( $inspection->is_failure() ) {
			self::fail( 'The live run snapshot could not be read.' );
		}
		$snapshot = $inspection->value;
		self::assertNotNull( $snapshot );
		self::assertTrue( $store->delete_exact( 'run-live', $snapshot['raw'] ) );
		self::assertNull( $store->replace_if_state_matches( 'run-live', $newer, $newer->with_action_seq( 3 ) ) );
		self::assertNull( $store->get( 'run-live' ) );
	}

	/**
	 * Executing-heartbeat transition leaves missing and corrupted options untouched.
	 *
	 * @return  void
	 */
	public function test_mark_executing_with_heartbeat_does_not_recreate_unrecoverable_runs(): void {
		$clock = new FixedClock( 200 );
		$store = new RunStore( self::identity( 'heartbeat' ), $clock, $this->rows );

		self::assertNull( $store->mark_executing_with_heartbeat( 'missing' ) );
		self::assertSame( 0, $clock->calls );
		self::assertSame( array(), $this->all_option_calls() );

		$key = 'a8csp_bgte_run_runs-tests:heartbeat_corrupted';

		$GLOBALS['a8csp_bgte_test_options'] = array( $key => 'corrupted' );

		self::assertNull( $store->mark_executing_with_heartbeat( 'corrupted' ) );
		self::assertSame( 0, $clock->calls );
		self::assertSame( array(), $this->all_option_calls() );
		self::assertSame( 'corrupted', $this->option( $key ) );
	}

	/**
	 * Deletion removes the exact run option and later reads report absence.
	 *
	 * @return  void
	 */
	public function test_delete_removes_the_run_option(): void {
		$clock = new FixedClock( 123 );
		$store = new RunStore( self::identity( 'cleanup' ), $clock, $this->rows );
		$store->create( 'run-delete', array(), 'hash', array() );

		$store->delete( 'run-delete' );

		self::assertNull( $store->get( 'run-delete' ) );
		self::assertArrayNotHasKey( 'a8csp_bgte_run_runs-tests:cleanup_run-delete', $this->options() );
		self::assertSame( array( 'a8csp_bgte_run_runs-tests:cleanup_run-delete' ), $this->option_calls( 'delete_option' )[0]['args'] );
	}

	/** Terminal transitions and cleanup win only against the exact observed raw snapshots. */
	public function test_replace_if_raw_matches_and_exact_delete_are_value_conditioned(): void {
		$clock = new FixedClock( 123 );
		$store = new RunStore( self::identity( 'fenced' ), $clock, $this->rows );
		$state = $store->create( 'run-fenced', array(), 'hash', array() );
		self::assertNotNull( $state );

		$inspection = $store->inspect( 'run-fenced' );
		if ( $inspection->is_failure() ) {
			self::fail( 'The running snapshot could not be read.' );
		}
		$running = $inspection->value;
		self::assertNotNull( $running );
		self::assertNotNull( $running['state'] );

		$terminal     = $state->with_status( RunStatus::Completed );
		$terminal_raw = $store->replace_if_raw_matches( 'run-fenced', $running['raw'], $terminal );
		self::assertIsString( $terminal_raw );
		self::assertSame( RunStatus::Completed, $store->get( 'run-fenced' )?->status );
		self::assertNull( $store->replace_if_raw_matches( 'run-fenced', $running['raw'], $terminal ) );
		self::assertTrue( $store->delete_exact( 'run-fenced', $terminal_raw ) );
		self::assertFalse( $store->delete_exact( 'run-fenced', $terminal_raw ) );
		self::assertNull( $store->replace_if_raw_matches( 'run-fenced', $terminal_raw, $terminal ) );
		self::assertNull( $store->get( 'run-fenced' ) );
	}

	/** Raw inspection retains corrupt bytes so maintenance can exact-delete only that snapshot. */
	public function test_inspect_exposes_a_corrupt_raw_snapshot_for_exact_deletion(): void {
		$key                                = 'a8csp_bgte_run_runs-tests:corruption_run-corrupt';
		$options                            = $this->options();
		$options[ $key ]                    = 'corrupt-raw';
		$GLOBALS['a8csp_bgte_test_options'] = $options;
		$store                              = new RunStore( self::identity( 'corruption' ), new FixedClock( 123 ), $this->rows );

		$inspection = $store->inspect( 'run-corrupt' );
		if ( $inspection->is_failure() ) {
			self::fail( 'The corrupt run snapshot could not be read.' );
		}
		$snapshot = $inspection->value;

		self::assertNotNull( $snapshot );
		self::assertSame( 'corrupt-raw', $snapshot['raw'] );
		self::assertNull( $snapshot['state'] );
		self::assertTrue( $store->delete_exact( 'run-corrupt', $snapshot['raw'] ) );
		$missing = $store->inspect( 'run-corrupt' );
		if ( $missing->is_failure() ) {
			self::fail( 'The deleted run snapshot could not be read.' );
		}
		self::assertNull( $missing->value );
	}

	/**
	 * The authoritative row wins when the WordPress options view carries an older valid state.
	 *
	 * @return  void
	 */
	public function test_get_ignores_a_stale_options_view(): void {
		$key   = 'a8csp_bgte_run_runs-tests:authoritative_run-current';
		$state = array(
			'status'          => 'running',
			'executing'       => false,
			'start_args'      => array(),
			'args_hash'       => 'hash',
			'queue'           => array( array( 'page' => 1 ) ),
			'failed_attempts' => 0,
			'action_seq'      => 1,
			'created_at'      => 1,
			'heartbeat_at'    => 1,
		);

		$GLOBALS['a8csp_bgte_test_options'] = array( $key => $state );

		$state['queue']      = array( array( 'page' => 2 ) );
		$state['action_seq'] = 2;
		$raw                 = \maybe_serialize( $state );
		self::assertIsString( $raw );
		$this->wpdb->put( $key, $raw );
		$store = new RunStore( self::identity( 'authoritative' ), new FixedClock( 123 ), $this->rows );

		$stored = $store->get( 'run-current' );

		self::assertNotNull( $stored );
		self::assertSame( 2, $stored->action_seq );
		self::assertSame( array( array( 'page' => 2 ) ), $stored->queue );
	}

	/**
	 * An authoritative read failure preserves the public no-run result without using cached state.
	 *
	 * @return  void
	 */
	public function test_get_returns_null_when_the_authoritative_read_fails(): void {
		$key = 'a8csp_bgte_run_runs-tests:read-failure_run-current';
		$raw = \maybe_serialize(
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array(),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( $key, $raw );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient run read failure';
			}
		);
		$store = new RunStore( self::identity( 'read-failure' ), new FixedClock( 123 ), $this->rows );

		self::assertNull( $store->get( 'run-current' ) );
		self::assertSame( $raw, $this->wpdb->rows[ $key ] );
	}

	/**
	 * Run reads reject object-bearing queue chunks without invoking serialized wakeup hooks.
	 *
	 * @return  void
	 */
	public function test_get_and_inspect_reject_object_bearing_chunks_without_instantiation(): void {
		$key = 'a8csp_bgte_run_runs-tests:poisoned_run-object';
		$raw = \maybe_serialize(
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array(
					array( 'private-payload' => new RunStoreWakeupProbe() ),
				),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( $key, $raw );

		RunStoreWakeupProbe::$wakeups = 0;

		$GLOBALS['a8csp_bgte_test_get_option'] = static function ( string $option, mixed $default_value ) use ( $key, $raw ): mixed {
			// This seam models the unrestricted decoder whose wakeup side effect is under test.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			return $key === $option ? \unserialize( $raw ) : $default_value;
		};

		$store = new RunStore( self::identity( 'poisoned' ), new FixedClock( 123 ), $this->rows );

		self::assertNull( $store->get( 'run-object' ) );
		$inspection = $store->inspect( 'run-object' );
		if ( $inspection->is_failure() ) {
			self::fail( $inspection->error->message );
		}

		self::assertSame( $raw, $inspection->value['raw'] ?? null );
		self::assertNull( $inspection->value['state'] ?? null );
		self::assertSame( 0, RunStoreWakeupProbe::$wakeups );
	}

	/**
	 * Missing and malformed option values cannot hydrate a typed run.
	 *
	 * @return  void
	 */
	public function test_get_returns_null_for_missing_and_malformed_options(): void {
		$clock = new FixedClock( 123 );
		$store = new RunStore( self::identity( 'corruption' ), $clock, $this->rows );
		$key   = 'a8csp_bgte_run_runs-tests:corruption_run-bad';

		self::assertNull( $store->get( 'run-bad' ) );

		$malformed_values = array(
			'not an array',
			array( 'status' => 'running' ),
			array(
				'status'          => 'unknown',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array(),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
			),
			array(
				'status'          => 'running',
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array(),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
			),
			array(
				'status'          => 'running',
				'executing'       => 'false',
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array(),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
			),
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array( 'not-a-list' => array() ),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
			),
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array( 'not-an-array' ),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
			),
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => 'not-an-array',
				'args_hash'       => 'hash',
				'queue'           => array(),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
			),
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => false,
				'queue'           => array(),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
			),
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array(),
				'failed_attempts' => '0',
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
			),
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array(),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1.0,
				'heartbeat_at'    => 1,
			),
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array(),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => '1',
			),
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array(),
				'failed_attempts' => 0,
				'action_seq'      => '1',
				'created_at'      => 1,
				'heartbeat_at'    => 1,
			),
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array(),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
				'pending'         => null,
			),
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array(),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
				'pending'         => array(
					'stage'    => 'run',
					'mode'     => 'single',
					'priority' => 10,
				),
			),
			array(
				'status'          => 'running',
				'executing'       => false,
				'start_args'      => array(),
				'args_hash'       => 'hash',
				'queue'           => array(),
				'failed_attempts' => 0,
				'action_seq'      => 1,
				'created_at'      => 1,
				'heartbeat_at'    => 1,
				'pending'         => array(
					'stage'    => 'unknown',
					'mode'     => 'async',
					'fire_at'  => null,
					'priority' => 10,
				),
			),
		);

		foreach ( $malformed_values as $value ) {
			$GLOBALS['a8csp_bgte_test_options'] = array( $key => $value );

			self::assertNull( $store->get( 'run-bad' ) );
		}
	}

	/**
	 * Pending descriptors require the exact canonical field set and mode-specific fire time.
	 *
	 * @return  void
	 */
	public function test_get_rejects_noncanonical_pending_descriptors(): void {
		$store           = new RunStore( self::identity( 'corruption' ), new FixedClock( 123 ), $this->rows );
		$key             = 'a8csp_bgte_run_runs-tests:corruption_run-bad';
		$state           = array(
			'status'          => 'running',
			'executing'       => false,
			'start_args'      => array(),
			'args_hash'       => 'hash',
			'queue'           => array(),
			'failed_attempts' => 0,
			'action_seq'      => 1,
			'created_at'      => 1,
			'heartbeat_at'    => 1,
		);
		$invalid_pending = array(
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
				'stage'    => 'start',
				'mode'     => 'single',
				'fire_at'  => 2,
				'priority' => 10,
			),
			array(
				'stage'    => 'cleanup',
				'mode'     => 'single',
				'fire_at'  => 2,
				'priority' => 10,
			),
		);

		foreach ( $invalid_pending as $pending ) {
			$GLOBALS['a8csp_bgte_test_options'] = array(
				$key => array(
					...$state,
					'pending' => $pending,
				),
			);

			self::assertNull( $store->get( 'run-bad' ) );
		}
	}

	/** Optional terminal metadata must use its exact canonical nested shapes. */
	public function test_get_rejects_noncanonical_terminal_metadata(): void {
		$store            = new RunStore( self::identity( 'corruption' ), new FixedClock( 123 ), $this->rows );
		$key              = 'a8csp_bgte_run_runs-tests:corruption_run-bad';
		$state            = array(
			'status'          => 'failed',
			'executing'       => false,
			'start_args'      => array(),
			'args_hash'       => 'hash',
			'queue'           => array(),
			'failed_attempts' => 0,
			'action_seq'      => 1,
			'created_at'      => 1,
			'heartbeat_at'    => 1,
		);
		$invalid_metadata = array(
			array( 'error' => null ),
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
					'class'   => null,
					'message' => 'Failure.',
					'extra'   => true,
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

		foreach ( $invalid_metadata as $metadata ) {
			$GLOBALS['a8csp_bgte_test_options'] = array( $key => array( ...$state, ...$metadata ) );

			self::assertNull( $store->get( 'run-bad' ) );
		}
	}

	/**
	 * Asserts every persisted RunState field independently.
	 *
	 * @param   RunState $expected Expected state.
	 * @param   RunState $actual   Actual state.
	 *
	 * @return  void
	 */
	private static function assert_state_same( RunState $expected, RunState $actual ): void {
		self::assertSame( $expected->status, $actual->status );
		self::assertSame( $expected->executing, $actual->executing );
		self::assertSame( $expected->start_args, $actual->start_args );
		self::assertSame( $expected->args_hash, $actual->args_hash );
		self::assertSame( $expected->queue, $actual->queue );
		self::assertSame( $expected->failed_attempts, $actual->failed_attempts );
		self::assertSame( $expected->action_seq, $actual->action_seq );
		self::assertSame( $expected->created_at, $actual->created_at );
		self::assertSame( $expected->heartbeat_at, $actual->heartbeat_at );
		self::assertEquals( $expected->pending, $actual->pending );
		self::assertSame( $expected->error, $actual->error );
		self::assertSame( $expected->effects, $actual->effects );
	}

	/**
	 * Returns one stored option value.
	 *
	 * @param   string $option_name Option name.
	 *
	 * @return  mixed
	 */
	private function option( string $option_name ): mixed {
		$options = $this->options();

		return $options[ $option_name ] ?? null;
	}

	/**
	 * Returns the recorded autoload flag for one option.
	 *
	 * @param   string $option_name Option name.
	 *
	 * @return  mixed
	 */
	private function autoload_flag( string $option_name ): mixed {
		$autoload_flags = $GLOBALS['a8csp_bgte_test_option_autoload'] ?? null;
		self::assertIsArray( $autoload_flags );

		return $autoload_flags[ $option_name ] ?? null;
	}

	/**
	 * Returns the current option ledger.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function options(): array {
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );

		return $options;
	}

	/**
	 * Returns one run state after asserting that it remains recoverable.
	 *
	 * @param   RunStore $store  Run store.
	 * @param   string   $run_id Run identifier.
	 *
	 * @return  RunState
	 */
	private function stored_state( RunStore $store, string $run_id ): RunState {
		$state = $store->get( $run_id );
		self::assertNotNull( $state );

		return $state;
	}

	/**
	 * Returns calls for one option function in recording order.
	 *
	 * @param   string $function_name Function name.
	 *
	 * @return  list<array{function: string, args: list<mixed>}>
	 */
	private function option_calls( string $function_name ): array {
		return \array_values( \array_filter( $this->all_option_calls(), static fn ( array $call ): bool => $function_name === $call['function'] ) );
	}

	/**
	 * Returns every recorded option-function call.
	 *
	 * @return  list<array{function: string, args: list<mixed>}>
	 */
	private function all_option_calls(): array {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls = $GLOBALS['a8csp_bgte_test_option_calls'];

		return $calls;
	}
}
