<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * The contract every composable unit of the plugin fulfills.
 *
 * The boot pipeline runs each phase across ALL components before starting the next one, so by the
 * time any hook can fire, every surviving component is fully initialized. A component may
 * therefore rely on its peers' readiness inside `register_hooks()` and hook callbacks, but never
 * inside `initialize()`.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface ComponentInterface {
	// region METHODS

	/**
	 * Determines whether the component should take part in this request at all.
	 *
	 * Static so the gate runs BEFORE construction — an optional integration must never fatal on
	 * autoload or construction when its companion is absent. Gate only on facts stable at
	 * composition time (environment, companion-plugin presence, WP_CLI, is_admin(),
	 * wp_installing()). Request-type surfaces such as REST are NOT gates — they stage onto their
	 * own hooks in `register_hooks()`. Capability checks run inside the hook callbacks, after the
	 * current user exists.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public static function should_load(): bool;

	/**
	 * Prepares the component's internal state.
	 *
	 * Readiness only: wire the private object graph and contribute to registries the composition
	 * root injected. No writes, no output, no hook registration. Option reads are permitted but
	 * execute host filters — keep them cheap, and never cache blog-scoped values across
	 * switch_to_blog() without invalidation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function initialize(): void;

	/**
	 * Attaches the component's behavior to WordPress.
	 *
	 * The only place add_action()/add_filter() calls happen. Work that needs locale, user, or
	 * registry state is staged onto `init` (or later request-type hooks) from here, not run
	 * inline. On a late boot (e.g. the activating request) already-fired stages will not replay —
	 * work that must run on that request belongs to the installer, not to a staged callback.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function register_hooks(): void;

	// endregion
}
