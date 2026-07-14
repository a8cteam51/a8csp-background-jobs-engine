<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Pins GitHub release lookup, comparison, and cache behavior.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class GitHubUpdateCheckTest extends TestCase {
	private const API_URL       = 'https://api.github.com/repos/a8cteam51/a8csp-background-tasks-engine/releases/latest';
	private const PLUGIN_FILE   = 'a8csp-background-tasks-engine/a8csp-background-tasks-engine.php';
	private const TRANSIENT_KEY = 'a8csp_bgte_github_latest_release';

	/**
	 * Loads the plugin entry file with guarded WordPress API stubs.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-time-constant-stubs.php';
		require_once __DIR__ . '/wp-update-stubs.php';

		$GLOBALS['a8csp_bgte_test_hooks']                = array();
		$GLOBALS['a8csp_bgte_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_filter_registrations'] = array();

		require_once \dirname( __DIR__, 2 ) . '/a8csp-background-tasks-engine.php';
	}

	/**
	 * Resets HTTP scripts, request records, and the transient store.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_remote_requests']     = array();
		$GLOBALS['a8csp_bgte_test_set_transient_calls'] = array();
		$GLOBALS['a8csp_bgte_test_transients']          = array();
	}

	/**
	 * A newer release returns the matching asset and populates the positive cache.
	 *
	 * @return  void
	 */
	public function test_newer_release_is_offered_and_cached(): void {
		$release                                    = $this->release( 'v1.1.0' );
		$GLOBALS['a8csp_bgte_test_remote_response'] = $this->http_response( $release );

		self::assertSame(
			array(
				'slug'    => 'a8csp-background-tasks-engine',
				'version' => '1.1.0',
				'url'     => $release['html_url'],
				'package' => $release['assets'][0]['browser_download_url'],
			),
			( $this->update_callback() )( false, $this->plugin_data( '1.0.0' ), self::PLUGIN_FILE )
		);
		self::assertSame( array( self::API_URL ), $GLOBALS['a8csp_bgte_test_remote_requests'] );
		self::assertSame(
			array(
				array(
					'transient'  => self::TRANSIENT_KEY,
					'value'      => $release,
					'expiration' => \HOUR_IN_SECONDS,
				),
			),
			$GLOBALS['a8csp_bgte_test_set_transient_calls']
		);
	}

	/**
	 * A foreign asset before the plugin ZIP does not affect the update package.
	 *
	 * @return  void
	 */
	public function test_newer_release_selects_matching_asset_after_foreign_asset(): void {
		$release                                    = $this->release( 'v1.1.0' );
		$plugin_asset                               = $release['assets'][0];
		$release['assets']                          = array(
			array(
				'name'                 => 'checksums.txt',
				'browser_download_url' => 'https://github.com/a8cteam51/a8csp-background-tasks-engine/releases/download/v1.1.0/checksums.txt',
			),
			$plugin_asset,
		);
		$GLOBALS['a8csp_bgte_test_remote_response'] = $this->http_response( $release );

		self::assertSame(
			array(
				'slug'    => 'a8csp-background-tasks-engine',
				'version' => '1.1.0',
				'url'     => $release['html_url'],
				'package' => $plugin_asset['browser_download_url'],
			),
			( $this->update_callback() )( false, $this->plugin_data( '1.0.0' ), self::PLUGIN_FILE )
		);
	}

	/**
	 * Equal and older releases produce no update offer.
	 *
	 * @param   string $installed_version Installed plugin version.
	 *
	 * @return  void
	 */
	#[DataProvider( 'non_newer_versions' )]
	public function test_equal_or_older_release_is_not_offered( string $installed_version ): void {
		$release                                    = $this->release( 'v1.1.0' );
		$GLOBALS['a8csp_bgte_test_remote_response'] = $this->http_response( $release );

		self::assertFalse( ( $this->update_callback() )( false, $this->plugin_data( $installed_version ), self::PLUGIN_FILE ) );
	}

	/**
	 * A failed API request creates the short negative cache.
	 *
	 * @return  void
	 */
	public function test_api_failure_is_negatively_cached(): void {
		$GLOBALS['a8csp_bgte_test_remote_response'] = new \WP_Error( 'http_error' );

		self::assertFalse( ( $this->update_callback() )( false, $this->plugin_data( '1.0.0' ), self::PLUGIN_FILE ) );
		$this->assert_negative_cache();
	}

	/**
	 * A release without assets is guarded and negatively cached.
	 *
	 * @return  void
	 */
	public function test_empty_assets_are_negatively_cached(): void {
		$release                                    = $this->release( 'v1.1.0' );
		$release['assets']                          = array();
		$GLOBALS['a8csp_bgte_test_remote_response'] = $this->http_response( $release );

		self::assertFalse( ( $this->update_callback() )( false, $this->plugin_data( '1.0.0' ), self::PLUGIN_FILE ) );
		$this->assert_negative_cache();
	}

	/**
	 * A release without the plugin ZIP is guarded and negatively cached.
	 *
	 * @return  void
	 */
	public function test_missing_matching_asset_is_negatively_cached(): void {
		$release                                    = $this->release( 'v1.1.0' );
		$release['assets']                          = array(
			array(
				'name'                 => 'checksums.txt',
				'browser_download_url' => 'https://github.com/a8cteam51/a8csp-background-tasks-engine/releases/download/v1.1.0/checksums.txt',
			),
		);
		$GLOBALS['a8csp_bgte_test_remote_response'] = $this->http_response( $release );

		self::assertFalse( ( $this->update_callback() )( false, $this->plugin_data( '1.0.0' ), self::PLUGIN_FILE ) );
		$this->assert_negative_cache();
	}

	/**
	 * Returns the update callback recorded while loading the plugin entry file.
	 *
	 * @return  \Closure(false|array<string, mixed>, array{Version: string, TextDomain: string}, string): (false|array<string, mixed>)
	 */
	private function update_callback(): \Closure {
		$registrations = $GLOBALS['a8csp_bgte_test_filter_registrations'] ?? null;
		self::assertIsArray( $registrations );

		foreach ( $registrations as $registration ) {
			self::assertIsArray( $registration );
			if ( 'update_plugins_github.com' !== ( $registration['hook_name'] ?? null ) ) {
				continue;
			}

			$callback = $registration['callback'] ?? null;
			self::assertInstanceOf( \Closure::class, $callback );

			return $callback;
		}

		self::fail( 'The plugin did not register its GitHub update callback.' );
	}

	/**
	 * Supplies installed versions for equal and older release comparisons.
	 *
	 * @return  iterable<string, array{string}>
	 */
	public static function non_newer_versions(): iterable {
		yield 'equal release' => array( '1.1.0' );
		yield 'older release' => array( '1.2.0' );
	}

	/**
	 * Returns the plugin data consumed by the update hook.
	 *
	 * @param   string $version Installed version.
	 *
	 * @return  array{Version: string, TextDomain: string}
	 */
	private function plugin_data( string $version ): array {
		return array(
			'Version'    => $version,
			'TextDomain' => 'a8csp-background-tasks-engine',
		);
	}

	/**
	 * Returns a complete GitHub release payload.
	 *
	 * @param   string $tag Release tag.
	 *
	 * @return  array{tag_name: string, html_url: string, assets: list<array{name: string, browser_download_url: string}>}
	 */
	private function release( string $tag ): array {
		return array(
			'tag_name' => $tag,
			'html_url' => 'https://github.com/a8cteam51/a8csp-background-tasks-engine/releases/tag/' . $tag,
			'assets'   => array(
				array(
					'name'                 => 'a8csp-background-tasks-engine.zip',
					'browser_download_url' => 'https://github.com/a8cteam51/a8csp-background-tasks-engine/releases/download/' . $tag . '/a8csp-background-tasks-engine.zip',
				),
			),
		);
	}

	/**
	 * Wraps a release as a successful WordPress HTTP response.
	 *
	 * @param   array<string, mixed> $release Release payload.
	 *
	 * @return  array{response: array{code: 200}, body: string}
	 */
	private function http_response( array $release ): array {
		return array(
			'response' => array( 'code' => 200 ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in this isolated unit test.
			'body'     => \json_encode( $release, JSON_THROW_ON_ERROR ),
		);
	}

	/**
	 * Asserts the five-minute empty-array cache entry.
	 *
	 * @return  void
	 */
	private function assert_negative_cache(): void {
		self::assertSame(
			array(
				array(
					'transient'  => self::TRANSIENT_KEY,
					'value'      => array(),
					'expiration' => 5 * \MINUTE_IN_SECONDS,
				),
			),
			$GLOBALS['a8csp_bgte_test_set_transient_calls']
		);
	}
}
