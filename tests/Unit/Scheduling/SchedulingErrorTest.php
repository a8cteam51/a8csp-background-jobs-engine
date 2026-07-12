<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Scheduling;

use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the scheduling failure payload and its closed reason vocabulary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( SchedulingError::class )]
#[CoversClass( SchedulingErrorReason::class )]
final class SchedulingErrorTest extends TestCase {
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
	 * The error exposes the exact reason, message, and structured context supplied by its caller.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_carries_reason_message_and_context_unchanged(): void {
		$context = array(
			'backend' => 'action_scheduler',
			'attempt' => 2,
		);
		$error   = new SchedulingError(
			SchedulingErrorReason::ScheduleFailed,
			'Retry after the backend becomes available.',
			$context
		);

		self::assertSame( SchedulingErrorReason::ScheduleFailed, $error->reason );
		self::assertSame( 'Retry after the backend becomes available.', $error->message );
		self::assertSame( $context, $error->context );
	}

	/**
	 * Callers that have no structured detail receive an empty context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_context_defaults_to_an_empty_array(): void {
		$error = new SchedulingError(
			SchedulingErrorReason::BackendNotReady,
			'Load a supported scheduling backend.'
		);

		self::assertSame( array(), $error->context );
	}

	/**
	 * The reason set and its log-facing values remain an explicit closed contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_reason_set_and_backing_values_are_exact(): void {
		$reasons = SchedulingErrorReason::cases();

		self::assertSame(
			array(
				SchedulingErrorReason::BackendNotReady,
				SchedulingErrorReason::UnsupportedGroup,
				SchedulingErrorReason::UnsupportedCadence,
				SchedulingErrorReason::InvalidInterval,
				SchedulingErrorReason::PayloadTooLarge,
				SchedulingErrorReason::ScheduleFailed,
			),
			$reasons
		);
		self::assertSame(
			array(
				'backend_not_ready',
				'unsupported_group',
				'unsupported_cadence',
				'invalid_interval',
				'payload_too_large',
				'schedule_failed',
			),
			\array_map( static fn ( SchedulingErrorReason $reason ): string => $reason->value, $reasons )
		);
	}
}
