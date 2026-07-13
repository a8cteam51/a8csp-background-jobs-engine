<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\CLI;

use A8C\SpecialProjects\BackgroundTasksEngine\Component as ComponentContract;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers the background-task commands only inside WP-CLI.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class Component implements ComponentContract {
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
	public function is_needed(): bool {
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
	public function initialize(): void {
		\WP_CLI::add_command( 'background-tasks', BackgroundTasksCommand::class );
	}

	// endregion
}
