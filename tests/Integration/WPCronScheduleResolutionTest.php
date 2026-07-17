<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies persisted synthetic schedules resolve and recur across simulated WP-Cron requests.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[Group( 'degraded' )]
final class WPCronScheduleResolutionTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Hook isolated to synthetic schedule reconstruction. */
	private const string RESOLUTION_HOOK = 'a8csp_bgte/integration/wp_cron_resolution';

	/** Hook isolated to recurring occurrence delivery. */
	private const string RECURRING_HOOK = 'a8csp_bgte/integration/wp_cron_recurring';

	// endregion.

	// region TESTS.

	/**
	 * A fresh backend reconstructs a persisted interval without request-local registration state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_fresh_backend_resolves_the_persisted_synthetic_schedule(): void {
		$interval  = 137;
		$timestamp = \time() + \HOUR_IN_SECONDS;
		$args      = array( 'request-a' );
		$backend   = new WPCronBackend();
		$scheduled = $backend->schedule_recurring( self::RESOLUTION_HOOK, $interval, $args, $timestamp );

		self::assertInstanceOf( Success::class, $scheduled, 'WP-Cron must accept the synthetic recurring schedule' );
		self::assertTrue( $backend->is_scheduled( self::RESOLUTION_HOOK, $args ) );
		self::assertSame( $timestamp, $backend->get_next_scheduled( self::RESOLUTION_HOOK, $args ) );

		\remove_all_filters( 'cron_schedules' );
		$fresh_backend = new WPCronBackend();
		$fresh_backend->register_hooks();
		$schedules = \wp_get_schedules();

		self::assertSame(
			array(
				'interval' => $interval,
				'display'  => 'Every 137 seconds',
			),
			$schedules['a8csp_bgte_every_137s'] ?? null,
			'The fresh request must rebuild the synthetic schedule from the persisted cron array'
		);
	}

	/**
	 * WP-Cron persists the recurring successor before dispatching the due occurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_due_recurring_occurrence_fires_and_reschedules_its_successor(): void {
		// Overdue by more than one interval, so naive original-plus-interval successor math lands in the past.
		$interval  = 61;
		$timestamp = \time() - $interval - 5;
		$args      = array( 'occurrence-a' );
		$fired     = array();
		\add_action(
			self::RECURRING_HOOK,
			static function ( string $token ) use ( &$fired ): void {
				$fired[] = $token;
			},
			10,
			1
		);

		$scheduled = ( new WPCronBackend() )->schedule_recurring( self::RECURRING_HOOK, $interval, $args, $timestamp );
		self::assertInstanceOf( Success::class, $scheduled, 'WP-Cron must persist the due recurring occurrence' );

		\remove_all_filters( 'cron_schedules' );
		$fresh_backend = new WPCronBackend();
		$fresh_backend->register_hooks();

		self::assertSame( 1, $this->run_next_due_cron_event(), 'The WP-Cron drive must dispatch one due occurrence' );
		self::assertSame( array( 'occurrence-a' ), $fired, 'The due recurring hook must fire exactly once with its stored arguments' );

		self::assertTrue( $fresh_backend->is_scheduled( self::RECURRING_HOOK, $args ) );
		$successor = $fresh_backend->get_next_scheduled( self::RECURRING_HOOK, $args );
		self::assertIsInt( $successor );
		self::assertGreaterThan( \time(), $successor, 'The recurring successor must be scheduled in the future' );
		self::assertNotSame( $timestamp, $successor, 'The due occurrence must be replaced by its successor' );
	}

	// endregion.
}
