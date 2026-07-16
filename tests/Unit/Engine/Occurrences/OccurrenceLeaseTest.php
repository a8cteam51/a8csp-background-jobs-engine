<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Occurrences;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ClaimedLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins occurrence-decision lease claims, stale recovery, and exact release.
 *
 * @load-bearing concurrency
 * @pin-rationale Lease-token ownership and stale takeover are raw compare-and-swap contracts whose losing-writer states cannot be forced through a public schedule delivery.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( OccurrenceLease::class )]
#[CoversClass( ClaimedLease::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RawOptionDecoder::class )]
final class OccurrenceLeaseTest extends TestCase {
	private const KEY = 'owner-a:email-digest';
	private const NOW = 1_700_000_000;

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

		$GLOBALS['a8csp_bgte_test_blog_id']     = 1;
		$GLOBALS['a8csp_bgte_test_cache']       = array();
		$GLOBALS['a8csp_bgte_test_cache_calls'] = array();
		$this->clock                            = new FixedClock( self::NOW );
		$this->wpdb                             = new WpdbLockSpy();
		$this->lease                            = new OccurrenceLease( new OptionRows( $this->wpdb ), $this->clock, new RecordingRandomizer( 42 ) );
	}

	/** An absent lease is exclusively inserted and released by exact raw value. */
	public function test_absent_lease_is_claimed_and_exact_released(): void {
		$claim = $this->lease->claim( self::KEY );

		self::assertInstanceOf( ClaimedLease::class, $claim );
		self::assertArrayHasKey( self::option_name(), $this->wpdb->rows );
		$claim->release();
		self::assertArrayNotHasKey( self::option_name(), $this->wpdb->rows );
	}

	/** A claimed handle performs its exact release CAS at most once. */
	public function test_claimed_lease_release_is_idempotent(): void {
		$claim = $this->lease->claim( self::KEY );
		self::assertInstanceOf( ClaimedLease::class, $claim );

		$claim->release();
		$claim->release();

		self::assertCount( 1, \array_filter( $this->wpdb->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, 'DELETE ' ) && \str_contains( $query, self::option_name() ) ) );
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

		self::assertNull( $this->lease->claim( self::KEY ) );
		self::assertSame( 1, $confirmation_failures );
		self::assertArrayHasKey( self::option_name(), $this->wpdb->rows );
	}

	/** A claim exactly sixty seconds old remains a held lease. */
	public function test_fresh_lease_is_held_at_the_sixty_second_boundary(): void {
		$this->put_lease( self::NOW - 60 );

		self::assertNull( $this->lease->claim( self::KEY ) );
		self::assertSame( self::NOW - 60, $this->stored_lease()['claimed_at'] ?? null );
	}

	/** A claim older than sixty seconds is reclaimed by raw-value CAS. */
	public function test_stale_lease_is_reclaimed_after_sixty_seconds(): void {
		$this->put_lease( self::NOW - 61 );

		self::assertInstanceOf( ClaimedLease::class, $this->lease->claim( self::KEY ) );
		self::assertSame( self::NOW, $this->stored_lease()['claimed_at'] ?? null );
	}

	/** An unreadable incumbent is not replaced from non-authoritative absence. */
	public function test_incumbent_read_failure_does_not_replace_the_lease(): void {
		$raw = self::raw_lease( 'incumbent', self::NOW - 61 );
		$this->wpdb->put( self::option_name(), $raw );
		$incumbent_read_failures = 0;
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( &$incumbent_read_failures ): void {
				++$incumbent_read_failures;
				$wpdb->last_error = 'scripted incumbent lease read failure';
			}
		);

		self::assertNull( $this->lease->claim( self::KEY ) );
		self::assertSame( 1, $incumbent_read_failures );
		self::assertSame( $raw, $this->wpdb->rows[ self::option_name() ] );
		self::assertSame( array(), \array_filter( $this->wpdb->recorded_queries, static fn ( string $query ): bool => \str_starts_with( $query, 'UPDATE ' ) ) );
	}

	/** A malformed lease can be recovered without a blind delete window. */
	public function test_malformed_lease_is_value_cas_reclaimed(): void {
		$this->wpdb->put( self::option_name(), 'malformed' );

		self::assertInstanceOf( ClaimedLease::class, $this->lease->claim( self::KEY ) );
		self::assertSame( self::NOW, $this->stored_lease()['claimed_at'] ?? null );
	}

	/** A stale reclaim that loses its CAS leaves the winner untouched. */
	public function test_lost_reclaim_cas_does_not_claim(): void {
		$this->put_lease( self::NOW - 61 );
		$winner = self::raw_lease( 'winner', self::NOW );
		$this->wpdb->before_next(
			'update',
			function ( WpdbLockSpy $wpdb ) use ( $winner ): void {
				$wpdb->put( self::option_name(), $winner );
			}
		);

		self::assertNull( $this->lease->claim( self::KEY ) );
		self::assertSame( $winner, $this->wpdb->rows[ self::option_name() ] );
	}

	/**
	 * Stores one valid lease row as a test precondition.
	 *
	 * @param   int $claimed_at Lease claim timestamp.
	 *
	 * @return  void
	 */
	private function put_lease( int $claimed_at ): void {
		$this->wpdb->put( self::option_name(), self::raw_lease( 'incumbent', $claimed_at ) );
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
	 * @param   string $claim_token Lease claim token.
	 * @param   int    $claimed_at  Lease claim timestamp.
	 *
	 * @return  string
	 */
	private static function raw_lease( string $claim_token, int $claimed_at ): string {
		$raw = \maybe_serialize(
			array(
				'claim_token' => $claim_token,
				'claimed_at'  => $claimed_at,
			)
		);
		self::assertIsString( $raw );

		return $raw;
	}

	/** Returns the bounded hashed lease option name. */
	private static function option_name(): string {
		return 'a8csp_bgte_lease_' . \hash( 'sha256', self::KEY );
	}
}
