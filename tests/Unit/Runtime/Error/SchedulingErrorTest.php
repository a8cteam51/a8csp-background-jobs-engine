<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Error;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\BoundaryErrorMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises client-visible scheduling failures through the API boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( SchedulingError::class )]
#[UsesClass( BoundaryError::class )]
#[UsesClass( BoundaryErrorMapper::class )]
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
			'hook'     => 'a8csp_bgje/internal/deliver',
			'priority' => 10,
		);
		$result  = BoundaryErrorMapper::map( new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Retry after the backend becomes available.', $context ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::BackendRejected, $result->error->code );
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
		$result = BoundaryErrorMapper::map( new Failure( new SchedulingError( SchedulingErrorReason::BackendNotReady, 'Load a supported scheduling backend.' ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::BackendUnavailable, $result->error->code );
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
		$result = BoundaryErrorMapper::map( new Failure( SchedulingError::registry_read_failure( 'owner-a' ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::StorageFailed, $result->error->code );
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
		$result = BoundaryErrorMapper::map( new Failure( SchedulingError::registry_persist_failure( 'owner-a' ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::StorageFailed, $result->error->code );
		self::assertSame( 'Schedule registry state for owner "owner-a" could not be persisted; repair WordPress option writes and retry synchronization.', $result->error->message );
		self::assertSame( array( 'owner' => 'owner-a' ), $result->error->context );
	}

	/**
	 * Registry corruption names the exact row and its maintenance recovery path.
	 *
	 * @return  void
	 */
	public function test_registry_corruption_surfaces_as_storage_failure(): void {
		$option_name = 'a8csp_bgje_schedule_registrations_owner-a';
		$result      = BoundaryErrorMapper::map( new Failure( SchedulingError::registry_corrupt( 'owner-a', $option_name ) ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( BoundaryError::class, $result->error );
		self::assertSame( ErrorCode::StorageFailed, $result->error->code );
		self::assertSame( 'Schedule registry option row "a8csp_bgje_schedule_registrations_owner-a" is unreadable; maintenance reclaims it, then re-declare schedules on the next init.', $result->error->message );
		self::assertSame(
			array(
				'owner'       => 'owner-a',
				'option_name' => $option_name,
			),
			$result->error->context
		);
	}

	// endregion.
}
