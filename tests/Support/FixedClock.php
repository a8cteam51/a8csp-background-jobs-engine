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

	/**
	 * Constructor.
	 *
	 * @param   int $timestamp Current Unix timestamp.
	 */
	public function __construct( public int $timestamp ) {}

	/** {@inheritDoc} */
	#[\Override]
	public function now(): DateTimeImmutable {
		++$this->calls;

		return new DateTimeImmutable( '@' . $this->timestamp );
	}
}
