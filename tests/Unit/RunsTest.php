<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runs;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exercises the scope-bound runs manager through the production engine graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Runs::class )]
final class RunsTest extends AbstractCapabilityManagerTestCase {
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
		$engine = \a8csp_bgje( self::SCOPE );
		self::assertTrue( $engine->jobs()->register( self::job( 'inspect' ) ) );
		self::assertNull( $engine->runs()->last_completed( 'inspect' ) );

		$admitted = self::assert_run( $engine->jobs()->dispatch( 'inspect' ), self::SCOPE . ':inspect', RunStatus::Running );
		$live     = self::assert_run( $engine->runs()->inspect( 'inspect', $admitted->id ), self::SCOPE . ':inspect', RunStatus::Running, $admitted->id );
		$this->rig->run_due();
		$terminal = self::assert_run( $engine->runs()->inspect( 'inspect', $admitted->id ), self::SCOPE . ':inspect', RunStatus::Completed, $admitted->id );
		$last     = self::assert_run( $engine->runs()->last_completed( 'inspect' ), self::SCOPE . ':inspect', RunStatus::Completed, $admitted->id );

		self::assertSame( (string) $live->id, (string) $terminal->id );
		self::assertSame( (string) $terminal->id, (string) $last->id );
		self::assert_wp_error( $engine->runs()->inspect( 'inspect', RunId::from( self::MISSING_RUN_ID ) ), ErrorCode::RunNotRetained->value );
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
		$engine     = \a8csp_bgje( self::SCOPE );
		$failed_job = self::job(
			'failed',
			static function (): void {
				throw new NonRetryableException( 'Retain this failed run.' );
			}
		);
		self::assertTrue( $engine->jobs()->register( $failed_job ) );
		$failed = self::assert_run( $engine->jobs()->dispatch( 'failed', array( 'site_id' => 7 ) ), self::SCOPE . ':failed', RunStatus::Running );
		$this->rig->run_due();
		self::assert_run( $engine->runs()->inspect( 'failed', $failed->id ), self::SCOPE . ':failed', RunStatus::Failed, $failed->id );

		++$this->rig->clock()->timestamp;
		$retry = self::assert_run( $engine->runs()->retry_failed( 'failed', $failed->id ), self::SCOPE . ':failed', RunStatus::Running );
		self::assertNotSame( (string) $failed->id, (string) $retry->id );
		self::assert_wp_error( $engine->runs()->retry_failed( 'failed', RunId::from( self::MISSING_RUN_ID ) ), ErrorCode::RunNotRetained->value );

		self::assertTrue( $engine->jobs()->register( self::job( 'cancel' ) ) );
		$pending   = self::assert_run( $engine->jobs()->dispatch_at( 'cancel', self::NOW + 61 ), self::SCOPE . ':cancel', RunStatus::Running );
		$cancelled = self::assert_run( $engine->runs()->cancel( 'cancel', $pending->id ), self::SCOPE . ':cancel', RunStatus::Cancelled, $pending->id );
		self::assertSame( (string) $pending->id, (string) $cancelled->id );
		self::assert_wp_error( $engine->runs()->cancel( 'cancel', $pending->id ), ErrorCode::RunNotRetained->value );
	}

	// endregion.
}
