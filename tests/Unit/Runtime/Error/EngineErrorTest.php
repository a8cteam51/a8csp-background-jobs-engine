<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Error;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises client-visible terminal failure detail and its throwable redaction boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( EngineError::class )]
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
			'retry policy'                   => EngineError::retry_policy( 'job', Identity::compose( 'consumer', 'email-digest' ), $throwable ),
			'retry preparation'              => EngineError::retry_preparation( 'chunked_job', Identity::compose( 'consumer', 'catalog-sync' ), $throwable ),
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
			self::assertStringStartsWith( 'job "consumer:email-digest"', $error->message );
		} elseif ( 'retry preparation' === $boundary ) {
			self::assertStringStartsWith( 'chunked_job "consumer:catalog-sync"', $error->message );
		}
	}

	// endregion.

	// region DATA PROVIDERS.

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

	// endregion.
}
