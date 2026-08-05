<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Error;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\BoundaryErrorMapper;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
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

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ErrorCode::BackendRejected->value, $result->get_error_code() );
		self::assertSame( 'Retry after the backend becomes available.', $result->get_error_message() );
		self::assertSame( $context, $result->get_error_data() );
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

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ErrorCode::BackendUnavailable->value, $result->get_error_code() );
		self::assertSame( 'Load a supported scheduling backend.', $result->get_error_message() );
		self::assertSame( array(), $result->get_error_data() );
	}

	/**
	 * Registry read failures surface as storage failures with safe scope context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registry_read_failure_surfaces_as_storage_failure(): void {
		$result = BoundaryErrorMapper::map( new Failure( SchedulingError::registry_read_failure( 'scope-a' ) ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ErrorCode::StorageFailed->value, $result->get_error_code() );
		self::assertSame( 'Schedule registry state for scope "scope-a" could not be read; repair WordPress option reads and retry.', $result->get_error_message() );
		self::assertSame( array( 'scope' => 'scope-a' ), $result->get_error_data() );
	}

	/**
	 * Registry persist failures surface as storage failures with safe scope context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registry_persist_failure_surfaces_as_storage_failure(): void {
		$result = BoundaryErrorMapper::map( new Failure( SchedulingError::registry_persist_failure( 'scope-a' ) ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ErrorCode::StorageFailed->value, $result->get_error_code() );
		self::assertSame( 'Schedule registry state for scope "scope-a" could not be persisted; repair WordPress option writes and retry synchronization.', $result->get_error_message() );
		self::assertSame( array( 'scope' => 'scope-a' ), $result->get_error_data() );
	}

	/**
	 * Registry corruption names the exact row and its maintenance recovery path.
	 *
	 * @return  void
	 */
	public function test_registry_corruption_surfaces_as_storage_failure(): void {
		$option_name = 'a8csp_bgje_schedule_registrations_scope-a';
		$result      = BoundaryErrorMapper::map( new Failure( SchedulingError::registry_corrupt( 'scope-a', $option_name ) ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ErrorCode::StorageFailed->value, $result->get_error_code() );
		self::assertSame( 'Schedule registry option row "a8csp_bgje_schedule_registrations_scope-a" is unreadable; maintenance reclaims it, then re-declare schedules on the next init.', $result->get_error_message() );
		self::assertSame(
			array(
				'scope'       => 'scope-a',
				'option_name' => $option_name,
			),
			$result->get_error_data()
		);
	}

	// endregion.
}
