<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Error;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the guaranteed machine-branchable error-code surface.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ErrorCode::class )]
final class ErrorCodeTest extends TestCase {

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

	/**
	 * The required vocabulary and additive execution classification retain stable backing values.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_error_code_backing_values_are_stable(): void {
		self::assertEqualsCanonicalizing(
			array(
				'invalid_argument',
				'already_registered',
				'engine_unavailable',
				'unknown_job',
				'unknown_schedule',
				'overlap_held',
				'payload_rejected',
				'backend_unavailable',
				'backend_rejected',
				'storage_failed',
				'run_not_retained',
				'run_not_cancellable',
				'unsupported_operation',
				'execution_failed',
			),
			\array_map( static fn ( ErrorCode $code ): string => $code->value, ErrorCode::cases() )
		);
	}
}
