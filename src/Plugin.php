<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * The plugin's composition root: assembles the top-level components and runs the boot pipeline.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class Plugin {
	// region FIELDS AND CONSTANTS

	/**
	 * Add the plugin's top-level components here; they run through each phase in registration
	 * order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<int, class-string<ComponentInterface>>
	 */
	private const array COMPONENTS = array(
		Runtime\Component::class,
		CLI\Component::class,
	);

	/**
	 * Tri-state boot flag: null until `boot()` is first entered, false from entry until the hook
	 * phase completes — which also latches reentrant calls and post-failure retries into no-ops,
	 * since a half-attached boot must never be replayed — and true only on success.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     bool|null
	 */
	private ?bool $booted = null;

	// endregion

	// region METHODS

	/**
	 * Whether the boot pipeline completed successfully for this request.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public function is_booted(): bool {
		return true === $this->booted;
	}

	// endregion

	// region HOOKS

	/**
	 * Runs the plugin's boot pipeline.
	 *
	 * A boot failure propagates uncaught — fail loud; the entry latch already guarantees it cannot
	 * be retried into duplicate hook registrations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function boot(): void {
		if ( null !== $this->booted ) {
			return;
		}

		$this->booted = false;

		$components = ComponentCollection::assemble( self::COMPONENTS );
		$components->initialize();
		$components->register_hooks();

		$this->booted = true;
	}

	// endregion
}
