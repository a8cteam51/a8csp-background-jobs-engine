<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Integrations;

use A8C\SpecialProjects\BackgroundTasksEngine\Component;

\defined( 'ABSPATH' ) || exit;

/**
 * Provides the full-tier WooCommerce extension. It gates on WooCommerce core and the
 * header-declared `WC requires at least` floor, then adds a real section to WooCommerce → Settings
 * → Advanced.
 *
 * An absent WooCommerce stays silent because the plugin runs as its base tier by design; a present
 * WooCommerce below the floor is explained by an admin notice instead of silently gating off.
 *
 * This folder is the deletable WooCommerce tier; see the README's "Watering down to plain
 * WordPress" sequence.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @see uninstall.php
 */
class WC_Settings_Section implements Component {
	// region FIELDS AND CONSTANTS

	/**
	 * The persisted WooCommerce example option. It is mirrored in the `uninstall.php` footprint
	 * under this component's owner group; the footprint line goes with this folder when the
	 * WooCommerce tier is deleted.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const OPTION_KEY = 'a8csp_bgte_wc_example_option';

	// endregion

	// region METHODS

	/**
	 * Returns true when WooCommerce core is present. This gate checks presence, not full satisfaction;
	 * `initialize()` checks the version floor and explains a below-floor install with an admin notice.
	 * To require a specific WooCommerce extension instead, add its own `class_exists()` check here,
	 * such as `class_exists( 'WC_Subscriptions' )` for Subscriptions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public function is_needed(): bool {
		return \class_exists( 'WooCommerce' ) && \defined( 'WC_VERSION' );
	}

	/**
	 * Compares an installed WooCommerce version against the header-declared floor. A pure value
	 * comparison with no WordPress or WooCommerce calls, so the Unit suite can exercise the
	 * branching directly instead of stubbing globals.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $installed_version The installed WooCommerce version.
	 * @param   string|null $minimum_version    The minimum WooCommerce version declared in the plugin
	 *                                          header, or null/empty if the header doesn't declare one.
	 *
	 * @return  bool
	 */
	public static function meets_minimum_wc_version( string $installed_version, ?string $minimum_version ): bool {
		if ( null === $minimum_version || '' === $minimum_version ) {
			return true;
		}

		return \version_compare( $installed_version, $minimum_version, '>=' );
	}

	/**
	 * Registers the section when the running WooCommerce meets the header-declared floor. Below the
	 * floor, registers only the explanatory admin notice.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function initialize(): void {
		if ( ! self::meets_minimum_wc_version( (string) \constant( 'WC_VERSION' ), a8csp_bgte_get_plugin_metadata( 'WC requires at least' ) ) ) {
			add_action( 'admin_notices', array( $this, 'render_version_notice' ) );
			return;
		}

		add_filter( 'woocommerce_get_sections_advanced', array( $this, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_advanced', array( $this, 'get_settings' ), 10, 2 );
	}

	// endregion

	// region HOOKS

	/**
	 * Renders the below-floor explanation: which plugin's integration is off, the floor it requires,
	 * and the WooCommerce version running. Capability-gated to the people who can act on it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function render_version_notice(): void {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability for store managers and administrators.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$notice = wp_sprintf(
			/* translators: 1: Plugin name, 2: Minimum WooCommerce version, 3: Running WooCommerce version */
			__( 'The %1$s WooCommerce settings section requires WooCommerce %2$s or newer; version %3$s is running, so the section stayed off.', 'a8csp-background-tasks-engine' ),
			a8csp_bgte_get_plugin_name(),
			(string) a8csp_bgte_get_plugin_metadata( 'WC requires at least' ),
			(string) \constant( 'WC_VERSION' )
		);

		wp_admin_notice( esc_html( $notice ), array( 'type' => 'error' ) );
	}

	/**
	 * Adds the plugin's section to the Advanced settings tab. The section slug carries the prefix
	 * token so generation rewrites it, and the label doubles as the plugin title.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<string, string> $sections The registered Advanced settings sections.
	 *
	 * @return  array<string, string>
	 */
	public function add_section( array $sections ): array {
		$sections['a8csp_bgte'] = __( 'A8CSP Background Tasks Engine', 'a8csp-background-tasks-engine' );

		return $sections;
	}

	/**
	 * Declares this section's WooCommerce settings rows. WooCommerce persists the field through its
	 * own settings save, so declaring the field is the whole persistence story and the option key
	 * still appears in the uninstall footprint.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<int, array<string, mixed>> $settings        The settings rows for the current
	 *                                                            Advanced section.
	 * @param   string                           $current_section The current Advanced section slug.
	 *
	 * @return  array<int, array<string, mixed>>
	 */
	public function get_settings( array $settings, string $current_section ): array {
		if ( 'a8csp_bgte' !== $current_section ) {
			return $settings;
		}

		return array(
			array(
				'title' => __( 'A8CSP Background Tasks Engine', 'a8csp-background-tasks-engine' ),
				'type'  => 'title',
				'id'    => 'a8csp_bgte_wc_example_section',
			),
			array(
				'title' => __( 'Example option', 'a8csp-background-tasks-engine' ),
				'desc'  => __( 'A persisted example setting owned by the WooCommerce integration.', 'a8csp-background-tasks-engine' ),
				'id'    => self::OPTION_KEY,
				'type'  => 'text',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'a8csp_bgte_wc_example_section',
			),
		);
	}

	// endregion
}
