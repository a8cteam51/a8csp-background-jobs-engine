<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Schedules;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ClaimedLease;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceLeaseClaim;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceLeaseOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins occurrence-decision lease claims, stale recovery, and exact release.
 *
 * @load-bearing concurrency
 * @pin-rationale Lease-token ownership and stale takeover are raw compare-and-swap contracts whose losing-writer states cannot be forced through a public schedule delivery.
 * @fixture StoreFixtureBuilder
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( OccurrenceLease::class )]
#[CoversClass( OccurrenceLeaseClaim::class )]
#[CoversClass( OccurrenceLeaseOutcome::class )]
#[CoversClass( ClaimedLease::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
final class OccurrenceLeaseTest extends TestCase {
	private const string KEY = 'owner-a:email-digest';
	private const int NOW    = 1_700_000_000;

	private FixedClock $clock;
	private OccurrenceLease $lease;
	private WpdbLockSpy $wpdb;

	/** Loads the guarded WordPress functions used by lease rows. */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-lock-stubs.php';
	}

	/** Constructs one deterministic lease over an empty row store. */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgje_test_blog_id']     = 1;
		$GLOBALS['a8csp_bgje_test_cache']       = array();
		$GLOBALS['a8csp_bgje_test_cache_calls'] = array();
		$this->clock                            = new FixedClock( self::NOW );
		$this->wpdb                             = new WpdbLockSpy();
		$this->lease                            = new OccurrenceLease( new OptionRows( $this->wpdb ), $this->clock, new RecordingRandomizer( 42 ) );
	}

	/** An absent lease is exclusively inserted and released by exact raw value. */
	public function test_absent_lease_is_claimed_and_exact_released(): void {
		$claim = $this->lease->claim( self::KEY );

		self::assertSame( OccurrenceLeaseOutcome::Claimed, $claim->outcome );
		self::assertInstanceOf( ClaimedLease::class, $claim->lease );
		self::assertArrayHasKey( self::option_name(), $this->wpdb->rows );
		$claim->lease->release();
		self::assertArrayNotHasKey( self::option_name(), $this->wpdb->rows );
	}

	/** A claimed handle performs its exact release CAS at most once. */
	public function test_claimed_lease_release_is_idempotent(): void {
		$claim = $this->lease->claim( self::KEY );
		self::assertSame( OccurrenceLeaseOutcome::Claimed, $claim->outcome );
		self::assertInstanceOf( ClaimedLease::class, $claim->lease );

		$claim->lease->release();
		$claim->lease->release();

		self::assertCount( 1, \array_filter( $this->wpdb->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, 'DELETE ' ) && \str_contains( $query, self::option_name() ) ) );
	}

	/** Exact release cannot delete a newer lease generation. */
	public function test_claimed_lease_release_preserves_a_newer_generation(): void {
		$claim = $this->lease->claim( self::KEY );
		self::assertSame( OccurrenceLeaseOutcome::Claimed, $claim->outcome );
		self::assertInstanceOf( ClaimedLease::class, $claim->lease );
		$winner = self::raw_lease( 43, self::NOW + 1 );
		$this->wpdb->put( self::option_name(), $winner );

		$claim->lease->release();

		self::assertSame( $winner, $this->wpdb->rows[ self::option_name() ] ?? null );
	}

	/** An inserted lease remains unclaimed when its authoritative confirmation read fails. */
	public function test_insert_confirmation_read_failure_does_not_admit_the_claim(): void {
		$confirmation_failures = 0;
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( &$confirmation_failures ): void {
				++$confirmation_failures;
				$wpdb->last_error = 'scripted lease confirmation failure';
			}
		);

		$claim = $this->lease->claim( self::KEY );
		self::assertSame( OccurrenceLeaseOutcome::Indeterminate, $claim->outcome );
		self::assertSame( 'read', $claim->storage_operation );
		self::assertNull( $claim->lease );
		self::assertSame( 1, $confirmation_failures );
		self::assertArrayHasKey( self::option_name(), $this->wpdb->rows );
	}

	/** A successful insert whose confirmation observes a rival is classified as a lost race. */
	public function test_insert_confirmation_changed_by_a_rival_is_held(): void {
		$winner = self::raw_lease( 44, self::NOW );
		$this->wpdb->before_next(
			'select',
			function ( WpdbLockSpy $wpdb ) use ( $winner ): void {
				$wpdb->put( self::option_name(), $winner );
			}
		);

		$claim = $this->lease->claim( self::KEY );

		self::assertSame( OccurrenceLeaseOutcome::Held, $claim->outcome );
		self::assertNull( $claim->storage_operation );
		self::assertNull( $claim->lease );
		self::assertSame( $winner, $this->wpdb->rows[ self::option_name() ] ?? null );
	}

	/** A rejected insert with authoritative absence reports an unconfirmed storage write. */
	public function test_absent_lease_insert_failure_is_indeterminate(): void {
		$this->wpdb->script_result( 'insert', false );

		$claim = $this->lease->claim( self::KEY );

		self::assertSame( OccurrenceLeaseOutcome::Indeterminate, $claim->outcome );
		self::assertSame( 'write', $claim->storage_operation );
		self::assertNull( $claim->lease );
		self::assertArrayNotHasKey( self::option_name(), $this->wpdb->rows );
		self::assertCount( 1, $this->wpdb->recorded_queries );
	}

	/** A claim exactly sixty seconds old remains a held lease. */
	public function test_fresh_lease_is_held_at_the_sixty_second_boundary(): void {
		$this->put_lease( self::NOW - 60 );

		$claim = $this->lease->claim( self::KEY );
		self::assertSame( OccurrenceLeaseOutcome::Held, $claim->outcome );
		self::assertNull( $claim->storage_operation );
		self::assertNull( $claim->lease );
		self::assertSame( self::NOW - 60, $this->stored_lease()['claimed_at'] ?? null );
		self::assertCount( 2, $this->wpdb->recorded_queries );
	}

	/** A claim older than sixty seconds is reclaimed by raw-value CAS. */
	public function test_stale_lease_is_reclaimed_after_sixty_seconds(): void {
		$this->put_lease( self::NOW - 61 );

		$claim = $this->lease->claim( self::KEY );
		self::assertSame( OccurrenceLeaseOutcome::Claimed, $claim->outcome );
		self::assertInstanceOf( ClaimedLease::class, $claim->lease );
		self::assertSame( self::NOW, $this->stored_lease()['claimed_at'] ?? null );
	}

	/** An unreadable incumbent is not replaced from non-authoritative absence. */
	public function test_incumbent_read_failure_does_not_replace_the_lease(): void {
		$raw = self::raw_lease( 41, self::NOW - 61 );
		$this->wpdb->put( self::option_name(), $raw );
		$incumbent_read_failures = 0;
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( &$incumbent_read_failures ): void {
				++$incumbent_read_failures;
				$wpdb->last_error = 'scripted incumbent lease read failure';
			}
		);

		$claim = $this->lease->claim( self::KEY );
		self::assertSame( OccurrenceLeaseOutcome::Indeterminate, $claim->outcome );
		self::assertSame( 'read', $claim->storage_operation );
		self::assertNull( $claim->lease );
		self::assertSame( 1, $incumbent_read_failures );
		self::assertSame( $raw, $this->wpdb->rows[ self::option_name() ] );
		self::assertSame( array(), \array_filter( $this->wpdb->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, 'UPDATE ' ) ) );
	}

	/** A malformed lease can be recovered without a blind delete window. */
	public function test_malformed_lease_is_value_cas_reclaimed(): void {
		$this->wpdb->put( self::option_name(), 'malformed' );

		$claim = $this->lease->claim( self::KEY );
		self::assertSame( OccurrenceLeaseOutcome::Claimed, $claim->outcome );
		self::assertInstanceOf( ClaimedLease::class, $claim->lease );
		self::assertSame( self::NOW, $this->stored_lease()['claimed_at'] ?? null );
	}

	/** A stale reclaim that loses its CAS leaves the winner untouched. */
	public function test_lost_reclaim_cas_does_not_claim(): void {
		$this->put_lease( self::NOW - 61 );
		$winner = self::raw_lease( 45, self::NOW );
		$this->wpdb->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ) use ( $winner ): void {
				$wpdb->put( self::option_name(), $winner );
			}
		);

		$claim = $this->lease->claim( self::KEY );
		self::assertSame( OccurrenceLeaseOutcome::Held, $claim->outcome );
		self::assertNull( $claim->storage_operation );
		self::assertNull( $claim->lease );
		self::assertSame( $winner, $this->wpdb->rows[ self::option_name() ] );
		self::assertCount( 4, $this->wpdb->recorded_queries );
	}

	/** A stale reclaim that loses to row removal is classified as a lost race. */
	public function test_lost_reclaim_cas_after_row_removal_is_held(): void {
		$this->put_lease( self::NOW - 61 );
		$this->wpdb->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ): void {
				unset( $wpdb->rows[ self::option_name() ], $wpdb->autoload[ self::option_name() ] );
			}
		);

		$claim = $this->lease->claim( self::KEY );

		self::assertSame( OccurrenceLeaseOutcome::Held, $claim->outcome );
		self::assertNull( $claim->storage_operation );
		self::assertNull( $claim->lease );
		self::assertArrayNotHasKey( self::option_name(), $this->wpdb->rows );
	}

	/** A lost stale reclaim with an unreadable diagnostic snapshot is indeterminate. */
	public function test_lost_reclaim_cas_diagnostic_read_failure_is_indeterminate(): void {
		$this->put_lease( self::NOW - 61 );
		$this->wpdb->script_result( 'update', 0 );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted diagnostic lease read failure';
			}
		);

		$claim = $this->lease->claim( self::KEY );

		self::assertSame( OccurrenceLeaseOutcome::Indeterminate, $claim->outcome );
		self::assertSame( 'read', $claim->storage_operation );
		self::assertNull( $claim->lease );
		self::assertSame( self::NOW - 61, $this->stored_lease()['claimed_at'] ?? null );
		self::assertCount( 4, $this->wpdb->recorded_queries );
	}

	/** A stale reclaim write error is indeterminate without a diagnostic re-read. */
	public function test_reclaim_write_failure_is_indeterminate_without_a_diagnostic_read(): void {
		$this->put_lease( self::NOW - 61 );
		$this->wpdb->script_result( 'update', false );

		$claim = $this->lease->claim( self::KEY );

		self::assertSame( OccurrenceLeaseOutcome::Indeterminate, $claim->outcome );
		self::assertSame( 'write', $claim->storage_operation );
		self::assertNull( $claim->lease );
		self::assertSame( self::NOW - 61, $this->stored_lease()['claimed_at'] ?? null );
		self::assertCount( 3, $this->wpdb->recorded_queries );
	}

	/**
	 * Stores one valid lease row as a test precondition.
	 *
	 * @param   int $claimed_at Lease claim timestamp.
	 *
	 * @return  void
	 */
	private function put_lease( int $claimed_at ): void {
		$this->wpdb->put( self::option_name(), self::raw_lease( 41, $claimed_at ) );
	}

	/**
	 * Returns the decoded lease row.
	 *
	 * @return  array{claim_token: string, claimed_at: int}
	 */
	private function stored_lease(): array {
		$row = \maybe_unserialize( $this->wpdb->rows[ self::option_name() ] ?? '' );
		self::assertIsArray( $row );
		$claim_token = $row['claim_token'] ?? null;
		$claimed_at  = $row['claimed_at'] ?? null;
		self::assertIsString( $claim_token );
		self::assertIsInt( $claimed_at );
		self::assertCount( 2, $row );

		return array(
			'claim_token' => $claim_token,
			'claimed_at'  => $claimed_at,
		);
	}

	/**
	 * Returns one exact raw lease row.
	 *
	 * @param   int $claim_token Deterministic claim-token source.
	 * @param   int $claimed_at  Lease claim timestamp.
	 *
	 * @return  string
	 */
	private static function raw_lease( int $claim_token, int $claimed_at ): string {
		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( self::KEY )->occurrence_lease( $claimed_at, $claim_token );
		self::assertSame( self::option_name(), $option_name );

		return $raw;
	}

	/** Returns the bounded hashed lease option name. */
	private static function option_name(): string {
		return OccurrenceLease::OPTION_PREFIX . \hash( 'sha256', self::KEY );
	}
}
