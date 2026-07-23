<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;

/**
 * Verifies a non-retryable job failure terminates after its first attempt.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class NonRetryableTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Public owner unique to this integration-test graph. */
	private const string OWNER = 'integration-non-retryable';

	/** Job identity unique within the request-persistent integration registry. */
	private const string NAME = 'integration-non-retryable';

	/** Owner-qualified job identity persisted by the engine. */
	private const string IDENTITY = self::OWNER . ':' . self::NAME;

	// endregion.

	// region TESTS.

	/**
	 * A non-retryable exception fails immediately without scheduling or announcing a retry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing security
	 * @pin-rationale The upstream exception text exists only in the executing fixture and must not cross the RunFailure hook boundary; the public payload alone cannot prove which hidden source text was withheld.
	 *
	 * @return  void
	 */
	public function test_non_retryable_exception_is_terminal_on_attempt_one(): void {
		$this->expectOutputRegex( '/Run failed permanently; correct the cause/' );
		$args           = array(
			'record_id' => 404,
			'operation' => 'delete',
		);
		$job            = new RecordingJob( self::NAME );
		$job->throwable = new NonRetryableException( 'The requested record is permanently unavailable.' );

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::OWNER );
		$client->register( $job->definition() );

		$this->expect_option( 'a8csp_bgje_latest_run_' . self::IDENTITY );
		$this->expect_option( 'a8csp_bgje_failed_runs_' . self::IDENTITY );

		$named_retry_scheduled   = array();
		$generic_retry_scheduled = array();
		$failed                  = array();
		\add_action(
			'a8csp_bgje/retry_scheduled/' . self::IDENTITY,
			static function ( RunId $run_id, array $start_args, int $attempt, int $delay ) use ( &$named_retry_scheduled ): void {
				$named_retry_scheduled[] = array( (string) $run_id, $start_args, $attempt, $delay );
			},
			10,
			4
		);
		\add_action(
			'a8csp_bgje/retry_scheduled',
			static function ( string $name, RunId $run_id, array $start_args, int $attempt, int $delay ) use ( &$generic_retry_scheduled ): void {
				$generic_retry_scheduled[] = array( $name, (string) $run_id, $start_args, $attempt, $delay );
			},
			10,
			5
		);
		\add_action(
			'a8csp_bgje/failed',
			static function ( RunFailure $failure ) use ( &$failed ): void {
				$failed[] = $failure;
			},
			10,
			1
		);

		$result = $client->dispatch( self::NAME, $args );
		self::assertInstanceOf( Success::class, $result, 'The non-retryable job must enqueue before its handler fails' );
		self::assertIsString( $result->value );
		$run_id = $result->value;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the non-retryable job action' );

		self::assertSame( array( $args ), $job->calls, 'A non-retryable job must execute exactly once' );
		self::assertSame( array(), $named_retry_scheduled, 'A non-retryable failure must not fire the identity-specific retry-scheduled hook' );
		self::assertSame( array(), $generic_retry_scheduled, 'A non-retryable failure must not fire the generic retry-scheduled hook' );
		self::assertCount( 1, $failed, 'A non-retryable failure must fire the failed hook once' );

		$failure = $failed[0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		$expected_message = \sprintf( 'Background-work execution failed because %s was thrown.', NonRetryableException::class );
		self::assertSame( self::IDENTITY, $failure->identity );
		self::assertSame( $run_id, (string) $failure->run_id );
		self::assertSame( 1, $failure->attempts );
		self::assertSame( RunFailureStage::execution(), $failure->stage );
		self::assertSame( ErrorCode::ExecutionFailed, $failure->code );
		self::assertSame( $expected_message, $failure->summary );
		self::assertStringNotContainsString( 'The requested record is permanently unavailable.', $failure->summary, 'RunFailure must redact the upstream exception message at the public hook boundary' );
		self::assertNull( $failure->details );
		self::assertSame( array( $failure ), $failed, 'The failed hook must receive only the self-identifying failure value' );

		self::assertSame( 0, $this->run_next_due_action(), 'A non-retryable failure must not schedule another attempt' );
		$runs = $this->inspection()->runs( self::IDENTITY );
		self::assertSame( array(), $runs['live'], 'Terminal non-retryable failure must leave no live run' );
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
