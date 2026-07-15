<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

/**
 * Sweeps engine options and rejects undeclared steady-state rows before cleanup.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
trait OptionIsolationTrait {
	// region FIELDS AND CONSTANTS.

	/**
	 * Prefix shared by every engine-owned option row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const ENGINE_OPTION_PREFIX = 'a8csp_bgte_';

	/**
	 * Deliberate non-history leftovers declared by the current test.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, true>
	 */
	private array $expected_engine_options = array();

	// endregion.

	// region METHODS.

	/**
	 * Declares one deliberate engine option that may remain when the test finishes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Complete engine option name.
	 *
	 * @throws  \InvalidArgumentException When the option is outside the engine prefix.
	 *
	 * @return  void
	 */
	protected function expect_option( string $name ): void {
		if ( ! \str_starts_with( $name, self::ENGINE_OPTION_PREFIX ) ) {
			throw new \InvalidArgumentException( 'Expected integration leftovers must use the a8csp_bgte_ option prefix.' );
		}

		$this->expected_engine_options[ $name ] = true;
	}

	/**
	 * Clears prior engine rows and resets the current test's leftover declarations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function prepare_engine_options(): void {
		$this->expected_engine_options = array();
		$this->sweep_engine_options();
	}

	/**
	 * Asserts that only history rows and explicitly declared leftovers remain.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function assert_engine_option_hygiene(): void {
		$history_prefix = self::ENGINE_OPTION_PREFIX . 'history_';
		$unexpected     = array();

		foreach ( $this->engine_option_rows() as $row ) {
			$name = $row['option_name'];
			if ( \str_starts_with( $name, $history_prefix ) || isset( $this->expected_engine_options[ $name ] ) ) {
				continue;
			}

			$unexpected[] = $name;
		}

		self::assertSame(
			array(),
			$unexpected,
			\sprintf(
				'Delete transient engine rows before test completion or declare deliberate leftovers with expect_option(). ' .
				'Unexpected rows: %s',
				array() === $unexpected ? '(none)' : \implode( ', ', $unexpected )
			)
		);
	}

	/**
	 * Returns engine option names and autoload values in lexical order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{option_name: string, autoload: string}>
	 */
	protected function engine_option_rows(): array {
		global $wpdb;

		/** @var \wpdb $wpdb */
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT `option_name`, `autoload` FROM %i WHERE `option_name` LIKE %s ORDER BY `option_name` ASC', $wpdb->options, $wpdb->esc_like( self::ENGINE_OPTION_PREFIX ) . '%' ), \ARRAY_A );
		if ( ! \is_array( $rows ) ) {
			return array();
		}

		$engine_rows = array();
		foreach ( $rows as $row ) {
			if (
				! \is_array( $row )
				|| ! \is_string( $row['option_name'] ?? null )
				|| ! \is_string( $row['autoload'] ?? null )
			) {
				continue;
			}

			$engine_rows[] = array(
				'option_name' => $row['option_name'],
				'autoload'    => $row['autoload'],
			);
		}

		return $engine_rows;
	}

	/**
	 * Deletes every engine-prefixed option through WordPress's cache-aware API.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function sweep_engine_options(): void {
		global $wpdb;

		/** @var \wpdb $wpdb */
		$option_names = $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s', $wpdb->options, $wpdb->esc_like( self::ENGINE_OPTION_PREFIX ) . '%' ) );

		foreach ( $option_names as $option_name ) {
			if ( \is_string( $option_name ) ) {
				\delete_option( $option_name );
			}
		}
	}

	// endregion.
}
