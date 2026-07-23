<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Run;

use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\RandomizerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the public run-identifier value object to a wrap-only surface over the canonical wire value.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunId::class )]
final class RunIdTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string CANONICAL = '00000000001721664000-0000000000000000042';

	// endregion.

	// region LIFECYCLE.

	/**
	 * Satisfies the production files' ABSPATH boot guard before first autoload.
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
	}

	// endregion.

	// region TESTS.

	/**
	 * A canonical wire value wraps and casts back byte-identically.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_canonical_value_round_trips_through_the_string_cast(): void {
		$id = RunId::from( self::CANONICAL );

		self::assertInstanceOf( \Stringable::class, $id );
		self::assertSame( self::CANONICAL, (string) $id );

		$sibling = RunId::try_from( self::CANONICAL );
		self::assertInstanceOf( RunId::class, $sibling );
		self::assertSame( self::CANONICAL, (string) $sibling );
	}

	/**
	 * Malformed candidates are rejected: null from try_from(), an exception from from().
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_candidates_are_rejected(): void {
		$malformed = array(
			'',
			'run-7',
			'0000000000172166400-0000000000000000042',
			'00000000001721664000-000000000000000042',
			'00000000001721664000_0000000000000000042',
			'0000000000172166400a-0000000000000000042',
			self::CANONICAL . '9',
			' ' . self::CANONICAL,
		);

		foreach ( $malformed as $candidate ) {
			self::assertNull( RunId::try_from( $candidate ), '"' . $candidate . '" must not wrap.' );
		}

		$this->expectException( \InvalidArgumentException::class );
		RunId::from( 'run-7' );
	}

	/**
	 * Every identifier the internal minter generates wraps, and the wrapped length matches the
	 * minter's canonical length, so the public pattern cannot drift from the wire format.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_minted_identifiers_always_wrap(): void {
		$randomizer = new class() implements RandomizerInterface {
			/** {@inheritDoc} */
			#[\Override]
			public function int( int $min, int $max ): int {
				return $max;
			}
		};

		foreach ( array( 0, 1_721_664_000, \PHP_INT_MAX ) as $timestamp ) {
			$minted = RunIdentity::generate( $timestamp, $randomizer );
			$id     = RunId::try_from( $minted );

			self::assertInstanceOf( RunId::class, $id, $minted . ' must wrap.' );
			self::assertSame( $minted, (string) $id );
			self::assertSame( RunIdentity::LENGTH, \strlen( (string) $id ) );
		}
	}

	// endregion.
}
