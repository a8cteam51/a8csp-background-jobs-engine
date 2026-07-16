<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\Fixtures;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;

/**
 * Demonstrates a consumer plugin entry point built entirely on the public engine facade.
 *
 * A consumer plugin constructs this class from its main file. The registered `init` callback then
 * declares its task, batch, and complete owner-scoped schedule set on every request.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class DemoConsumer {
	// region FIELDS AND CONSTANTS.

	/**
	 * Stable owner isolating this consumer's complete schedule declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const OWNER = 'a8csp-demo-consumer';

	/**
	 * Stable name of the recurring site-health schedule.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const SCHEDULE_NAME = 'site-health-ping';

	/**
	 * Consumer-owned observation channel for registration failures.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const LOG_HOOK = 'a8csp_bgte_demo/log';

	// endregion.

	// region MAGIC METHODS.

	/**
	 * Constructor.
	 *
	 * The hourly default is the consumer configuration. A shorter positive interval lets the
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
	 * Hooks the consumer registration at the normal plugin `init` boundary.
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
	 * Registers one task, one batch, and the owner's complete schedule set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function register_background_work(): void {
		$consumer = \a8csp_bgte( self::OWNER );
		$consumer->tasks()->register( new SiteHealthPingTask() );
		$consumer->batches()->register( new CommentCountRecountBatch() );

		$synced = $consumer->schedules()->sync(
			array(
				new Schedule( self::SCHEDULE_NAME, Recurrence::every( $this->site_health_interval ), SiteHealthPingTask::NAME, array( 'transient' => SiteHealthPingTask::SNAPSHOT_TRANSIENT ), OverlapPolicy::Skip, CatchUpPolicy::RunOnce, 10 ),
			)
		);
		if ( $synced->is_failure() ) {
			/**
			 * Fires when the demo consumer cannot synchronize its schedule declaration.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $level   Consumer log level.
			 * @param   string                  $message Consumer failure message.
			 * @param   array<array-key, mixed> $context Structured failure context.
			 */
			\do_action( self::LOG_HOOK, 'error', 'The demo consumer could not synchronize its site-health schedule.', array( 'error_type' => \get_debug_type( $synced->error ) ) );
		}
	}

	// endregion.
}
