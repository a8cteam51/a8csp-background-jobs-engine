<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\RandomizerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\JobIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Owns the canonical run identifier and active-run option-name representation.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class RunIdentity {
	// region FIELDS AND CONSTANTS

	/**
	 * Decimal width reserved for a run identifier's timestamp prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int TIME_DIGITS = 20;

	/**
	 * Decimal width reserved for a run identifier's random suffix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int RANDOM_DIGITS = 19;

	/**
	 * Fixed character length of the canonical timestamp-randomness run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int LENGTH = self::TIME_DIGITS + 1 + self::RANDOM_DIGITS;

	// endregion

	// region METHODS

	/**
	 * Returns a lexically time-ordered identifier with a 63-bit random suffix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int                 $timestamp  Run creation timestamp.
	 * @param   RandomizerInterface $randomizer Run identifier randomness.
	 *
	 * @return  string
	 */
	public static function generate( int $timestamp, RandomizerInterface $randomizer ): string {
		return \sprintf( '%0' . self::TIME_DIGITS . 'd-%0' . self::RANDOM_DIGITS . 'd', $timestamp, $randomizer->int( 0, \PHP_INT_MAX ) );
	}

	/**
	 * Returns a canonical run identifier, or null when the candidate has another shape.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $candidate Run identifier candidate.
	 *
	 * @return  string|null
	 */
	public static function parse( string $candidate ): ?string {
		return 1 === \preg_match( '/\A' . self::pattern() . '\z/D', $candidate )
			? $candidate
			: null;
	}

	/**
	 * Returns the active-run option prefix owned by the run store.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public static function option_prefix(): string {
		return RunStore::OPTION_PREFIX;
	}

	/**
	 * Returns the active-run option prefix for one work identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  string
	 */
	public static function option_name_prefix( string $identity ): string {
		return self::option_prefix() . $identity . '_';
	}

	/**
	 * Returns the complete active-run option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  string
	 */
	public static function option_name( string $identity, string $run_id ): string {
		return self::option_name_prefix( $identity ) . $run_id;
	}

	/**
	 * Parses a canonical work identity and run identifier from one active-run option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Complete option name.
	 *
	 * @return  array{identity: string, run_id: string}|null
	 */
	public static function from_option_name( string $option_name ): ?array {
		$matched = \preg_match( '/\A' . \preg_quote( self::option_prefix(), '/' ) . '(?<identity>.+)_(?<run_id>' . self::pattern() . ')\z/D', $option_name, $matches );
		if (
			1 !== $matched
			|| null === JobIdentity::parts( $matches['identity'] )
		) {
			return null;
		}

		return array(
			'identity' => $matches['identity'],
			'run_id'   => $matches['run_id'],
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the regular-expression fragment shared by generation consumers and parsers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private static function pattern(): string {
		return '[0-9]{' . self::TIME_DIGITS . '}-[0-9]{' . self::RANDOM_DIGITS . '}';
	}

	// endregion
}
