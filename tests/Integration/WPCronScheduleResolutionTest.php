<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies persisted synthetic schedules resolve and recur across simulated WP-Cron requests.
 */
#[Group( 'degraded' )]
final class WPCronScheduleResolutionTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Hook isolated to synthetic schedule reconstruction. */
	private const RESOLUTION_HOOK = 'a8csp_bgte/integration/wp_cron_resolution';

	/** Hook isolated to recurring occurrence delivery. */
	private const RECURRING_HOOK = 'a8csp_bgte/integration/wp_cron_recurring';

	// endregion.

	// region TESTS.

	/**
	 * A fresh backend reconstructs a persisted interval without request-local registration state.
	 *
	 * @return  void
	 */
	public function test_fresh_backend_resolves_the_persisted_synthetic_schedule(): void {
		$interval  = 137;
		$timestamp = \time() + \HOUR_IN_SECONDS;
		$args      = array( 'request-a' );
		$scheduled = ( new WPCronBackend() )->schedule_recurring( self::RESOLUTION_HOOK, $interval, $args, $timestamp );

		self::assertInstanceOf( Success::class, $scheduled, 'WP-Cron must accept the synthetic recurring schedule' );
		$events = $this->wordpress_cron_events( self::RESOLUTION_HOOK, $args );
		self::assertCount( 1, $events, 'The cron array must hold exactly one synthetic recurring occurrence' );
		self::assertSame( $timestamp, $events[0]['timestamp'] );
		self::assertSame( 'a8csp_bgte_every_137s', $events[0]['schedule'] );
		self::assertSame( $interval, $events[0]['interval'] );

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

		$successors = $this->wordpress_cron_events( self::RECURRING_HOOK, $args );
		self::assertCount( 1, $successors, 'Recurring delivery must retain exactly one successor occurrence' );
		self::assertGreaterThan( \time(), $successors[0]['timestamp'], 'The recurring successor must be scheduled in the future' );
		self::assertNotSame( $timestamp, $successors[0]['timestamp'], 'The due occurrence must be replaced by its successor' );
		self::assertSame( 'a8csp_bgte_every_61s', $successors[0]['schedule'] );
		self::assertSame( $interval, $successors[0]['interval'] );
		self::assertSame( $args, $successors[0]['args'] );
		self::assertSame( $successors[0]['timestamp'], \wp_next_scheduled( self::RECURRING_HOOK, $args ), 'WP-Cron reads must resolve the persisted recurring successor' );
	}

	// endregion.
}
