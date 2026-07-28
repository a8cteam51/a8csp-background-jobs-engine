<?php declare( strict_types=1 );

/**
 * Scriptable Action Scheduler classes for unit tests outside WordPress.
 *
 * The guard keeps this file inert when a client loads the real Action Scheduler API. It lives apart
 * from the procedural stubs because a file carries either function declarations or classes.
 *
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital -- The vendor class name is the contract under test.
if ( ! \class_exists( 'ActionScheduler_Versions' ) ) {
	/**
	 * Scriptable stand-in for Action Scheduler's version registry.
	 *
	 * Action Scheduler publishes no version constant, so the registry's elected version is the only
	 * surface a supported-version gate can read.
	 */
	class ActionScheduler_Versions {
		/**
		 * Returns a registry instance.
		 *
		 * @return  self
		 */
		public static function instance(): self {
			return new self();
		}

		/**
		 * Returns the scripted elected version, defaulting to the supported floor.
		 *
		 * @return  string|false
		 */
		public function latest_version(): string|false {
			$version = $GLOBALS['a8csp_bgje_test_as_version'] ?? '4.0.0';

			return \is_string( $version ) ? $version : false;
		}
	}
}
