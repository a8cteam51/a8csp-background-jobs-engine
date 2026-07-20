<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Models;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function A8C\SpecialProjects\BackgroundJobsEngine\Bridge\failure_to_array;
use function A8C\SpecialProjects\BackgroundJobsEngine\Bridge\retry_policy;

/**
 * Pins the consumer-to-internal bridge conversions.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversFunction( 'A8C\SpecialProjects\BackgroundJobsEngine\Bridge\failure_to_array' )]
#[CoversFunction( 'A8C\SpecialProjects\BackgroundJobsEngine\Bridge\retry_policy' )]
#[UsesClass( RetryPolicy::class )]
#[UsesClass( RunFailure::class )]
final class BridgeHelpersTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads the guarded bridge and WordPress time constants before its dependencies are instantiated.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/wp-time-constant-stubs.php';
		require_once \dirname( __DIR__, 3 ) . '/includes/bridge.php';
	}

	// endregion.

	// region TESTS.

	/**
	 * An empty consumer declaration materializes the internal retry defaults.
	 *
	 * @return  void
	 */
	public function test_empty_retry_declaration_returns_internal_defaults(): void {
		$retry = retry_policy( array() );

		self::assertSame( 3, $retry->max_attempts );
		self::assertSame( \MINUTE_IN_SECONDS, $retry->base_delay );
		self::assertSame( 2, $retry->multiplier );
		self::assertSame( \HOUR_IN_SECONDS, $retry->max_delay );
	}

	/**
	 * Omitted retry fields inherit internal defaults while declared values remain exact.
	 *
	 * @return  void
	 */
	public function test_partial_retry_declaration_merges_with_internal_defaults(): void {
		$retry = retry_policy(
			array(
				'max_attempts' => 5,
				'multiplier'   => 3,
			)
		);

		self::assertSame( 5, $retry->max_attempts );
		self::assertSame( \MINUTE_IN_SECONDS, $retry->base_delay );
		self::assertSame( 3, $retry->multiplier );
		self::assertSame( \HOUR_IN_SECONDS, $retry->max_delay );
	}

	/**
	 * Invalid consumer retry declarations fail at the bridge boundary.
	 *
	 * @param   array<array-key, mixed> $declaration Invalid retry declaration.
	 * @param   string                  $message     Expected validation message.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_retry_declarations' )]
	public function test_rejects_invalid_retry_declarations( array $declaration, string $message ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/^' . \preg_quote( $message, '/' ) . '$/D' );

		retry_policy( $declaration );
	}

	/**
	 * Every internal failure field maps to the exact public six-field shape.
	 *
	 * @param   array<array-key, mixed>|null $failed_chunk Failed chunk projected by the bridge.
	 *
	 * @return  void
	 */
	#[DataProvider( 'failed_chunks' )]
	public function test_failure_conversion_maps_the_exact_public_shape( ?array $failed_chunk ): void {
		$failure = new RunFailure(
			identity: 'consumer-plugin:recount-comments',
			run_id: 'run-23',
			attempts: 4,
			stage: RunFailureStage::QueueGeneration,
			code: ApiErrorCode::PayloadRejected,
			summary: 'Generated queue payload was rejected.',
			failed_chunk: $failed_chunk,
		);

		self::assertSame(
			array(
				'run_id'       => 'run-23',
				'attempts'     => 4,
				'stage'        => 'queue_generation',
				'code'         => 'payload_rejected',
				'summary'      => 'Generated queue payload was rejected.',
				'failed_chunk' => $failed_chunk,
			),
			failure_to_array( $failure )
		);
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Supplies failure projections with and without chunk arguments.
	 *
	 * @phpstan-return iterable<string, array{array<array-key, mixed>|null}>
	 *
	 * @return  iterable
	 */
	public static function failed_chunks(): iterable {
		yield 'without failed chunk' => array( null );
		yield 'with failed chunk' => array( array( 'offset' => 20 ) );
	}

	/**
	 * Supplies retry declarations rejected by bridge schema and policy invariants.
	 *
	 * @phpstan-return iterable<string, array{array<array-key, mixed>, string}>
	 *
	 * @return  iterable
	 */
	public static function invalid_retry_declarations(): iterable {
		yield 'unsupported field' => array(
			array( 'jitter' => 1 ),
			'Retry declarations accept only integer max_attempts, base_delay, multiplier, and max_delay fields.',
		);
		yield 'non-integer field' => array(
			array( 'max_attempts' => '3' ),
			'Retry declarations accept only integer max_attempts, base_delay, multiplier, and max_delay fields.',
		);
		yield 'invalid policy invariant' => array(
			array( 'max_attempts' => 0 ),
			'Retry policy requires at least one attempt and a positive base delay.',
		);
	}

	// endregion.
}
