<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Resolves chunked job continuation delay and its twice-delay lock-staleness floor for one-off and
 * chunked job runs.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class LockWindows {
	// region FIELDS AND CONSTANTS

	/**
	 * Default inter-chunk delay whose doubled value floors every run's lock staleness.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int CONTINUE_DELAY = 60;

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
	private const int MAX_EXECUTION_LEASE = 6 * \HOUR_IN_SECONDS;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ClockInterface  $clock  Timestamp source.
	 * @param   LoggerInterface $logger Engine diagnostic sink.
	 */
	public function __construct(
		private ClockInterface $clock,
		private LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Resolves the non-negative continuation delay for one job or chunked job run.
	 *
	 * The resolved delay also sets the crash-reclamation floor: a run's lock staleness is never
	 * below twice this value, because a chunk legitimately sleeping its continuation delay must
	 * never look abandoned. Once twice the delay exceeds the lock-staleness window, filtering
	 * the delay up extends how long a crashed run waits for reclamation.
	 * This floor applies to one-off jobs even though they do not sleep between chunks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  int
	 */
	public function continue_delay( string $identity, string $run_id ): int {
		/**
		 * Filters the inter-chunk delay; twice the resolved value floors every run's lock staleness,
		 * including one-off jobs.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   int    $delay    Default continuation delay in seconds.
		 * @param   string $identity Complete owner-qualified job or chunked job identity.
		 * @param   string $run_id   Run identifier.
		 */
		$delay = \apply_filters( 'a8csp_jobs_engine/continue_delay', self::CONTINUE_DELAY, $identity, $run_id );
		if ( \is_int( $delay ) && 0 <= $delay ) {
			return $delay;
		}

		$this->logger->warning(
			'Continue-delay filter returned an invalid value; return a non-negative integer to override the default delay.',
			array(
				'name'          => $identity,
				'run_id'        => $run_id,
				'returned_type' => \get_debug_type( $delay ),
				'default_delay' => self::CONTINUE_DELAY,
			)
		);

		return self::CONTINUE_DELAY;
	}

	/**
	 * Resolves the per-run lock window at least twice the continue delay.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  int
	 */
	public function lock_staleness( string $identity, string $run_id ): int {
		$continue_delay = $this->continue_delay( $identity, $run_id );

		$default_staleness = 15 * \MINUTE_IN_SECONDS;

		/**
		 * Filters the lock-staleness window in seconds.
		 *
		 * The dynamic portion of the hook name, `$identity`, refers to the owner-qualified work identity.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   int $default_staleness Default lock-staleness window in seconds.
		 */
		$staleness = \apply_filters( 'a8csp_jobs_engine/lock_staleness/' . $identity, $default_staleness );
		if ( ! \is_int( $staleness ) || 1 > $staleness ) {
			$this->logger->warning(
				'Lock-staleness filter returned an invalid value; return a positive integer to override the default staleness window.',
				array(
					'name'              => $identity,
					'run_id'            => $run_id,
					'returned_type'     => \get_debug_type( $staleness ),
					'default_staleness' => $default_staleness,
				)
			);
			$staleness = $default_staleness;
		}

		$floor = $continue_delay > \intdiv( \PHP_INT_MAX, 2 )
			? \PHP_INT_MAX
			: 2 * $continue_delay;

		return \max( $staleness, $floor );
	}

	/**
	 * Resolves the bounded liveness credit for one client callback invocation.
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
			return JobInterface::DEFAULT_MAX_CALLBACK_RUNTIME;
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
