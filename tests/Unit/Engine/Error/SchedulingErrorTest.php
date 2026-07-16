<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Error;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\ApiErrorMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises consumer-visible scheduling failures through the API boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( SchedulingError::class )]
#[UsesClass( ApiError::class )]
#[UsesClass( ApiErrorMapper::class )]
#[UsesClass( Failure::class )]
#[UsesClass( SchedulingErrorReason::class )]
final class SchedulingErrorTest extends TestCase {
	// region LIFECYCLE.

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

	// endregion.

	// region TESTS.

	/**
	 * The API exposes the public code, message, and redaction-safe structured context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_carries_code_message_and_context_unchanged(): void {
		$context = array(
			'hook'     => 'a8csp_background_tasks/run_task',
			'priority' => 10,
		);
		$result  = ApiErrorMapper::map( new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Retry after the backend becomes available.', $context ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( ApiErrorCode::BackendRejected, $result->error->code );
		self::assertSame( 'Retry after the backend becomes available.', $result->error->message );
		self::assertSame( $context, $result->error->context );
	}

	/**
	 * Scheduling failures without safe structured detail expose an empty context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_context_defaults_to_an_empty_array(): void {
		$result = ApiErrorMapper::map( new Failure( new SchedulingError( SchedulingErrorReason::BackendNotReady, 'Load a supported scheduling backend.' ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( ApiErrorCode::BackendUnavailable, $result->error->code );
		self::assertSame( 'Load a supported scheduling backend.', $result->error->message );
		self::assertSame( array(), $result->error->context );
	}

	/**
	 * Registry read failures surface as storage failures with safe owner context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registry_read_failure_surfaces_as_storage_failure(): void {
		$result = ApiErrorMapper::map( new Failure( SchedulingError::registry_read_failure( 'owner-a' ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( ApiErrorCode::StorageFailure, $result->error->code );
		self::assertSame( 'Schedule registry state for owner "owner-a" could not be read; repair WordPress option reads and retry.', $result->error->message );
		self::assertSame( array( 'owner' => 'owner-a' ), $result->error->context );
	}

	/**
	 * Registry persist failures surface as storage failures with safe owner context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registry_persist_failure_surfaces_as_storage_failure(): void {
		$result = ApiErrorMapper::map( new Failure( SchedulingError::registry_persist_failure( 'owner-a' ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( ApiErrorCode::StorageFailure, $result->error->code );
		self::assertSame( 'Schedule registry state for owner "owner-a" could not be persisted; repair WordPress option writes and retry synchronization.', $result->error->message );
		self::assertSame( array( 'owner' => 'owner-a' ), $result->error->context );
	}

	/**
	 * Each scheduling rejection scenario exposes its stable public classification.
	 *
	 * @load-bearing security
	 * @pin-rationale The API boundary's scheduling classification table is the security contract that decides which internal failure becomes which public code; a public seam cannot construct the internal reasons, so the table is pinned directly.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $reason        Internal scheduling-reason backing value.
	 * @param   string $expected_code Consumer-visible scheduling classification.
	 *
	 * @return  void
	 */
	#[DataProvider( 'scheduling_failure_codes' )]
	public function test_scheduling_scenarios_expose_public_codes( string $reason, string $expected_code ): void {
		$result = ApiErrorMapper::map( new Failure( new SchedulingError( SchedulingErrorReason::from( $reason ), 'Correct the scheduling request and retry.', array( 'hook' => 'a8csp_background_tasks/run_task' ) ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( ApiErrorCode::from( $expected_code ), $result->error->code );
		self::assertSame( 'Correct the scheduling request and retry.', $result->error->message );
		self::assertSame( array( 'hook' => 'a8csp_background_tasks/run_task' ), $result->error->context );
	}

	// endregion.

	// region PROVIDERS.

	/**
	 * Supplies each scheduling rejection scenario and its public classification.
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
