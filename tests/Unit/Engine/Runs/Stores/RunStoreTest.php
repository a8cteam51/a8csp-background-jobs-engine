<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins consolidated run-option persistence and typed read-modify-write state.
 *
 */
#[CoversClass( RunStore::class )]
#[UsesClass( RunState::class )]
#[UsesClass( RunStatus::class )]
#[UsesClass( RawOptionDecoder::class )]
final class RunStoreTest extends TestCase {
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

	/**
	 * Creation persists the exact schema and hydrates every typed field unchanged.
	 *
	 * @return  void
	 */
	public function test_create_get_round_trip_pins_schema_key_and_clock_stamps(): void {
		$clock = new FixedClock( 1_700_000_100 );
		$store = new RunStore( 'email-digest', $clock, $this->rows );
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
		self::assertSame(
			array(
				'status'        => 'running',
				'executing'     => false,
				'start_args'    => array( 'site_id' => 7 ),
				'args_hash'     => 'hash-a',
				'queue'         => array(
					array( 'page' => 1 ),
					array( 'page' => 2 ),
				),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => 1_700_000_100,
				'heartbeat_at'  => 1_700_000_100,
			),
			$this->option( 'a8csp_bgte_run_email-digest_run-123' )
		);
		self::assertSame( false, $this->autoload_flag( 'a8csp_bgte_run_email-digest_run-123' ) );

		$add_calls = $this->option_calls( 'add_option' );
		self::assertCount( 1, $add_calls );
		self::assertSame( 'a8csp_bgte_run_email-digest_run-123', $add_calls[0]['args'][0] );
		self::assertSame( '', $add_calls[0]['args'][2] );
		self::assertSame( false, $add_calls[0]['args'][3] );
	}

	/**
	 * Creation failure leaves an existing run option untouched and returns no state.
	 *
	 * @return  void
	 */
	public function test_create_reports_failure_without_overwriting_an_existing_option(): void {
		$clock = new FixedClock( 123 );

		$key = 'a8csp_bgte_run_reports_run-existing';

		$GLOBALS['a8csp_bgte_test_options'] = array( $key => 'existing value' );

		$store = new RunStore( 'reports', $clock, $this->rows );

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
		$store = new RunStore( 'reports', $clock, $this->rows );
		$state = $store->create(
			'run-rmw',
			array( 'scope' => 'all' ),
			'hash-rmw',
			array( array( 'page' => 1 ), array( 'page' => 2 ) ),
		);
		self::assertNotNull( $state );

		$replacement = $state->with_queue( \array_slice( $state->queue, 1 ) );
		self::assertIsString( $store->transition_state( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( array( array( 'page' => 2 ) ), $this->stored_state( $store, 'run-rmw' )->queue );

		$queue       = $state->queue;
		$queue[]     = array( 'page' => 3 );
		$replacement = $state->with_queue( $queue );
		self::assertIsString( $store->transition_state( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( array( array( 'page' => 2 ), array( 'page' => 3 ) ), $this->stored_state( $store, 'run-rmw' )->queue );

		$queue = $state->queue;
		\array_unshift( $queue, array( 'page' => 0 ) );
		$replacement = $state->with_queue( $queue );
		self::assertIsString( $store->transition_state( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame(
			array( array( 'page' => 0 ), array( 'page' => 2 ), array( 'page' => 3 ) ),
			$this->stored_state( $store, 'run-rmw' )->queue
		);

		$replacement = $state->with_chunk_retries( $state->chunk_retries + 1 );
		self::assertIsString( $store->transition_state( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( 1, $this->stored_state( $store, 'run-rmw' )->chunk_retries );

		$replacement = $state->with_chunk_retries( 0 );
		self::assertIsString( $store->transition_state( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( 0, $this->stored_state( $store, 'run-rmw' )->chunk_retries );

		$replacement = $state->with_action_seq( 2 );
		self::assertIsString( $store->transition_state( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( 2, $this->stored_state( $store, 'run-rmw' )->action_seq );

		$replacement = $state->with_executing( true );
		self::assertIsString( $store->transition_state( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertTrue( $this->stored_state( $store, 'run-rmw' )->executing );

		$replacement = $state->with_executing( false );
		self::assertIsString( $store->transition_state( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertFalse( $this->stored_state( $store, 'run-rmw' )->executing );

		$replacement = $state->with_status( RunStatus::Failed );
		self::assertIsString( $store->transition_state( 'run-rmw', $state, $replacement ) );
		$state = $replacement;
		self::assertSame( RunStatus::Failed, $this->stored_state( $store, 'run-rmw' )->status );

		$clock->timestamp = 200;
		$state            = $store->refresh_heartbeat( 'run-rmw', $state );
		self::assertNotNull( $state );
		self::assertTrue( $this->stored_state( $store, 'run-rmw' )->executing );
		self::assertSame( 200, $this->stored_state( $store, 'run-rmw' )->heartbeat_at );
		self::assertSame( 100, $this->stored_state( $store, 'run-rmw' )->created_at );

		self::assertSame( array(), $this->option_calls( 'update_option' ) );
	}

	/** A stale live-state writer loses after an exact transition or terminal deletion. */
	public function test_state_transition_never_recreates_or_overwrites_a_lost_snapshot(): void {
		$store = new RunStore( 'fenced-live', new FixedClock( 200 ), $this->rows );
		$state = $store->create( 'run-live', array(), 'hash', array() );
		self::assertNotNull( $state );

		$newer = $state->with_action_seq( 2 );
		self::assertIsString( $store->transition_state( 'run-live', $state, $newer ) );
		self::assertNull( $store->transition_state( 'run-live', $state, $state->with_action_seq( 3 ) ) );
		self::assertSame( 2, $store->get( 'run-live' )?->action_seq );

		$snapshot = $store->inspect( 'run-live' );
		self::assertNotNull( $snapshot );
		self::assertTrue( $store->delete_exact( 'run-live', $snapshot['raw'] ) );
		self::assertNull( $store->transition_state( 'run-live', $newer, $newer->with_action_seq( 3 ) ) );
		self::assertNull( $store->get( 'run-live' ) );
	}

	/**
	 * Heartbeat refresh leaves missing and corrupted options untouched.
	 *
	 * @return  void
	 */
	public function test_refresh_heartbeat_does_not_recreate_unrecoverable_runs(): void {
		$clock = new FixedClock( 200 );
		$store = new RunStore( 'heartbeat', $clock, $this->rows );

		self::assertNull( $store->refresh_heartbeat( 'missing' ) );
		self::assertSame( 0, $clock->calls );
		self::assertSame( array(), $this->all_option_calls() );

		$key = 'a8csp_bgte_run_heartbeat_corrupted';

		$GLOBALS['a8csp_bgte_test_options'] = array( $key => 'corrupted' );

		self::assertNull( $store->refresh_heartbeat( 'corrupted' ) );
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
		$store = new RunStore( 'cleanup', $clock, $this->rows );
		$store->create( 'run-delete', array(), 'hash', array() );

		$store->delete( 'run-delete' );

		self::assertNull( $store->get( 'run-delete' ) );
		self::assertArrayNotHasKey( 'a8csp_bgte_run_cleanup_run-delete', $this->options() );
		self::assertSame(
			array( 'a8csp_bgte_run_cleanup_run-delete' ),
			$this->option_calls( 'delete_option' )[0]['args']
		);
	}

	/** Terminal transitions and cleanup win only against the exact observed raw snapshots. */
	public function test_transition_and_exact_delete_are_value_conditioned(): void {
		$clock = new FixedClock( 123 );
		$store = new RunStore( 'fenced', $clock, $this->rows );
		$state = $store->create( 'run-fenced', array(), 'hash', array() );
		self::assertNotNull( $state );

		$running = $store->inspect( 'run-fenced' );
		self::assertNotNull( $running );
		self::assertNotNull( $running['state'] );

		$terminal     = $state->with_status( RunStatus::Completed );
		$terminal_raw = $store->transition( 'run-fenced', $running['raw'], $terminal );
		self::assertIsString( $terminal_raw );
		self::assertSame( RunStatus::Completed, $store->get( 'run-fenced' )?->status );
		self::assertNull( $store->transition( 'run-fenced', $running['raw'], $terminal ) );
		self::assertTrue( $store->delete_exact( 'run-fenced', $terminal_raw ) );
		self::assertFalse( $store->delete_exact( 'run-fenced', $terminal_raw ) );
		self::assertNull( $store->transition( 'run-fenced', $terminal_raw, $terminal ) );
		self::assertNull( $store->get( 'run-fenced' ) );
	}

	/** Raw inspection retains corrupt bytes so maintenance can exact-delete only that snapshot. */
	public function test_inspect_exposes_a_corrupt_raw_snapshot_for_exact_deletion(): void {
		$key                                = 'a8csp_bgte_run_corruption_run-corrupt';
		$options                            = $this->options();
		$options[ $key ]                    = 'corrupt-raw';
		$GLOBALS['a8csp_bgte_test_options'] = $options;
		$store                              = new RunStore( 'corruption', new FixedClock( 123 ), $this->rows );

		$snapshot = $store->inspect( 'run-corrupt' );

		self::assertNotNull( $snapshot );
		self::assertSame( 'corrupt-raw', $snapshot['raw'] );
		self::assertNull( $snapshot['state'] );
		self::assertTrue( $store->delete_exact( 'run-corrupt', $snapshot['raw'] ) );
		self::assertNull( $store->inspect( 'run-corrupt' ) );
	}

	/**
	 * Missing and malformed option values cannot hydrate a typed run.
	 *
	 * @return  void
	 */
	public function test_get_returns_null_for_missing_and_malformed_options(): void {
		$clock = new FixedClock( 123 );
		$store = new RunStore( 'corruption', $clock, $this->rows );
		$key   = 'a8csp_bgte_run_corruption_run-bad';

		self::assertNull( $store->get( 'run-bad' ) );

		$malformed_values = array(
			'not an array',
			array( 'status' => 'running' ),
			array(
				'status'        => 'unknown',
				'executing'     => false,
				'start_args'    => array(),
				'args_hash'     => 'hash',
				'queue'         => array(),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => 1,
				'heartbeat_at'  => 1,
			),
			array(
				'status'        => 'running',
				'start_args'    => array(),
				'args_hash'     => 'hash',
				'queue'         => array(),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => 1,
				'heartbeat_at'  => 1,
			),
			array(
				'status'        => 'running',
				'executing'     => 'false',
				'start_args'    => array(),
				'args_hash'     => 'hash',
				'queue'         => array(),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => 1,
				'heartbeat_at'  => 1,
			),
			array(
				'status'        => 'running',
				'executing'     => false,
				'start_args'    => array(),
				'args_hash'     => 'hash',
				'queue'         => array( 'not-a-list' => array() ),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => 1,
				'heartbeat_at'  => 1,
			),
			array(
				'status'        => 'running',
				'executing'     => false,
				'start_args'    => array(),
				'args_hash'     => 'hash',
				'queue'         => array( 'not-an-array' ),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => 1,
				'heartbeat_at'  => 1,
			),
			array(
				'status'        => 'running',
				'executing'     => false,
				'start_args'    => 'not-an-array',
				'args_hash'     => 'hash',
				'queue'         => array(),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => 1,
				'heartbeat_at'  => 1,
			),
			array(
				'status'        => 'running',
				'executing'     => false,
				'start_args'    => array(),
				'args_hash'     => false,
				'queue'         => array(),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => 1,
				'heartbeat_at'  => 1,
			),
			array(
				'status'        => 'running',
				'executing'     => false,
				'start_args'    => array(),
				'args_hash'     => 'hash',
				'queue'         => array(),
				'chunk_retries' => '0',
				'action_seq'    => 1,
				'created_at'    => 1,
				'heartbeat_at'  => 1,
			),
			array(
				'status'        => 'running',
				'executing'     => false,
				'start_args'    => array(),
				'args_hash'     => 'hash',
				'queue'         => array(),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => 1.0,
				'heartbeat_at'  => 1,
			),
			array(
				'status'        => 'running',
				'executing'     => false,
				'start_args'    => array(),
				'args_hash'     => 'hash',
				'queue'         => array(),
				'chunk_retries' => 0,
				'action_seq'    => 1,
				'created_at'    => 1,
				'heartbeat_at'  => '1',
			),
			array(
				'status'        => 'running',
				'executing'     => false,
				'start_args'    => array(),
				'args_hash'     => 'hash',
				'queue'         => array(),
				'chunk_retries' => 0,
				'action_seq'    => '1',
				'created_at'    => 1,
				'heartbeat_at'  => 1,
			),
		);

		foreach ( $malformed_values as $value ) {
			$GLOBALS['a8csp_bgte_test_options'] = array( $key => $value );

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
		self::assertSame( $expected->chunk_retries, $actual->chunk_retries );
		self::assertSame( $expected->action_seq, $actual->action_seq );
		self::assertSame( $expected->created_at, $actual->created_at );
		self::assertSame( $expected->heartbeat_at, $actual->heartbeat_at );
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
		return \array_values(
			\array_filter(
				$this->all_option_calls(),
				static fn ( array $call ): bool => $function_name === $call['function']
			)
		);
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
