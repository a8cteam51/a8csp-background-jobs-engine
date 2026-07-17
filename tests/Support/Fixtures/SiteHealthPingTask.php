<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\Fixtures;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\NonRetryableException;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;

/**
 * Demonstrates a small task that stores one idempotent site-health snapshot.
 *
 * Repeated delivery overwrites the same consumer-owned transient key with the same current-site
 * fields, so it cannot append duplicate records or repeat an external command.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class SiteHealthPingTask implements TaskInterface {
	// region FIELDS AND CONSTANTS.

	/**
	 * Stable task identity used by direct and scheduled dispatches.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string NAME = 'a8csp-bgte-demo-site-health-ping';

	/**
	 * Default consumer-owned transient key for the scheduled snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string SNAPSHOT_TRANSIENT = 'a8csp_demo_site_health_snapshot';

	// endregion.

	// region INHERITED METHODS.

	/**
	 * Returns the stable task identity registered with the engine.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	#[\Override]
	public function get_name(): string {
		return self::NAME;
	}

	/**
	 * Returns the shared ceiling for one site-health snapshot invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	#[\Override]
	public function max_callback_runtime(): int {
		return self::DEFAULT_MAX_CALLBACK_RUNTIME;
	}

	/**
	 * Overwrites one consumer-owned transient with the current site-health snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args Invocation arguments containing `transient`.
	 *
	 * @throws  NonRetryableException When `transient` is absent, invalid, or over WordPress's length limit.
	 * @throws  \RuntimeException         When WordPress cannot persist the snapshot; retryable.
	 *
	 * @return  void
	 */
	#[\Override]
	public function handle( array $args ): void {
		$transient = $args['transient'] ?? null;
		// WordPress caps transient names at 172 characters; a longer name is a permanent input
		// defect, so it escapes the retry ladder instead of burning attempts.
		if ( ! \is_string( $transient ) || 1 !== \preg_match( '/\A[a-z0-9_]{1,172}\z/', $transient ) ) {
			throw new NonRetryableException( 'Site-health ping arguments require a lowercase transient key of at most 172 characters; pass the consumer storage key when dispatching the task.' );
		}

		$snapshot = array(
			'environment'       => \wp_get_environment_type(),
			'site_url'          => \home_url( '/' ),
			'wordpress_version' => \get_bloginfo( 'version' ),
		);
		$saved    = \set_transient( $transient, $snapshot, 0 );
		if ( ! $saved && \get_transient( $transient ) !== $snapshot ) {
			throw new \RuntimeException( \sprintf( 'WordPress could not persist the site-health snapshot in transient "%s".', $transient ) );
		}
	}

	/**
	 * Returns the bounded retry policy for transient persistence failures.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RetryPolicy
	 */
	#[\Override]
	public function get_retry_policy(): RetryPolicy {
		return new RetryPolicy( max_attempts: 3, base_delay: 30, multiplier: 2, max_delay: 5 * \MINUTE_IN_SECONDS );
	}

	// endregion.
}
