<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\Fixtures\CommentCountRecountBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\Fixtures\DemoConsumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\Fixtures\SiteHealthPingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the demo consumer registers and executes through only the public engine surface.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[Group( 'degraded' )]
final class DemoConsumerTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Post type isolated to this consumer's comment-count queue. */
	private const POST_TYPE = 'a8csp_demo_item';

	/** Transient isolated to the directly dispatched task. */
	private const MANUAL_SNAPSHOT_TRANSIENT = 'a8csp_demo_manual_site_health_snapshot';

	/** Owner-qualified demo task identity. */
	private const TASK_IDENTITY = DemoConsumer::OWNER . ':' . SiteHealthPingTask::NAME;

	/** Owner-qualified demo batch identity. */
	private const BATCH_IDENTITY = DemoConsumer::OWNER . ':' . CommentCountRecountBatch::NAME;

	/** Owner-qualified demo schedule identity. */
	private const SCHEDULE_IDENTITY = DemoConsumer::OWNER . ':' . DemoConsumer::SCHEDULE_NAME;

	/**
	 * Posts created for the batch proof and removed during teardown.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var list<int>
	 */
	private array $post_ids = array();

	// endregion.

	// region LIFECYCLE.

	/**
	 * Registers the isolated post type used as the batch's by-reference lookup key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function setUp(): void {
		parent::setUp();
		\delete_transient( self::MANUAL_SNAPSHOT_TRANSIENT );
		\delete_transient( SiteHealthPingTask::SNAPSHOT_TRANSIENT );

		$post_type = \register_post_type(
			self::POST_TYPE,
			array(
				'public'   => false,
				'supports' => array( 'comments', 'title' ),
			)
		);
		self::assertInstanceOf( \WP_Post_Type::class, $post_type );
		$this->sweep_fixture_posts();
	}

	/**
	 * Removes fixture content and the isolated post type before engine-state cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function tearDown(): void {
		try {
			\delete_transient( self::MANUAL_SNAPSHOT_TRANSIENT );
			\delete_transient( SiteHealthPingTask::SNAPSHOT_TRANSIENT );

			foreach ( $this->post_ids as $post_id ) {
				\wp_delete_post( $post_id, true );
			}

			if ( \post_type_exists( self::POST_TYPE ) ) {
				\unregister_post_type( self::POST_TYPE );
			}
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * The init entry point registers, a real schedule occurrence dispatches, and every run drains.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_demo_consumer_runs_task_schedule_and_batch_end_to_end(): void {
		$first_post_id    = $this->create_commented_post( 'Demo recount one' );
		$second_post_id   = $this->create_commented_post( 'Demo recount two' );
		$scheduled_args   = array( 'transient' => SiteHealthPingTask::SNAPSHOT_TRANSIENT );
		$scheduled_run_id = null;

		$this->expect_option( 'a8csp_bgte_latest_run_' . self::TASK_IDENTITY );
		$this->expect_option( 'a8csp_bgte_latest_run_' . self::BATCH_IDENTITY );

		$task_started_named     = array();
		$task_started_generic   = array();
		$task_completed_named   = array();
		$task_completed_generic = array();
		$recounted              = array();
		$batch_succeeded        = array();
		$batch_completed_named  = array();
		$batch_completed_global = array();

		\add_action(
			'a8csp_background_tasks/started/' . self::TASK_IDENTITY,
			static function ( string $run_id, array $args ) use ( &$task_started_named, &$scheduled_run_id, $scheduled_args ): void {
				$task_started_named[] = array( $run_id, $args );
				if ( $scheduled_args === $args ) {
					$scheduled_run_id = $run_id;
				}
			},
			10,
			2
		);
		\add_action(
			'a8csp_background_tasks/started',
			static function ( string $name, string $run_id, array $args ) use ( &$task_started_generic ): void {
				if ( self::TASK_IDENTITY === $name ) {
					$task_started_generic[] = array( $name, $run_id, $args );
				}
			},
			10,
			3
		);
		\add_action(
			'a8csp_background_tasks/completed/' . self::TASK_IDENTITY,
			static function ( string $run_id, array $args ) use ( &$task_completed_named ): void {
				$task_completed_named[] = array( $run_id, $args );
			},
			10,
			2
		);
		\add_action(
			'a8csp_background_tasks/completed',
			static function ( string $name, string $run_id, array $args ) use ( &$task_completed_generic ): void {
				if ( self::TASK_IDENTITY === $name ) {
					$task_completed_generic[] = array( $name, $run_id, $args );
				}
			},
			10,
			3
		);
		\add_action(
			CommentCountRecountBatch::RECOUNTED_HOOK,
			static function ( int $post_id, string $run_id ) use ( &$recounted ): void {
				$recounted[] = array( $post_id, $run_id );
			},
			10,
			2
		);
		\add_action(
			CommentCountRecountBatch::SUCCEEDED_HOOK,
			static function ( string $run_id, array $args ) use ( &$batch_succeeded ): void {
				$batch_succeeded[] = array( $run_id, $args );
			},
			10,
			2
		);
		\add_action(
			'a8csp_background_tasks/completed/' . self::BATCH_IDENTITY,
			static function ( string $run_id, array $args ) use ( &$batch_completed_named ): void {
				$batch_completed_named[] = array( $run_id, $args );
			},
			10,
			2
		);
		\add_action(
			'a8csp_background_tasks/completed',
			static function ( string $name, string $run_id, array $args ) use ( &$batch_completed_global ): void {
				if ( self::BATCH_IDENTITY === $name ) {
					$batch_completed_global[] = array( $name, $run_id, $args );
				}
			},
			10,
			3
		);
		\add_filter( 'a8csp_background_tasks/continue_delay', static fn ( int $delay, string $name ): int => self::BATCH_IDENTITY === $name ? 0 : $delay, 10, 2 );

		// WordPress booted before PHPUnit, so isolate this callback instead of rerunning every init subscriber.
		\remove_all_actions( 'init' );
		$consumer = new DemoConsumer( 1 );
		$consumer->boot();
		self::assertNotFalse( \has_action( 'init', array( $consumer, 'register_background_work' ) ), 'The demo entry point must register its declarations from init' );
		$api = null;
		\add_action(
			'init',
			static function () use ( &$api ): void {
				$api = \a8csp_bgte( DemoConsumer::OWNER );
			},
			\PHP_INT_MAX
		);
		\do_action( 'init' );
		self::assertInstanceOf( Consumer::class, $api );

		$manual_args = array( 'transient' => self::MANUAL_SNAPSHOT_TRANSIENT );
		$manual      = $api->tasks()->enqueue( SiteHealthPingTask::NAME, $manual_args );
		self::assertInstanceOf( Success::class, $manual, 'The demo task must enqueue through the owner-bound facade' );
		self::assertIsString( $manual->value );
		$manual_run_id = $manual->value;
		self::assertSame( array( array( $manual_run_id, $manual_args ) ), $task_started_named );
		self::assertSame( array( array( self::TASK_IDENTITY, $manual_run_id, $manual_args ) ), $task_started_generic );

		$schedule_due_before_manual = \did_action( 'a8csp_background_tasks/schedule_due' );
		$matches_manual_run         = static fn ( string $hook, array $args ): bool =>
			'a8csp_background_tasks/run_task' === $hook
			&& ( $args[1] ?? null ) === $manual_run_id;
		$manual_actions_processed   = \class_exists( \ActionScheduler::class )
			? $this->run_matching_due_action( $matches_manual_run )
			: $this->run_matching_due_cron_event( $matches_manual_run );
		self::assertSame( 1, $manual_actions_processed, 'The scheduler must execute the direct demo task' );
		self::assertSame( $schedule_due_before_manual, \did_action( 'a8csp_background_tasks/schedule_due' ), 'The direct task drive must not consume the recurring schedule occurrence' );
		$this->assert_site_health_snapshot( self::MANUAL_SNAPSHOT_TRANSIENT );
		self::assertSame( array( array( $manual_run_id, $manual_args ) ), $task_completed_named );
		self::assertSame( array( array( self::TASK_IDENTITY, $manual_run_id, $manual_args ) ), $task_completed_generic );

		\sleep( 1 );
		$schedule_due_before = \did_action( 'a8csp_background_tasks/schedule_due' );
		self::assertSame( 1, \class_exists( \ActionScheduler::class ) ? $this->run_matching_due_action( static fn ( string $hook, array $args ): bool => 'a8csp_background_tasks/schedule_due' === $hook && array( self::SCHEDULE_IDENTITY ) === $args ) : $this->run_matching_due_cron_event( static fn ( string $hook, array $args ): bool => 'a8csp_background_tasks/schedule_due' === $hook && array( self::SCHEDULE_IDENTITY ) === $args ), 'The scheduler must execute the demo consumer recurring occurrence' );
		self::assertSame( $schedule_due_before + 1, \did_action( 'a8csp_background_tasks/schedule_due' ), 'The registered recurring occurrence must fire the engine schedule-due action' );
		self::assertCount( 2, $task_started_named, 'Schedule delivery must enqueue one additional task run' );
		self::assertCount( 2, $task_started_generic, 'Schedule delivery must publish the generic started hook' );
		self::assertFalse( \get_transient( SiteHealthPingTask::SNAPSHOT_TRANSIENT ), 'Schedule delivery must enqueue instead of running the task inline' );
		self::assertIsString( $scheduled_run_id );
		self::assertContains( array( $scheduled_run_id, $scheduled_args ), $task_started_named );

		$stopped_schedule = $api->schedules()->sync( array() );
		self::assertInstanceOf( Success::class, $stopped_schedule, 'Public owner sync must stop the one-second proof recurrence after its occurrence fires' );
		self::assertSame( 1, $this->run_next_engine_action(), 'The scheduler must execute the scheduled demo task' );
		$this->assert_site_health_snapshot( SiteHealthPingTask::SNAPSHOT_TRANSIENT );
		self::assertSame(
			array(
				array( $manual_run_id, $manual_args ),
				array( $scheduled_run_id, $scheduled_args ),
			),
			$task_completed_named
		);

		// Core already counted the comments at insertion; force stale zeros so only the demo
		// batch's recount can produce the terminal counts asserted below.
		global $wpdb;

		self::assertInstanceOf( \wpdb::class, $wpdb );
		foreach ( array( $first_post_id, $second_post_id ) as $stale_post_id ) {
			$updated = $wpdb->update( $wpdb->posts, array( 'comment_count' => 0 ), array( 'ID' => $stale_post_id ), array( '%d' ), array( '%d' ) );
			self::assertSame( 1, $updated, 'The recount proof must stale exactly one persisted comment count' );
			\clean_post_cache( $stale_post_id );
			self::assertSame( 0, (int) \get_comments_number( $stale_post_id ) );
		}

		$batch_args = array( 'post_type' => self::POST_TYPE );
		$batch      = $api->batches()->start( CommentCountRecountBatch::NAME, $batch_args );
		self::assertInstanceOf( Success::class, $batch, 'The demo batch must start through the owner-bound facade' );
		self::assertIsString( $batch->value );
		$batch_run_id = $batch->value;

		for ( $step = 1; 7 >= $step; ++$step ) {
			self::assertSame( 1, $this->run_next_engine_action(), \sprintf( 'The scheduler must execute demo batch action %d of 7.', $step ) );
		}

		self::assertSame(
			array(
				array( $first_post_id, $batch_run_id ),
				array( $second_post_id, $batch_run_id ),
			),
			$recounted,
			'Each queried post must run as its own chunk under the same batch run'
		);
		self::assertSame( array( array( $batch_run_id, $batch_args ) ), $batch_succeeded );
		self::assertSame( array( array( $batch_run_id, $batch_args ) ), $batch_completed_named );
		self::assertSame( array( array( self::BATCH_IDENTITY, $batch_run_id, $batch_args ) ), $batch_completed_global );
		self::assertSame( 1, (int) \get_comments_number( $first_post_id ) );
		self::assertSame( 1, (int) \get_comments_number( $second_post_id ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Creates one published post with one approved comment for the recount queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $title Post title.
	 *
	 * @return  int
	 */
	private function create_commented_post( string $title ): int {
		$post_id = \wp_insert_post(
			array(
				'comment_status' => 'open',
				'post_status'    => 'publish',
				'post_title'     => $title,
				'post_type'      => self::POST_TYPE,
			),
			true
		);
		self::assertIsInt( $post_id );
		self::assertGreaterThan( 0, $post_id );
		$this->post_ids[] = $post_id;

		$comment_id = \wp_insert_comment(
			array(
				'comment_approved'     => 1,
				'comment_author'       => 'Demo fixture',
				'comment_author_email' => 'demo-fixture@example.com',
				'comment_content'      => 'One approved comment for the recount proof.',
				'comment_post_ID'      => $post_id,
			)
		);
		self::assertIsInt( $comment_id );
		self::assertGreaterThan( 0, $comment_id );

		return $post_id;
	}

	/**
	 * Removes posts left by an interrupted prior run of this persistent rig.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function sweep_fixture_posts(): void {
		$post_ids = \get_posts(
			array(
				'fields'         => 'ids',
				'post_status'    => 'any',
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => -1,
			)
		);
		foreach ( $post_ids as $post_id ) {
			if ( \is_int( $post_id ) ) {
				\wp_delete_post( $post_id, true );
			}
		}
	}

	/**
	 * Asserts one task run persisted the deterministic current-site snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $transient Consumer-owned transient key.
	 *
	 * @return  void
	 */
	private function assert_site_health_snapshot( string $transient ): void {
		self::assertSame(
			array(
				'environment'       => \wp_get_environment_type(),
				'site_url'          => \home_url( '/' ),
				'wordpress_version' => \get_bloginfo( 'version' ),
			),
			\get_transient( $transient )
		);
	}

	// endregion.
}
