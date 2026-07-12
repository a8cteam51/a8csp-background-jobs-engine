<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Result;

use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two Result variants and their branch-then-property consumption contract.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( AbstractResult::class )]
#[CoversClass( Success::class )]
#[CoversClass( Failure::class )]
#[UsesClass( SchedulingError::class )]
#[UsesClass( SchedulingErrorReason::class )]
final class ResultTest extends TestCase {
	/**
	 * Satisfies the production files' `ABSPATH` boot guard before the classes are first autoloaded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}
	}

	/**
	 * A success selects only the successful branch predicates.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_success_truth_table(): void {
		$this->assert_truth_table( new Success( 'complete' ), true );
	}

	/**
	 * A failure selects only the failed branch predicates.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failure_truth_table(): void {
		$this->assert_truth_table(
			new Failure(
				new SchedulingError(
					SchedulingErrorReason::ScheduleFailed,
					'Retry with a supported schedule.'
				)
			),
			false
		);
	}

	/**
	 * Null remains a value rather than becoming an absent success payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_success_carries_null_exactly(): void {
		$result = new Success( null );

		self::assertSame( null, $result->value );
	}

	/**
	 * Structured values retain their keys, values, and ordering.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_success_carries_an_array_exactly(): void {
		$value  = array(
			'task_id' => 42,
			'queued'  => true,
		);
		$result = new Success( $value );

		self::assertSame( $value, $result->value );
	}

	/**
	 * A failure exposes the same scheduling error instance supplied by its caller.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failure_carries_its_exact_error(): void {
		$error  = new SchedulingError(
			SchedulingErrorReason::InvalidInterval,
			'Use an interval greater than zero.'
		);
		$result = new Failure( $error );

		self::assertSame( $error, $result->error );
	}

	/**
	 * Predicate checks expose each variant's payload without a second type check.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_predicate_branches_expose_the_narrowed_payload(): void {
		$error = new SchedulingError(
			SchedulingErrorReason::BackendNotReady,
			'Load a supported scheduling backend.'
		);

		self::assertSame( 42, $this->read_narrowed_result( new Success( 42 ) ) );
		self::assertSame( $error->message, $this->read_narrowed_result( new Failure( $error ) ) );
	}

	/**
	 * Reads the payload selected by the result predicate.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param AbstractResult<int, SchedulingError> $result
	 *
	 * @param   AbstractResult $result Result to consume.
	 *
	 * @return  int|string
	 */
	private function read_narrowed_result( AbstractResult $result ): int|string {
		if ( $result->is_failure() ) {
			return $result->error->message;
		}

		return $result->value;
	}

	/**
	 * Asserts both predicates through the shared result contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param AbstractResult<mixed, mixed> $result
	 *
	 * @param   AbstractResult $result           Result to inspect.
	 * @param   bool           $expected_success Expected successful state.
	 *
	 * @return  void
	 */
	private function assert_truth_table( AbstractResult $result, bool $expected_success ): void {
		self::assertSame( $expected_success, $result->is_success() );
		self::assertSame( ! $expected_success, $result->is_failure() );
	}
}
