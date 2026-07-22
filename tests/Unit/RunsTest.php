<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Runs;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exercises the owner-bound runs manager through the production engine graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Runs::class )]
final class RunsTest extends CapabilityManagerTestCase {
	// region TESTS.

	/**
	 * Inspection distinguishes a live run, its terminal outcome, and an absent retained run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_inspect_and_last_completed_project_retained_state(): void {
		$engine = \a8csp_bgje( self::OWNER );
		self::assertTrue( $engine->jobs()->register( self::job( 'inspect' ) ) );
		self::assertNull( $engine->runs()->last_completed( 'inspect' ) );

		$admitted = self::assert_run( $engine->jobs()->enqueue( 'inspect' ), self::OWNER . ':inspect', RunStatus::Running );
		$live     = self::assert_run( $engine->runs()->inspect( 'inspect', $admitted->run_id ), self::OWNER . ':inspect', RunStatus::Running, $admitted->run_id );
		$this->rig->run_due();
		$terminal = self::assert_run( $engine->runs()->inspect( 'inspect', $admitted->run_id ), self::OWNER . ':inspect', RunStatus::Completed, $admitted->run_id );
		$last     = self::assert_run( $engine->runs()->last_completed( 'inspect' ), self::OWNER . ':inspect', RunStatus::Completed, $admitted->run_id );

		self::assertSame( $live->run_id, $terminal->run_id );
		self::assertSame( $terminal->run_id, $last->run_id );
		self::assert_wp_error( $engine->runs()->inspect( 'inspect', 'malformed' ), 'invalid_argument' );
		self::assert_wp_error( $engine->runs()->inspect( 'inspect', self::MISSING_RUN_ID ), ErrorCode::RunNotRetained->value );
	}

	/**
	 * Failed-run retry and live-run cancellation project the specified replacement states and IDs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_and_cancel_project_running_and_cancelled_runs(): void {
		$engine     = \a8csp_bgje( self::OWNER );
		$failed_job = self::job(
			'failed',
			static function (): void {
				throw new NonRetryableException( 'Retain this failed run.' );
			}
		);
		self::assertTrue( $engine->jobs()->register( $failed_job ) );
		$failed = self::assert_run( $engine->jobs()->enqueue( 'failed', array( 'site_id' => 7 ) ), self::OWNER . ':failed', RunStatus::Running );
		$this->rig->run_due();
		self::assert_run( $engine->runs()->inspect( 'failed', $failed->run_id ), self::OWNER . ':failed', RunStatus::Failed, $failed->run_id );

		++$this->rig->clock()->timestamp;
		$retry = self::assert_run( $engine->runs()->retry_failed( 'failed', $failed->run_id ), self::OWNER . ':failed', RunStatus::Running );
		self::assertNotSame( $failed->run_id, $retry->run_id );
		self::assert_wp_error( $engine->runs()->retry_failed( 'failed', self::MISSING_RUN_ID ), ErrorCode::RunNotRetained->value );

		self::assertTrue( $engine->jobs()->register( self::job( 'cancel' ) ) );
		$pending   = self::assert_run( $engine->jobs()->enqueue( 'cancel', delay_seconds: 60 ), self::OWNER . ':cancel', RunStatus::Running );
		$cancelled = self::assert_run( $engine->runs()->cancel( 'cancel', $pending->run_id ), self::OWNER . ':cancel', RunStatus::Cancelled, $pending->run_id );
		self::assertSame( $pending->run_id, $cancelled->run_id );
		self::assert_wp_error( $engine->runs()->cancel( 'cancel', $pending->run_id ), ErrorCode::RunNotRetained->value );
	}

	// endregion.
}
