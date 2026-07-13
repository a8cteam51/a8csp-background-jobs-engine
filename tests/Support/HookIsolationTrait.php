<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

/**
 * Restores WordPress hook registrations, invocation counts, and the active filter stack after each integration test.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
trait HookIsolationTrait {
	// region FIELDS AND CONSTANTS.

	/**
	 * Request hook registry captured before test listeners are registered.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<array-key, mixed>
	 */
	private array $wp_filter_snapshot = array();

	/**
	 * Fired action counts captured before the test runs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<array-key, mixed>
	 */
	private array $wp_actions_snapshot = array();

	/**
	 * Applied filter counts captured before the test runs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<array-key, mixed>
	 */
	private array $wp_filters_snapshot = array();

	/**
	 * Active filter stack captured before the test runs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<array-key, mixed>
	 */
	private array $wp_current_filter_snapshot = array();

	// endregion.

	// region METHODS.

	/**
	 * Captures independent hook objects because add_filter() mutates existing WP_Hook instances.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function snapshot_wordpress_hooks(): void {
		$wp_filter = $GLOBALS['wp_filter'] ?? array();
		if ( ! \is_array( $wp_filter ) ) {
			$wp_filter = array();
		}

		$this->wp_filter_snapshot = array();
		foreach ( $wp_filter as $hook_name => $hook ) {
			$this->wp_filter_snapshot[ $hook_name ] = $hook instanceof \WP_Hook ? clone $hook : $hook;
		}

		$wp_actions        = $GLOBALS['wp_actions'] ?? array();
		$wp_filters        = $GLOBALS['wp_filters'] ?? array();
		$wp_current_filter = $GLOBALS['wp_current_filter'] ?? array();

		$this->wp_actions_snapshot        = \is_array( $wp_actions ) ? $wp_actions : array();
		$this->wp_filters_snapshot        = \is_array( $wp_filters ) ? $wp_filters : array();
		$this->wp_current_filter_snapshot = \is_array( $wp_current_filter ) ? $wp_current_filter : array();
	}

	/**
	 * Reinstates the request hook registry, invocation counts, and active filter stack captured before the test.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function restore_wordpress_hooks(): void {
		$GLOBALS['wp_filter']         = $this->wp_filter_snapshot;
		$GLOBALS['wp_actions']        = $this->wp_actions_snapshot;
		$GLOBALS['wp_filters']        = $this->wp_filters_snapshot;
		$GLOBALS['wp_current_filter'] = $this->wp_current_filter_snapshot;
	}

	// endregion.
}
