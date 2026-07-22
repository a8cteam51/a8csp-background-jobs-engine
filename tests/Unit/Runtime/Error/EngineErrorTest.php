<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Error;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises client-visible terminal failure detail and its throwable redaction boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( EngineError::class )]
#[CoversClass( RunFailure::class )]
#[UsesClass( SchedulingError::class )]
final class EngineErrorTest extends TestCase {
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
	 * Client-visible terminal detail retains its stable summary and classification.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_failure_carries_summary_and_code_unchanged(): void {
		$failure = self::failure( ErrorCode::ExecutionFailed, 'Index refresh failed.' );

		self::assertSame( 'Index refresh failed.', $failure->summary );
		self::assertSame( ErrorCode::ExecutionFailed, $failure->code );
	}

	/**
	 * Terminal failures without a failed chunked job chunk expose null through the public value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_failure_carries_an_absent_chunk_as_null(): void {
		$failure = self::failure( ErrorCode::ExecutionFailed, 'Work failed.' );

		self::assertNull( $failure->failed_chunk );
	}

	/**
	 * Throwable-derived terminal detail never retains arbitrary throwable text or source paths.
	 *
	 * @load-bearing security
	 * @pin-rationale Callback and retry throwables cross an internal terminalization boundary; public values cannot reveal whether raw throwable content was retained before redacted projection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string     $boundary         Throwable boundary under test.
	 * @param   \Throwable $throwable        Throwable carrying prohibited diagnostic content.
	 * @param   string     $secret           Content that must not be retained.
	 * @param   string     $expected_class   Redaction-safe throwable class name.
	 * @param   string     $corrective_prose Engine-authored corrective message anchor.
	 *
	 * @return  void
	 */
	#[DataProvider( 'throwable_redaction_scenarios' )]
	public function test_throwable_content_is_redacted_before_terminal_detail_is_retained( string $boundary, \Throwable $throwable, string $secret, string $expected_class, string $corrective_prose ): void {
		$error = match ( $boundary ) {
			'callback', 'anonymous callback' => EngineError::from_throwable( $throwable ),
			'retry policy'                   => EngineError::retry_policy( 'job', 'email-digest', $throwable ),
			'retry preparation'              => EngineError::retry_preparation( 'chunked_job', 'catalog-sync', $throwable ),
			default                          => self::fail( 'Unknown throwable boundary: ' . $boundary ),
		};

		self::assertSame( $expected_class, $error->exception_class );
		self::assertNotSame( '', $error->message );
		self::assertStringContainsString( $corrective_prose, $error->message );
		self::assertStringNotContainsString( $secret, $error->message );
		self::assertStringNotContainsString( $secret, $error->exception_class ?? '' );
		self::assertStringNotContainsString( "\0", $error->exception_class ?? '' );
		self::assertStringNotContainsString( __DIR__, $error->exception_class ?? '' );
		if ( 'retry policy' === $boundary ) {
			self::assertStringStartsWith( 'job "email-digest"', $error->message );
		} elseif ( 'retry preparation' === $boundary ) {
			self::assertStringStartsWith( 'chunked_job "catalog-sync"', $error->message );
		}
	}

	/**
	 * Scheduling terminalization scenarios expose their consumer-visible classifications.
	 *
	 * @load-bearing security
	 * @pin-rationale The terminalization boundary's scheduling classification table is the security contract that decides which internal failure becomes which public code; a public seam cannot construct the internal reasons, so the table is pinned directly.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $reason        Internal scheduling-reason backing value.
	 * @param   string $expected_code Client-visible terminal classification.
	 *
	 * @return  void
	 */
	#[DataProvider( 'scheduling_code_mappings' )]
	public function test_scheduling_failures_expose_public_codes( string $reason, string $expected_code ): void {
		$error = new SchedulingError( SchedulingErrorReason::from( $reason ), 'Corrective engine prose.' );

		self::assertSame( ErrorCode::from( $expected_code ), EngineError::api_code_for_scheduling( $error ) );
	}

	// endregion.

	// region PROVIDERS.

	/**
	 * Supplies every throwable boundary that produces retained terminal detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{boundary: string, throwable: \Throwable, secret: string, expected_class: string, corrective_prose: string}>
	 */
	public static function throwable_redaction_scenarios(): array {
		return array(
			'callback'           => array(
				'boundary'         => 'callback',
				'throwable'        => new \RuntimeException( 'Bearer secret-token' ),
				'secret'           => 'secret-token',
				'expected_class'   => \RuntimeException::class,
				'corrective_prose' => 'Background-work execution failed because RuntimeException was thrown.',
			),
			'anonymous callback' => array(
				'boundary'         => 'anonymous callback',
				'throwable'        => new class( 'Bearer secret-token' ) extends \RuntimeException {},
				'secret'           => 'secret-token',
				'expected_class'   => 'RuntimeException@anonymous',
				'corrective_prose' => 'Background-work execution failed because RuntimeException@anonymous was thrown.',
			),
			'retry policy'       => array(
				'boundary'         => 'retry policy',
				'throwable'        => new \DomainException( 'user@example.com' ),
				'secret'           => 'user@example.com',
				'expected_class'   => \DomainException::class,
				'corrective_prose' => 'Fix the retry policy provider or filter before retrying the failed run manually.',
			),
			'retry preparation'  => array(
				'boundary'         => 'retry preparation',
				'throwable'        => new \UnexpectedValueException( 'password=hunter2' ),
				'secret'           => 'password=hunter2',
				'expected_class'   => \UnexpectedValueException::class,
				'corrective_prose' => 'Fix the retry policy, randomness source, retry-scheduled hook, or scheduler before retrying the failed run manually.',
			),
		);
	}

	/**
	 * Supplies every scheduling reason and its client-visible classification.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{reason: string, expected_code: string}>
	 */
	public static function scheduling_code_mappings(): array {
		return array(
			'backend not ready'  => array(
				'reason'        => 'backend_not_ready',
				'expected_code' => 'backend_unavailable',
			),
			'unsupported group'  => array(
				'reason'        => 'unsupported_group',
				'expected_code' => 'backend_rejected',
			),
			'invalid time input' => array(
				'reason'        => 'invalid_time_input',
				'expected_code' => 'backend_rejected',
			),
			'invalid payload'    => array(
				'reason'        => 'invalid_payload',
				'expected_code' => 'backend_rejected',
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

	// region HELPERS.

	/**
	 * Creates one public terminal-failure value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ErrorCode $code    Client-visible classification.
	 * @param   string       $summary Engine-authored redacted summary.
	 *
	 * @return  RunFailure
	 */
	private static function failure( ErrorCode $code, string $summary ): RunFailure {
		return new RunFailure( identity: 'consumer-plugin:sync', run_id: RunId::from( '00000000001721664000-0000000000000000007' ), attempts: 1, stage: RunFailureStage::Scheduling, code: $code, summary: $summary, failed_chunk: null, );
	}

	// endregion.
}
