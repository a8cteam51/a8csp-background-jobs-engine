<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Schedules;

\defined( 'ABSPATH' ) || exit;

/**
 * Fixed-interval or calendar-expression cadence for one schedule.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Cadence {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'every'|'cron' $type  Cadence representation.
	 * @param   int|string     $value Positive seconds or cron expression.
	 */
	private function __construct(
		private string $type,
		private int|string $value,
	) {}

	// endregion

	// region FACTORIES

	/**
	 * Creates a fixed elapsed-time cadence.
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
			throw new \InvalidArgumentException(
				'Cadence interval must be positive; pass a value of at least one second.'
			);
		}

		return new self( 'every', $seconds );
	}

	/**
	 * Creates a calendar cron-expression cadence.
	 *
	 * Backend capability is evaluated when the schedule is synchronized.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $expression Cron expression.
	 *
	 * @return  self
	 */
	public static function cron( string $expression ): self {
		return new self( 'cron', $expression );
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the fixed interval, or null for a cron expression.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int|null
	 */
	public function interval(): ?int {
		return \is_int( $this->value ) ? $this->value : null;
	}

	/**
	 * Returns the cron expression, or null for a fixed interval.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string|null
	 */
	public function expression(): ?string {
		return \is_string( $this->value ) ? $this->value : null;
	}

	/**
	 * Returns the stable cadence representation included in schedule fingerprints.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{type: 'every'|'cron', value: int|string}
	 */
	public function fingerprint_value(): array {
		return array(
			'type'  => $this->type,
			'value' => $this->value,
		);
	}

	// endregion
}
