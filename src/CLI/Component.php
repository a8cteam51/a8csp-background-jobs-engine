<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI;

use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands\LocksCommand;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands\ResetCommand;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands\RunsCommand;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands\SchedulesCommand;
use A8C\SpecialProjects\BackgroundJobsEngine\AbstractComponent;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers the background-job commands only inside WP-CLI.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class Component extends AbstractComponent {
	// region INHERITED METHODS

	/**
	 * Returns whether the current request is running through WP-CLI.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	#[\Override]
	public static function should_load(): bool {
		return \defined( 'WP_CLI' ) && true === \constant( 'WP_CLI' );
	}

	/**
	 * Registers the spoken command surface once under its canonical root.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public function register_hooks(): void {
		// The last registration supplies the namespace description, so the inspection surface registers last.
		\WP_CLI::add_command( 'a8csp-bgje', SchedulesCommand::class );
		\WP_CLI::add_command( 'a8csp-bgje', ResetCommand::class );
		\WP_CLI::add_command( 'a8csp-bgje', LocksCommand::class );
		\WP_CLI::add_command( 'a8csp-bgje', RunsCommand::class );
	}

	// endregion
}
