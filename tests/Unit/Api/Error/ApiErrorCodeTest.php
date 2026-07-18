<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Api\Error;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the guaranteed machine-branchable error-code surface.
 */
#[CoversClass( ApiErrorCode::class )]
final class ApiErrorCodeTest extends TestCase {

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
	 * The required vocabulary and additive execution classification retain stable backing values.
	 *
	 * @return  void
	 */
	public function test_error_code_backing_values_are_stable(): void {
		self::assertEqualsCanonicalizing(
			array(
				'engine_unavailable',
				'unknown_work',
				'unknown_schedule',
				'overlap_held',
				'payload_rejected',
				'backend_unavailable',
				'backend_rejected',
				'storage_failure',
				'run_not_retained',
				'run_not_cancellable',
				'unsupported_operation',
				'execution_failed',
			),
			\array_map( static fn ( ApiErrorCode $code ): string => $code->value, ApiErrorCode::cases() )
		);
	}
}
