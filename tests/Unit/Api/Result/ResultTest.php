<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Api\Result;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ErrorInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two Result variants and their branch-then-property consumption contract.
 *
 */
#[CoversClass( AbstractResult::class )]
#[CoversClass( Success::class )]
#[CoversClass( Failure::class )]
final class ResultTest extends TestCase {
	/**
	 * Satisfies the production files' `ABSPATH` boot guard before the classes are first autoloaded.
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
	 * @return  void
	 */
	public function test_success_truth_table(): void {
		$this->assert_truth_table( new Success( 'complete' ), true );
	}

	/**
	 * A failure selects only the failed branch predicates.
	 *
	 * @return  void
	 */
	public function test_failure_truth_table(): void {
		$this->assert_truth_table( new Failure( new class() implements ErrorInterface {} ), false );
	}

	/**
	 * Null remains a value rather than becoming an absent success payload.
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
	 * A failure exposes the same contract-compatible error instance supplied by its caller.
	 *
	 * @return  void
	 */
	public function test_failure_carries_its_exact_error(): void {
		$error  = new class() implements ErrorInterface {};
		$result = new Failure( $error );

		self::assertSame( $error, $result->error );
	}

	/**
	 * Runtime callers cannot construct a failed result with a value outside the error contract.
	 *
	 * @return  void
	 */
	public function test_failure_rejects_a_non_error_value_at_runtime(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs( 'A failed result requires an error implementing ErrorInterface.' );

		( new \ReflectionClass( Failure::class ) )->newInstanceArgs( array( 'not-an-error' ) );
	}

	/**
	 * Predicate checks expose each variant's payload without a second type check.
	 *
	 * @return  void
	 */
	public function test_predicate_branches_expose_the_narrowed_payload(): void {
		$error = new ApiError( ApiErrorCode::BackendUnavailable, 'Load a supported scheduling backend.' );

		self::assertSame( 42, $this->read_narrowed_result( new Success( 42 ) ) );
		self::assertSame( $error->message, $this->read_narrowed_result( new Failure( $error ) ) );
	}

	/**
	 * Reads the payload selected by the result predicate.
	 *
	 * @phpstan-param AbstractResult<int, ApiError> $result
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
	 * @phpstan-param AbstractResult<mixed, ErrorInterface> $result
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
