<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Locks;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\ClaimResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\MaintenanceFenceOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/** Detects whether lock-row decoding constructs a serialized class. */
final class LockRowWakeupProbe {
	public static bool $woke = false;

	/** Records an unsafe object construction during unserialization. */
	public function __wakeup(): void {
		self::$woke = true;
	}
}

/**
 * Pins execution-overlap ownership, liveness, reclaim, and release behavior.
 *
 */
#[CoversClass( OverlapGuard::class )]
#[UsesClass( ClaimResult::class )]
#[UsesClass( HeartbeatOutcome::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
final class OverlapGuardTest extends TestCase {
	private const ARGS_HASH = 'args-123';
	private const KEY       = 'a8csp_bgte_lock_email-digest_args-123';
	private const NAME      = 'email-digest';

	private WpdbLockSpy $wpdb;

	private OptionRows $rows;

	/** Loads guarded WordPress functions before production classes are autoloaded. */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-lock-stubs.php';
	}

	/** Resets the site, database, and cache state. */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_blog_id']     = 1;
		$GLOBALS['a8csp_bgte_test_cache']       = array();
		$GLOBALS['a8csp_bgte_test_cache_calls'] = array();
		$this->wpdb                             = new WpdbLockSpy();
		$this->rows                             = new OptionRows( $this->wpdb );
	}

	/** Claim outcomes expose only the three lowercase-backed contract states. */
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

	/** Heartbeat outcomes expose only the four lowercase-backed ownership states. */
	public function test_heartbeat_outcome_pins_cases_and_backing_values(): void {
		self::assertSame(
			array( HeartbeatOutcome::Owned, HeartbeatOutcome::Lost, HeartbeatOutcome::Stale, HeartbeatOutcome::Indeterminate ),
			HeartbeatOutcome::cases()
		);
		self::assertSame(
			array( 'owned', 'lost', 'stale', 'indeterminate' ),
			\array_column( HeartbeatOutcome::cases(), 'value' )
		);
	}

	/** An absent lock is claimed with the exact schema and non-autoload policy. */
	public function test_fresh_claim_inserts_the_literal_non_autoloaded_lock(): void {
		$result = $this->guard_at( 1_700_000_100 )->claim(
			self::NAME,
			self::ARGS_HASH,
			'run-new',
			900
		);

		self::assertSame( ClaimResult::Claimed, $result );
		self::assertSame( self::row( 'run-new', 1_700_000_100, 1_700_000_100 ), $this->lock() );
		self::assertSame( 'off', $this->wpdb->autoload[ self::KEY ] );
		self::assertSame( array( 'insert' ), $this->operations() );
	}

	/** A fresh foreign owner blocks a claim without changing its raw row. */
	public function test_claim_returns_held_and_leaves_a_fresh_foreign_lock_untouched(): void {
		$foreign = self::row( 'run-live', 1_700_000_000, 1_700_000_090 );
		$this->store_lock( $foreign );

		$result = $this->guard_at( 1_700_000_100 )->claim(
			self::NAME,
			self::ARGS_HASH,
			'run-new',
			900
		);

		self::assertSame( ClaimResult::Held, $result );
		self::assertSame( $foreign, $this->lock() );
		self::assertSame( array( 'insert', 'select' ), $this->operations() );
	}

	/** A failed claim read leaves the contended row held without attempting reclamation. */
	public function test_claim_returns_held_without_writing_after_read_failure(): void {
		$raw = self::raw( self::row( 'run-owner', 100, 120 ) );
		$this->wpdb->put( self::KEY, $raw );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient claim read failure';
			}
		);

		$result = $this->guard_at( 200 )->claim( self::NAME, self::ARGS_HASH, 'run-rival', 100 );

		self::assertSame( ClaimResult::Held, $result );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'insert', 'select' ), $this->operations() );
	}

	/**
	 * A fresh lock is replaced only while its exact selected row is unchanged.
	 *
	 * @return  void
	 */
	public function test_replace_moves_a_fresh_lock_to_the_replacement(): void {
		$this->store_lock( self::row( 'run-live', 1_700_000_000, 1_700_000_090 ) );

		$replaced = $this->guard_at( 1_700_000_100 )->replace(
			self::NAME,
			self::ARGS_HASH,
			'run-new'
		);

		self::assertTrue( $replaced );
		self::assertSame( self::row( 'run-new', 1_700_000_100, 1_700_000_100 ), $this->lock() );
		self::assertSame( array( 'select', 'update' ), $this->operations() );
	}

	/**
	 * A replacement that loses its row CAS cannot displace the winner.
	 *
	 * @return  void
	 */
	public function test_replace_cas_loser_cannot_displace_the_winner(): void {
		$winner_raw = self::raw( self::row( 'run-winner', 1_700_000_100, 1_700_000_100 ) );
		$this->store_lock( self::row( 'run-live', 1_700_000_000, 1_700_000_090 ) );
		$this->wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $database ) use ( $winner_raw ): void {
				$database->put( self::KEY, $winner_raw );
			}
		);

		$replaced = $this->guard_at( 1_700_000_100 )->replace(
			self::NAME,
			self::ARGS_HASH,
			'run-new'
		);

		self::assertFalse( $replaced );
		self::assertSame( $winner_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select', 'update' ), $this->operations() );
	}

	/** A stale owner is value-conditionally replaced and reported through the warning channel. */
	public function test_claim_reclaims_a_stale_lock_and_logs_the_dead_run(): void {
		$logger = new RecordingLogger();
		$this->store_lock( self::row( 'run-dead', 1_699_999_000, 1_699_999_199 ) );

		$result = $this->guard_at( 1_700_000_100, $logger )->claim(
			self::NAME,
			self::ARGS_HASH,
			'run-new',
			900
		);

		self::assertSame( ClaimResult::Reclaimed, $result );
		self::assertSame( self::row( 'run-new', 1_700_000_100, 1_700_000_100 ), $this->lock() );
		self::assertSame( array( 'insert', 'select', 'delete', 'insert' ), $this->operations() );
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

	/** A rival that inserts after deletion owns the row and makes the reclaim attempt Held. */
	public function test_claim_returns_held_when_the_post_delete_insert_race_is_lost(): void {
		$logger = new RecordingLogger();
		$this->store_lock( self::row( 'run-dead', 100, 100 ) );
		$this->wpdb->before_next( 'insert', static function (): void {} );
		$this->wpdb->before_next(
			'insert',
			static function ( WpdbLockSpy $database ): void {
				$database->put( self::KEY, self::raw( self::row( 'run-rival', 1_000, 1_000 ) ) );
			}
		);

		$result = $this->guard_at( 1_000, $logger )->claim(
			self::NAME,
			self::ARGS_HASH,
			'run-new',
			100
		);

		self::assertSame( ClaimResult::Held, $result );
		self::assertSame( self::row( 'run-rival', 1_000, 1_000 ), $this->lock() );
		self::assertSame( array(), $logger->records );
	}

	/** A stale delete cannot remove a fresh winner that replaces the selected raw row first. */
	public function test_stale_delete_cas_loser_cannot_delete_the_winners_row(): void {
		$stale_raw  = self::raw( self::row( 'run-dead', 100, 100 ) );
		$winner_raw = self::raw( self::row( 'run-winner', 1_000, 1_000 ) );
		$this->wpdb->put( self::KEY, $stale_raw );
		$this->wpdb->before_next(
			'delete',
			static function ( WpdbLockSpy $database ) use ( $winner_raw ): void {
				$database->put( self::KEY, $winner_raw );
			}
		);

		$result = $this->guard_at( 1_000 )->claim( self::NAME, self::ARGS_HASH, 'run-new', 100 );

		self::assertSame( ClaimResult::Held, $result );
		self::assertSame( $winner_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'insert', 'select', 'delete' ), $this->operations() );
	}

	/** Two stale claimants cannot both reclaim the same selected raw row. */
	public function test_dual_reclaimed_interleaving_is_impossible(): void {
		$this->store_lock( self::row( 'run-dead', 100, 100 ) );
		$guard_a  = $this->guard_at( 1_000 );
		$guard_b  = $this->guard_at( 1_000 );
		$a_result = null;
		$this->wpdb->before_next(
			'delete',
			function () use ( $guard_a, &$a_result ): void {
				$a_result = $guard_a->claim( self::NAME, self::ARGS_HASH, 'run-a', 100 );
			}
		);

		$b_result = $guard_b->claim( self::NAME, self::ARGS_HASH, 'run-b', 100 );

		self::assertSame( ClaimResult::Reclaimed, $a_result );
		self::assertSame( ClaimResult::Held, $b_result );
		self::assertSame( self::row( 'run-a', 1_000, 1_000 ), $this->lock() );
		self::assertSame(
			array( 'insert', 'select', 'insert', 'select', 'delete', 'insert', 'delete' ),
			$this->operations()
		);
	}

	/** A fresh owner reuses its selected raw row for one idempotent heartbeat update. */
	public function test_same_run_claim_refreshes_with_one_select_and_retains_claim_time(): void {
		$this->store_lock( self::row( 'run-owner', 100, 120 ) );

		$result = $this->guard_at( 200 )->claim( self::NAME, self::ARGS_HASH, 'run-owner', 900 );

		self::assertSame( ClaimResult::Claimed, $result );
		self::assertSame( self::row( 'run-owner', 100, 200 ), $this->lock() );
		self::assertSame( 1, $this->operation_count( 'select' ) );
		self::assertSame( array( 'insert', 'select', 'update' ), $this->operations() );
	}

	/** A malformed raw row is not held and is reclaimable with bounded diagnostic context. */
	public function test_malformed_row_is_consistently_reclaimable_and_logs_a_bounded_snippet(): void {
		$logger = new RecordingLogger();
		$raw    = \str_repeat( 'malformed-', 30 );
		$this->wpdb->put( self::KEY, $raw );
		$guard = $this->guard_at( 1_000, $logger );

		self::assertFalse( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );
		$result = $guard->claim( self::NAME, self::ARGS_HASH, 'run-new', 100 );

		self::assertSame( ClaimResult::Reclaimed, $result );
		self::assertSame( self::row( 'run-new', 1_000, 1_000 ), $this->lock() );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Reclaimed malformed execution-overlap lock.',
					'context' => array(
						'name'      => self::NAME,
						'args_hash' => self::ARGS_HASH,
						'malformed' => true,
						'raw_row'   => \substr( $raw, 0, 200 ),
						'run_id'    => 'run-new',
					),
				),
			),
			$logger->records
		);
		self::assertArrayNotHasKey( 'dead_run_id', $logger->records[0]['context'] );
	}

	/** A serialized object is malformed without constructing its class during reclaim. */
	public function test_malformed_object_row_is_reclaimed_without_class_construction(): void {
		LockRowWakeupProbe::$woke = false;
		$this->wpdb->put( self::KEY, self::raw( new LockRowWakeupProbe() ) );

		$result = $this->guard_at( 1_000 )->claim( self::NAME, self::ARGS_HASH, 'run-new', 100 );

		self::assertSame( ClaimResult::Reclaimed, $result );
		self::assertFalse( LockRowWakeupProbe::$woke );
		self::assertSame( self::row( 'run-new', 1_000, 1_000 ), $this->lock() );
	}

	/** Heartbeat refreshes only an owned row's liveness timestamp. */
	public function test_heartbeat_refreshes_only_the_owned_rows_liveness_timestamp(): void {
		$this->store_lock( self::row( 'run-owner', 100, 120 ) );

		$outcome = $this->guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( HeartbeatOutcome::Owned, $outcome );
		self::assertSame( self::row( 'run-owner', 100, 200 ), $this->lock() );
		self::assertSame( array( 'select', 'update' ), $this->operations() );
	}

	/**
	 * An explicit heartbeat timestamp keeps a retry lock held through its expected fire window.
	 *
	 * @return  void
	 */
	public function test_forward_dated_heartbeat_holds_until_after_expected_fire_plus_window(): void {
		$clock = new FixedClock( 200 );
		$guard = new OverlapGuard( $clock, new RecordingLogger(), $this->rows );

		$this->store_lock( self::row( 'run-owner', 100, 120 ) );

		self::assertSame(
			HeartbeatOutcome::Owned,
			$guard->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner', 1_000 )
		);
		self::assertSame( 0, $clock->calls );
		self::assertSame( self::row( 'run-owner', 100, 1_000 ), $this->lock() );
		self::assertSame(
			ClaimResult::Held,
			$this->guard_at( 500 )->claim( self::NAME, self::ARGS_HASH, 'run-rival', 100 )
		);
		self::assertSame(
			ClaimResult::Held,
			$this->guard_at( 1_100 )->claim( self::NAME, self::ARGS_HASH, 'run-rival', 100 )
		);
		self::assertSame(
			ClaimResult::Reclaimed,
			$this->guard_at( 1_101 )->claim( self::NAME, self::ARGS_HASH, 'run-rival', 100 )
		);
	}

	/** An identical-second heartbeat is confirmed after MySQL reports zero affected rows. */
	public function test_identical_second_heartbeat_confirms_the_unchanged_owned_row(): void {
		$row = self::row( 'run-owner', 100, 200 );
		$raw = self::raw( $row );
		$this->wpdb->put( self::KEY, $raw );

		$outcome = $this->guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( HeartbeatOutcome::Owned, $outcome );
		self::assertSame( $row, $this->lock() );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select', 'update', 'select' ), $this->operations() );
	}

	/** Heartbeat leaves a foreign lock byte-for-byte unchanged. */
	public function test_heartbeat_does_not_touch_a_foreign_lock(): void {
		$foreign_raw = self::raw( self::row( 'run-rival', 100, 120 ) );
		$this->wpdb->put( self::KEY, $foreign_raw );

		$outcome = $this->guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( HeartbeatOutcome::Lost, $outcome );
		self::assertSame( $foreign_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select' ), $this->operations() );
	}

	/** Heartbeat does not create an absent lock row. */
	public function test_heartbeat_is_a_no_op_when_the_lock_is_absent(): void {
		$outcome = $this->guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( HeartbeatOutcome::Lost, $outcome );
		self::assertArrayNotHasKey( self::KEY, $this->wpdb->rows );
		self::assertSame( array( 'select' ), $this->operations() );
	}

	/** A malformed heartbeat row cannot carry owned execution. */
	public function test_heartbeat_reports_lost_without_touching_a_malformed_lock(): void {
		$malformed_raw = 'not-a-lock-row';
		$this->wpdb->put( self::KEY, $malformed_raw );

		$outcome = $this->guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( HeartbeatOutcome::Lost, $outcome );
		self::assertSame( $malformed_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select' ), $this->operations() );
	}

	/** A failed heartbeat read leaves ownership indeterminate without attempting a refresh. */
	public function test_heartbeat_reports_indeterminate_without_refresh_after_read_failure(): void {
		$logger    = new RecordingLogger();
		$owned_raw = self::raw( self::row( 'run-owner', 100, 120 ) );
		$this->wpdb->put( self::KEY, $owned_raw );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient read failure';
			}
		);

		$outcome = $this->guard_at( 200, $logger )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( HeartbeatOutcome::Indeterminate, $outcome );
		self::assertSame( $owned_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select' ), $this->operations() );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Execution-overlap lock heartbeat could not read the authoritative lock row; ownership is indeterminate and the caller aborts without a terminal claim.',
					'context' => array(
						'key'       => self::KEY,
						'name'      => self::NAME,
						'args_hash' => self::ARGS_HASH,
						'run_id'    => 'run-owner',
					),
				),
			),
			$logger->records
		);
	}

	/** A heartbeat that loses its CAS leaves the replacement owner's row unchanged. */
	public function test_heartbeat_cas_loss_is_a_no_op(): void {
		$winner_raw = self::raw( self::row( 'run-winner', 200, 200 ) );
		$this->store_lock( self::row( 'run-owner', 100, 120 ) );
		$this->wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $database ) use ( $winner_raw ): void {
				$database->put( self::KEY, $winner_raw );
			}
		);

		$outcome = $this->guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( HeartbeatOutcome::Lost, $outcome );
		self::assertSame( $winner_raw, $this->wpdb->rows[ self::KEY ] );
	}

	/** An expected heartbeat refuses to shorten a newer generation owned by the same run. */
	public function test_heartbeat_rejects_a_newer_same_run_generation(): void {
		$newer_raw = self::raw( self::row( 'run-owner', 100, 300 ) );
		$this->store_lock( self::row( 'run-owner', 100, 120 ) );
		$this->wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $database ) use ( $newer_raw ): void {
				$database->put( self::KEY, $newer_raw );
			}
		);

		$outcome = $this->guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner', null, 120 );

		self::assertSame( HeartbeatOutcome::Stale, $outcome );
		self::assertSame( $newer_raw, $this->wpdb->rows[ self::KEY ] );
	}

	/** Release deletes a lock owned by the terminating run. */
	public function test_release_deletes_an_owned_lock(): void {
		$this->store_lock( self::row( 'run-owner', 100, 120 ) );

		$this->guard_at( 200 )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertArrayNotHasKey( self::KEY, $this->wpdb->rows );
		self::assertSame( array( 'select', 'delete' ), $this->operations() );
	}

	/** Release leaves a foreign lock byte-for-byte unchanged. */
	public function test_release_does_not_delete_a_foreign_lock(): void {
		$foreign_raw = self::raw( self::row( 'run-rival', 100, 120 ) );
		$this->wpdb->put( self::KEY, $foreign_raw );

		$this->guard_at( 200 )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( $foreign_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select' ), $this->operations() );
	}

	/** Release does not write when no lock exists. */
	public function test_release_is_a_no_op_when_the_lock_is_absent(): void {
		$this->guard_at( 200 )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertArrayNotHasKey( self::KEY, $this->wpdb->rows );
		self::assertSame( array( 'select' ), $this->operations() );
	}

	/** A failed release read leaves the lock for the staleness sweep and names the leaked key. */
	public function test_release_warns_that_the_staleness_sweep_reclaims_a_read_failure_leak(): void {
		$logger    = new RecordingLogger();
		$owned_raw = self::raw( self::row( 'run-owner', 100, 120 ) );
		$this->wpdb->put( self::KEY, $owned_raw );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient read failure';
			}
		);

		$this->guard_at( 200, $logger )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( $owned_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select' ), $this->operations() );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Execution-overlap lock release could not read the lock row; the staleness sweep reclaims the leaked key.',
					'context' => array(
						'key'       => self::KEY,
						'name'      => self::NAME,
						'args_hash' => self::ARGS_HASH,
						'run_id'    => 'run-owner',
					),
				),
			),
			$logger->records
		);
	}

	/** A release that loses its CAS leaves the replacement owner's row unchanged. */
	public function test_release_cas_loss_is_a_no_op(): void {
		$winner_raw = self::raw( self::row( 'run-winner', 200, 200 ) );
		$this->store_lock( self::row( 'run-owner', 100, 120 ) );
		$this->wpdb->before_next(
			'delete',
			static function ( WpdbLockSpy $database ) use ( $winner_raw ): void {
				$database->put( self::KEY, $winner_raw );
			}
		);

		$this->guard_at( 200 )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( $winner_raw, $this->wpdb->rows[ self::KEY ] );
	}

	/** Held-state reads distinguish fresh, stale, absent, and malformed rows without writing. */
	public function test_is_held_reports_only_parseable_fresh_locks(): void {
		$guard = $this->guard_at( 1_000 );
		$this->store_lock( self::row( 'run-owner', 100, 901 ) );
		self::assertTrue( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );

		$this->store_lock( self::row( 'run-owner', 100, 899 ) );
		self::assertFalse( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );

		unset( $this->wpdb->rows[ self::KEY ], $this->wpdb->autoload[ self::KEY ] );
		self::assertFalse( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );

		$this->wpdb->put( self::KEY, 'not-a-lock-row' );
		self::assertFalse( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );
		self::assertSame( array( 'select', 'select', 'select', 'select' ), $this->operations() );
	}

	/** Held-state reads reject a deserializable row outside the exact three-field schema. */
	public function test_is_held_rejects_a_lock_row_with_extra_fields(): void {
		$row = self::row( 'run-owner', 100, 200 );

		$row['extra'] = true;
		$this->wpdb->put( self::KEY, self::raw( $row ) );

		self::assertFalse( $this->guard_at( 200 )->is_held( self::NAME, self::ARGS_HASH, 100 ) );
	}

	/** A failed authoritative held-state read fails closed without writing. */
	public function test_is_held_reports_held_after_read_failure(): void {
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient read failure';
			}
		);

		self::assertTrue( $this->guard_at( 200 )->is_held( self::NAME, self::ARGS_HASH, 100 ) );
		self::assertSame( array( 'select' ), $this->operations() );
	}

	/** Maintenance distinguishes owned, transferred, and absent locks with typed outcomes. */
	public function test_maintenance_fence_returns_typed_ownership_outcomes(): void {
		$guard = $this->guard_at( 1_000 );

		$this->store_lock( self::row( 'run-owner', 900, 950 ) );
		self::assertSame(
			MaintenanceFenceOutcome::Owned,
			$guard->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 100 )
		);

		$this->store_lock( self::row( 'run-rival', 900, 950 ) );
		self::assertSame(
			MaintenanceFenceOutcome::Transferred,
			$guard->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 100 )
		);

		unset( $this->wpdb->rows[ self::KEY ], $this->wpdb->autoload[ self::KEY ] );
		self::assertSame(
			MaintenanceFenceOutcome::Abandoned,
			$guard->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 100 )
		);
	}

	/** A redrive fence classifies a stale owner without deleting its exact-generation heartbeat. */
	public function test_redrive_fence_preserves_a_stale_owned_lock(): void {
		$raw = self::raw( self::row( 'run-owner', 800, 899 ) );
		$this->wpdb->put( self::KEY, $raw );

		self::assertSame(
			MaintenanceFenceOutcome::Owned,
			$this->guard_at( 1_000 )->classify_run_fence( self::NAME, self::ARGS_HASH, 'run-owner' )
		);
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select' ), $this->operations() );
	}

	/** A callback credit remains owned through its strict credit-plus-staleness boundary. */
	public function test_maintenance_fence_preserves_a_credited_callback_until_the_full_window_elapses(): void {
		$credited = self::row( 'run-owner', 1_000, 1_300 );
		$this->store_lock( $credited );

		self::assertSame(
			MaintenanceFenceOutcome::Owned,
			$this->guard_at( 1_901 )->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 900 )
		);
		self::assertSame( $credited, $this->lock() );
		self::assertSame(
			MaintenanceFenceOutcome::Owned,
			$this->guard_at( 2_200 )->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 900 )
		);
		self::assertSame( $credited, $this->lock() );
		self::assertSame(
			MaintenanceFenceOutcome::Abandoned,
			$this->guard_at( 2_201 )->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 900 )
		);
		self::assertArrayNotHasKey( self::KEY, $this->wpdb->rows );
	}

	/** A stale owned lock is abandoned only after its exact deletion wins. */
	public function test_maintenance_fence_reports_abandoned_after_stale_delete(): void {
		$this->store_lock( self::row( 'run-owner', 800, 899 ) );

		self::assertSame(
			MaintenanceFenceOutcome::Abandoned,
			$this->guard_at( 1_000 )->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 100 )
		);
		self::assertArrayNotHasKey( self::KEY, $this->wpdb->rows );
	}

	/** A failed authoritative read leaves maintenance unable to classify ownership. */
	public function test_maintenance_fence_reports_indeterminate_after_read_failure(): void {
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient read failure';
			}
		);

		self::assertSame(
			MaintenanceFenceOutcome::Indeterminate,
			$this->guard_at( 1_000 )->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 100 )
		);
	}

	/** A stale-delete CAS loss leaves maintenance unable to claim the crash decision. */
	public function test_maintenance_fence_reports_indeterminate_after_stale_delete_loss(): void {
		$this->store_lock( self::row( 'run-owner', 800, 899 ) );
		$winner = self::raw( self::row( 'run-owner', 1_000, 1_000 ) );
		$this->wpdb->before_next(
			'delete',
			static function ( WpdbLockSpy $wpdb ) use ( $winner ): void {
				$wpdb->put( self::KEY, $winner );
			}
		);

		self::assertSame(
			MaintenanceFenceOutcome::Indeterminate,
			$this->guard_at( 1_000 )->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 100 )
		);
		self::assertSame( $winner, $this->wpdb->rows[ self::KEY ] );
	}

	/** A heartbeat exactly one window old remains fresh until one more second elapses. */
	public function test_staleness_requires_heartbeat_age_to_exceed_the_given_window(): void {
		$boundary = self::row( 'run-owner', 100, 900 );
		$this->store_lock( $boundary );
		$guard = $this->guard_at( 1_000 );

		self::assertTrue( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );
		self::assertSame(
			ClaimResult::Held,
			$guard->claim( self::NAME, self::ARGS_HASH, 'run-new', 100 )
		);
		self::assertSame( $boundary, $this->lock() );
		self::assertFalse( $this->guard_at( 1_001 )->is_held( self::NAME, self::ARGS_HASH, 100 ) );
	}

	/**
	 * Returns a guard with deterministic time and the shared SQL seam.
	 *
	 * @param   int                  $timestamp Current Unix timestamp.
	 * @param   RecordingLogger|null $logger    Optional log recorder.
	 *
	 * @return  OverlapGuard
	 */
	private function guard_at( int $timestamp, ?RecordingLogger $logger = null ): OverlapGuard {
		return new OverlapGuard(
			new FixedClock( $timestamp ),
			$logger ?? new RecordingLogger(),
			$this->rows
		);
	}

	/**
	 * Stores one structured lock row directly as a deterministic precondition.
	 *
	 * @param array<array-key, mixed> $row Lock row.
	 */
	private function store_lock( array $row ): void {
		$this->wpdb->put( self::KEY, self::raw( $row ) );
	}

	/** Returns the current lock row after WordPress-shaped unserialization. */
	private function lock(): mixed {
		if ( ! isset( $this->wpdb->rows[ self::KEY ] ) ) {
			return null;
		}

		return \maybe_unserialize( $this->wpdb->rows[ self::KEY ] );
	}

	/**
	 * Returns an exact lock row.
	 *
	 * @param   string $run_id       Run identifier.
	 * @param   int    $claimed_at   Claim timestamp.
	 * @param   int    $heartbeat_at Heartbeat timestamp.
	 *
	 * @return  array{run_id: string, claimed_at: int, heartbeat_at: int}
	 */
	private static function row( string $run_id, int $claimed_at, int $heartbeat_at ): array {
		return array(
			'run_id'       => $run_id,
			'claimed_at'   => $claimed_at,
			'heartbeat_at' => $heartbeat_at,
		);
	}

	/**
	 * Returns a value's WordPress-shaped raw representation.
	 *
	 * @param   mixed $value Value to serialize.
	 *
	 * @return  string
	 */
	private static function raw( mixed $value ): string {
		$raw = \maybe_serialize( $value );
		self::assertIsString( $raw );

		return $raw;
	}

	/** @return list<'insert'|'select'|'update'|'delete'> */
	private function operations(): array {
		return \array_map(
			static function ( string $query ): string {
				return match ( true ) {
					\str_starts_with( $query, 'INSERT IGNORE ' ) => 'insert',
					\str_starts_with( $query, 'SELECT ' )        => 'select',
					\str_starts_with( $query, 'UPDATE ' )        => 'update',
					\str_starts_with( $query, 'DELETE ' )        => 'delete',
					default => throw new \UnexpectedValueException( 'Unexpected lock query.' ),
				};
			},
			$this->wpdb->recorded_queries
		);
	}

	/**
	 * Returns the number of recorded operations with one verb.
	 *
	 * @param   string $operation Operation name.
	 *
	 * @return  int
	 */
	private function operation_count( string $operation ): int {
		return \count(
			\array_filter(
				$this->operations(),
				static fn ( string $candidate ): bool => $operation === $candidate
			)
		);
	}
}
