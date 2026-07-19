<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Defaults-only base for components, supplying the no-op defaults of `ComponentInterface`. It
 * must never grow state, shared behavior, or helpers; every component types against the
 * interface, so extending this base is optional.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class AbstractComponent implements ComponentInterface {
	// region METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public static function should_load(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function initialize(): void {}

	// endregion
}
