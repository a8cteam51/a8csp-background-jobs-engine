<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

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
	 * Complete schedule specifications synchronize and immediate dispatch projects the schedule run.
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
		$args = array( 'scope' => 'all' );
		$spec = array(
			'name'     => 'nightly',
			'every'    => 300,
			'anchor'   => 650,
			'job'      => 'scheduled-job',
			'args'     => $args,
			'catch_up' => 'skip',
			'priority' => 41,
		);

		self::assertTrue( $engine->schedules()->sync( array( $spec ) ) );
		$schedule_call = self::latest_backend_call( $this->rig, 'schedule_recurring' );
		self::assertSame( 300, $schedule_call['args']['interval'] ?? null );
		self::assertSame( 41, $schedule_call['args']['priority'] ?? null );
		self::assertIsInt( $schedule_call['args']['first_run_timestamp'] ?? null );
		self::assertSame( 50, ( $schedule_call['args']['first_run_timestamp'] ?? 0 ) % 300 );

		$run = self::assert_run( $engine->schedules()->dispatch( 'nightly' ), self::OWNER . ':nightly', RunStatus::Running );
		$this->rig->run_due();

		self::assertNotSame( '', $run->run_id );
		self::assertSame( array( $args ), $handled );
	}

	/**
	 * Malformed schedule declarations remain inside the invalid-argument boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $schedules Invalid schedule specifications.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_schedules' )]
	public function test_sync_rejects_malformed_entries( array $schedules ): void {
		self::assert_wp_error( \a8csp_bgje( self::OWNER )->schedules()->sync( $schedules ), 'invalid_argument' );
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Supplies representative malformed schedule shapes and fields.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{schedules: array<array-key, mixed>}>
	 */
	public static function invalid_schedules(): array {
		return array(
			'non-array entry'  => array( 'schedules' => array( 'nightly' ) ),
			'missing name'     => array(
				'schedules' => array(
					array(
						'every' => 300,
						'job'   => 'job',
					),
				),
			),
			'invalid catch up' => array(
				'schedules' => array(
					array(
						'name'     => 'nightly',
						'every'    => 300,
						'job'      => 'job',
						'catch_up' => 'replay_all',
					),
				),
			),
		);
	}

	// endregion.
}
