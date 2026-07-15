<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\AdmissionErrorMapper;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exhaustive internal-to-public admission failure translation.
 *
 */
#[CoversClass( AdmissionErrorMapper::class )]
#[UsesClass( ApiError::class )]
#[UsesClass( EngineError::class )]
#[UsesClass( SchedulingError::class )]
final class AdmissionErrorMapperTest extends TestCase {
	/**
	 * Satisfies the production files' `ABSPATH` boot guard before first autoload.
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
	 * Every classified engine failure maps to its one public code and retains safe context.
	 *
	 * @param   string $reason        Internal engine-reason backing value.
	 * @param   string $expected_code Public error-code backing value.
	 *
	 * @return  void
	 */
	#[DataProvider( 'engine_failure_mappings' )]
	public function test_maps_every_engine_failure_reason( string $reason, string $expected_code ): void {
		$context = array( 'run_id' => 'run-7' );
		$result  = AdmissionErrorMapper::map(
			new Failure(
				new EngineError(
					message: 'Engine-authored corrective detail.',
					reason: EngineErrorReason::from( $reason ),
					context: $context,
				)
			)
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( ApiErrorCode::from( $expected_code ), $result->error->code );
		self::assertSame( 'Engine-authored corrective detail.', $result->error->message );
		self::assertSame( $context, $result->error->context );
	}

	/**
	 * Supplies the complete internal engine-reason mapping table.
	 *
	 * @return  array<string, array{reason: string, expected_code: string}>
	 */
	public static function engine_failure_mappings(): array {
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
	 * Every scheduling reason maps without message inspection.
	 *
	 * @param   string $reason        Internal scheduling-reason backing value.
	 * @param   string $expected_code Public error-code backing value.
	 *
	 * @return  void
	 */
	#[DataProvider( 'scheduling_failure_mappings' )]
	public function test_maps_every_scheduling_failure_reason( string $reason, string $expected_code ): void {
		$context = array( 'hook' => 'a8csp_background_tasks/run' );
		$result  = AdmissionErrorMapper::map(
			new Failure(
				new SchedulingError(
					SchedulingErrorReason::from( $reason ),
					'Engine-authored scheduling detail.',
					$context
				)
			)
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( ApiErrorCode::from( $expected_code ), $result->error->code );
		self::assertSame( 'Engine-authored scheduling detail.', $result->error->message );
		self::assertSame( $context, $result->error->context );
	}

	/**
	 * Supplies the complete internal scheduling-reason mapping table.
	 *
	 * @return  array<string, array{reason: string, expected_code: string}>
	 */
	public static function scheduling_failure_mappings(): array {
		return array(
			'backend not ready'      => array(
				'reason'        => 'backend_not_ready',
				'expected_code' => 'backend_unavailable',
			),
			'unsupported group'      => array(
				'reason'        => 'unsupported_group',
				'expected_code' => 'unsupported_operation',
			),
			'unsupported recurrence' => array(
				'reason'        => 'unsupported_recurrence',
				'expected_code' => 'unsupported_operation',
			),
			'invalid time input'     => array(
				'reason'        => 'invalid_time_input',
				'expected_code' => 'payload_rejected',
			),
			'invalid payload'        => array(
				'reason'        => 'invalid_payload',
				'expected_code' => 'payload_rejected',
			),
			'schedule failed'        => array(
				'reason'        => 'schedule_failed',
				'expected_code' => 'backend_rejected',
			),
			'storage failure'        => array(
				'reason'        => 'storage_failure',
				'expected_code' => 'storage_failure',
			),
		);
	}

	/**
	 * Successful values cross the boundary without allocation or payload changes.
	 *
	 * @return  void
	 */
	public function test_preserves_a_success_result_instance(): void {
		$success = new Success( 'run-7' );

		self::assertSame( $success, AdmissionErrorMapper::map( $success ) );
	}

	/**
	 * An unclassified engine failure fails closed instead of guessing from prose.
	 *
	 * @return  void
	 */
	public function test_rejects_an_unclassified_engine_failure(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs( 'An internal engine failure reached the admission boundary without a public classification.' );

		$result = AdmissionErrorMapper::map( new Failure( new EngineError( 'Unclassified failure.' ) ) );
		self::fail( 'The unclassified failure was unexpectedly mapped: ' . \get_debug_type( $result ) );
	}

	/**
	 * Internal diagnostic fields that can contain arbitrary external text do not cross the boundary.
	 *
	 * @return  void
	 */
	public function test_removes_context_fields_that_are_not_redaction_safe(): void {
		$result = AdmissionErrorMapper::map(
			new Failure(
				new SchedulingError(
					SchedulingErrorReason::ScheduleFailed,
					'Engine-authored scheduling detail.',
					array(
						'hook'          => 'a8csp_background_tasks/run',
						'expression'    => 'consumer-controlled expression',
						'storage_error' => 'external storage detail',
						'wp_error'      => 'external storage detail',
					)
				)
			)
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( array( 'hook' => 'a8csp_background_tasks/run' ), $result->error->context );
	}
}
