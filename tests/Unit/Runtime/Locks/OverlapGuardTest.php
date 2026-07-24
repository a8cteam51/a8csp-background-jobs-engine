<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Locks;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockClaimOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockClaimResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockTransferOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\MaintenanceFenceOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\MaintenanceLockSweep;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\RedeliveryFenceOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
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
 * @load-bearing concurrency
 * @pin-rationale Exact database interleavings decide lock acquisition, replacement, heartbeat, and release; public consumer operations cannot deterministically create the losing-writer states.
 * @fixture StoreFixtureBuilder
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 */
#[CoversClass( OverlapGuard::class )]
#[UsesClass( LockClaimOutcome::class )]
#[UsesClass( LockClaimResult::class )]
#[UsesClass( LockTransferOutcome::class )]
#[UsesClass( HeartbeatOutcome::class )]
#[UsesClass( MaintenanceLockSweep::class )]
#[UsesClass( RedeliveryFenceOutcome::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
final class OverlapGuardTest extends TestCase {
	private const string ARGS_HASH = 'args-123';
	private const string KEY       = 'a8csp_bgje_overlap_lock_email-digest_args-123';
	private const string NAME      = 'email-digest';

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

		$GLOBALS['a8csp_bgje_test_blog_id']     = 1;
		$GLOBALS['a8csp_bgje_test_cache']       = array();
		$GLOBALS['a8csp_bgje_test_cache_calls'] = array();
		$this->wpdb                             = new WpdbLockSpy();
		$this->rows                             = new OptionRows( $this->wpdb );
	}

	/** Lock option parsing derives the exact prefix and accepts the canonical identity and lowercase hash grammar. */
	public function test_option_name_parser_uses_the_canonical_lock_key_grammar(): void {
		$hash = \str_repeat( 'a', 64 );

		self::assertSame( 'a8csp_bgje_overlap_lock_', OverlapGuard::OPTION_PREFIX );
		self::assertSame(
			array(
				'identity'  => 'owner:under_score',
				'args_hash' => $hash,
			),
			OverlapGuard::identity_from_option_name( 'a8csp_bgje_overlap_lock_owner:under_score_' . $hash )
		);
		self::assertNull( OverlapGuard::identity_from_option_name( 'other_lock_owner:under_score_' . $hash ) );
		self::assertNull( OverlapGuard::identity_from_option_name( 'a8csp_bgje_overlap_lock_invalid-owner_' . $hash ) );
		self::assertNull( OverlapGuard::identity_from_option_name( 'a8csp_bgje_overlap_lock_owner:sync_' . \str_repeat( 'A', 64 ) ) );
		self::assertNull( OverlapGuard::identity_from_option_name( "a8csp_bgje_overlap_lock_owner:sync_{$hash}\n" ) );
	}

	/** An absent lock is claimed with the exact schema and non-autoload policy. */
	public function test_fresh_claim_inserts_the_literal_non_autoloaded_lock(): void {
		$result = $this->guard_at( 1_700_000_100 )->claim( self::NAME, self::ARGS_HASH, 'run-new', 900 );

		self::assertSame( LockClaimOutcome::Claimed, $result->outcome );
		self::assertNull( $result->owner_run_id );
		self::assertNull( $result->raw );
		self::assertNull( $result->stale );
		self::assertSame( self::expected_lock_row( 'run-new', 1_700_000_100, 1_700_000_100 ), $this->lock() );
		self::assertTrue( $this->wpdb->is_non_autoloaded( self::KEY ) );
		self::assertSame( array( 'insert' ), $this->operations() );
	}

	/** A fresh foreign owner is selected with its exact raw row without mutation. */
	public function test_claim_selects_a_fresh_foreign_lock_without_mutation(): void {
		$raw = self::fixture_lock_raw( 'run-live', 1_700_000_000, 1_700_000_090 );
		$this->wpdb->put( self::KEY, $raw );

		$result = $this->guard_at( 1_700_000_100 )->claim( self::NAME, self::ARGS_HASH, 'run-new', 900 );

		self::assertSame( LockClaimOutcome::Contended, $result->outcome );
		self::assertSame( 'run-live', $result->owner_run_id );
		self::assertSame( $raw, $result->raw );
		self::assertFalse( $result->stale );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'insert', 'select' ), $this->operations() );
	}

	/** A failed claim read is indeterminate and leaves the contended row untouched. */
	public function test_claim_is_indeterminate_without_writing_after_read_failure(): void {
		$raw = self::fixture_lock_raw( 'run-owner', 100, 120 );
		$this->wpdb->put( self::KEY, $raw );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient claim read failure';
			}
		);

		$result = $this->guard_at( 200 )->claim( self::NAME, self::ARGS_HASH, 'run-rival', 100 );

		self::assertSame( LockClaimOutcome::Indeterminate, $result->outcome );
		self::assertNull( $result->owner_run_id );
		self::assertNull( $result->raw );
		self::assertNull( $result->stale );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'insert', 'select' ), $this->operations() );
	}

	/**
	 * A fresh lock is replaced only while its exact selected row is unchanged.
	 *
	 * @return  void
	 */
	public function test_replace_moves_a_fresh_lock_to_the_replacement(): void {
		$raw = self::fixture_lock_raw( 'run-live', 1_700_000_000, 1_700_000_090 );
		$this->wpdb->put( self::KEY, $raw );

		$outcome = $this->guard_at( 1_700_000_100 )->replace( self::NAME, self::ARGS_HASH, 'run-live', $raw, 'run-new' );

		self::assertSame( LockTransferOutcome::Transferred, $outcome );
		self::assertSame( self::expected_lock_row( 'run-new', 1_700_000_100, 1_700_000_100 ), $this->lock() );
		self::assertSame( array( 'update' ), $this->operations() );
	}

	/** A replacement refuses expected bytes that do not name the expected owner. */
	public function test_replace_reports_lost_without_writing_when_the_expected_owner_mismatches(): void {
		$raw = self::fixture_lock_raw( 'run-live', 1_700_000_000, 1_700_000_090 );
		$this->wpdb->put( self::KEY, $raw );

		$outcome = $this->guard_at( 1_700_000_100 )->replace( self::NAME, self::ARGS_HASH, 'run-other', $raw, 'run-new' );

		self::assertSame( LockTransferOutcome::Lost, $outcome );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array(), $this->operations() );
	}

	/** An absent replacement row loses the exact transfer compare-and-swap. */
	public function test_replace_reports_lost_when_the_lock_row_is_absent(): void {
		$raw     = self::fixture_lock_raw( 'run-live', 1_700_000_000, 1_700_000_090 );
		$outcome = $this->guard_at( 1_700_000_100 )->replace( self::NAME, self::ARGS_HASH, 'run-live', $raw, 'run-new' );

		self::assertSame( LockTransferOutcome::Lost, $outcome );
		self::assertSame( array( 'update' ), $this->operations() );
	}

	/** A failed replacement write leaves transfer indeterminate without changing the selected row. */
	public function test_replace_reports_indeterminate_after_write_failure(): void {
		$raw = self::fixture_lock_raw( 'run-live', 1_700_000_000, 1_700_000_090 );
		$this->wpdb->put( self::KEY, $raw );
		$this->wpdb->script_result( 'update', false );

		$outcome = $this->guard_at( 1_700_000_100 )->replace( self::NAME, self::ARGS_HASH, 'run-live', $raw, 'run-new' );

		self::assertSame( LockTransferOutcome::Indeterminate, $outcome );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'update' ), $this->operations() );
	}

	/**
	 * A replacement cannot overwrite a newer owner that arrived after its snapshot.
	 *
	 * @return  void
	 */
	public function test_replace_preserves_a_newer_owner_that_changed_the_lock_after_selection(): void {
		$incumbent_raw = self::fixture_lock_raw( 'run-one', 1_700_000_000, 1_700_000_090 );
		$newer_raw     = self::fixture_lock_raw( 'run-three', 1_700_000_100, 1_700_000_100 );
		$this->wpdb->put( self::KEY, $incumbent_raw );
		$this->wpdb->put( self::KEY, $newer_raw );

		$outcome = $this->guard_at( 1_700_000_100 )->replace( self::NAME, self::ARGS_HASH, 'run-one', $incumbent_raw, 'run-two' );

		self::assertSame( LockTransferOutcome::Lost, $outcome );
		self::assertSame( $newer_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'update' ), $this->operations() );
	}

	/** A stale owner is selected with its exact raw row without mutation. */
	public function test_claim_selects_a_stale_lock_without_mutation(): void {
		$logger = new RecordingLogger();
		$raw    = self::fixture_lock_raw( 'run-dead', 1_699_999_000, 1_699_999_199 );
		$this->wpdb->put( self::KEY, $raw );

		$result = $this->guard_at( 1_700_000_100, $logger )->claim( self::NAME, self::ARGS_HASH, 'run-new', 900 );

		self::assertSame( LockClaimOutcome::Contended, $result->outcome );
		self::assertSame( 'run-dead', $result->owner_run_id );
		self::assertSame( $raw, $result->raw );
		self::assertTrue( $result->stale );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'insert', 'select' ), $this->operations() );
		self::assertSame( array(), $logger->records );
	}

	/** A fresh same-owner row is selected as contention without mutation. */
	public function test_claim_selects_a_fresh_same_owner_lock_without_mutation(): void {
		$raw = self::fixture_lock_raw( 'run-owner', 100, 120 );
		$this->wpdb->put( self::KEY, $raw );

		$result = $this->guard_at( 200 )->claim( self::NAME, self::ARGS_HASH, 'run-owner', 900 );

		self::assertSame( LockClaimOutcome::Contended, $result->outcome );
		self::assertSame( 'run-owner', $result->owner_run_id );
		self::assertSame( $raw, $result->raw );
		self::assertFalse( $result->stale );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'insert', 'select' ), $this->operations() );
	}

	/** A malformed raw row is classified with its exact bytes without mutation. */
	public function test_claim_classifies_a_malformed_row_without_mutation(): void {
		$logger = new RecordingLogger();
		$raw    = \str_repeat( 'malformed-', 30 );
		$this->wpdb->put( self::KEY, $raw );
		$guard = $this->guard_at( 1_000, $logger );

		self::assertFalse( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );
		$result = $guard->claim( self::NAME, self::ARGS_HASH, 'run-new', 100 );

		self::assertSame( LockClaimOutcome::Malformed, $result->outcome );
		self::assertNull( $result->owner_run_id );
		self::assertSame( $raw, $result->raw );
		self::assertNull( $result->stale );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array(), $logger->records );
	}

	/** A serialized object is classified as malformed without constructing its class or changing its bytes. */
	public function test_malformed_object_row_is_classified_without_class_construction_or_mutation(): void {
		LockRowWakeupProbe::$woke = false;

		$raw = StoreFixtureBuilder::corrupt_row( new LockRowWakeupProbe() );
		$this->wpdb->put( self::KEY, $raw );

		$result = $this->guard_at( 1_000 )->claim( self::NAME, self::ARGS_HASH, 'run-new', 100 );

		self::assertSame( LockClaimOutcome::Malformed, $result->outcome );
		self::assertSame( $raw, $result->raw );
		self::assertFalse( LockRowWakeupProbe::$woke );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'insert', 'select' ), $this->operations() );
	}

	/** Heartbeat refreshes only an owned row's liveness timestamp. */
	public function test_heartbeat_refreshes_only_the_owned_rows_liveness_timestamp(): void {
		$this->store_fixture_lock( 'run-owner', 100, 120 );

		$outcome = $this->guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( HeartbeatOutcome::Owned, $outcome );
		self::assertSame( self::expected_lock_row( 'run-owner', 100, 200 ), $this->lock() );
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

		$this->store_fixture_lock( 'run-owner', 100, 120 );

		self::assertSame( HeartbeatOutcome::Owned, $guard->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner', 1_000 ) );
		self::assertSame( 0, $clock->calls );
		self::assertSame( self::expected_lock_row( 'run-owner', 100, 1_000 ), $this->lock() );
		self::assertFalse( $this->guard_at( 500 )->claim( self::NAME, self::ARGS_HASH, 'run-rival', 100 )->stale );
		self::assertFalse( $this->guard_at( 1_100 )->claim( self::NAME, self::ARGS_HASH, 'run-rival', 100 )->stale );
		self::assertTrue( $this->guard_at( 1_101 )->claim( self::NAME, self::ARGS_HASH, 'run-rival', 100 )->stale );
		self::assertSame( self::expected_lock_row( 'run-owner', 100, 1_000 ), $this->lock() );
	}

	/** An identical-second heartbeat is confirmed after MySQL reports zero affected rows. */
	public function test_identical_second_heartbeat_confirms_the_unchanged_owned_row(): void {
		$row = self::fixture_lock_row( 'run-owner', 100, 200 );
		$raw = self::fixture_lock_raw( 'run-owner', 100, 200 );
		$this->wpdb->put( self::KEY, $raw );

		$outcome = $this->guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( HeartbeatOutcome::Owned, $outcome );
		self::assertSame( $row, $this->lock() );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select', 'update', 'select' ), $this->operations() );
	}

	/** Heartbeat leaves a foreign lock byte-for-byte unchanged. */
	public function test_heartbeat_does_not_touch_a_foreign_lock(): void {
		$foreign_raw = self::fixture_lock_raw( 'run-rival', 100, 120 );
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
		$owned_raw = self::fixture_lock_raw( 'run-owner', 100, 120 );
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
		self::assertCount( 1, $logger->records );
		self::assertSame( 'warning', $logger->records[0]['level'] ?? null );
		self::assertSame( self::KEY, $logger->records[0]['context']['key'] ?? null );
		self::assertSame( self::NAME, $logger->records[0]['context']['identity'] ?? null );
		self::assertSame( self::ARGS_HASH, $logger->records[0]['context']['args_hash'] ?? null );
		self::assertSame( 'run-owner', $logger->records[0]['context']['run_id'] ?? null );
	}

	/** A failed heartbeat write leaves ownership indeterminate instead of reporting a lost generation. */
	public function test_heartbeat_reports_indeterminate_after_write_failure(): void {
		$logger    = new RecordingLogger();
		$owned_raw = self::fixture_lock_raw( 'run-owner', 100, 120 );
		$this->wpdb->put( self::KEY, $owned_raw );
		$this->wpdb->script_result( 'update', false );

		$outcome = $this->guard_at( 200, $logger )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertSame( HeartbeatOutcome::Indeterminate, $outcome );
		self::assertSame( $owned_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select', 'update' ), $this->operations() );
		self::assertCount( 1, $logger->records );
		self::assertSame( 'warning', $logger->records[0]['level'] ?? null );
		self::assertSame( self::KEY, $logger->records[0]['context']['key'] ?? null );
		self::assertSame( self::NAME, $logger->records[0]['context']['identity'] ?? null );
		self::assertSame( self::ARGS_HASH, $logger->records[0]['context']['args_hash'] ?? null );
		self::assertSame( 'run-owner', $logger->records[0]['context']['run_id'] ?? null );
	}

	/** A heartbeat that loses its CAS leaves the replacement owner's row unchanged. */
	public function test_heartbeat_cas_loss_is_a_no_op(): void {
		$winner_raw = self::fixture_lock_raw( 'run-winner', 200, 200 );
		$this->store_fixture_lock( 'run-owner', 100, 120 );
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
		$newer_raw = self::fixture_lock_raw( 'run-owner', 100, 300 );
		$this->store_fixture_lock( 'run-owner', 100, 120 );
		$this->wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $database ) use ( $newer_raw ): void {
				$database->put( self::KEY, $newer_raw );
			}
		);

		$outcome = $this->guard_at( 200 )->heartbeat( self::NAME, self::ARGS_HASH, 'run-owner', null, 120 );

		self::assertSame( HeartbeatOutcome::GenerationMismatch, $outcome );
		self::assertSame( $newer_raw, $this->wpdb->rows[ self::KEY ] );
	}

	/** Release deletes a lock owned by the terminating run. */
	public function test_release_deletes_an_owned_lock(): void {
		$this->store_fixture_lock( 'run-owner', 100, 120 );

		$released = $this->guard_at( 200 )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertTrue( $released );
		self::assertArrayNotHasKey( self::KEY, $this->wpdb->rows );
		self::assertSame( array( 'select', 'delete' ), $this->operations() );
	}

	/** Release leaves a foreign lock byte-for-byte unchanged. */
	public function test_release_does_not_delete_a_foreign_lock(): void {
		$foreign_raw = self::fixture_lock_raw( 'run-rival', 100, 120 );
		$this->wpdb->put( self::KEY, $foreign_raw );

		$released = $this->guard_at( 200 )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertTrue( $released );
		self::assertSame( $foreign_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select' ), $this->operations() );
	}

	/** Release does not write when no lock exists. */
	public function test_release_is_a_no_op_when_the_lock_is_absent(): void {
		$released = $this->guard_at( 200 )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertTrue( $released );
		self::assertArrayNotHasKey( self::KEY, $this->wpdb->rows );
		self::assertSame( array( 'select' ), $this->operations() );
	}

	/** A failed release read leaves the lock for the staleness sweep and names the leaked key. */
	public function test_release_warns_that_the_staleness_sweep_reclaims_a_read_failure_leak(): void {
		$logger    = new RecordingLogger();
		$owned_raw = self::fixture_lock_raw( 'run-owner', 100, 120 );
		$this->wpdb->put( self::KEY, $owned_raw );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient read failure';
			}
		);

		$released = $this->guard_at( 200, $logger )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertFalse( $released );
		self::assertSame( $owned_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select' ), $this->operations() );
		self::assertCount( 1, $logger->records );
		self::assertSame( 'warning', $logger->records[0]['level'] ?? null );
		self::assertSame( self::KEY, $logger->records[0]['context']['key'] ?? null );
		self::assertSame( self::NAME, $logger->records[0]['context']['identity'] ?? null );
		self::assertSame( self::ARGS_HASH, $logger->records[0]['context']['args_hash'] ?? null );
		self::assertSame( 'run-owner', $logger->records[0]['context']['run_id'] ?? null );
	}

	/** A release that loses its CAS leaves the replacement owner's row unchanged. */
	public function test_release_cas_loss_is_a_no_op(): void {
		$winner_raw = self::fixture_lock_raw( 'run-winner', 200, 200 );
		$this->store_fixture_lock( 'run-owner', 100, 120 );
		$this->wpdb->before_next(
			'delete',
			static function ( WpdbLockSpy $database ) use ( $winner_raw ): void {
				$database->put( self::KEY, $winner_raw );
			}
		);

		$released = $this->guard_at( 200 )->release( self::NAME, self::ARGS_HASH, 'run-owner' );

		self::assertFalse( $released );
		self::assertSame( $winner_raw, $this->wpdb->rows[ self::KEY ] );
	}

	/** Held-state reads distinguish fresh, stale, absent, and malformed rows without writing. */
	public function test_is_held_reports_only_parseable_fresh_locks(): void {
		$guard = $this->guard_at( 1_000 );
		$this->store_fixture_lock( 'run-owner', 100, 901 );
		self::assertTrue( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );

		$this->store_fixture_lock( 'run-owner', 100, 899 );
		self::assertFalse( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );

		unset( $this->wpdb->rows[ self::KEY ], $this->wpdb->autoload[ self::KEY ] );
		self::assertFalse( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );

		$this->wpdb->put( self::KEY, 'not-a-lock-row' );
		self::assertFalse( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );
		self::assertSame( array( 'select', 'select', 'select', 'select' ), $this->operations() );
	}

	/** Held-state reads reject a deserializable row outside the exact three-field schema. */
	public function test_is_held_rejects_a_lock_row_with_extra_fields(): void {
		$this->wpdb->put(
			self::KEY,
			StoreFixtureBuilder::corrupt_row(
				array(
					'run_id'       => 'run-owner',
					'claimed_at'   => 100,
					'heartbeat_at' => 200,
					'extra'        => true,
				)
			)
		);

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

	/** A maintenance lock sweep preserves a malformed row and returns only redacted correlation. */
	public function test_maintenance_lock_sweep_preserves_a_malformed_row_with_redacted_correlation(): void {
		$raw = 'not-a-lock-row';
		$this->wpdb->put( self::KEY, $raw );

		$sweep = $this->guard_at( 1_000 )->sweep_persisted_lock( self::NAME, self::ARGS_HASH );

		self::assertNull( $sweep->run_id );
		self::assertTrue( $sweep->malformed_preserved );
		self::assertSame( \strlen( $raw ), $sweep->raw_length );
		self::assertSame( \substr( \hash( 'sha256', $raw ), 0, 16 ), $sweep->raw_sha256 );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select' ), $this->operations() );
	}

	/** A maintenance lock sweep returns a healthy owner without writing or reading twice. */
	public function test_maintenance_lock_sweep_returns_a_healthy_run_without_writing(): void {
		$raw = self::fixture_lock_raw( 'run-owner', 900, 950 );
		$this->wpdb->put( self::KEY, $raw );

		$sweep = $this->guard_at( 1_000 )->sweep_persisted_lock( self::NAME, self::ARGS_HASH );

		self::assertSame( 'run-owner', $sweep->run_id );
		self::assertFalse( $sweep->malformed_preserved );
		self::assertNull( $sweep->raw_length );
		self::assertNull( $sweep->raw_sha256 );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select' ), $this->operations() );
	}

	/** Maintenance distinguishes owned, transferred, and absent locks with typed outcomes. */
	public function test_maintenance_fence_returns_typed_ownership_outcomes(): void {
		$guard = $this->guard_at( 1_000 );

		$this->store_fixture_lock( 'run-owner', 900, 950 );
		self::assertSame( MaintenanceFenceOutcome::Owned, $guard->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 100 ) );

		$this->store_fixture_lock( 'run-rival', 900, 950 );
		self::assertSame( MaintenanceFenceOutcome::Transferred, $guard->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 100 ) );

		unset( $this->wpdb->rows[ self::KEY ], $this->wpdb->autoload[ self::KEY ] );
		self::assertSame( MaintenanceFenceOutcome::Abandoned, $guard->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 100 ) );
	}

	/** A redelivery fence classifies a stale owner without deleting its exact-generation heartbeat. */
	public function test_redelivery_fence_preserves_a_stale_owned_lock(): void {
		$raw = self::fixture_lock_raw( 'run-owner', 800, 899 );
		$this->wpdb->put( self::KEY, $raw );

		self::assertSame( MaintenanceFenceOutcome::Owned, $this->guard_at( 1_000 )->classify_run_fence( self::NAME, self::ARGS_HASH, 'run-owner' ) );
		self::assertSame( $raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select' ), $this->operations() );
	}

	/** An absent redelivery fence reports an insert error without a diagnostic re-read. */
	public function test_redelivery_fence_reports_indeterminate_after_insert_failure(): void {
		$this->wpdb->script_result( 'insert', false );

		$outcome = $this->guard_at( 1_000 )->prepare_run_redelivery_fence( self::NAME, self::ARGS_HASH, 'run-owner', 800, 899, 100 );

		self::assertSame( RedeliveryFenceOutcome::Indeterminate, $outcome );
		self::assertSame( array( 'select', 'insert' ), $this->operations() );
	}

	/** A lost redelivery-fence insert inspects and classifies the incumbent generation. */
	public function test_redelivery_fence_classifies_incumbent_after_lost_insert(): void {
		$winner_raw = self::fixture_lock_raw( 'run-rival', 900, 950 );
		$this->wpdb->before_next(
			'insert',
			function ( WpdbLockSpy $wpdb ) use ( $winner_raw ): void {
				$wpdb->put( self::KEY, $winner_raw );
			}
		);

		$outcome = $this->guard_at( 1_000 )->prepare_run_redelivery_fence( self::NAME, self::ARGS_HASH, 'run-owner', 800, 899, 100 );

		self::assertSame( RedeliveryFenceOutcome::Transferred, $outcome );
		self::assertSame( $winner_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select', 'insert', 'select' ), $this->operations() );
	}

	/** A malformed redelivery fence reports a replacement error without a diagnostic re-read. */
	public function test_redelivery_fence_reports_indeterminate_after_compare_and_swap_failure(): void {
		$this->wpdb->put( self::KEY, 'malformed' );
		$this->wpdb->script_result( 'update', false );

		$outcome = $this->guard_at( 1_000 )->prepare_run_redelivery_fence( self::NAME, self::ARGS_HASH, 'run-owner', 800, 899, 100 );

		self::assertSame( RedeliveryFenceOutcome::Indeterminate, $outcome );
		self::assertSame( array( 'select', 'update' ), $this->operations() );
	}

	/** A stale same-owner fence reports a replacement error without a diagnostic re-read. */
	public function test_stale_redelivery_fence_reports_indeterminate_after_compare_and_swap_failure(): void {
		$owned_raw = self::fixture_lock_raw( 'run-owner', 800, 800 );
		$this->wpdb->put( self::KEY, $owned_raw );
		$this->wpdb->script_result( 'update', false );

		$outcome = $this->guard_at( 1_000 )->prepare_run_redelivery_fence( self::NAME, self::ARGS_HASH, 'run-owner', 800, 899, 100 );

		self::assertSame( RedeliveryFenceOutcome::Indeterminate, $outcome );
		self::assertSame( $owned_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select', 'update' ), $this->operations() );
	}

	/** A lost redelivery-fence replacement inspects and classifies the incumbent generation. */
	public function test_redelivery_fence_classifies_incumbent_after_lost_compare_and_swap(): void {
		$this->wpdb->put( self::KEY, 'malformed' );
		$winner_raw = self::fixture_lock_raw( 'run-rival', 900, 950 );
		$this->wpdb->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ) use ( $winner_raw ): void {
				$wpdb->put( self::KEY, $winner_raw );
			}
		);

		$outcome = $this->guard_at( 1_000 )->prepare_run_redelivery_fence( self::NAME, self::ARGS_HASH, 'run-owner', 800, 899, 100 );

		self::assertSame( RedeliveryFenceOutcome::Transferred, $outcome );
		self::assertSame( $winner_raw, $this->wpdb->rows[ self::KEY ] );
		self::assertSame( array( 'select', 'update', 'select' ), $this->operations() );
	}

	/** A callback credit remains owned through its strict credit-plus-staleness boundary. */
	public function test_maintenance_fence_preserves_a_credited_callback_until_the_full_window_elapses(): void {
		$credited = self::fixture_lock_row( 'run-owner', 1_000, 1_300 );
		$this->store_fixture_lock( 'run-owner', 1_000, 1_300 );

		self::assertSame( MaintenanceFenceOutcome::Owned, $this->guard_at( 1_901 )->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 900 ) );
		self::assertSame( $credited, $this->lock() );
		self::assertSame( MaintenanceFenceOutcome::Owned, $this->guard_at( 2_200 )->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 900 ) );
		self::assertSame( $credited, $this->lock() );
		self::assertSame( MaintenanceFenceOutcome::Abandoned, $this->guard_at( 2_201 )->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 900 ) );
		self::assertArrayNotHasKey( self::KEY, $this->wpdb->rows );
	}

	/** A stale owned lock is abandoned only after its exact deletion wins. */
	public function test_maintenance_fence_reports_abandoned_after_stale_delete(): void {
		$this->store_fixture_lock( 'run-owner', 800, 899 );

		self::assertSame( MaintenanceFenceOutcome::Abandoned, $this->guard_at( 1_000 )->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 100 ) );
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

		self::assertSame( MaintenanceFenceOutcome::Indeterminate, $this->guard_at( 1_000 )->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 100 ) );
	}

	/** A stale-delete CAS loss leaves maintenance unable to claim the crash decision. */
	public function test_maintenance_fence_reports_indeterminate_after_stale_delete_loss(): void {
		$this->store_fixture_lock( 'run-owner', 800, 899 );
		$winner = self::fixture_lock_raw( 'run-owner', 1_000, 1_000 );
		$this->wpdb->before_next(
			'delete',
			static function ( WpdbLockSpy $wpdb ) use ( $winner ): void {
				$wpdb->put( self::KEY, $winner );
			}
		);

		self::assertSame( MaintenanceFenceOutcome::Indeterminate, $this->guard_at( 1_000 )->fence_abandoned_run( self::NAME, self::ARGS_HASH, 'run-owner', 100 ) );
		self::assertSame( $winner, $this->wpdb->rows[ self::KEY ] );
	}

	/** A heartbeat exactly one window old remains fresh until one more second elapses. */
	public function test_staleness_requires_heartbeat_age_to_exceed_the_given_window(): void {
		$boundary = self::fixture_lock_row( 'run-owner', 100, 900 );
		$this->store_fixture_lock( 'run-owner', 100, 900 );
		$guard = $this->guard_at( 1_000 );

		self::assertTrue( $guard->is_held( self::NAME, self::ARGS_HASH, 100 ) );
		$selection = $guard->claim( self::NAME, self::ARGS_HASH, 'run-new', 100 );
		self::assertSame( LockClaimOutcome::Contended, $selection->outcome );
		self::assertFalse( $selection->stale );
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
		return new OverlapGuard( new FixedClock( $timestamp ), $logger ?? new RecordingLogger(), $this->rows );
	}

	/**
	 * Stores one production-authored lock row as a deterministic precondition.
	 *
	 * @param   string $run_id       Run identifier.
	 * @param   int    $claimed_at   Claim timestamp.
	 * @param   int    $heartbeat_at Heartbeat timestamp.
	 *
	 * @return  void
	 */
	private function store_fixture_lock( string $run_id, int $claimed_at, int $heartbeat_at ): void {
		$this->wpdb->put( self::KEY, self::fixture_lock_raw( $run_id, $claimed_at, $heartbeat_at ) );
	}

	/** Returns the current lock row after WordPress-shaped unserialization. */
	private function lock(): mixed {
		if ( ! isset( $this->wpdb->rows[ self::KEY ] ) ) {
			return null;
		}

		return \maybe_unserialize( $this->wpdb->rows[ self::KEY ] );
	}

	/**
	 * Returns the literal lock-row shape expected from a production guard write.
	 *
	 * Literal arrays are the test's independent encoding oracle; builder-sourced bytes make these assertions circular because
	 * StoreFixtureBuilder lock rows come from the production guard.
	 *
	 * @param   string $run_id       Run identifier.
	 * @param   int    $claimed_at   Claim timestamp.
	 * @param   int    $heartbeat_at Heartbeat timestamp.
	 *
	 * @return  array{run_id: string, claimed_at: int, heartbeat_at: int}
	 */
	private static function expected_lock_row( string $run_id, int $claimed_at, int $heartbeat_at ): array {
		return array(
			'run_id'       => $run_id,
			'claimed_at'   => $claimed_at,
			'heartbeat_at' => $heartbeat_at,
		);
	}

	/**
	 * Returns one decoded production-authored lock row.
	 *
	 * @param   string $run_id       Run identifier.
	 * @param   int    $claimed_at   Claim timestamp.
	 * @param   int    $heartbeat_at Heartbeat timestamp.
	 *
	 * @return  array{run_id: string, claimed_at: int, heartbeat_at: int}
	 */
	private static function fixture_lock_row( string $run_id, int $claimed_at, int $heartbeat_at ): array {
		/** @var array{run_id: string, claimed_at: int, heartbeat_at: int} $row */
		$row = \maybe_unserialize( self::fixture_lock_raw( $run_id, $claimed_at, $heartbeat_at ) );
		self::assertIsArray( $row );

		return $row;
	}

	/**
	 * Returns one exact production-authored lock row.
	 *
	 * @param   string $run_id       Run identifier.
	 * @param   int    $claimed_at   Claim timestamp.
	 * @param   int    $heartbeat_at Heartbeat timestamp.
	 *
	 * @return  string
	 */
	private static function fixture_lock_raw( string $run_id, int $claimed_at, int $heartbeat_at ): string {
		[ , $raw ] = StoreFixtureBuilder::for_identity( 'owner-a:' . self::NAME )->lock( self::ARGS_HASH, $run_id, $claimed_at, $heartbeat_at );

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
}
