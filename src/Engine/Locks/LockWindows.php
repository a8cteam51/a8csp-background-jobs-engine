<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkInterface;
use Psr\Clock\ClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Resolves filterable timing policy for run locks and batch continuation.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class LockWindows {
	// region FIELDS AND CONSTANTS

	/**
	 * Default delay between completed batch chunks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const CONTINUE_DELAY = 60;

	/**
	 * Maximum credited callback window before crash reclamation can resume.
	 *
	 * A bounded ceiling prevents an accidental declaration from deferring recovery indefinitely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const MAX_EXECUTION_LEASE = 6 * \HOUR_IN_SECONDS;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ClockInterface $clock Timestamp source.
	 */
	public function __construct(
		private ClockInterface $clock
	) {}

	// endregion

	// region METHODS

	/**
	 * Resolves the non-negative inter-chunk delay for one batch run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $batch_name Stable batch name.
	 * @param   string $run_id     Run identifier.
	 *
	 * @return  int
	 */
	public function continue_delay( string $batch_name, string $run_id ): int {
		$delay = \apply_filters(
			'a8csp_background_tasks/continue_delay',
			self::CONTINUE_DELAY,
			$batch_name,
			$run_id
		);

		return \is_int( $delay ) && 0 <= $delay ? $delay : self::CONTINUE_DELAY;
	}

	/**
	 * Resolves the per-run lock window above twice the continue delay.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name   Stable task or batch name.
	 * @param   string $run_id Run identifier.
	 *
	 * @return  int
	 */
	public function lock_staleness( string $name, string $run_id ): int {
		$continue_delay = $this->continue_delay( $name, $run_id );

		$default_staleness = 15 * \MINUTE_IN_SECONDS;
		$staleness         = \apply_filters(
			'a8csp_background_tasks/lock_staleness/' . $name,
			$default_staleness
		);
		if ( ! \is_int( $staleness ) || 1 > $staleness ) {
			$staleness = $default_staleness;
		}

		$floor = $continue_delay > \intdiv( \PHP_INT_MAX, 2 )
			? \PHP_INT_MAX
			: 2 * $continue_delay;

		return \max( $staleness, $floor );
	}

	/**
	 * Resolves the bounded liveness credit for one consumer callback invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int|null $declared Declared callback ceiling in seconds, or null when no usable declaration exists.
	 *
	 * @return  int
	 */
	public function execution_lease( ?int $declared ): int {
		if ( null === $declared || 1 > $declared ) {
			return WorkInterface::DEFAULT_MAX_RUNTIME;
		}

		return \min( $declared, self::MAX_EXECUTION_LEASE );
	}

	/**
	 * Returns whether one run heartbeat exceeds the resolved strict staleness window.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $heartbeat_at Latest run heartbeat timestamp.
	 * @param   int $staleness    Positive staleness window.
	 *
	 * @return  bool
	 */
	public function heartbeat_is_stale( int $heartbeat_at, int $staleness ): bool {
		$now = $this->clock->now()->getTimestamp();

		return $now > \PHP_INT_MIN + $staleness
			&& $heartbeat_at < $now - $staleness;
	}

	// endregion
}
