<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the engine failure detail and its absent exception-class default.
 *
 */
#[CoversClass( EngineError::class )]
#[UsesClass( SchedulingError::class )]
final class EngineErrorTest extends TestCase {

	/**
	 * Satisfies the production file's `ABSPATH` boot guard before first autoload.
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
	 * Message and exception class retain the caller's exact values.
	 *
	 * @return  void
	 */
	public function test_carries_message_and_exception_class_unchanged(): void {
		$error = new EngineError(
			message: 'Index refresh failed.',
			exception_class: \RuntimeException::class,
		);

		self::assertSame( 'Index refresh failed.', $error->message );
		self::assertSame( \RuntimeException::class, $error->exception_class );
		self::assertObjectNotHasProperty( 'context', $error );
	}

	/**
	 * Callers without an exception class receive a null default.
	 *
	 * @return  void
	 */
	public function test_defaults_exception_class(): void {
		$error = new EngineError( 'Work failed.' );

		self::assertSame( 'Work failed.', $error->message );
		self::assertNull( $error->exception_class );
		self::assertObjectNotHasProperty( 'context', $error );
	}

	/**
	 * Throwable conversion names the class without retaining consumer-controlled message content.
	 *
	 * @return  void
	 */
	public function test_from_throwable_omits_the_throwable_message(): void {
		$error = EngineError::from_throwable( new \RuntimeException( 'Bearer secret-token' ) );

		self::assertSame(
			'Background-work execution failed because RuntimeException was thrown.',
			$error->message
		);
		self::assertSame( \RuntimeException::class, $error->exception_class );
		self::assertStringNotContainsString( 'secret-token', $error->message );
	}

	/**
	 * Anonymous throwable diagnostics do not retain their synthetic source-path class suffix.
	 *
	 * @return  void
	 */
	public function test_from_throwable_uses_a_path_free_anonymous_class_type(): void {
		$throwable = new class( 'Bearer secret-token' ) extends \RuntimeException {};

		$error = EngineError::from_throwable( $throwable );

		self::assertSame( 'Background-work execution failed because RuntimeException@anonymous was thrown.', $error->message );
		self::assertSame( 'RuntimeException@anonymous', $error->exception_class );
		self::assertStringNotContainsString( "\0", $error->exception_class );
		self::assertStringNotContainsString( __DIR__, $error->exception_class );
	}

	/**
	 * Retry-policy conversion retains corrective engine prose without consumer message content.
	 *
	 * @return  void
	 */
	public function test_retry_policy_omits_the_throwable_message(): void {
		$error = EngineError::retry_policy(
			'Task',
			'email-digest',
			new \DomainException( 'user@example.com' )
		);

		self::assertSame(
			'Task "email-digest" could not resolve the retry policy because DomainException was thrown. Fix the retry policy provider or filter before retrying the failed run manually.',
			$error->message
		);
		self::assertSame( \DomainException::class, $error->exception_class );
		self::assertStringNotContainsString( 'user@example.com', $error->message );
	}

	/**
	 * Retry-preparation conversion retains corrective engine prose without consumer message content.
	 *
	 * @return  void
	 */
	public function test_retry_preparation_omits_the_throwable_message(): void {
		$error = EngineError::retry_preparation(
			'Batch',
			'catalog-sync',
			new \UnexpectedValueException( 'password=hunter2' )
		);

		self::assertSame(
			'Batch "catalog-sync" could not prepare the retry action because UnexpectedValueException was thrown. Fix the retry policy, randomness source, retrying hook, or scheduler before retrying the failed run manually.',
			$error->message
		);
		self::assertSame( \UnexpectedValueException::class, $error->exception_class );
		self::assertStringNotContainsString( 'password=hunter2', $error->message );
	}

	/**
	 * Scheduling reasons map to the public availability classification without message inspection.
	 *
	 * @param   string $reason        Internal scheduling-reason backing value.
	 * @param   string $expected_code Consumer-visible classification backing value.
	 *
	 * @return  void
	 */
	#[DataProvider( 'scheduling_code_mappings' )]
	public function test_maps_scheduling_reasons_to_api_codes( string $reason, string $expected_code ): void {
		$error = new SchedulingError( SchedulingErrorReason::from( $reason ), 'Corrective engine prose.' );

		self::assertSame( ApiErrorCode::from( $expected_code ), EngineError::api_code_for_scheduling( $error ) );
	}

	/**
	 * Supplies every scheduling reason and its consumer-visible classification.
	 *
	 * @return  array<string, array{reason: string, expected_code: string}>
	 */
	public static function scheduling_code_mappings(): array {
		return array(
			'backend not ready'      => array(
				'reason'        => 'backend_not_ready',
				'expected_code' => 'backend_unavailable',
			),
			'unsupported group'      => array(
				'reason'        => 'unsupported_group',
				'expected_code' => 'backend_rejected',
			),
			'unsupported recurrence' => array(
				'reason'        => 'unsupported_recurrence',
				'expected_code' => 'backend_rejected',
			),
			'invalid interval'       => array(
				'reason'        => 'invalid_interval',
				'expected_code' => 'backend_rejected',
			),
			'payload too large'      => array(
				'reason'        => 'payload_too_large',
				'expected_code' => 'backend_rejected',
			),
			'schedule failed'        => array(
				'reason'        => 'schedule_failed',
				'expected_code' => 'backend_rejected',
			),
		);
	}
}
