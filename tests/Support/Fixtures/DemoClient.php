<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\Fixtures;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;

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

	/**
	 * Owner-qualified identity that scopes lifecycle reactions to the recount execution.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const string COMMENT_COUNT_RECOUNT_IDENTITY = self::OWNER . ':' . CommentCountRecountChunkedJob::NAME;

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
		\add_action( 'a8csp_jobs_engine/completed/' . self::COMMENT_COUNT_RECOUNT_IDENTITY, array( $this, 'publish_comment_count_recount_success' ), 10, 2 );
		\add_action( 'a8csp_jobs_engine/failed', array( $this, 'publish_comment_count_recount_failure' ) );
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
			'register its site-health job'   => $engine->jobs()->register(
				JobDefinition::job(
					SiteHealthPingJob::NAME,
					new SiteHealthPingJob(),
					new JobOptions( retry: new RetryPolicy( max_attempts: 3, base_delay: 30, multiplier: 2, max_delay: 5 * \MINUTE_IN_SECONDS ) ),
				)
			),
			'register its comment-count job' => $engine->jobs()->register(
				JobDefinition::chunked_job(
					CommentCountRecountChunkedJob::NAME,
					new CommentCountRecountChunkedJob(),
					new JobOptions( retry: new RetryPolicy( max_attempts: 3, base_delay: 5, multiplier: 2, max_delay: \MINUTE_IN_SECONDS ) ),
				)
			),
			'synchronize its schedule set'   => $engine->schedules()->sync(
				new Schedule(
					name: self::SCHEDULE_NAME,
					recurrence: Recurrence::every( $this->site_health_interval ),
					job: SiteHealthPingJob::NAME,
					args: array( 'transient' => SiteHealthPingJob::SNAPSHOT_TRANSIENT ),
					catch_up: CatchUpPolicy::RunOnce,
					priority: 10,
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

	/**
	 * Publishes the successful recount through the client's observation channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunId                         $run_id     Engine-assigned chunked job run identifier.
	 * @param   array<array-key, mixed>        $start_args Original chunked job start arguments.
	 *
	 * @return  void
	 */
	public function publish_comment_count_recount_success( RunId $run_id, array $start_args ): void {
		/**
		 * Fires after every comment-count chunk succeeds.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   string                  $run_id     Engine-assigned chunked job run identifier.
		 * @param   array<array-key, mixed> $start_args Original chunked job start arguments.
		 */
		\do_action( CommentCountRecountChunkedJob::SUCCEEDED_HOOK, (string) $run_id, $start_args );
	}

	/**
	 * Publishes this client's terminal recount failures through its observation channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunFailure $failure Persisted terminal-failure value.
	 *
	 * @return  void
	 */
	public function publish_comment_count_recount_failure( RunFailure $failure ): void {
		if ( self::COMMENT_COUNT_RECOUNT_IDENTITY !== $failure->identity ) {
			return;
		}

		/**
		 * Fires after the demo chunked job reaches terminal failure.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   RunFailure $failure Persisted terminal-failure value.
		 */
		\do_action( CommentCountRecountChunkedJob::FAILED_HOOK, $failure );
	}

	// endregion.
}
