<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Mutable deterministic clock with observable reads.
 */
final class FixedClock implements ClockInterface {
	// region FIELDS AND CONSTANTS.

	/** Number of clock reads. */
	public int $calls = 0;

	/** Current Unix timestamp. */
	public int $timestamp;

	// endregion.

	// region MAGIC METHODS.

	/**
	 * Constructor.
	 *
	 * @param   int|DateTimeImmutable $instant Current Unix timestamp or instant.
	 */
	public function __construct( int|DateTimeImmutable $instant ) {
		$this->timestamp = \is_int( $instant ) ? $instant : $instant->getTimestamp();
	}

	// endregion.

	// region METHODS.

	/** {@inheritDoc} */
	#[\Override]
	public function now(): DateTimeImmutable {
		++$this->calls;

		return new DateTimeImmutable( '@' . $this->timestamp );
	}

	// endregion.
}
