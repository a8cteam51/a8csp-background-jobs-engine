<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Engine\Error;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\ApiErrorMapper;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises client-visible API outcomes and the context-redaction boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ApiErrorMapper::class )]
#[UsesClass( ApiError::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( Failure::class )]
#[UsesClass( SchedulingError::class )]
#[UsesClass( Success::class )]
final class ApiErrorMapperTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies the production files' `ABSPATH` boot guard before first autoload.
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

	// endregion.

	// region TESTS.

	/**
	 * Every engine failure reason maps to its stable public classification.
	 *
	 * @load-bearing security
	 * @pin-rationale The API boundary's engine classification table is the security contract that decides which internal failure becomes which public code; a public seam cannot construct the internal reasons, so the table is pinned directly.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $reason        Internal engine-reason backing value.
	 * @param   string $expected_code Client-visible classification.
	 *
	 * @return  void
	 */
	#[DataProvider( 'engine_failure_codes' )]
	public function test_engine_failure_scenarios_expose_public_codes( string $reason, string $expected_code ): void {
		$result = ApiErrorMapper::map( new Failure( new EngineError( message: 'Engine-authored corrective detail.', reason: EngineErrorReason::from( $reason ), context: array( 'run_id' => 'run-7' ), ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( ErrorCode::from( $expected_code ), $result->error->code );
		self::assertSame( 'Engine-authored corrective detail.', $result->error->message );
		self::assertSame( array( 'run_id' => 'run-7' ), $result->error->context );
	}

	/**
	 * Every scheduling failure reason maps to its stable public classification.
	 *
	 * @load-bearing security
	 * @pin-rationale The API boundary's scheduling classification table is the security contract that decides which internal failure becomes which public code; a public seam cannot construct the internal reasons, so the table is pinned directly.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $reason        Internal scheduling-reason backing value.
	 * @param   string $expected_code Client-visible classification.
	 *
	 * @return  void
	 */
	#[DataProvider( 'scheduling_failure_codes' )]
	public function test_scheduling_failure_scenarios_expose_public_codes( string $reason, string $expected_code ): void {
		$result = ApiErrorMapper::map( new Failure( new SchedulingError( SchedulingErrorReason::from( $reason ), 'Engine-authored scheduling detail.', array( 'hook' => 'a8csp_jobs_engine/run_job' ) ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( ErrorCode::from( $expected_code ), $result->error->code );
		self::assertSame( 'Engine-authored scheduling detail.', $result->error->message );
		self::assertSame( array( 'hook' => 'a8csp_jobs_engine/run_job' ), $result->error->context );
	}

	/**
	 * Successful values cross the API boundary without allocation or payload changes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_preserves_a_success_result_instance(): void {
		$success = new Success( 'run-7' );

		self::assertSame( $success, ApiErrorMapper::map( $success ) );
	}

	/**
	 * An unclassified internal failure fails closed at the API boundary.
	 *
	 * @load-bearing security
	 * @pin-rationale An unclassified internal failure must fail loudly at the API boundary rather than silently reach a consumer; no public seam can construct the unclassified state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_rejects_an_unclassified_engine_failure(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs( 'An internal engine failure reached the API boundary without a public classification.' );

		$result = ApiErrorMapper::map( new Failure( new EngineError( 'Unclassified failure.' ) ) );
		self::fail( 'The unclassified failure was unexpectedly mapped: ' . \get_debug_type( $result ) );
	}

	/**
	 * Database diagnostics never cross the API boundary into consumer error context.
	 *
	 * @load-bearing security
	 * @pin-rationale Database drivers expose arbitrary external text only inside the internal scheduling failure; public facades cannot inject that hostile context to prove the mapper strips it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_database_detail_does_not_reach_client_error_context(): void {
		$secret = 'password=hunter2';
		$result = ApiErrorMapper::map(
			new Failure(
				new SchedulingError(
					SchedulingErrorReason::StorageFailure,
					'The schedule registry could not be persisted.',
					array(
						'owner'         => 'consumer-plugin',
						'storage_error' => $secret,
						'wp_error'      => $secret,
					)
				)
			)
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( array( 'owner' => 'consumer-plugin' ), $result->error->context );
		self::assertArrayNotHasKey( 'storage_error', $result->error->context );
		self::assertArrayNotHasKey( 'wp_error', $result->error->context );
	}

	// endregion.

	// region PROVIDERS.

	/**
	 * Supplies every engine failure reason and its public classification.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{reason: string, expected_code: string}>
	 */
	public static function engine_failure_codes(): array {
		return array(
			'engine unavailable'    => array(
				'reason'        => 'engine_unavailable',
				'expected_code' => 'engine_unavailable',
			),
			'unknown work'          => array(
				'reason'        => 'unknown_work',
				'expected_code' => 'unknown_work',
			),
			'unknown schedule'      => array(
				'reason'        => 'unknown_schedule',
				'expected_code' => 'unknown_schedule',
			),
			'overlap held'          => array(
				'reason'        => 'overlap_held',
				'expected_code' => 'overlap_held',
			),
			'payload rejected'      => array(
				'reason'        => 'payload_rejected',
				'expected_code' => 'payload_rejected',
			),
			'storage failure'       => array(
				'reason'        => 'storage_failure',
				'expected_code' => 'storage_failure',
			),
			'run not retained'      => array(
				'reason'        => 'run_not_retained',
				'expected_code' => 'run_not_retained',
			),
			'run not cancellable'   => array(
				'reason'        => 'run_not_cancellable',
				'expected_code' => 'run_not_cancellable',
			),
			'unsupported operation' => array(
				'reason'        => 'unsupported_operation',
				'expected_code' => 'unsupported_operation',
			),
			'execution failed'      => array(
				'reason'        => 'execution_failed',
				'expected_code' => 'execution_failed',
			),
		);
	}

	/**
	 * Supplies every scheduling failure reason and its public classification.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{reason: string, expected_code: string}>
	 */
	public static function scheduling_failure_codes(): array {
		return array(
			'backend not ready'  => array(
				'reason'        => 'backend_not_ready',
				'expected_code' => 'backend_unavailable',
			),
			'unsupported group'  => array(
				'reason'        => 'unsupported_group',
				'expected_code' => 'unsupported_operation',
			),
			'invalid time input' => array(
				'reason'        => 'invalid_time_input',
				'expected_code' => 'payload_rejected',
			),
			'invalid payload'    => array(
				'reason'        => 'invalid_payload',
				'expected_code' => 'payload_rejected',
			),
			'schedule failed'    => array(
				'reason'        => 'schedule_failed',
				'expected_code' => 'backend_rejected',
			),
			'storage failure'    => array(
				'reason'        => 'storage_failure',
				'expected_code' => 'storage_failure',
			),
		);
	}

	// endregion.
}
