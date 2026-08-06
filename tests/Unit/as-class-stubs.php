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
			$version = $GLOBALS['a8csp_bgje_test_as_version'] ?? '4.1.0';

			return \is_string( $version ) ? $version : false;
		}
	}
}

if ( ! \class_exists( 'A8CSP_BGJE_Test_AS_Schedule' ) ) {
	/**
	 * Fixed-recurrence stand-in for an Action Scheduler schedule.
	 */
	class A8CSP_BGJE_Test_AS_Schedule {
		/**
		 * Builds a schedule reporting one recurrence.
		 *
		 * @param   int|string|null $recurrence Recurrence reported to callers. A cron expression is a string.
		 */
		public function __construct( private readonly int|string|null $recurrence ) {}

		/**
		 * Returns the recurrence this schedule reports.
		 *
		 * @return  int|string|null
		 */
		public function get_recurrence(): int|string|null {
			return $this->recurrence;
		}
	}
}

if ( ! \class_exists( 'A8CSP_BGJE_Test_AS_Action' ) ) {
	/**
	 * Stand-in for one hydrated Action Scheduler action.
	 */
	class A8CSP_BGJE_Test_AS_Action {
		/**
		 * Builds an action carrying an identity and a schedule.
		 *
		 * @param   list<mixed>                     $args     Hook arguments.
		 * @param   string                          $group    Action group.
		 * @param   A8CSP_BGJE_Test_AS_Schedule|null $schedule Schedule, or null when the action carries none.
		 */
		public function __construct(
			private readonly array $args,
			private readonly string $group,
			private readonly ?A8CSP_BGJE_Test_AS_Schedule $schedule = null,
		) {}

		/**
		 * Returns the hook arguments.
		 *
		 * @return  list<mixed>
		 */
		public function get_args(): array {
			return $this->args;
		}

		/**
		 * Returns the action group.
		 *
		 * @return  string
		 */
		public function get_group(): string {
			return $this->group;
		}

		/**
		 * Returns the action's schedule.
		 *
		 * @return  A8CSP_BGJE_Test_AS_Schedule|null
		 */
		public function get_schedule(): ?A8CSP_BGJE_Test_AS_Schedule {
			return $this->schedule;
		}
	}
}
