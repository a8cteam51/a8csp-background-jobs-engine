<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedules;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exercises the owner-bound schedules manager through the production engine graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Schedules::class )]
final class SchedulesTest extends CapabilityManagerTestCase {
	// region TESTS.

	/**
	 * Complete schedule values synchronize and immediate dispatch projects the run under the
	 * target job's identity, so the returned handle round-trips through the runs portal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_and_dispatch_project_public_results(): void {
		/** @var list<array<array-key, mixed>> $handled */
		$handled = array();
		$engine  = \a8csp_bgje( self::OWNER );
		$job     = self::job(
			'scheduled-job',
			static function ( array $args ) use ( &$handled ): void {
				$handled[] = $args;
			}
		);
		self::assertTrue( $engine->jobs()->register( $job ) );
		$args     = array( 'scope' => 'all' );
		$schedule = new Schedule( name: 'nightly', recurrence: Recurrence::every_anchored( 300, 650 ), job: 'scheduled-job', args: $args, catch_up: CatchUpPolicy::Skip, priority: 41 );

		self::assertTrue( $engine->schedules()->sync( $schedule ) );
		$schedule_call = self::latest_backend_call( $this->rig, 'schedule_recurring' );
		self::assertSame( 300, $schedule_call['args']['interval'] ?? null );
		self::assertSame( 41, $schedule_call['args']['priority'] ?? null );
		self::assertIsInt( $schedule_call['args']['first_run_timestamp'] ?? null );
		self::assertSame( 50, ( $schedule_call['args']['first_run_timestamp'] ?? 0 ) % 300 );

		$run = self::assert_run( $engine->schedules()->dispatch( 'nightly' ), self::OWNER . ':scheduled-job', RunStatus::Running );
		self::assert_run( $engine->runs()->inspect( 'scheduled-job', $run->id ), self::OWNER . ':scheduled-job', RunStatus::Running, $run->id );
		$this->rig->run_due();

		self::assertSame( array( $args ), $handled );
	}

	/**
	 * The variadic surface accepts several schedules at once, and no arguments clears the set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_accepts_several_schedules_and_no_arguments_clears_the_set(): void {
		$engine = \a8csp_bgje( self::OWNER );
		self::assertTrue( $engine->jobs()->register( self::job( 'hourly-job', static function (): void {} ) ) );
		self::assertTrue( $engine->jobs()->register( self::job( 'daily-job', static function (): void {} ) ) );

		$hourly = new Schedule( 'hourly', Recurrence::every( 3_600 ), 'hourly-job', array(), CatchUpPolicy::RunOnce, 10 );
		$daily  = new Schedule( 'daily', Recurrence::every( 86_400 ), 'daily-job', array(), CatchUpPolicy::RunOnce, 10 );

		self::assertTrue( $engine->schedules()->sync( $hourly, $daily ) );
		self::assert_run( $engine->schedules()->dispatch( 'hourly' ), self::OWNER . ':hourly-job', RunStatus::Running );
		self::assert_run( $engine->schedules()->dispatch( 'daily' ), self::OWNER . ':daily-job', RunStatus::Running );

		self::assertTrue( $engine->schedules()->sync() );
		self::assertInstanceOf( \WP_Error::class, $engine->schedules()->dispatch( 'hourly' ), 'A cleared schedule must no longer be dispatchable.' );
	}

	// endregion.
}
