<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

/**
 * Resets Action Scheduler state, its four custom tables and the store's caches, around each integration test.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
trait ActionSchedulerIsolationTrait {
	// region FIELDS AND CONSTANTS.

	/**
	 * Action Scheduler table suffixes in semantic child-first cleanup order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const array ACTION_SCHEDULER_TABLE_SUFFIXES = array(
		'actionscheduler_logs',
		'actionscheduler_claims',
		'actionscheduler_actions',
		'actionscheduler_groups',
	);

	// endregion.

	// region METHODS.

	/**
	 * Deletes rows from each installed Action Scheduler custom table and flushes the store's caches, without assuming schema availability.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException           When the live database or custom-table store is unavailable.
	 * @throws  \RuntimeException         When table discovery or row deletion fails.
	 * @throws  \UnexpectedValueException When WordPress cannot prepare a database query.
	 *
	 * @return  void
	 */
	protected function truncate_action_scheduler_tables(): void {
		global $wpdb;

		if ( ! \class_exists( \ActionScheduler::class ) ) {
			return;
		}

		if ( ! \ActionScheduler::store() instanceof \ActionScheduler_DBStore ) {
			throw new \LogicException( 'complete the Action Scheduler data migration; the rig supports the custom-table store only.' );
		}

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \LogicException( 'Action Scheduler table cleanup requires the live WordPress database connection.' );
		}

		foreach ( self::ACTION_SCHEDULER_TABLE_SUFFIXES as $suffix ) {
			$table_name   = $wpdb->prefix . $suffix;
			$lookup_query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) );
			if ( ! \is_string( $lookup_query ) ) {
				throw new \UnexpectedValueException( \sprintf( 'WordPress must prepare the Action Scheduler table lookup for "%s".', $table_name ) );
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query prepared and validated above.
			$installed = $wpdb->get_var( $lookup_query );
			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException( \sprintf( 'Action Scheduler table lookup failed for "%s": %s. Fix the database error before rerunning the integration suite.', $table_name, $wpdb->last_error ) );
			}

			if ( $table_name !== $installed ) {
				continue;
			}

			$delete_query = $wpdb->prepare( 'DELETE FROM %i', $table_name );
			if ( ! \is_string( $delete_query ) ) {
				throw new \UnexpectedValueException( \sprintf( 'WordPress must prepare the Action Scheduler table deletion for "%s".', $table_name ) );
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifier prepared above.
			$deleted = $wpdb->query( $delete_query );
			if ( false === $deleted ) {
				throw new \RuntimeException( \sprintf( 'Action Scheduler table cleanup failed for "%s": %s. Fix the database error before rerunning the integration suite.', $table_name, $wpdb->last_error ) );
			}
		}

		// Empty tables are not an isolated store while resolved group IDs remain cached: a stale ID orphans every action row written afterwards.
		\ActionScheduler::store()->flush_caches();
	}

	// endregion.
}
