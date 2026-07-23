<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Verifies the documented network-uninstall and site-bound storage contracts in real multisite.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[Group( 'multisite' )]
final class MultisiteTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** One sentinel from every option family documented for operators. */
	private const array DOCUMENTED_OPTION_TEMPLATES = array(
		'a8csp_bgje_schedule_registrations_multisite-%d',
		'a8csp_bgje_active_run_multisite-%d:job_run-1',
		'a8csp_bgje_failed_runs_multisite-%d:job',
		'a8csp_bgje_latest_run_multisite-%d:job',
		'a8csp_bgje_run_history_multisite-%d:job',
		'a8csp_bgje_overlap_lock_multisite-%d:job_args-hash',
		'a8csp_bgje_occurrence_lease_multisite-%d-registration-hash',
		'a8csp_bgje_cleanup_intent_multisite-%d-registration-hash',
		'a8csp_bgje_cleanup_sweep_cursor',
	);

	/** Internal delivery hooks that may retain scheduled work. */
	private const array DELIVERY_HOOKS = array(
		'a8csp_bgje/internal/deliver',
		'a8csp_bgje/internal/schedule_due',
	);

	/**
	 * Sites provisioned by the current test and removed during teardown.
	 *
	 * @var list<int>
	 */
	private array $created_site_ids = array();

	/**
	 * Sites where the current test seeds lifecycle work and teardown clears interrupted runs.
	 *
	 * @var list<int>
	 */
	private array $scheduled_site_ids = array();

	// endregion.

	// region LIFECYCLE.

	/**
	 * Restricts the group to the converted network environment.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! \is_multisite() ) {
			self::markTestSkipped( 'The multisite contract requires the converted network wp-env.' );
		}
	}

	/**
	 * Removes provisioned sites before the shared integration cleanup runs on the primary site.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function tearDown(): void {
		// Skipped single-site runs still tear down, where the ms_* functions never load.
		if ( ! \is_multisite() ) {
			parent::tearDown();

			return;
		}

		try {
			while ( \ms_is_switched() ) {
				\restore_current_blog();
			}

			try {
				foreach ( $this->scheduled_site_ids as $site_id ) {
					\switch_to_blog( $site_id );
					try {
						self::clear_scheduled_work();
					} finally {
						\restore_current_blog();
					}
				}
			} finally {
				foreach ( $this->created_site_ids as $site_id ) {
					$result = \wp_delete_site( $site_id );
					self::assertNotInstanceOf( \WP_Error::class, $result );
				}
			}
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * Network uninstall sweeps documented engine options and scheduled work from every site.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	public function test_network_uninstall_sweeps_every_site(): void {
		$this->assert_engine_is_network_active();
		$site_ids     = $this->ensure_site_count( 3 );
		$scheduled_at = \time() + \HOUR_IN_SECONDS;

		foreach ( $site_ids as $site_id ) {
			\switch_to_blog( $site_id );
			try {
				self::initialize_action_scheduler_schema();
				$this->scheduled_site_ids[] = $site_id;
				$schedule_args              = array( 'multisite-uninstall', \sprintf( 'site-%d', $site_id ), $site_id );
				$schedule_group             = \sprintf( 'multisite-uninstall|site-%d', $site_id );
				$wp_cron                    = new WPCronBackend();
				$action_scheduler           = new ActionSchedulerBackend();

				foreach ( self::DOCUMENTED_OPTION_TEMPLATES as $template ) {
					$option = \sprintf( $template, $site_id );
					self::assertTrue( \update_option( $option, 'sentinel', false ), "Site {$site_id} must persist the '{$option}' uninstall sentinel" );
				}

				foreach ( self::DELIVERY_HOOKS as $hook ) {
					self::assertInstanceOf( Success::class, $wp_cron->schedule_single( $hook, $scheduled_at, $schedule_args ) );
					self::assertInstanceOf( Success::class, $action_scheduler->schedule_single( $hook, $scheduled_at, $schedule_args, $schedule_group ) );
					self::assertSame( $scheduled_at, $wp_cron->get_next_scheduled( $hook, $schedule_args ), "Site {$site_id} must persist the '{$hook}' WP-Cron uninstall sentinel" );
					self::assertSame( $scheduled_at, $action_scheduler->get_next_scheduled( $hook, $schedule_args, $schedule_group ), "Site {$site_id} must persist the '{$hook}' Action Scheduler uninstall sentinel" );
				}
			} finally {
				\restore_current_blog();
			}
		}

		\define( 'WP_UNINSTALL_PLUGIN', true );
		require \dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( $site_ids as $site_id ) {
			\switch_to_blog( $site_id );
			try {
				// Action Scheduler binds its table names when a store initializes, not when the blog switches.
				self::initialize_action_scheduler_schema();
				self::assertSame( array(), self::engine_option_names(), "Network uninstall must leave site {$site_id} with zero a8csp_bgje_ rows" );

				$schedule_args    = array( 'multisite-uninstall', \sprintf( 'site-%d', $site_id ), $site_id );
				$schedule_group   = \sprintf( 'multisite-uninstall|site-%d', $site_id );
				$wp_cron          = new WPCronBackend();
				$action_scheduler = new ActionSchedulerBackend();
				foreach ( self::DELIVERY_HOOKS as $hook ) {
					self::assertFalse( $wp_cron->is_scheduled( $hook, $schedule_args ), "Network uninstall must remove every '{$hook}' WP-Cron event from site {$site_id}" );
					self::assertFalse( $action_scheduler->is_scheduled( $hook, $schedule_args, $schedule_group ), "Network uninstall must remove every pending '{$hook}' Action Scheduler action from site {$site_id}" );
				}
			} finally {
				\restore_current_blog();
			}
		}
	}

	/**
	 * Behavioral contract: v1 storage is request-site-bound, so a public operation after an ambient
	 * blog switch fails loudly instead of writing through the graph built for another site.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_storage_operation_fails_loudly_after_switch_to_blog(): void {
		$this->assert_engine_is_network_active();
		$current_site_id = \get_current_blog_id();
		$site_ids        = $this->ensure_site_count( 2 );
		$other_site_ids  = \array_values( \array_filter( $site_ids, static fn ( int $site_id ): bool => $current_site_id !== $site_id ) );
		$other_site_id   = $other_site_ids[0] ?? null;
		self::assertIsInt( $other_site_id );

		\switch_to_blog( $other_site_id );
		try {
			$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( 'multisite-contract' );
			$client->register( ( new RecordingJob( 'site-bound-job' ) )->definition() );

			$this->expectException( \LogicException::class );

			$result = $client->dispatch( 'site-bound-job' );
			self::fail( \sprintf( 'Expected storage access to fail after switch_to_blog(); got %s.', \get_debug_type( $result ) ) );
		} finally {
			\restore_current_blog();
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Creates the complete Action Scheduler custom-table schema for the switched site.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private static function initialize_action_scheduler_schema(): void {
		$store = \ActionScheduler::store();
		self::assertInstanceOf( \ActionScheduler_DBStore::class, $store );
		$store->init();

		$logger = \ActionScheduler::logger();
		self::assertInstanceOf( \ActionScheduler_DBLogger::class, $logger );
		$logger->init();
	}

	/**
	 * Clears lifecycle work that may persist after an interrupted test.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private static function clear_scheduled_work(): void {
		foreach ( self::DELIVERY_HOOKS as $hook ) {
			\wp_unschedule_hook( $hook );
			if ( \function_exists( 'as_unschedule_all_actions' ) ) {
				\as_unschedule_all_actions( $hook );
			}
		}
	}

	/**
	 * Asserts that the converted environment activates the engine for the complete network.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function assert_engine_is_network_active(): void {
		if ( ! \function_exists( 'is_plugin_active_for_network' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/plugin.php';
		}

		self::assertTrue( \is_plugin_active_for_network( \A8CSP_BGJE_BASENAME ), 'The multisite wp-env must network-activate the engine before running the contract group' );
	}

	/**
	 * Returns at least the requested number of initialized network sites in ID order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $minimum Required site count.
	 *
	 * @return  list<int>
	 */
	private function ensure_site_count( int $minimum ): array {
		$site_ids = self::network_site_ids();
		$network  = \get_network();
		self::assertInstanceOf( \WP_Network::class, $network );
		$site_count = \count( $site_ids );

		while ( $site_count < $minimum ) {
			$suffix = \strtolower( \wp_generate_password( 10, false, false ) );
			$domain = \is_subdomain_install() ? 'bgje-' . $suffix . '.' . $network->domain : $network->domain;
			$path   = \is_subdomain_install() ? $network->path : \trailingslashit( $network->path ) . 'bgje-' . $suffix . '/';
			$result = \wp_insert_site(
				array(
					'domain'     => $domain,
					'network_id' => (int) $network->id,
					'path'       => $path,
					'title'      => 'Background jobs multisite fixture',
					'user_id'    => \get_current_user_id(),
				)
			);
			self::assertIsInt( $result );
			$this->created_site_ids[] = $result;
			$site_ids                 = self::network_site_ids();
			$site_count               = \count( $site_ids );
		}

		return $site_ids;
	}

	/**
	 * Returns network site IDs in ascending order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<int>
	 */
	private static function network_site_ids(): array {
		$site_ids = array();
		$offset   = 0;
		do {
			$page = \get_sites(
				array(
					'fields'  => 'ids',
					'number'  => 100,
					'offset'  => $offset,
					'orderby' => 'id',
					'order'   => 'ASC',
				)
			);
			self::assertIsList( $page );
			foreach ( $page as $site_id ) {
				self::assertIsInt( $site_id );
				$site_ids[] = $site_id;
			}

			$page_count = \count( $page );
			$offset    += 100;
		} while ( 100 === $page_count );

		return $site_ids;
	}

	/**
	 * Returns every option inside the documented engine ownership prefix in lexical order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>
	 */
	private static function engine_option_names(): array {
		global $wpdb;

		self::assertInstanceOf( \wpdb::class, $wpdb );
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s ORDER BY `option_name` ASC', $wpdb->options, $wpdb->esc_like( 'a8csp_bgje_' ) . '%' ) );
		self::assertIsArray( $names );
		self::assertContainsOnlyString( $names );

		return \array_values( $names );
	}

	// endregion.
}
