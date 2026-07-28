<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\Fixtures;

use A8C\SpecialProjects\BackgroundJobsEngine\JobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContextInterface;

/**
 * Demonstrates a small job execution that stores one idempotent site-health snapshot.
 *
 * Repeated delivery overwrites the same client-owned transient key with the same current-site
 * fields, so it cannot append duplicate records or repeat an external command.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class SiteHealthPingJob implements JobExecutionInterface {
	// region FIELDS AND CONSTANTS.

	/**
	 * Stable job identity used by direct and scheduled dispatches.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string NAME = 'a8csp-bgje-demo-site-health-ping';

	/**
	 * Default client-owned transient key for the scheduled snapshot.
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
	 * Overwrites one client-owned transient with the current site-health snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts, containing `transient`.
	 * @param   RunContextInterface     $context    Controlled access to this run.
	 *
	 * @throws  NonRetryableException When `transient` is absent, invalid, or over WordPress's length limit.
	 * @throws  \RuntimeException     When WordPress cannot persist the snapshot; retryable.
	 *
	 * @return  void
	 */
	#[\Override]
	public function handle( array $start_args, RunContextInterface $context ): void {
		$transient = $start_args['transient'] ?? null;
		// WordPress caps transient names at 172 characters; a longer name is a permanent input
		// defect, so it escapes the retry ladder instead of burning attempts.
		if ( ! \is_string( $transient ) || 1 !== \preg_match( '/\A[a-z0-9_]{1,172}\z/', $transient ) ) {
			throw new NonRetryableException( 'Site-health ping arguments require a lowercase transient key of at most 172 characters; pass the client storage key when dispatching the job.' );
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

	// endregion.
}
