<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\Fixtures\CommentCountRecountChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\Fixtures\DemoClient;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\Fixtures\SiteHealthPingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the demo client registers and executes through only the public engine surface.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[Group( 'degraded' )]
final class DemoClientTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Post type isolated to this client's comment-count queue. */
	private const string POST_TYPE = 'a8csp_demo_item';

	/** Transient isolated to the directly dispatched job. */
	private const string MANUAL_SNAPSHOT_TRANSIENT = 'a8csp_demo_manual_site_health_snapshot';

	/** Owner-qualified demo job identity. */
	private const string JOB_IDENTITY = DemoClient::OWNER . ':' . SiteHealthPingJob::NAME;

	/** Owner-qualified demo chunked job identity. */
	private const string CHUNKED_JOB_IDENTITY = DemoClient::OWNER . ':' . CommentCountRecountChunkedJob::NAME;

	/** Owner-qualified demo schedule identity. */
	private const string SCHEDULE_IDENTITY = DemoClient::OWNER . ':' . DemoClient::SCHEDULE_NAME;

	/** Documented owner-scoped schedule-registration option. */
	private const string SCHEDULE_OPTION = 'a8csp_bgje_schedule_registrations_' . DemoClient::OWNER;

	/**
	 * Posts created for the chunked job proof and removed during teardown.
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
	 * Registers the isolated post type used as the chunked job's by-reference lookup key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function setUp(): void {
		parent::setUp();
		\delete_transient( self::MANUAL_SNAPSHOT_TRANSIENT );
		\delete_transient( SiteHealthPingJob::SNAPSHOT_TRANSIENT );

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
			\delete_transient( SiteHealthPingJob::SNAPSHOT_TRANSIENT );

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
	public function test_demo_client_runs_job_schedule_and_chunked_job_end_to_end(): void {
		$first_post_id    = $this->create_commented_post( 'Demo recount one' );
		$second_post_id   = $this->create_commented_post( 'Demo recount two' );
		$scheduled_args   = array( 'transient' => SiteHealthPingJob::SNAPSHOT_TRANSIENT );
		$scheduled_run_id = null;

		$this->expect_option( 'a8csp_bgje_latest_run_' . self::JOB_IDENTITY );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::CHUNKED_JOB_IDENTITY );

		$job_started_named            = array();
		$job_started_generic          = array();
		$job_completed_named          = array();
		$job_completed_generic        = array();
		$recounted                    = array();
		$chunked_job_succeeded        = array();
		$chunked_job_completed_named  = array();
		$chunked_job_completed_global = array();

		\add_action(
			'a8csp_jobs_engine/started/' . self::JOB_IDENTITY,
			static function ( string $run_id, array $args ) use ( &$job_started_named, &$scheduled_run_id, $scheduled_args ): void {
				$job_started_named[] = array( $run_id, $args );
				if ( $scheduled_args === $args ) {
					$scheduled_run_id = $run_id;
				}
			},
			10,
			2
		);
		\add_action(
			'a8csp_jobs_engine/started',
			static function ( string $name, string $run_id, array $args ) use ( &$job_started_generic ): void {
				if ( self::JOB_IDENTITY === $name ) {
					$job_started_generic[] = array( $name, $run_id, $args );
				}
			},
			10,
			3
		);
		\add_action(
			'a8csp_jobs_engine/completed/' . self::JOB_IDENTITY,
			static function ( string $run_id, array $args, ?string $previous_completed_run_id ) use ( &$job_completed_named ): void {
				$job_completed_named[] = array( $run_id, $args, $previous_completed_run_id );
			},
			10,
			3
		);
		\add_action(
			'a8csp_jobs_engine/completed',
			static function ( string $name, string $run_id, array $args, ?string $previous_completed_run_id ) use ( &$job_completed_generic ): void {
				if ( self::JOB_IDENTITY === $name ) {
					$job_completed_generic[] = array( $name, $run_id, $args, $previous_completed_run_id );
				}
			},
			10,
			4
		);
		\add_action(
			CommentCountRecountChunkedJob::RECOUNTED_HOOK,
			static function ( int $post_id, string $run_id ) use ( &$recounted ): void {
				$recounted[] = array( $post_id, $run_id );
			},
			10,
			2
		);
		\add_action(
			CommentCountRecountChunkedJob::SUCCEEDED_HOOK,
			static function ( string $run_id, array $args ) use ( &$chunked_job_succeeded ): void {
				$chunked_job_succeeded[] = array( $run_id, $args );
			},
			10,
			2
		);
		\add_action(
			'a8csp_jobs_engine/completed/' . self::CHUNKED_JOB_IDENTITY,
			static function ( string $run_id, array $args, ?string $previous_completed_run_id ) use ( &$chunked_job_completed_named ): void {
				$chunked_job_completed_named[] = array( $run_id, $args, $previous_completed_run_id );
			},
			10,
			3
		);
		\add_action(
			'a8csp_jobs_engine/completed',
			static function ( string $name, string $run_id, array $args, ?string $previous_completed_run_id ) use ( &$chunked_job_completed_global ): void {
				if ( self::CHUNKED_JOB_IDENTITY === $name ) {
					$chunked_job_completed_global[] = array( $name, $run_id, $args, $previous_completed_run_id );
				}
			},
			10,
			4
		);
		\add_filter( 'a8csp_jobs_engine/continue_delay', static fn ( int $delay, string $name ): int => self::CHUNKED_JOB_IDENTITY === $name ? 0 : $delay, 10, 2 );

		// WordPress booted before PHPUnit, so isolate this callback instead of rerunning every init subscriber.
		\remove_all_actions( 'init' );
		$client = new DemoClient( 1 );
		$client->boot();
		self::assertNotFalse( \has_action( 'init', array( $client, 'register_background_work' ) ), 'The demo entry point must register its declarations from init' );
		$api = null;
		\add_action(
			'init',
			static function () use ( &$api ): void {
				$api = \A8C\SpecialProjects\BackgroundJobsEngine\Engine\Component::client( DemoClient::OWNER );
			},
			\PHP_INT_MAX
		);
		\do_action( 'init' );
		self::assertInstanceOf( Client::class, $api );

		$manual_args = array( 'transient' => self::MANUAL_SNAPSHOT_TRANSIENT );
		$manual      = $api->jobs()->enqueue( SiteHealthPingJob::NAME, $manual_args );
		self::assertInstanceOf( Success::class, $manual, 'The demo job must enqueue through the owner-bound facade' );
		self::assertIsString( $manual->value );
		$manual_run_id = $manual->value;
		self::assertSame( array( array( $manual_run_id, $manual_args ) ), $job_started_named );
		self::assertSame( array( array( self::JOB_IDENTITY, $manual_run_id, $manual_args ) ), $job_started_generic );

		$schedule_due_before_manual = \did_action( 'a8csp_jobs_engine/schedule_due' );
		$matches_manual_run         = static fn ( string $hook, array $args ): bool =>
			'a8csp_jobs_engine/run_job' === $hook
			&& ( $args[1] ?? null ) === $manual_run_id;
		$manual_actions_processed   = \class_exists( \ActionScheduler::class )
			? $this->run_matching_due_action( $matches_manual_run )
			: $this->run_matching_due_cron_event( $matches_manual_run );
		self::assertSame( 1, $manual_actions_processed, 'The scheduler must execute the direct demo job' );
		self::assertSame( $schedule_due_before_manual, \did_action( 'a8csp_jobs_engine/schedule_due' ), 'The direct job drive must not consume the recurring schedule occurrence' );
		$this->assert_site_health_snapshot( self::MANUAL_SNAPSHOT_TRANSIENT );
		self::assertSame( array( array( $manual_run_id, $manual_args, null ) ), $job_completed_named );
		self::assertSame( array( array( self::JOB_IDENTITY, $manual_run_id, $manual_args, null ) ), $job_completed_generic );

		$this->make_demo_schedule_due();
		$schedule_due_before = \did_action( 'a8csp_jobs_engine/schedule_due' );
		self::assertSame( 1, \class_exists( \ActionScheduler::class ) ? $this->run_matching_due_action( static fn ( string $hook, array $args ): bool => 'a8csp_jobs_engine/schedule_due' === $hook && array( self::SCHEDULE_IDENTITY ) === $args ) : $this->run_matching_due_cron_event( static fn ( string $hook, array $args ): bool => 'a8csp_jobs_engine/schedule_due' === $hook && array( self::SCHEDULE_IDENTITY ) === $args ), 'The scheduler must execute the demo client recurring occurrence' );
		self::assertSame( $schedule_due_before + 1, \did_action( 'a8csp_jobs_engine/schedule_due' ), 'The registered recurring occurrence must fire the engine schedule-due action' );
		self::assertCount( 2, $job_started_named, 'Schedule delivery must enqueue one additional job run' );
		self::assertCount( 2, $job_started_generic, 'Schedule delivery must publish the generic started hook' );
		self::assertFalse( \get_transient( SiteHealthPingJob::SNAPSHOT_TRANSIENT ), 'Schedule delivery must enqueue instead of running the job inline' );
		self::assertIsString( $scheduled_run_id );
		self::assertContains( array( $scheduled_run_id, $scheduled_args ), $job_started_named );

		$stopped_schedule = $api->schedules()->sync( array() );
		self::assertInstanceOf( Success::class, $stopped_schedule, 'Public owner sync must stop the one-second proof recurrence after its occurrence fires' );
		self::assertSame( 1, $this->run_next_engine_action(), 'The scheduler must execute the scheduled demo job' );
		$this->assert_site_health_snapshot( SiteHealthPingJob::SNAPSHOT_TRANSIENT );
		self::assertSame(
			array(
				array( $manual_run_id, $manual_args, null ),
				array( $scheduled_run_id, $scheduled_args, $manual_run_id ),
			),
			$job_completed_named
		);
		self::assertSame(
			array(
				array( self::JOB_IDENTITY, $manual_run_id, $manual_args, null ),
				array( self::JOB_IDENTITY, $scheduled_run_id, $scheduled_args, $manual_run_id ),
			),
			$job_completed_generic
		);

		// Core already counted the comments at insertion; force stale zeros so only the demo
		// chunked job's recount can produce the terminal counts asserted below.
		global $wpdb;

		self::assertInstanceOf( \wpdb::class, $wpdb );
		foreach ( array( $first_post_id, $second_post_id ) as $stale_post_id ) {
			$updated = $wpdb->update( $wpdb->posts, array( 'comment_count' => 0 ), array( 'ID' => $stale_post_id ), array( '%d' ), array( '%d' ) );
			self::assertSame( 1, $updated, 'The recount proof must stale exactly one persisted comment count' );
			\clean_post_cache( $stale_post_id );
			self::assertSame( 0, (int) \get_comments_number( $stale_post_id ) );
		}

		$chunked_job_args = array( 'post_type' => self::POST_TYPE );
		$chunked_job      = $api->chunked_jobs()->start( CommentCountRecountChunkedJob::NAME, $chunked_job_args );
		self::assertInstanceOf( Success::class, $chunked_job, 'The demo chunked job must start through the owner-bound facade' );
		self::assertIsString( $chunked_job->value );
		$chunked_job_run_id = $chunked_job->value;

		for ( $step = 1; 5 >= $step; ++$step ) {
			self::assertSame( 1, $this->run_next_engine_action(), \sprintf( 'The scheduler must execute demo chunked job action %d of 5.', $step ) );
		}

		self::assertSame(
			array(
				array( $first_post_id, $chunked_job_run_id ),
				array( $second_post_id, $chunked_job_run_id ),
			),
			$recounted,
			'Each queried post must run as its own chunk under the same chunked job run'
		);
		self::assertSame( array( array( $chunked_job_run_id, $chunked_job_args ) ), $chunked_job_succeeded );
		self::assertSame( array( array( $chunked_job_run_id, $chunked_job_args, null ) ), $chunked_job_completed_named );
		self::assertSame( array( array( self::CHUNKED_JOB_IDENTITY, $chunked_job_run_id, $chunked_job_args, null ) ), $chunked_job_completed_global );
		self::assertSame( 1, (int) \get_comments_number( $first_post_id ) );
		self::assertSame( 1, (int) \get_comments_number( $second_post_id ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Advances the real recurring occurrence from its persisted next-due token without wall time.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function make_demo_schedule_due(): void {
		$registrations = \get_option( self::SCHEDULE_OPTION, null );
		self::assertIsArray( $registrations );
		$registration = $registrations[ self::SCHEDULE_IDENTITY ] ?? null;
		self::assertIsArray( $registration );
		$next_due = $registration['next_due'] ?? null;
		self::assertIsInt( $next_due );
		$due = $next_due - 1;
		self::assertGreaterThan( 0, $due );

		$registration['next_due']                 = $due;
		$registrations[ self::SCHEDULE_IDENTITY ] = $registration;
		self::assertTrue( \update_option( self::SCHEDULE_OPTION, $registrations, false ), 'The persisted demo occurrence must advance into its due window' );

		$args = array( self::SCHEDULE_IDENTITY );
		if ( \class_exists( \ActionScheduler::class ) ) {
			\as_unschedule_all_actions( 'a8csp_jobs_engine/schedule_due', $args, self::SCHEDULE_IDENTITY );
			$action_id = \as_schedule_recurring_action( $due, 1, 'a8csp_jobs_engine/schedule_due', $args, self::SCHEDULE_IDENTITY, true, 10 );
			self::assertGreaterThan( 0, $action_id, 'Action Scheduler must persist the advanced demo occurrence' );

			return;
		}

		$events = $this->wordpress_cron_events( 'a8csp_jobs_engine/schedule_due', $args );
		self::assertCount( 1, $events );
		$event = $events[0];
		self::assertSame( $next_due, $event['timestamp'] );
		self::assertIsString( $event['schedule'] );
		self::assertSame( 1, $event['interval'] );
		self::assertTrue( true === \wp_unschedule_event( $event['timestamp'], 'a8csp_jobs_engine/schedule_due', $args, true ), 'WP-Cron must remove the future demo occurrence before advancing it' );
		self::assertTrue( true === \wp_schedule_event( $due, $event['schedule'], 'a8csp_jobs_engine/schedule_due', $args, true ), 'WP-Cron must persist the advanced demo occurrence' );
	}

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
	 * Asserts one job run persisted the deterministic current-site snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $transient Client-owned transient key.
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
