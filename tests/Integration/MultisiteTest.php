<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Verifies the documented network-uninstall and site-bound storage contracts in real multisite.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[Group( 'multisite' )]
final class MultisiteTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** One sentinel from every option family documented for operators. */
	private const DOCUMENTED_OPTION_TEMPLATES = array(
		'a8csp_bgte_schedule_registrations_multisite-%d',
		'a8csp_bgte_run_multisite-%d:task_run-1',
		'a8csp_bgte_failed_runs_multisite-%d:task',
		'a8csp_bgte_latest_run_multisite-%d:task',
		'a8csp_bgte_run_history_multisite-%d:task',
		'a8csp_bgte_overlap_lock_multisite-%d:task_args-hash',
		'a8csp_bgte_occurrence_lease_multisite-%d-registration-hash',
		'a8csp_bgte_cleanup_intent_multisite-%d-registration-hash',
	);

	/**
	 * Sites provisioned by the current test and removed during teardown.
	 *
	 * @var list<int>
	 */
	private array $created_site_ids = array();

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

			foreach ( $this->created_site_ids as $site_id ) {
				$result = \wp_delete_site( $site_id );
				self::assertNotInstanceOf( \WP_Error::class, $result );
			}
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * Network uninstall sweeps the documented engine ownership prefix from every site.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[RunInSeparateProcess]
	public function test_network_uninstall_sweeps_every_site(): void {
		$this->assert_engine_is_network_active();
		$site_ids = $this->ensure_site_count( 3 );

		foreach ( $site_ids as $site_id ) {
			\switch_to_blog( $site_id );
			try {
				foreach ( self::DOCUMENTED_OPTION_TEMPLATES as $template ) {
					$option = \sprintf( $template, $site_id );
					self::assertTrue( \update_option( $option, 'sentinel', false ), "Site {$site_id} must persist the '{$option}' uninstall sentinel" );
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
				self::assertSame( array(), self::engine_option_names(), "Network uninstall must leave site {$site_id} with zero a8csp_bgte_ rows" );
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
			$consumer = \a8csp_bgte( 'multisite-contract' );
			$consumer->tasks()->register( new RecordingTask( 'site-bound-task' ) );

			$this->expectException( \LogicException::class );

			$result = $consumer->tasks()->enqueue( 'site-bound-task' );
			self::fail( \sprintf( 'Expected storage access to fail after switch_to_blog(); got %s.', \get_debug_type( $result ) ) );
		} finally {
			\restore_current_blog();
		}
	}

	// endregion.

	// region HELPERS.

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

		self::assertTrue( \is_plugin_active_for_network( \A8CSP_BGTE_BASENAME ), 'The multisite wp-env must network-activate the engine before running the contract group' );
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
			$domain = \is_subdomain_install() ? 'bgte-' . $suffix . '.' . $network->domain : $network->domain;
			$path   = \is_subdomain_install() ? $network->path : \trailingslashit( $network->path ) . 'bgte-' . $suffix . '/';
			$result = \wp_insert_site(
				array(
					'domain'     => $domain,
					'network_id' => (int) $network->id,
					'path'       => $path,
					'title'      => 'Background tasks multisite fixture',
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
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s ORDER BY `option_name` ASC', $wpdb->options, $wpdb->esc_like( 'a8csp_bgte_' ) . '%' ) );
		self::assertIsArray( $names );
		self::assertContainsOnlyString( $names );

		return \array_values( $names );
	}

	// endregion.
}
