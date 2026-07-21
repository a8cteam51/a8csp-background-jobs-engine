<?php declare( strict_types=1 );

/**
 * WordPress bootstrap, HTTP, and transient functions for update-checker unit tests.
 *
 * The guards keep this file inert wherever WordPress supplies the real APIs.
 *
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

require_once __DIR__ . '/wp-hook-stubs.php';

if ( ! \defined( 'WP_PLUGIN_DIR' ) ) {
	\define( 'WP_PLUGIN_DIR', \dirname( __DIR__, 3 ) );
}

if ( ! \class_exists( 'WP_Error' ) ) {
	\class_alias( \A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WPErrorStub::class, 'WP_Error' );
}

if ( ! \function_exists( 'trailingslashit' ) ) {
	/**
	 * Appends a trailing slash to a filesystem path.
	 *
	 * @param   string $value Filesystem path.
	 *
	 * @return  string
	 */
	function trailingslashit( $value ) {
		return \rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! \function_exists( 'plugin_basename' ) ) {
	/**
	 * Returns a plugin path relative to the plugins directory.
	 *
	 * @param   string $file Absolute plugin file path.
	 *
	 * @return  string
	 */
	function plugin_basename( $file ) {
		return \ltrim( \str_replace( trailingslashit( WP_PLUGIN_DIR ), '', $file ), '/' );
	}
}

if ( ! \function_exists( 'plugin_dir_path' ) ) {
	/**
	 * Returns the plugin file's directory with a trailing slash.
	 *
	 * @param   string $file Absolute plugin file path.
	 *
	 * @return  string
	 */
	function plugin_dir_path( $file ) {
		return trailingslashit( \dirname( $file ) );
	}
}

if ( ! \function_exists( 'sanitize_key' ) ) {
	/**
	 * Returns a lowercase key containing WordPress key characters.
	 *
	 * @param   string $key Key to sanitize.
	 *
	 * @return  string
	 */
	function sanitize_key( $key ) {
		$sanitized_key = \preg_replace( '/[^a-z0-9_\-]/', '', \strtolower( $key ) );

		return \is_string( $sanitized_key ) ? $sanitized_key : '';
	}
}

if ( ! \function_exists( 'get_plugin_data' ) ) {
	/**
	 * Returns plugin metadata that satisfies the declared runtime floors.
	 *
	 * @param   string $plugin_file Absolute plugin file path.
	 * @param   bool   $markup      Whether to apply markup.
	 * @param   bool   $translate   Whether to translate metadata.
	 *
	 * @return  array{Name: string, PluginURI: string, Version: string, Description: string, Author: string, AuthorURI: string, TextDomain: string, DomainPath: string, Network: bool, RequiresWP: string, RequiresPHP: string, UpdateURI: string, RequiresPlugins: string, Title: string, AuthorName: string}
	 */
	function get_plugin_data( $plugin_file, $markup = true, $translate = true ) {
		return array(
			'Name'            => 'A8CSP Background Jobs Engine',
			'PluginURI'       => 'https://specialprojects.automattic.com',
			'Version'         => '1.0.0',
			'Description'     => 'A background-work engine for WordPress sites.',
			'Author'          => 'A8C Special Projects',
			'AuthorURI'       => 'https://specialprojects.automattic.com',
			'TextDomain'      => 'a8csp-background-jobs-engine',
			'DomainPath'      => '/languages',
			'Network'         => false,
			'RequiresWP'      => '7.0',
			'RequiresPHP'     => '8.5',
			'UpdateURI'       => 'https://github.com/a8cteam51/a8csp-background-jobs-engine',
			'RequiresPlugins' => '',
			'Title'           => 'A8CSP Background Jobs Engine',
			'AuthorName'      => 'A8C Special Projects',
		);
	}
}

if ( ! \function_exists( 'is_wp_version_compatible' ) ) {
	/**
	 * Reports a compatible WordPress runtime.
	 *
	 * @param   string $required Required WordPress version.
	 *
	 * @return  true
	 */
	function is_wp_version_compatible( $required ) {
		return true;
	}
}

if ( ! \function_exists( 'is_php_version_compatible' ) ) {
	/**
	 * Reports a compatible PHP runtime.
	 *
	 * @param   string $required Required PHP version.
	 *
	 * @return  true
	 */
	function is_php_version_compatible( $required ) {
		return true;
	}
}

if ( ! \function_exists( 'load_plugin_textdomain' ) ) {
	/**
	 * Accepts the plugin text-domain registration.
	 *
	 * @param   string       $domain          Text domain.
	 * @param   false|string $deprecated      Deprecated absolute path.
	 * @param   false|string $plugin_rel_path Relative language directory.
	 *
	 * @return  true
	 */
	function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
		$calls = $GLOBALS['a8csp_bgje_test_loaded_textdomains'] ?? array();
		if ( ! \is_array( $calls ) ) {
			throw new \UnexpectedValueException( 'Initialize the text-domain test ledger as an array.' );
		}

		$calls[] = array(
			'domain'          => $domain,
			'deprecated'      => $deprecated,
			'plugin_rel_path' => $plugin_rel_path,
		);

		$GLOBALS['a8csp_bgje_test_loaded_textdomains'] = $calls;

		return true;
	}
}

if ( ! \function_exists( 'get_transient' ) ) {
	/**
	 * Returns a scripted transient value or a cache miss.
	 *
	 * @param   string $transient Transient name.
	 *
	 * @return  mixed
	 *
	 * @phpstan-impure
	 */
	function get_transient( $transient ) {
		/** @var array<string, mixed> $transients */
		$transients = $GLOBALS['a8csp_bgje_test_transients'] ?? array();

		return \array_key_exists( $transient, $transients ) ? $transients[ $transient ] : false;
	}
}

if ( ! \function_exists( 'set_transient' ) ) {
	/**
	 * Stores a transient value and records its expiration.
	 *
	 * @param   string $transient Transient name.
	 * @param   mixed  $value     Transient value.
	 * @param   int    $expiration Expiration in seconds.
	 *
	 * @return  true
	 *
	 * @phpstan-impure
	 */
	function set_transient( $transient, $value, $expiration = 0 ) {
		/** @var array<string, mixed> $transients */
		$transients = $GLOBALS['a8csp_bgje_test_transients'] ?? array();
		/** @var list<array{transient: string, value: mixed, expiration: int}> $calls */
		$calls = $GLOBALS['a8csp_bgje_test_set_transient_calls'] ?? array();

		$transients[ $transient ] = $value;
		$calls[]                  = array(
			'transient'  => $transient,
			'value'      => $value,
			'expiration' => $expiration,
		);

		$GLOBALS['a8csp_bgje_test_transients']          = $transients;
		$GLOBALS['a8csp_bgje_test_set_transient_calls'] = $calls;

		return true;
	}
}

if ( ! \function_exists( 'wp_remote_get' ) ) {
	/**
	 * Returns the scripted HTTP response and records the requested URL.
	 *
	 * @param   string               $url  Request URL.
	 * @param   array<string, mixed> $args Request arguments.
	 *
	 * @return  mixed
	 *
	 * @phpstan-impure
	 */
	function wp_remote_get( $url, $args = array() ) {
		/** @var list<string> $requests */
		$requests   = $GLOBALS['a8csp_bgje_test_remote_requests'] ?? array();
		$requests[] = $url;

		$GLOBALS['a8csp_bgje_test_remote_requests'] = $requests;

		if ( ! \array_key_exists( 'a8csp_bgje_test_remote_response', $GLOBALS ) ) {
			throw new \UnexpectedValueException( 'Script an update-check HTTP response before requesting it.' );
		}

		return $GLOBALS['a8csp_bgje_test_remote_response'];
	}
}

if ( ! \function_exists( 'is_wp_error' ) ) {
	/**
	 * Identifies the scripted HTTP error.
	 *
	 * @param   mixed $thing Possible error value.
	 *
	 * @return  bool
	 *
	 * @phpstan-assert-if-true \WP_Error $thing
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}

if ( ! \function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Returns an HTTP response code from the scripted response.
	 *
	 * @param   mixed $response HTTP response.
	 *
	 * @return  int
	 */
	function wp_remote_retrieve_response_code( $response ) {
		if (
			! \is_array( $response ) ||
			! isset( $response['response'] ) ||
			! \is_array( $response['response'] ) ||
			! isset( $response['response']['code'] ) ||
			! \is_int( $response['response']['code'] )
		) {
			return 0;
		}

		return $response['response']['code'];
	}
}

if ( ! \function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Returns the body from the scripted HTTP response.
	 *
	 * @param   mixed $response HTTP response.
	 *
	 * @return  string
	 */
	function wp_remote_retrieve_body( $response ) {
		if ( ! \is_array( $response ) || ! isset( $response['body'] ) || ! \is_string( $response['body'] ) ) {
			return '';
		}

		return $response['body'];
	}
}
