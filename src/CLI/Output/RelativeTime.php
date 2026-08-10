<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output;

\defined( 'ABSPATH' ) || exit;

/**
 * Formats stable non-negative relative durations for CLI inspection.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class RelativeTime {
	// region FIELDS AND CONSTANTS

	/**
	 * Stable elapsed-time unit boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int SECONDS_PER_MINUTE = 60;
	private const int SECONDS_PER_HOUR   = 3_600;
	private const int SECONDS_PER_DAY    = 86_400;

	// endregion

	// region METHODS

	/**
	 * Formats a non-negative duration at stable second, minute, hour, and day boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $seconds Non-negative duration in seconds.
	 *
	 * @return  string
	 */
	public static function duration( int $seconds ): string {
		if ( self::SECONDS_PER_MINUTE > $seconds ) {
			return $seconds . 's';
		}
		if ( self::SECONDS_PER_HOUR > $seconds ) {
			return \intdiv( $seconds, self::SECONDS_PER_MINUTE ) . 'm';
		}
		if ( self::SECONDS_PER_DAY > $seconds ) {
			return \intdiv( $seconds, self::SECONDS_PER_HOUR ) . 'h';
		}

		return \intdiv( $seconds, self::SECONDS_PER_DAY ) . 'd';
	}

	/**
	 * Returns the saturating distance between ordered integer timestamps.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $larger  Greater timestamp.
	 * @param   int $smaller Lesser timestamp.
	 *
	 * @return  int
	 */
	public static function distance( int $larger, int $smaller ): int {
		if ( 0 <= $smaller || $larger <= \PHP_INT_MAX + $smaller ) {
			return $larger - $smaller;
		}

		return \PHP_INT_MAX;
	}

	// endregion
}
