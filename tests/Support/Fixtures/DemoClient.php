<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\Fixtures;

/**
 * Demonstrates a client plugin entry point built entirely on the public engine facade.
 *
 * A client plugin constructs this class from its main file. The registered `init` callback then
 * declares its job, chunked job, and complete owner-scoped schedule set on every request.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class DemoClient {
	// region FIELDS AND CONSTANTS.

	/**
	 * Stable owner isolating this client's complete schedule declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string OWNER = 'a8csp-demo-consumer';

	/**
	 * Stable name of the recurring site-health schedule.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string SCHEDULE_NAME = 'site-health-ping';

	/**
	 * Client-owned observation channel for registration failures.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string LOG_HOOK = 'a8csp_bgje_demo/log';

	// endregion.

	// region MAGIC METHODS.

	/**
	 * Constructor.
	 *
	 * The hourly default is the client configuration. A shorter positive interval lets the
	 * executable fixture prove a real recurring occurrence without altering engine persistence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $site_health_interval Recurring site-health interval in seconds.
	 *
	 * @throws  \InvalidArgumentException When the interval is not positive.
	 */
	public function __construct(
		private int $site_health_interval = \HOUR_IN_SECONDS,
	) {
		if ( 1 > $this->site_health_interval ) {
			throw new \InvalidArgumentException( 'The demo site-health interval must be positive; pass at least one second.' );
		}
	}

	// endregion.

	// region METHODS.

	/**
	 * Hooks the client registration at the normal plugin `init` boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function boot(): void {
		\add_action( 'init', array( $this, 'register_background_work' ) );
	}

	/**
	 * Registers one job, one chunked job, and the owner's complete schedule set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function register_background_work(): void {
		$engine   = \a8csp_bgje( self::OWNER );
		$outcomes = array(
			'register its site-health job'   => $engine->register( new SiteHealthPingJob() ),
			'register its comment-count job' => $engine->register( new CommentCountRecountChunkedJob() ),
			'synchronize its schedule set'   => $engine->sync_schedules(
				array(
					array(
						'name'     => self::SCHEDULE_NAME,
						'every'    => $this->site_health_interval,
						'job'      => SiteHealthPingJob::NAME,
						'args'     => array( 'transient' => SiteHealthPingJob::SNAPSHOT_TRANSIENT ),
						'catch_up' => 'run_once',
						'priority' => 10,
					),
				)
			),
		);

		foreach ( $outcomes as $operation => $outcome ) {
			if ( ! $outcome instanceof \WP_Error ) {
				continue;
			}

			/**
			 * Fires when the demo client cannot publish one background-work declaration.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $level   Client log level.
			 * @param   string                  $message Client failure message.
			 * @param   array<array-key, mixed> $context Structured failure context.
			 */
			\do_action(
				self::LOG_HOOK,
				'error',
				'The demo client could not ' . $operation . '.',
				array(
					'code'    => $outcome->get_error_code(),
					'message' => $outcome->get_error_message(),
					'data'    => $outcome->get_error_data(),
				)
			);
		}
	}

	// endregion.
}
