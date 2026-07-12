<?php declare( strict_types=1 );

/**
 * In-memory WordPress option functions for unit tests outside WordPress.
 *
 * The shared get stub also exposes the existing cron fake so guarded global functions remain
 * deterministic regardless of PHPUnit's test-class load order.
 *
 * @since   1.0.0
 * @version 1.0.0
 * @package A8C\SpecialProjects\BackgroundTasksEngine
 */

if ( ! \function_exists( 'a8csp_bgte_test_record_option_call' ) ) {
	/**
	 * Appends an option-function call to the test ledger.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   string $function_name Function name.
	 * @param   array  $args          Function arguments.
	 *
	 * @return  void
	 */
	function a8csp_bgte_test_record_option_call( string $function_name, array $args ): void {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls   = $GLOBALS['a8csp_bgte_test_option_calls'] ?? array();
		$calls[] = array(
			'function' => $function_name,
			'args'     => $args,
		);

		$GLOBALS['a8csp_bgte_test_option_calls'] = $calls;
	}
}

if ( ! \function_exists( 'get_option' ) ) {
	/**
	 * Returns a stored option or the supplied default.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option        Option name.
	 * @param   mixed  $default_value Default value.
	 *
	 * @return  mixed
	 *
	 * @phpstan-impure
	 */
	function get_option( $option, $default_value = false ) {
		if ( 'cron' === $option && \array_key_exists( 'a8csp_bgte_test_cron_array', $GLOBALS ) ) {
			return $GLOBALS['a8csp_bgte_test_cron_array'];
		}

		/** @var array<string, mixed> $options */
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? array();

		return \array_key_exists( $option, $options ) ? $options[ $option ] : $default_value;
	}
}

if ( ! \function_exists( 'add_option' ) ) {
	/**
	 * Adds an option when its name is absent.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string           $option     Option name.
	 * @param   mixed            $value      Option value.
	 * @param   string           $deprecated Unused legacy argument.
	 * @param   bool|string|null $autoload   Autoload policy.
	 *
	 * @return  bool
	 *
	 * @phpstan-impure
	 */
	function add_option( $option, $value = '', $deprecated = '', $autoload = null ) {
		a8csp_bgte_test_record_option_call( 'add_option', array( $option, $value, $deprecated, $autoload ) );

		/** @var callable(string, mixed, string, bool|string|null): void|null $before_add */
		$before_add = $GLOBALS['a8csp_bgte_test_before_add_option'] ?? null;
		if ( null !== $before_add ) {
			if ( ! \is_callable( $before_add ) ) {
				throw new \UnexpectedValueException( 'Initialize the before-add-option test hook as a callable.' );
			}

			$before_add( $option, $value, $deprecated, $autoload );
		}

		/** @var array<string, mixed> $options */
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? array();
		if ( \array_key_exists( $option, $options ) ) {
			return false;
		}

		/** @var array<string, bool|string|null> $autoload_flags */
		$autoload_flags = $GLOBALS['a8csp_bgte_test_option_autoload'] ?? array();

		$options[ $option ] = $value;

		$autoload_flags[ $option ] = $autoload;

		$GLOBALS['a8csp_bgte_test_options']         = $options;
		$GLOBALS['a8csp_bgte_test_option_autoload'] = $autoload_flags;

		return true;
	}
}

if ( ! \function_exists( 'update_option' ) ) {
	/**
	 * Creates or replaces an option.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string           $option   Option name.
	 * @param   mixed            $value    Option value.
	 * @param   bool|string|null $autoload Autoload policy.
	 *
	 * @return  bool
	 *
	 * @phpstan-impure
	 */
	function update_option( $option, $value, $autoload = null ) {
		a8csp_bgte_test_record_option_call( 'update_option', array( $option, $value, $autoload ) );

		/** @var array<string, mixed> $options */
		$options   = $GLOBALS['a8csp_bgte_test_options'] ?? array();
		$unchanged = \array_key_exists( $option, $options ) && $value === $options[ $option ];

		/** @var array<string, bool|string|null> $autoload_flags */
		$autoload_flags = $GLOBALS['a8csp_bgte_test_option_autoload'] ?? array();

		$options[ $option ] = $value;

		$autoload_flags[ $option ] = $autoload;

		$GLOBALS['a8csp_bgte_test_options']         = $options;
		$GLOBALS['a8csp_bgte_test_option_autoload'] = $autoload_flags;

		return ! $unchanged;
	}
}

if ( ! \function_exists( 'delete_option' ) ) {
	/**
	 * Deletes an option when it exists.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option Option name.
	 *
	 * @return  bool
	 *
	 * @phpstan-impure
	 */
	function delete_option( $option ) {
		a8csp_bgte_test_record_option_call( 'delete_option', array( $option ) );

		/** @var array<string, mixed> $options */
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? array();
		if ( ! \array_key_exists( $option, $options ) ) {
			return false;
		}

		/** @var array<string, bool|string|null> $autoload_flags */
		$autoload_flags = $GLOBALS['a8csp_bgte_test_option_autoload'] ?? array();
		unset( $options[ $option ], $autoload_flags[ $option ] );

		$GLOBALS['a8csp_bgte_test_options']         = $options;
		$GLOBALS['a8csp_bgte_test_option_autoload'] = $autoload_flags;

		return true;
	}
}
