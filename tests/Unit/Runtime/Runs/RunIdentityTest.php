<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingRandomizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the canonical run identifier and option-name representation.
 */
#[CoversClass( RunIdentity::class )]
final class RunIdentityTest extends TestCase {
	/** Satisfies the production boot guard before the identity helper is autoloaded. */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}
	}

	/** Generation and parsing share the documented fixed-width representation. */
	public function test_generated_run_id_round_trips_through_the_canonical_parser(): void {
		$randomizer = new RecordingRandomizer( 42 );
		$run_id     = RunIdentity::generate( 1_700_000_000, $randomizer );

		self::assertSame( 20, RunIdentity::TIME_DIGITS );
		self::assertSame( 19, RunIdentity::RANDOM_DIGITS );
		self::assertSame( 40, RunIdentity::LENGTH );
		self::assertSame( '00000000001700000000-0000000000000000042', $run_id );
		self::assertSame( $run_id, RunIdentity::parse( $run_id ) );
		self::assertSame(
			array(
				array(
					'min' => 0,
					'max' => \PHP_INT_MAX,
				),
			),
			$randomizer->calls
		);
	}

	/** Run option composition retains the exact persisted prefix and separators. */
	public function test_option_name_composes_and_decomposes_without_changing_bytes(): void {
		$identity    = Identity::compose( 'owner', 'sync_job' );
		$run_id      = '00000000001700000000-0000000000000000042';
		$option_name = 'a8csp_bgje_active_run_owner:sync_job_' . $run_id;

		self::assertSame( RunStore::OPTION_PREFIX, RunIdentity::option_prefix() );
		self::assertSame( 'a8csp_bgje_active_run_owner:sync_job_', RunIdentity::option_name_prefix( $identity ) );
		self::assertSame( $option_name, RunIdentity::option_name( $identity, $run_id ) );
		$parsed = RunIdentity::from_option_name( $option_name );
		self::assertIsArray( $parsed );
		self::assertInstanceOf( Identity::class, $parsed['identity'] );
		self::assertSame( (string) $identity, (string) $parsed['identity'] );
		self::assertSame( $run_id, $parsed['run_id'] );
	}

	/**
	 * Candidates outside the legacy fixed-width shape are rejected by the single parser.
	 *
	 * @param   string $candidate Malformed run identifier candidate.
	 *
	 * @return  void
	 */
	#[DataProvider( 'malformed_run_ids' )]
	public function test_malformed_run_ids_are_rejected_everywhere( string $candidate ): void {
		self::assertNull( RunIdentity::parse( $candidate ) );
		self::assertNull( RunIdentity::from_option_name( 'a8csp_bgje_active_run_owner:sync_' . $candidate ) );
	}

	/**
	 * Supplies representative candidates rejected by both previous run-ID regular expressions.
	 *
	 * @return  array<string, array{candidate: string}>
	 */
	public static function malformed_run_ids(): array {
		return array(
			'empty'             => array( 'candidate' => '' ),
			'short time'        => array( 'candidate' => '0000000001700000000-0000000000000000042' ),
			'long random'       => array( 'candidate' => '00000000001700000000-00000000000000000042' ),
			'missing separator' => array( 'candidate' => '000000000017000000000000000000000000042' ),
			'non-ascii digit'   => array( 'candidate' => '00000000001700000000-000000000000000004٢' ),
			'letter'            => array( 'candidate' => '00000000001700000000-000000000000000004x' ),
			'trailing newline'  => array( 'candidate' => "00000000001700000000-0000000000000000042\n" ),
		);
	}

	/** Legacy option-name grammar accepts only canonical work identities around valid IDs. */
	public function test_option_name_parser_matches_the_previous_run_key_regexes(): void {
		$run_id = '99999999999999999999-9999999999999999999';

		$parsed = RunIdentity::from_option_name( 'a8csp_bgje_active_run_owner:under_score_' . $run_id );
		self::assertIsArray( $parsed );
		self::assertInstanceOf( Identity::class, $parsed['identity'] );
		self::assertSame( 'owner:under_score', (string) $parsed['identity'] );
		self::assertSame( $run_id, $parsed['run_id'] );
		self::assertNull( RunIdentity::from_option_name( 'other_run_owner:under_score_' . $run_id ) );
		self::assertNull( RunIdentity::from_option_name( 'a8csp_bgje_active_run_invalid-owner_' . $run_id ) );
		self::assertNull( RunIdentity::from_option_name( 'a8csp_bgje_active_run_Owner:sync_' . $run_id ) );
	}
}
