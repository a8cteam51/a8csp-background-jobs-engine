<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Provides the canonical base-WordPress component. It registers and persists one setting through
 * the Settings API, so it doubles as the worked example for the uninstall footprint.
 *
 * Imitate this component for any option your plugin owns. Delete it if your plugin persists
 * nothing.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @see uninstall.php
 */
final class Settings implements Component {
	// region FIELDS AND CONSTANTS

	/**
	 * The persisted example option. Every persisted key is mirrored in the `uninstall.php`
	 * footprint manifest in the same change that introduces the write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const OPTION_KEY = 'a8csp_bgte_example_option';

	// endregion

	// region METHODS

	/**
	 * Base-WordPress components with no environmental dependency always run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public function is_needed(): bool {
		return true;
	}

	/**
	 * Wires the component only; the hook method performs the settings registration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function initialize(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	// endregion

	// region HOOKS

	/**
	 * Hangs the demo field on the core General options page to avoid inventing a whole admin page
	 * for a one-field demo. Imitate the `register_setting()` shape and retarget the page as needed.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function register_settings(): void {
		register_setting(
			'general',
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_section(
			'a8csp_bgte_example_section',
			__( 'A8CSP Background Tasks Engine', 'a8csp-background-tasks-engine' ),
			'__return_empty_string',
			'general'
		);

		add_settings_field(
			'a8csp_bgte_example_field',
			__( 'Example option', 'a8csp-background-tasks-engine' ),
			array( $this, 'render_field' ),
			'general',
			'a8csp_bgte_example_section'
		);
	}

	/**
	 * Renders the persisted value as a text field. Escaping on output is the point this example
	 * models, even though the value is also sanitized before WordPress persists it.
	 * The value comes through the typed reader in `includes/settings.php`, the worked example of
	 * reading an option this component registers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function render_field(): void {
		\printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( a8csp_bgte_get_example_option() )
		);
	}

	// endregion
}
