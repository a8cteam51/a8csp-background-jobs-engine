<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards agreement between the plugin header floors and composer.json.
 *
 * @load-bearing structural-guard
 * @pin-rationale This release-metadata guard keeps the plugin header and composer PHP floors in cross-file agreement so packaged installs cannot advertise an unsupported runtime.
 */
final class PluginHeaderFloorsTest extends TestCase {
	/**
	 * The PHP floor declared in the plugin header must match composer.json.
	 *
	 * @return  void
	 */
	public function test_php_floor_matches_composer(): void {
		$header = $this->get_plugin_header();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read in a WP-less unit test; wp_remote_get() is for remote URLs and isn't even loaded here.
		$composer = \json_decode( (string) \file_get_contents( \dirname( __DIR__, 2 ) . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );

		self::assertSame( 1, \preg_match( '/Requires PHP:\s*([\d.]+)/', $header, $matches ) );
		$header_floor = $matches[1] ?? null;
		self::assertIsString( $header_floor );

		self::assertIsArray( $composer );
		$requirements = $composer['require'] ?? null;
		self::assertIsArray( $requirements );
		$composer_floor = $requirements['php'] ?? null;
		self::assertIsString( $composer_floor );

		self::assertSame( '>=' . $header_floor, $composer_floor );
	}

	/**
	 * Locates the main plugin file without hardcoding its name.
	 *
	 * @return  string
	 */
	private function get_plugin_header(): string {
		$files = \glob( \dirname( __DIR__, 2 ) . '/*.php' );
		foreach ( ( false !== $files ? $files : array() ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read in a WP-less unit test; wp_remote_get() is for remote URLs and isn't even loaded here.
			$contents = (string) \file_get_contents( $file );
			if ( \str_contains( $contents, 'Plugin Name:' ) ) {
				return $contents;
			}
		}
		self::fail( 'No main plugin file found.' );
	}
}
