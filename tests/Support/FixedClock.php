<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Mutable deterministic clock with observable reads.
 */
final class FixedClock implements ClockInterface {
	/** Number of clock reads. */
	public int $calls = 0;

	/** Current Unix timestamp. */
	public int $timestamp;

	/**
	 * Constructor.
	 *
	 * @param   int|DateTimeImmutable $instant Current Unix timestamp or instant.
	 */
	public function __construct( int|DateTimeImmutable $instant ) {
		$this->timestamp = \is_int( $instant ) ? $instant : $instant->getTimestamp();
	}

	/** {@inheritDoc} */
	#[\Override]
	public function now(): DateTimeImmutable {
		++$this->calls;

		return new DateTimeImmutable( '@' . $this->timestamp );
	}
}
