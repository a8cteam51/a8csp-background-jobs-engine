<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\ClaimResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins execution-overlap ownership, liveness, reclaim, and release behavior.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( OverlapGuard::class )]
#[UsesClass( ClaimResult::class )]
final class OverlapGuardTest extends TestCase {
	private const ARGS_HASH = 'args-123';
	private const KEY       = 'a8csp_bgte_lock_email-digest_args-123';
	private const NAME      = 'email-digest';

	/**
	 * Loads guarded WordPress option functions before the guard is autoloaded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/wp-options-stubs.php';
	}

	/**
	 * Resets request-local option and interleaving state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']         = array();
		$GLOBALS['a8csp_bgte_test_option_calls']    = array();
		$GLOBALS['a8csp_bgte_test_option_autoload'] = array();
		unset( $GLOBALS['a8csp_bgte_test_before_add_option'] );
	}

	/**
	 * Clears a scripted interleaving even when a race assertion fails.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function tearDown(): void {
		unset( $GLOBALS['a8csp_bgte_test_before_add_option'] );

		parent::tearDown();
	}

	/**
	 * Claim outcomes expose only the three lowercase-backed contract states.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_claim_result_pins_cases_and_backing_values(): void {
		self::assertSame(
			array( ClaimResult::Claimed, ClaimResult::Reclaimed, ClaimResult::Held ),
			ClaimResult::cases()
		);
		self::assertSame(
			array( 'claimed', 'reclaimed', 'held' ),
			\array_column( ClaimResult::cases(), 'value' )
		);
	}

	/**
	 * A fresh claim inserts the exact lock schema under the literal non-autoloaded key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_fresh_claim_inserts_the_literal_non_autoloaded_lock(): void {
		$result = self::guard_at( 1_700_000_100 )->claim(
			self::NAME,
			self::ARGS_HASH,
			'run-new',
			900
		);

		$expected_lock = array(
			'run_id'       => 'run-new',
			'claimed_at'   => 1_700_000_100,
			'heartbeat_at' => 1_700_000_100,
		);

		self::assertSame( ClaimResult::Claimed, $result );
		self::assertSame( $expected_lock, $this->lock() );
		self::assertSame( false, $this->autoload_flag() );
		self::assertSame(
			array(
				array(
					'function' => 'add_option',
					'args'     => array( self::KEY, $expected_lock, '', false ),
				),
			),
			$this->all_option_calls()
		);
	}

	/**
	 * A live foreign owner blocks a claim without changing its lock row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_claim_returns_held_and_leaves_a_fresh_foreign_lock_untouched(): void {
		$foreign_lock = array(
			'run_id'       => 'run-live',
			'claimed_at'   => 1_700_000_000,
			'heartbeat_at' => 1_700_000_090,
		);
		$this->store_lock( $foreign_lock );

		$result = self::guard_at( 1_700_000_100 )->claim(
			self::NAME,
			self::ARGS_HASH,
			'run-new',
			900
		);

		self::assertSame( ClaimResult::Held, $result );
		self::assertSame( $foreign_lock, $this->lock() );
		self::assertCount( 1, $this->option_calls( 'add_option' ) );
		self::assertSame( array(), $this->option_calls( 'update_option' ) );
		self::assertSame( array(), $this->option_calls( 'delete_option' ) );
	}

	/**
	 * The next claimant replaces a stale owner and reports the dead run through the log channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_claim_reclaims_a_stale_lock_and_logs_the_dead_run(): void {
		$logger = new RecordingLogger();
		$this->store_lock(
			array(
				'run_id'       => 'run-dead',
				'claimed_at'   => 1_699_999_000,
				'heartbeat_at' => 1_699_999_199,
			)
		);

		$result = self::guard_at( 1_700_000_100, $logger )->claim(
			self::NAME,
			self::ARGS_HASH,
			'run-new',
			900
		);

		self::assertSame( ClaimResult::Reclaimed, $result );
		self::assertSame(
			array(
				'run_id'       => 'run-new',
				'claimed_at'   => 1_700_000_100,
				'heartbeat_at' => 1_700_000_100,
			),
			$this->lock()
		);
		self::assertSame( false, $this->autoload_flag() );
		self::assertSame(
			array( 'add_option', 'delete_option', 'add_option' ),
			\array_column( $this->all_option_calls(), 'function' )
		);
		self::assertSame( false, $this->option_calls( 'add_option' )[0]['args'][3] );
		self::assertSame( false, $this->option_calls( 'add_option' )[1]['args'][3] );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Reclaimed stale execution-overlap lock.',
					'context' => array(
						'name'        => self::NAME,
						'args_hash'   => self::ARGS_HASH,
						'dead_run_id' => 'run-dead',
						'run_id'      => 'run-new',
					),
				),
			),
			$logger->records
		);
	}

	/**
	 * A rival that inserts after deletion owns the row and turns this reclaim attempt into Held.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_claim_returns_held_when_the_reclaim_race_is_lost(): void {
		$logger = new RecordingLogger();
		$this->store_lock(
			array(
				'run_id'       => 'run-dead',
				'claimed_at'   => 100,
				'heartbeat_at' => 100,
			)
		);

		$add_attempts = 0;
		$before_add   = function ( string $option ) use ( &$add_attempts ): void {
			++$add_attempts;
			if ( 2 !== $add_attempts ) {
				return;
			}

			self::assertSame( self::KEY, $option );
			$this->store_lock(
				array(
					'run_id'       => 'run-rival',
					'claimed_at'   => 1_000,
					'heartbeat_at' => 1_000,
				)
			);
		};

		$GLOBALS['a8csp_bgte_test_before_add_option'] = $before_add;

		$result = self::guard_at( 1_000, $logger )->claim( self::NAME, self::ARGS_HASH, 'run-new', 100 );

		self::assertSame( ClaimResult::Held, $result );
		self::assertSame(
			array(
				'run_id'       => 'run-rival',
				'claimed_at'   => 1_000,
				'heartbeat_at' => 1_000,
			),
			$this->lock()
		);
		self::assertSame( false, $this->autoload_flag() );
		self::assertSame(
			array( 'add_option', 'delete_option', 'add_option' ),
			\array_column( $this->all_option_calls(), 'function' )
		);
		self::assertSame( array(), $logger->records );
	}

	/**
	 * A fresh owner can claim idempotently while retaining its original claim timestamp.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_same_run_reclaim_refreshes_the_heartbeat_and_returns_claimed(): void {
		$first_result = self::guard_at( 100 )->claim( self::NAME, self::ARGS_HASH, 'run-owner', 900 );
		self::assertSame( ClaimResult::Claimed, $first_result );

		$result = self::guard_at( 200 )->claim( self::NAME, self::ARGS_HASH, 'run-owner', 900 );

		self::assertSame( ClaimResult::Claimed, $result );
		self::assertSame(
			array(
				'run_id'       => 'run-owner',
				'claimed_at'   => 100,
				'heartbeat_at' => 200,
			),
			$this->lock()
		);
		self::assertSame( false, $this->autoload_flag() );
		self::assertCount( 1, $this->option_calls( 'update_option' ) );
	}

	/**
	 * Heartbeat refreshes an owned row without changing its claim timestamp.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_heartbeat_refreshes_only_the_owned_rows_liveness_timestamp(): void {
		$this->store_lock(
			array(
				'run_id'       => 'run-owner',
				'claimed_at'   => 100,
				'heartbeat_at' => 120,
			)
		);

		self::guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame(
			array(
				'run_id'       => 'run-owner',
				'claimed_at'   => 100,
				'heartbeat_at' => 200,
			),
			$this->lock()
		);
		self::assertSame( false, $this->autoload_flag() );
	}

	/**
	 * Heartbeat leaves a foreign lock byte-for-byte unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_heartbeat_does_not_touch_a_foreign_lock(): void {
		$foreign_lock = array(
			'run_id'       => 'run-rival',
			'claimed_at'   => 100,
			'heartbeat_at' => 120,
		);
		$this->store_lock( $foreign_lock );

		self::guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( $foreign_lock, $this->lock() );
		self::assertSame( array(), $this->all_option_calls() );
	}

	/**
	 * Heartbeat does not create an absent lock row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_heartbeat_is_a_no_op_when_the_lock_is_absent(): void {
		self::guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertNull( $this->lock() );
		self::assertSame( array(), $this->all_option_calls() );
	}

	/**
	 * Release deletes a lock owned by the terminating run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_release_deletes_an_owned_lock(): void {
		$this->store_lock(
			array(
				'run_id'       => 'run-owner',
				'claimed_at'   => 100,
				'heartbeat_at' => 120,
			)
		);

		self::guard_at( 200 )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertNull( $this->lock() );
		self::assertCount( 1, $this->option_calls( 'delete_option' ) );
	}

	/**
	 * Release leaves a foreign lock byte-for-byte unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_release_does_not_delete_a_foreign_lock(): void {
		$foreign_lock = array(
			'run_id'       => 'run-rival',
			'claimed_at'   => 100,
			'heartbeat_at' => 120,
		);
		$this->store_lock( $foreign_lock );

		self::guard_at( 200 )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( $foreign_lock, $this->lock() );
		self::assertSame( array(), $this->all_option_calls() );
	}

	/**
	 * Release does not write when no lock exists.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_release_is_a_no_op_when_the_lock_is_absent(): void {
		self::guard_at( 200 )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertNull( $this->lock() );
		self::assertSame( array(), $this->all_option_calls() );
	}

	/**
	 * Held-state reads distinguish a fresh row, an expired row, and no row without writing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_is_held_reports_fresh_stale_and_absent_locks(): void {
		$guard = self::guard_at( 1_000 );
		$this->store_lock(
			array(
				'run_id'       => 'run-owner',
				'claimed_at'   => 100,
				'heartbeat_at' => 901,
			)
		);

		self::assertTrue( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );

		$this->store_lock(
			array(
				'run_id'       => 'run-owner',
				'claimed_at'   => 100,
				'heartbeat_at' => 899,
			)
		);

		self::assertFalse( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );

		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		unset( $options[ self::KEY ] );
		$GLOBALS['a8csp_bgte_test_options'] = $options;

		self::assertFalse( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );
		self::assertSame( array(), $this->all_option_calls() );
	}

	/**
	 * Held-state reads reject rows outside the lock's exact three-field schema.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_is_held_rejects_a_lock_row_with_extra_fields(): void {
		$GLOBALS['a8csp_bgte_test_options'] = array(
			self::KEY => array(
				'run_id'       => 'run-owner',
				'claimed_at'   => 100,
				'heartbeat_at' => 200,
				'extra'        => true,
			),
		);

		self::assertFalse( self::guard_at( 200 )->is_held( self::NAME, self::ARGS_HASH, 100 ) );
		self::assertSame( array(), $this->all_option_calls() );
	}

	/**
	 * A heartbeat exactly one window old remains fresh until one more second elapses.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_staleness_requires_heartbeat_age_to_exceed_the_given_window(): void {
		$boundary_lock = array(
			'run_id'       => 'run-owner',
			'claimed_at'   => 100,
			'heartbeat_at' => 900,
		);
		$this->store_lock( $boundary_lock );

		$boundary_guard = self::guard_at( 1_000 );

		self::assertTrue( $boundary_guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );
		self::assertSame(
			ClaimResult::Held,
			$boundary_guard->claim( self::NAME, self::ARGS_HASH, 'run-new', 100 )
		);
		self::assertSame( $boundary_lock, $this->lock() );
		self::assertFalse( self::guard_at( 1_001 )->is_held( self::NAME, self::ARGS_HASH, 100 ) );
	}

	/**
	 * Returns a guard with a deterministic timestamp source.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int                  $timestamp Current Unix timestamp.
	 * @param   RecordingLogger|null $logger    Optional log recorder.
	 *
	 * @return  OverlapGuard
	 */
	private static function guard_at( int $timestamp, ?RecordingLogger $logger = null ): OverlapGuard {
		return new OverlapGuard(
			new FixedClock( $timestamp ),
			$logger ?? new RecordingLogger()
		);
	}

	/**
	 * Stores one lock row directly for a deterministic precondition.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{run_id: string, claimed_at: int, heartbeat_at: int} $lock Lock row.
	 *
	 * @return  void
	 */
	private function store_lock( array $lock ): void {
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		$options[ self::KEY ]               = $lock;
		$GLOBALS['a8csp_bgte_test_options'] = $options;

		$autoload_flags = $GLOBALS['a8csp_bgte_test_option_autoload'] ?? null;
		self::assertIsArray( $autoload_flags );
		$autoload_flags[ self::KEY ]                = false;
		$GLOBALS['a8csp_bgte_test_option_autoload'] = $autoload_flags;
	}

	/**
	 * Returns the current lock row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  mixed
	 */
	private function lock(): mixed {
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );

		return $options[ self::KEY ] ?? null;
	}

	/**
	 * Returns the lock row's recorded autoload policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  mixed
	 */
	private function autoload_flag(): mixed {
		$autoload_flags = $GLOBALS['a8csp_bgte_test_option_autoload'] ?? null;
		self::assertIsArray( $autoload_flags );

		return $autoload_flags[ self::KEY ] ?? null;
	}

	/**
	 * Returns calls for one option function in recording order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{function: string, args: list<mixed>}>
	 */
	private function all_option_calls(): array {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls = $GLOBALS['a8csp_bgte_test_option_calls'];

		return $calls;
	}
}
