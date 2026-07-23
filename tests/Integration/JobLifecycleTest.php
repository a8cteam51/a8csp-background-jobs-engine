<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies job persistence, scheduler dispatch, lifecycle hooks, and terminal cleanup.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class JobLifecycleTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Public owner unique to this integration-test graph. */
	private const string OWNER = 'integration-job-lifecycle';

	/** Successful job identity unique within the request-persistent integration registry. */
	private const string SUCCESS_NAME = 'integration-job-lifecycle-success';

	/** Owner-qualified successful job identity persisted by the engine. */
	private const string SUCCESS_IDENTITY = self::OWNER . ':' . self::SUCCESS_NAME;

	/** Failed job identity unique within the request-persistent integration registry. */
	private const string FAILURE_NAME = 'integration-job-lifecycle-failure';

	/** Owner-qualified failed job identity persisted by the engine. */
	private const string FAILURE_IDENTITY = self::OWNER . ':' . self::FAILURE_NAME;

	// endregion.

	// region TESTS.

	/**
	 * A registered job runs through the available scheduler and leaves only bounded terminal state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[Group( 'degraded' )]
	public function test_registered_job_completes_through_the_available_scheduler(): void {
		$args = array(
			'account_id' => 42,
			'mode'       => 'refresh',
		);
		$job  = new RecordingJob( self::SUCCESS_NAME );

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::OWNER );
		$client->register( $job->definition() );

		$this->expect_option( 'a8csp_bgje_latest_run_' . self::SUCCESS_IDENTITY );

		$named_completed   = array();
		$generic_completed = array();
		\add_action(
			'a8csp_bgje/completed/' . self::SUCCESS_IDENTITY,
			static function ( RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ) use ( &$named_completed ): void {
				$named_completed[] = array( (string) $run_id, $start_args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
			},
			10,
			3
		);
		\add_action(
			'a8csp_bgje/completed',
			static function ( string $name, RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ) use ( &$generic_completed ): void {
				$generic_completed[] = array( $name, (string) $run_id, $start_args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
			},
			10,
			4
		);

		$result = $client->dispatch( self::SUCCESS_NAME, $args );
		self::assertInstanceOf( Success::class, $result, 'The registered job must enqueue through the public API' );
		self::assertIsString( $result->value );
		$run_id = $result->value;

		self::assertCount( 0, $job->calls, 'Enqueueing a job must not invoke its handler inline' );
		self::assertCount( 0, $named_completed, 'Enqueueing a job must not fire its identity-specific completed hook inline' );
		self::assertCount( 0, $generic_completed, 'Enqueueing a job must not fire its generic completed hook inline' );

		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must execute the pending job action' );

		self::assertCount( 1, $job->calls, 'The runner drive must invoke the job handler exactly once' );
		self::assertCount( 1, $named_completed, 'The runner drive must fire the identity-specific completed hook exactly once' );
		self::assertCount( 1, $generic_completed, 'The runner drive must fire the generic completed hook exactly once' );
		self::assertSame( array( $args ), $job->calls, 'The job must receive its original argument array exactly once' );
		self::assertSame( array( array( $run_id, $args, null ) ), $named_completed, 'The identity-specific completed hook must receive run ID, start arguments, and the previous completion' );
		self::assertSame( array( array( self::SUCCESS_IDENTITY, $run_id, $args, null ) ), $generic_completed, 'The generic completed hook must prepend the job name to the same payload' );
		$last_completed = $client->last_completed_run_id( self::SUCCESS_NAME );
		self::assertInstanceOf( Success::class, $last_completed );
		self::assertSame( $run_id, $last_completed->value );
		$runs = $this->inspection()->runs( self::SUCCESS_IDENTITY );
		self::assertSame( array(), $runs['live'], 'Terminal job success must leave no live run' );
		self::assertSame(
			array(
				array(
					'run_id'       => $run_id,
					'outcome'      => 'completed',
					'failed_store' => false,
				),
			),
			$runs['history']
		);
	}

	/**
	 * A non-retryable throwable fails on attempt one and retains only bounded failure state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing security
	 * @pin-rationale The upstream exception text exists only in the executing fixture and must not cross the RunFailure hook boundary; the public payload alone cannot prove which hidden source text was withheld.
	 *
	 * @return  void
	 */
	public function test_non_retryable_job_failure_is_terminal_on_attempt_one(): void {
		$this->expectOutputRegex( '/Run failed permanently; correct the cause/' );
		$args           = array(
			'account_id' => 84,
			'mode'       => 'delete',
		);
		$job            = new RecordingJob( self::FAILURE_NAME );
		$job->throwable = new NonRetryableException( 'The remote record no longer exists.' );

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::OWNER );
		$client->register( $job->definition() );

		$this->expect_option( 'a8csp_bgje_latest_run_' . self::FAILURE_IDENTITY );
		$this->expect_option( 'a8csp_bgje_failed_runs_' . self::FAILURE_IDENTITY );

		$failed = array();
		\add_action(
			'a8csp_bgje/failed',
			static function ( RunFailure $failure ) use ( &$failed ): void {
				$failed[] = $failure;
			},
			10,
			1
		);

		$result = $client->dispatch( self::FAILURE_NAME, $args );
		self::assertInstanceOf( Success::class, $result, 'The failing job must enqueue before its handler executes' );
		self::assertIsString( $result->value );
		$run_id = $result->value;

		self::assertCount( 0, $job->calls, 'Enqueueing a job must not invoke its handler inline' );
		self::assertCount( 0, $failed, 'Enqueueing a job must not fire its failed hook inline' );

		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must execute the failing job action' );

		self::assertCount( 1, $job->calls, 'The runner drive must invoke the failing job handler exactly once' );
		self::assertSame( array( $args ), $job->calls, 'A non-retryable job must execute exactly once' );
		self::assertCount( 1, $failed, 'The failed hook must fire exactly once' );
		$failure = $failed[0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		$expected_message = \sprintf( 'Background-work execution failed because %s was thrown.', NonRetryableException::class );
		self::assertSame( self::FAILURE_IDENTITY, $failure->identity );
		self::assertSame( $run_id, (string) $failure->run_id );
		self::assertSame( 1, $failure->attempts );
		self::assertSame( RunFailureStage::execution(), $failure->stage );
		self::assertSame( ErrorCode::ExecutionFailed, $failure->code );
		self::assertSame( $expected_message, $failure->summary );
		self::assertStringNotContainsString( 'The remote record no longer exists.', $failure->summary, 'RunFailure must redact the upstream exception message at the public hook boundary' );
		self::assertNull( $failure->details );
		self::assertSame( array( $failure ), $failed, 'The failed hook must receive only the self-identifying failure value' );
		self::assertSame( 0, $this->run_next_engine_action(), 'A non-retryable failure must not schedule another run attempt' );
		$runs = $this->inspection()->runs( self::FAILURE_IDENTITY );
		self::assertSame( array(), $runs['live'], 'Terminal job failure must leave no live run' );
		self::assertSame(
			array(
				array(
					'run_id'       => $run_id,
					'outcome'      => 'failed',
					'failed_store' => true,
				),
			),
			$runs['history'],
			'Inspection must expose the retained failed outcome for manual retry'
		);
	}

	// endregion.
}
