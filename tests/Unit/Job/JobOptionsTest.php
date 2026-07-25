<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the immutable policy declaration and its named-argument API.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( JobOptions::class )]
#[UsesClass( RetryPolicy::class )]
final class JobOptionsTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads WordPress time constants before retry policies are constructed.
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

		require_once \dirname( __DIR__ ) . '/wp-time-constant-stubs.php';
	}

	// endregion.

	// region TESTS.

	/**
	 * Constructor defaults select the engine-managed policy values.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_constructor_defaults_select_engine_policy(): void {
		$options = new JobOptions();
		self::assertNull( $options->max_runtime );
		self::assertNull( $options->retry );
		self::assertNull( $options->overlap );
		self::assertNull( $options->overlap_key );
	}

	/**
	 * One second is the minimum valid declared runtime.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_max_runtime_accepts_positive_seconds(): void {
		$options = new JobOptions( max_runtime: 1 );

		self::assertSame( 1, $options->max_runtime );
	}

	/**
	 * Declarations above the effective lease ceiling remain valid policy data.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_max_runtime_accepts_values_above_the_effective_lease_ceiling(): void {
		$options = new JobOptions( max_runtime: 21_601 );

		self::assertSame( 21_601, $options->max_runtime );
	}

	/**
	 * Zero cannot declare a positive execution ceiling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_max_runtime_rejects_zero(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Job maximum runtime must be positive; pass null for the engine default or a value of at least one second.' );

		new JobOptions( max_runtime: 0 );
	}

	/**
	 * Negative seconds cannot declare a positive execution ceiling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_max_runtime_rejects_negative_seconds(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Job maximum runtime must be positive; pass null for the engine default or a value of at least one second.' );

		new JobOptions( max_runtime: -1 );
	}

	/**
	 * Explicit policy values are retained unchanged for registration-time resolution.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_explicit_policy_values_are_retained_by_identity(): void {
		$retry       = new RetryPolicy( max_attempts: 1, base_delay: 5, multiplier: 1, max_delay: 5 );
		$overlap_key = static fn ( array $args ): ?string => \is_string( $args['tenant'] ?? null ) ? $args['tenant'] : null;
		$options     = new JobOptions( max_runtime: 42, retry: $retry, overlap: OverlapPolicy::Replace, overlap_key: $overlap_key );

		self::assertSame( 42, $options->max_runtime );
		self::assertSame( $retry, $options->retry );
		self::assertSame( OverlapPolicy::Replace, $options->overlap );
		self::assertSame( $overlap_key, $options->overlap_key );
	}

	// endregion.
}
