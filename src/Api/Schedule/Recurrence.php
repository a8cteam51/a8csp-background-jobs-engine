<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule;

\defined( 'ABSPATH' ) || exit;

/**
 * Fixed-interval recurrence for one schedule.
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
	 * @param   int $interval Positive seconds.
	 */
	private function __construct(
		private int $interval,
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

	// endregion

	// region GETTERS

	/**
	 * Returns the fixed interval.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	public function interval(): int {
		return $this->interval;
	}

	/**
	 * Returns the stable recurrence representation included in schedule fingerprints.
	 *
	 * @internal Engine change-detection seam; the representation is not consumer contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{type: 'every', value: int}
	 */
	public function fingerprint_value(): array {
		return array(
			'type'  => 'every',
			'value' => $this->interval,
		);
	}

	// endregion
}
