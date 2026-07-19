<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * A collection of components that gates its members on construction and runs their lifecycle
 * phases. Owns the component-loop machinery once for the composition root and every group root.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ComponentCollection {
	// region FIELDS AND CONSTANTS

	/**
	 * The components that survived their gates, keyed by class so membership is answerable by name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<class-string<ComponentInterface>, ComponentInterface>
	 */
	private array $components = array();

	// endregion

	// region CONSTRUCTORS

	/**
	 * ComponentCollection constructor. Private: `assemble()` is the only way to build the
	 * collection, so a component can never enter it without passing its gate.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	private function __construct() {}

	// endregion

	// region METHODS

	/**
	 * Gates and constructs the given component classes into a collection.
	 *
	 * The static gates run BEFORE construction, so an optional integration whose companion is
	 * absent is never autoloaded into a fatal; the survivors construct in registration order and
	 * run each later phase in that same order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<int, class-string<ComponentInterface>> $component_classes The component classes to gate and construct.
	 *
	 * @return  self
	 */
	public static function assemble( array $component_classes ): self {
		$collection = new self();
		foreach ( $component_classes as $component_class ) {
			if ( $component_class::should_load() ) {
				$collection->components[ $component_class ] = new $component_class();
			}
		}

		return $collection;
	}

	/**
	 * Runs the readiness phase across every component in the collection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function initialize(): void {
		foreach ( $this->components as $component ) {
			$component->initialize();
		}
	}

	/**
	 * Runs the hook phase across every component in the collection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function register_hooks(): void {
		foreach ( $this->components as $component ) {
			$component->register_hooks();
		}
	}

	/**
	 * Whether the given component class survived its gate into this collection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   class-string<ComponentInterface> $component_class The component class to look up.
	 *
	 * @return  bool
	 */
	public function has( string $component_class ): bool {
		return isset( $this->components[ $component_class ] );
	}

	// endregion
}
