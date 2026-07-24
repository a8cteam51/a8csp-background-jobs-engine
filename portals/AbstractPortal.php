<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\EngineUnavailableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\OwnerOperations;

\defined( 'ABSPATH' ) || exit;

/**
 * Shared owner resolution and error conversion for the public verb portals.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract readonly class AbstractPortal {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Client plugin owner.
	 */
	public function __construct(
		protected string $owner,
	) {}

	// endregion

	// region HELPERS

	/**
	 * Resolves the owner operations adapter for the bound owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \InvalidArgumentException  When the owner violates the owner contract.
	 * @throws  EngineUnavailableException When the internal graph is unavailable.
	 *
	 * @return  OwnerOperations
	 */
	protected function operations(): OwnerOperations {
		return Component::operations( $this->owner );
	}

	/**
	 * Converts one boundary failure to the WordPress error boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BoundaryError $error Boundary failure.
	 *
	 * @return  \WP_Error
	 */
	protected static function wp_error( BoundaryError $error ): \WP_Error {
		return new \WP_Error( $error->code->value, $error->message, $error->context );
	}

	// endregion
}
