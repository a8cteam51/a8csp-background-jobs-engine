<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
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
	 * Constructor parameter names and null defaults remain the public named-argument contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_constructor_names_and_defaults_are_exact(): void {
		$constructor = ( new \ReflectionClass( JobOptions::class ) )->getConstructor();
		self::assertNotNull( $constructor );
		$parameters = $constructor->getParameters();

		self::assertSame( array( 'max_runtime', 'retry', 'overlap', 'overlap_key' ), \array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->getName(), $parameters ) );
		foreach ( $parameters as $parameter ) {
			self::assertTrue( $parameter->isDefaultValueAvailable() );
			self::assertNull( $parameter->getDefaultValue() );
		}

		$options = new JobOptions();
		self::assertNull( $options->max_runtime );
		self::assertNull( $options->retry );
		self::assertNull( $options->overlap );
		self::assertNull( $options->overlap_key );
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
