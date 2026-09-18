<?php declare( strict_types=1 );

/**
 * Stageable plugin-header stub for Unit tests that drive the real metadata reader.
 * `get_plugin_data()` returns `$GLOBALS['a8csp_bgje_test_plugin_data']`, so a test can change
 * which headers exist between two reads and observe how the reader answers. The hook stubs it
 * pulls in supply the `did_action()` fire counts the reader's cache condition consults.
 * The `function_exists()` guard keeps this file inert wherever WordPress is loaded.
 *
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

require_once __DIR__ . '/wp-hook-stubs.php';

if ( ! \function_exists( 'get_plugin_data' ) ) {
	/**
	 * Returns the staged plugin metadata, ignoring the file path like a canned parse would.
	 *
	 * @param   string $plugin_file Absolute plugin file path.
	 * @param   bool   $markup      Whether to apply markup.
	 * @param   bool   $translate   Whether to translate metadata.
	 *
	 * @return  array<string, string>
	 *
	 * @phpstan-impure
	 */
	function get_plugin_data( $plugin_file, $markup = true, $translate = true ) {
		/** @var array<string, string> $plugin_data */
		$plugin_data = $GLOBALS['a8csp_bgje_test_plugin_data'] ?? array();

		return $plugin_data;
	}
}
