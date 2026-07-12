<?php declare( strict_types=1 );

/**
 * WordPress site, serialization, and cache functions used by lock-row unit tests.
 *
 * @package A8C\SpecialProjects\BackgroundTasksEngine
 */

if ( ! \defined( 'ARRAY_A' ) ) {
	\define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! \function_exists( 'get_current_blog_id' ) ) {
	/** Returns the scripted current site ID. */
	function get_current_blog_id(): int {
		$blog_id = $GLOBALS['a8csp_bgte_test_blog_id'] ?? 1;
		if ( ! \is_int( $blog_id ) ) {
			throw new \UnexpectedValueException( 'Initialize the test blog ID as an integer.' );
		}

		return $blog_id;
	}
}

if ( ! \function_exists( 'maybe_serialize' ) ) {
	/**
	 * Serializes arrays and objects using WordPress option-row semantics.
	 *
	 * @param   mixed $value Value to serialize.
	 *
	 * @return  mixed
	 */
	function maybe_serialize( mixed $value ): mixed {
		return \is_array( $value ) || \is_object( $value )
			? \call_user_func( 'serialize', $value )
			: $value;
	}
}

if ( ! \function_exists( 'maybe_unserialize' ) ) {
	/**
	 * Unserializes valid data and preserves malformed raw strings.
	 *
	 * @param   mixed $value Raw value.
	 *
	 * @return  mixed
	 */
	function maybe_unserialize( mixed $value ): mixed {
		if ( ! \is_string( $value ) ) {
			return $value;
		}

		\call_user_func(
			'set_error_handler',
			static function ( int $severity, string $message ): never {
				throw new \ErrorException( $message, 0, $severity );
			}
		);

		try {
			$unserialized = \call_user_func( 'unserialize', $value, array( 'allowed_classes' => false ) );
		} catch ( \Throwable ) {
			return $value;
		} finally {
			\call_user_func( 'restore_error_handler' );
		}

		return false === $unserialized && 'b:0;' !== $value ? $value : $unserialized;
	}
}

if ( ! \function_exists( 'a8csp_bgte_test_record_cache_call' ) ) {
	/**
	 * Records one cache operation.
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   string $function_name Function name.
	 * @param   array  $args          Function arguments.
	 *
	 * @return  void
	 */
	function a8csp_bgte_test_record_cache_call( string $function_name, array $args ): void {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls = $GLOBALS['a8csp_bgte_test_cache_calls'] ?? array();

		$calls[] = array(
			'function' => $function_name,
			'args'     => $args,
		);

		$GLOBALS['a8csp_bgte_test_cache_calls'] = $calls;
	}
}

if ( ! \function_exists( 'wp_cache_get' ) ) {
	/**
	 * Returns a scripted cache value.
	 *
	 * @phpstan-param-out bool $found
	 *
	 * @param   int|string $key   Cache key.
	 * @param   string     $group Cache group.
	 * @param   bool       $force Whether to force a persistent-cache refresh.
	 * @param   bool|null  $found Whether the key was found.
	 *
	 * @return  mixed
	 */
	function wp_cache_get( int|string $key, string $group = '', bool $force = false, ?bool &$found = null ): mixed {
		a8csp_bgte_test_record_cache_call( 'wp_cache_get', array( $key, $group, $force ) );
		/** @var array<string, array<int|string, mixed>> $cache */
		$cache = $GLOBALS['a8csp_bgte_test_cache'] ?? array();
		$found = isset( $cache[ $group ] ) && \array_key_exists( $key, $cache[ $group ] );

		return $found ? $cache[ $group ][ $key ] : false;
	}
}

if ( ! \function_exists( 'wp_cache_set' ) ) {
	/**
	 * Stores and records a cache value.
	 *
	 * @param   int|string $key    Cache key.
	 * @param   mixed      $data   Cache value.
	 * @param   string     $group  Cache group.
	 * @param   int        $expire Expiration in seconds.
	 *
	 * @return  bool
	 */
	function wp_cache_set( int|string $key, mixed $data, string $group = '', int $expire = 0 ): bool {
		a8csp_bgte_test_record_cache_call( 'wp_cache_set', array( $key, $data, $group, $expire ) );
		/** @var array<string, array<int|string, mixed>> $cache */
		$cache = $GLOBALS['a8csp_bgte_test_cache'] ?? array();

		$cache[ $group ][ $key ] = $data;

		$GLOBALS['a8csp_bgte_test_cache'] = $cache;

		return true;
	}
}

if ( ! \function_exists( 'wp_cache_delete' ) ) {
	/**
	 * Deletes and records a cache value.
	 *
	 * @param   int|string $key   Cache key.
	 * @param   string     $group Cache group.
	 *
	 * @return  bool
	 */
	function wp_cache_delete( int|string $key, string $group = '' ): bool {
		a8csp_bgte_test_record_cache_call( 'wp_cache_delete', array( $key, $group ) );
		/** @var array<string, array<int|string, mixed>> $cache */
		$cache  = $GLOBALS['a8csp_bgte_test_cache'] ?? array();
		$exists = isset( $cache[ $group ] ) && \array_key_exists( $key, $cache[ $group ] );
		unset( $cache[ $group ][ $key ] );
		$GLOBALS['a8csp_bgte_test_cache'] = $cache;

		return $exists;
	}
}
