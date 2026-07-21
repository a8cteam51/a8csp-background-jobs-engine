<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the WordPress update filter over a deterministic HTTP boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
#[CoversFunction( 'a8csp_bgje_check_github_release_update' )]
final class GitHubUpdateCheckTest extends TestCase {
	private const string API_URL_PRERELEASE       = 'https://api.github.com/repos/a8cteam51/a8csp-background-jobs-engine/releases?per_page=10';
	private const string API_URL_STABLE           = 'https://api.github.com/repos/a8cteam51/a8csp-background-jobs-engine/releases/latest';
	private const string PLUGIN_FILE              = 'a8csp-background-jobs-engine/a8csp-background-jobs-engine.php';
	private const string TRANSIENT_KEY_PRERELEASE = 'a8csp_bgje_github_latest_release_prerelease';
	private const string TRANSIENT_KEY_STABLE     = 'a8csp_bgje_github_latest_release_stable';

	/**
	 * Loads the named bootstrap helper with guarded WordPress API stubs and clean transient state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}
		if ( ! \defined( 'A8CSP_BGJE_BASENAME' ) ) {
			\define( 'A8CSP_BGJE_BASENAME', self::PLUGIN_FILE );
		}

		require_once __DIR__ . '/wp-time-constant-stubs.php';
		require_once __DIR__ . '/wp-update-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/functions-bootstrap.php';

		$GLOBALS['a8csp_bgje_test_remote_requests']     = array();
		$GLOBALS['a8csp_bgje_test_set_transient_calls'] = array();
		$GLOBALS['a8csp_bgje_test_transients']          = array();
		unset( $GLOBALS['a8csp_bgje_test_remote_response'] );
	}

	/**
	 * A newer release returns the matching asset and populates the positive cache.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_newer_release_is_offered_and_cached(): void {
		$release                                    = $this->release( 'v1.1.0' );
		$GLOBALS['a8csp_bgje_test_remote_response'] = $this->http_response( $release );

		self::assertSame(
			array(
				'slug'    => 'a8csp-background-jobs-engine',
				'version' => '1.1.0',
				'url'     => $release['html_url'],
				'package' => $release['assets'][0]['browser_download_url'],
			),
			$this->apply_update_filter( '1.0.0' )
		);
		self::assertSame( array( self::API_URL_STABLE ), $GLOBALS['a8csp_bgje_test_remote_requests'] );
		self::assertSame(
			array(
				array(
					'transient'  => self::TRANSIENT_KEY_STABLE,
					'value'      => $release,
					'expiration' => \HOUR_IN_SECONDS,
				),
			),
			$GLOBALS['a8csp_bgje_test_set_transient_calls']
		);
	}

	/**
	 * A foreign asset before the plugin ZIP does not affect the update package.
	 *
	 * @load-bearing operator-contract
	 * @pin-rationale Release automation and installed-site updates agree on the exact a8csp-background-jobs-engine.zip asset name; selecting any other release asset would install the wrong artifact.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_newer_release_selects_matching_asset_after_foreign_asset(): void {
		$release                                    = $this->release( 'v1.1.0' );
		$plugin_asset                               = $release['assets'][0];
		$release['assets']                          = array(
			array(
				'name'                 => 'checksums.txt',
				'browser_download_url' => 'https://github.com/a8cteam51/a8csp-background-jobs-engine/releases/download/v1.1.0/checksums.txt',
			),
			$plugin_asset,
		);
		$GLOBALS['a8csp_bgje_test_remote_response'] = $this->http_response( $release );

		self::assertSame(
			array(
				'slug'    => 'a8csp-background-jobs-engine',
				'version' => '1.1.0',
				'url'     => $release['html_url'],
				'package' => $plugin_asset['browser_download_url'],
			),
			$this->apply_update_filter( '1.0.0' )
		);
	}

	/**
	 * A prerelease install follows the full release list and skips draft entries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_prerelease_install_follows_the_release_list_channel(): void {
		$draft          = $this->release( 'v1.0.0-beta.3' );
		$draft['draft'] = true;
		$beta           = $this->release( 'v1.0.0-beta.2' );

		$GLOBALS['a8csp_bgje_test_remote_response'] = $this->http_response( array( $draft, $beta ) );

		self::assertSame(
			array(
				'slug'    => 'a8csp-background-jobs-engine',
				'version' => '1.0.0-beta.2',
				'url'     => $beta['html_url'],
				'package' => $beta['assets'][0]['browser_download_url'],
			),
			$this->apply_update_filter( '1.0.0-beta.1' )
		);
		self::assertSame( array( self::API_URL_PRERELEASE ), $GLOBALS['a8csp_bgje_test_remote_requests'] );
		self::assertSame(
			array(
				array(
					'transient'  => self::TRANSIENT_KEY_PRERELEASE,
					'value'      => $beta,
					'expiration' => \HOUR_IN_SECONDS,
				),
			),
			$GLOBALS['a8csp_bgje_test_set_transient_calls']
		);
	}

	/**
	 * The highest-versioned prerelease is offered even when a lower version is published more recently and appears first.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_prerelease_install_selects_the_highest_version_over_publish_order(): void {
		$republished_older = $this->release( 'v1.0.0-beta.1' );
		$newer             = $this->release( 'v1.0.0-beta.2' );

		// GitHub orders /releases by publish time, so a re-published older tag can appear before the newer one.
		$GLOBALS['a8csp_bgje_test_remote_response'] = $this->http_response( array( $republished_older, $newer ) );

		self::assertSame(
			array(
				'slug'    => 'a8csp-background-jobs-engine',
				'version' => '1.0.0-beta.2',
				'url'     => $newer['html_url'],
				'package' => $newer['assets'][0]['browser_download_url'],
			),
			$this->apply_update_filter( '1.0.0-beta.1' )
		);
	}

	/**
	 * A stable install keeps the stable latest-release channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_stable_install_keeps_the_stable_channel(): void {
		$GLOBALS['a8csp_bgje_test_remote_response'] = $this->http_response( $this->release( 'v1.1.0' ) );

		$this->apply_update_filter( '1.0.0' );

		self::assertSame( array( self::API_URL_STABLE ), $GLOBALS['a8csp_bgje_test_remote_requests'] );
	}

	/**
	 * Stable and prerelease checks cannot reuse each other's cached channel response.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_stable_and_prerelease_channels_use_separate_caches(): void {
		$stable = $this->release( 'v1.1.0' );
		$beta   = $this->release( 'v1.2.0-beta.1' );

		$GLOBALS['a8csp_bgje_test_remote_response'] = $this->http_response( $stable );
		$this->apply_update_filter( '1.0.0' );

		$GLOBALS['a8csp_bgje_test_remote_response'] = $this->http_response( array( $beta ) );
		$this->apply_update_filter( '1.1.0-beta.1' );

		$transient_calls = $GLOBALS['a8csp_bgje_test_set_transient_calls'] ?? null;
		self::assertIsArray( $transient_calls );
		self::assertSame( array( self::API_URL_STABLE, self::API_URL_PRERELEASE ), $GLOBALS['a8csp_bgje_test_remote_requests'] );
		self::assertSame(
			array( self::TRANSIENT_KEY_STABLE, self::TRANSIENT_KEY_PRERELEASE ),
			\array_column( $transient_calls, 'transient' )
		);
	}

	/**
	 * A stable release outranks the running prerelease on the list channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_prerelease_install_is_offered_the_stable_successor(): void {
		$stable = $this->release( 'v1.0.0' );

		$GLOBALS['a8csp_bgje_test_remote_response'] = $this->http_response( array( $stable ) );

		self::assertSame(
			array(
				'slug'    => 'a8csp-background-jobs-engine',
				'version' => '1.0.0',
				'url'     => $stable['html_url'],
				'package' => $stable['assets'][0]['browser_download_url'],
			),
			$this->apply_update_filter( '1.0.0-beta.1' )
		);
	}

	/**
	 * Equal and older releases produce no update offer.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $installed_version Installed plugin version.
	 *
	 * @return  void
	 */
	#[DataProvider( 'non_newer_versions' )]
	public function test_equal_or_older_release_is_not_offered( string $installed_version ): void {
		$release                                    = $this->release( 'v1.1.0' );
		$GLOBALS['a8csp_bgje_test_remote_response'] = $this->http_response( $release );

		self::assertFalse( $this->apply_update_filter( $installed_version ) );
	}

	/**
	 * A failed API request creates the short negative cache.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_api_failure_is_negatively_cached(): void {
		$GLOBALS['a8csp_bgje_test_remote_response'] = new \WP_Error( 'http_error' );

		self::assertFalse( $this->apply_update_filter( '1.0.0' ) );
		$this->assert_negative_cache();
	}

	/**
	 * A release without assets is guarded and negatively cached.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_empty_assets_are_negatively_cached(): void {
		$release                                    = $this->release( 'v1.1.0' );
		$release['assets']                          = array();
		$GLOBALS['a8csp_bgje_test_remote_response'] = $this->http_response( $release );

		self::assertFalse( $this->apply_update_filter( '1.0.0' ) );
		$this->assert_negative_cache();
	}

	/**
	 * A release without the plugin ZIP is guarded and negatively cached.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_missing_matching_asset_is_negatively_cached(): void {
		$release                                    = $this->release( 'v1.1.0' );
		$release['assets']                          = array(
			array(
				'name'                 => 'checksums.txt',
				'browser_download_url' => 'https://github.com/a8cteam51/a8csp-background-jobs-engine/releases/download/v1.1.0/checksums.txt',
			),
		);
		$GLOBALS['a8csp_bgje_test_remote_response'] = $this->http_response( $release );

		self::assertFalse( $this->apply_update_filter( '1.0.0' ) );
		$this->assert_negative_cache();
	}

	/**
	 * Applies the production updater helper.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $installed_version Installed plugin version.
	 *
	 * @return  false|array<string, mixed>
	 */
	private function apply_update_filter( string $installed_version ): false|array {
		$result = \a8csp_bgje_check_github_release_update( false, $this->plugin_data( $installed_version ), self::PLUGIN_FILE );
		if ( false === $result ) {
			return false;
		}
		if ( ! \is_array( $result ) ) {
			throw new \LogicException( 'The update filter returned an invalid value.' );
		}

		$update = array();
		foreach ( $result as $key => $value ) {
			if ( ! \is_string( $key ) ) {
				throw new \LogicException( 'The update filter returned a non-string field name.' );
			}
			$update[ $key ] = $value;
		}

		return $update;
	}

	/**
	 * Supplies installed versions for equal and older release comparisons.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $version Installed version.
	 *
	 * @return  array{Version: string, TextDomain: string}
	 */
	private function plugin_data( string $version ): array {
		return array(
			'Version'    => $version,
			'TextDomain' => 'a8csp-background-jobs-engine',
		);
	}

	/**
	 * Returns a complete GitHub release payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $tag Release tag.
	 *
	 * @return  array{tag_name: string, html_url: string, assets: list<array{name: string, browser_download_url: string}>}
	 */
	private function release( string $tag ): array {
		return array(
			'tag_name' => $tag,
			'html_url' => 'https://github.com/a8cteam51/a8csp-background-jobs-engine/releases/tag/' . $tag,
			'assets'   => array(
				array(
					'name'                 => 'a8csp-background-jobs-engine.zip',
					'browser_download_url' => 'https://github.com/a8cteam51/a8csp-background-jobs-engine/releases/download/' . $tag . '/a8csp-background-jobs-engine.zip',
				),
			),
		);
	}

	/**
	 * Wraps a release payload as a successful WordPress HTTP response.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $release Release entry or release list.
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function assert_negative_cache(): void {
		self::assertSame(
			array(
				array(
					'transient'  => self::TRANSIENT_KEY_STABLE,
					'value'      => array(),
					'expiration' => 5 * \MINUTE_IN_SECONDS,
				),
			),
			$GLOBALS['a8csp_bgje_test_set_transient_calls']
		);
	}
}
