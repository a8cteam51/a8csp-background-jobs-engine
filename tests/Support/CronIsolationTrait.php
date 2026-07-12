<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

/**
 * Clears WordPress cron state around each live integration test.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
trait CronIsolationTrait {
	// region METHODS.

	/**
	 * Replaces the persisted cron array with an empty schedule.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function reset_wordpress_cron(): void {
		\_set_cron_array( array() );
	}

	// endregion.
}
