<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Internal\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Job;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Job\CallableJob;
use A8C\SpecialProjects\BackgroundJobsEngine\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\OverlapPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the public callable-backed job implementation.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( CallableJob::class )]
#[UsesClass( Job::class )]
#[UsesClass( RetryPolicy::class )]
#[UsesClass( RunFailure::class )]
#[UsesClass( OverlapPolicy::class )]
final class CallableJobTest extends TestCase {
	/**
	 * Loads WordPress constants before a default retry policy is constructed.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-time-constant-stubs.php';
	}

	/**
	 * The minimal constructor supplies the Job defaults and forwards handler arguments.
	 *
	 * @return  void
	 */
	public function test_minimal_callable_job_uses_inherited_defaults_and_invokes_the_handler(): void {
		/** @var list<array<array-key, mixed>> $calls */
		$calls            = array();
		$observed_context = null;
		$job              = new CallableJob(
			'refresh-index',
			static function ( array $args, RunContext $context ) use ( &$calls, &$observed_context ): void {
				$calls[]          = $args;
				$observed_context = $context;
			}
		);
		$args             = array( 'site_id' => 7 );
		$context          = self::createStub( RunContext::class );
		$retry            = $job->get_retry_policy();
		$failure          = new RunFailure( identity: 'consumer-plugin:refresh-index', run_id: 'run-7', attempts: 1, stage: RunFailureStage::Execution, code: ErrorCode::ExecutionFailed, summary: 'Handler failed.', failed_chunk: null );

		$job->handle( $args, $context );
		$job->on_completed( 'run-7', $args, null );
		$job->on_failed( 'run-7', $args, $failure );

		self::assertInstanceOf( Job::class, $job );
		self::assertSame( 'refresh-index', $job->get_name() );
		self::assertSame( array( $args ), $calls );
		self::assertSame( $context, $observed_context );
		self::assertSame( 300, $job->max_callback_runtime() );
		self::assertSame( OverlapPolicy::Reject, $job->overlap_policy() );
		self::assertNull( $job->overlap_key( $args ) );
		self::assertSame( 3, $retry->max_attempts );
		self::assertSame( \MINUTE_IN_SECONDS, $retry->base_delay );
		self::assertSame( 2, $retry->multiplier );
		self::assertSame( \HOUR_IN_SECONDS, $retry->max_delay );
	}

	/**
	 * Configured terminal callbacks receive the exact lifecycle arguments.
	 *
	 * @return  void
	 */
	public function test_terminal_callbacks_forward_exact_arguments(): void {
		/** @var list<array{string, array<array-key, mixed>, string|null}> $completed_calls */
		$completed_calls = array();
		/** @var list<array{string, array<array-key, mixed>, RunFailure}> $failed_calls */
		$failed_calls = array();
		$on_completed = static function ( string $run_id, array $start_args, ?string $previous_completed_run_id ) use ( &$completed_calls ): void {
			$completed_calls[] = array( $run_id, $start_args, $previous_completed_run_id );
		};
		$on_failed    = static function ( string $run_id, array $start_args, RunFailure $failure ) use ( &$failed_calls ): void {
			$failed_calls[] = array( $run_id, $start_args, $failure );
		};
		$job          = new CallableJob( 'refresh-index', static function ( array $args, RunContext $context ): void {}, on_completed: $on_completed, on_failed: $on_failed );
		$args         = array( 'site_id' => 7 );
		$failure      = new RunFailure( identity: 'consumer-plugin:refresh-index', run_id: 'run-7', attempts: 1, stage: RunFailureStage::Execution, code: ErrorCode::ExecutionFailed, summary: 'Handler failed.', failed_chunk: null );

		$job->on_completed( 'run-7', $args, 'run-previous' );
		$job->on_failed( 'run-7', $args, $failure );

		self::assertSame( array( array( 'run-7', $args, 'run-previous' ) ), $completed_calls );
		self::assertSame( array( array( 'run-7', $args, $failure ) ), $failed_calls );
	}

	/**
	 * Explicit invariant values override the inherited defaults unchanged.
	 *
	 * @return  void
	 */
	public function test_explicit_invariants_are_returned_unchanged(): void {
		$retry       = new RetryPolicy( max_attempts: 1, base_delay: 5, multiplier: 1, max_delay: 5 );
		$overlap_key = static fn ( array $args ): ?string => \is_string( $args['tenant'] ?? null ) ? $args['tenant'] : null;
		$job         = new CallableJob( 'refresh-index', static function ( array $args, RunContext $context ): void {}, 42, $retry, OverlapPolicy::Allow, $overlap_key );

		self::assertSame( 42, $job->max_callback_runtime() );
		self::assertSame( $retry, $job->get_retry_policy() );
		self::assertSame( OverlapPolicy::Allow, $job->overlap_policy() );
		self::assertSame( 'tenant-7', $job->overlap_key( array( 'tenant' => 'tenant-7' ) ) );
	}
}
