<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Observes what Action Scheduler does to an anchored chain executed late.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[Group( 'anchored-phase' )]
final class AnchoredSchedulePhaseTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	private const int INTERVAL    = 300;
	private const string JOB      = 'phase-target';
	private const string SCHEDULE = 'phase';
	private const string SCOPE    = 'integration-anchored-phase';
	private const string TICK     = 'a8csp_bgje/internal/schedule_due';

	// endregion.

	// region TESTS.

	/**
	 * An anchored chain executed late keeps the grid its declaration named.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_late_anchored_tick_keeps_its_declared_grid(): void {
		global $wpdb;
		self::assertInstanceOf( \wpdb::class, $wpdb );

		$this->expect_option( 'a8csp_bgje_schedule_registrations_' . self::SCOPE );

		$engine = \a8csp_bgje( self::SCOPE );
		self::assertTrue( $engine->jobs()->register( JobDefinition::closure( self::JOB, static function ( array $start_args, RunContextInterface $context ): void {} ) ) );
		self::assertTrue( $engine->schedules()->sync( new Schedule( self::SCHEDULE, Recurrence::every_anchored( self::INTERVAL, 0 ), self::JOB ) ) );

		$identity  = self::SCOPE . ':' . self::SCHEDULE;
		$scheduled = \as_next_scheduled_action( self::TICK, array( $identity ), $identity );
		self::assertIsInt( $scheduled, 'The engine must have scheduled an anchored tick' );
		self::assertSame( 0, $scheduled % self::INTERVAL, 'The engine schedules the tick on the declared anchor grid' );

		// Make the pending tick overdue by an amount that is not a whole interval, so a chain derived from the
		// execution time lands off the declared grid and a chain derived from the grid does not.
		$late_by = 137;
		$overdue = $scheduled - self::INTERVAL - $late_by;
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET scheduled_date_gmt = %s, scheduled_date_local = %s WHERE hook = %s AND status = %s',
				$wpdb->prefix . 'actionscheduler_actions',
				\gmdate( 'Y-m-d H:i:s', $overdue ),
				\gmdate( 'Y-m-d H:i:s', $overdue ),
				self::TICK,
				'pending'
			) ?? throw new \LogicException( 'The backdating statement must prepare.' )
		);
		self::assertSame( 1, $updated, 'Exactly one pending tick must have been backdated' );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the overdue tick' );

		$next = \as_next_scheduled_action( self::TICK, array( $identity ), $identity );
		self::assertIsInt( $next, 'Action Scheduler must retain the recurring chain after a late execution' );

		self::assertSame( 0, $next % self::INTERVAL, 'A late execution must not move an anchored chain off its declared grid' );
	}

	// endregion.
}
