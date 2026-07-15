<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Backends;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the scheduling failure payload and its closed reason vocabulary.
 *
 */
#[CoversClass( SchedulingError::class )]
#[CoversClass( SchedulingErrorReason::class )]
final class SchedulingErrorTest extends TestCase {
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
	 * The error exposes the exact reason, message, and structured context supplied by its caller.
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
	 * Registry read failures describe an authoritative storage read.
	 *
	 * @return  void
	 */
	public function test_registry_read_failure_names_the_failed_read(): void {
		$error = SchedulingError::registry_read_failure( 'owner-a' );

		self::assertSame( SchedulingErrorReason::StorageFailure, $error->reason );
		self::assertSame(
			'Schedule registry state for owner "owner-a" could not be read; repair WordPress option reads and retry.',
			$error->message
		);
		self::assertSame( array( 'owner' => 'owner-a' ), $error->context );
	}

	/**
	 * Registry persist failures describe the failed durable write.
	 *
	 * @return  void
	 */
	public function test_registry_persist_failure_names_the_failed_write(): void {
		$error = SchedulingError::registry_persist_failure( 'owner-a' );

		self::assertSame( SchedulingErrorReason::StorageFailure, $error->reason );
		self::assertSame(
			'Schedule registry state for owner "owner-a" could not be persisted; repair WordPress option writes and retry synchronization.',
			$error->message
		);
		self::assertSame( array( 'owner' => 'owner-a' ), $error->context );
	}

	/**
	 * The reason set and its log-facing values remain an explicit closed contract.
	 *
	 * @return  void
	 */
	public function test_reason_set_and_backing_values_are_exact(): void {
		$reasons = SchedulingErrorReason::cases();

		self::assertSame(
			array(
				SchedulingErrorReason::BackendNotReady,
				SchedulingErrorReason::UnsupportedGroup,
				SchedulingErrorReason::UnsupportedRecurrence,
				SchedulingErrorReason::InvalidTimeInput,
				SchedulingErrorReason::InvalidPayload,
				SchedulingErrorReason::ScheduleFailed,
				SchedulingErrorReason::StorageFailure,
			),
			$reasons
		);
		self::assertSame(
			array(
				'backend_not_ready',
				'unsupported_group',
				'unsupported_recurrence',
				'invalid_time_input',
				'invalid_payload',
				'schedule_failed',
				'storage_failure',
			),
			\array_map( static fn ( SchedulingErrorReason $reason ): string => $reason->value, $reasons )
		);
	}
}
