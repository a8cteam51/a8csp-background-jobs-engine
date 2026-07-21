<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Schedule;

\defined( 'ABSPATH' ) || exit;

/**
 * Fixed-interval recurrence for one schedule.
 *
 * An optional anchor is a canonical phase offset in UTC Unix seconds. Anchored occurrences retain
 * that phase modulo the interval without introducing site-local or calendar-time semantics.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Recurrence {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int      $interval Positive seconds.
	 * @param   int|null $anchor   Phase offset in seconds (anchor reduced modulo the interval), or null when unanchored.
	 */
	private function __construct(
		public int $interval,
		public ?int $anchor = null,
	) {}

	// endregion

	// region FACTORIES

	/**
	 * Creates a fixed elapsed-time recurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $seconds Positive interval in seconds.
	 *
	 * @throws  \InvalidArgumentException When the interval is not positive.
	 *
	 * @return  self
	 */
	public static function every( int $seconds ): self {
		if ( 1 > $seconds ) {
			throw new \InvalidArgumentException( 'Recurrence interval must be positive; pass a value of at least one second.' );
		}

		return new self( $seconds );
	}

	/**
	 * Creates a fixed recurrence aligned to one UTC Unix-epoch phase.
	 *
	 * The anchor is reduced modulo the interval, so equivalent offsets retain one canonical value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $seconds Positive interval in seconds.
	 * @param   int $anchor  Non-negative UTC phase offset in seconds.
	 *
	 * @throws  \InvalidArgumentException When the interval is not positive or the anchor is negative.
	 *
	 * @return  self
	 */
	public static function every_anchored( int $seconds, int $anchor ): self {
		if ( 1 > $seconds ) {
			throw new \InvalidArgumentException( 'Recurrence interval must be positive; pass a value of at least one second.' );
		}
		if ( 0 > $anchor ) {
			throw new \InvalidArgumentException( 'Recurrence anchor must be non-negative; pass a UTC phase offset of zero seconds or greater.' );
		}

		return new self( $seconds, $anchor % $seconds );
	}

	// endregion

	// region METHODS

	/**
	 * Returns the stable recurrence representation included in schedule fingerprints.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{type: 'every', value: int, anchor?: int}
	 */
	public function fingerprint_value(): array {
		$value = array(
			'type'  => 'every',
			'value' => $this->interval,
		);
		if ( null !== $this->anchor ) {
			$value['anchor'] = $this->anchor;
		}

		return $value;
	}

	// endregion
}
