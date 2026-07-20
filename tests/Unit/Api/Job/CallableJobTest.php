<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Api\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\AbstractJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\CallableJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\OverlapPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the public callable-backed job implementation.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( CallableJob::class )]
#[UsesClass( AbstractJob::class )]
#[UsesClass( RetryPolicy::class )]
#[UsesClass( OverlapPolicy::class )]
final class CallableJobTest extends TestCase {
	/**
	 * Loads WordPress constants before a default retry policy is constructed.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-time-constant-stubs.php';
	}

	/**
	 * The minimal constructor supplies the AbstractJob defaults and forwards handler arguments.
	 *
	 * @return  void
	 */
	public function test_minimal_callable_job_uses_inherited_defaults_and_invokes_the_handler(): void {
		/** @var list<array<array-key, mixed>> $calls */
		$calls = array();
		$job   = new CallableJob(
			'refresh-index',
			static function ( array $args ) use ( &$calls ): void {
				$calls[] = $args;
			}
		);
		$args  = array( 'site_id' => 7 );
		$retry = $job->get_retry_policy();

		$job->handle( $args );

		self::assertInstanceOf( AbstractJob::class, $job );
		self::assertSame( 'refresh-index', $job->get_name() );
		self::assertSame( array( $args ), $calls );
		self::assertSame( 300, $job->max_callback_runtime() );
		self::assertSame( OverlapPolicy::Reject, $job->overlap_policy() );
		self::assertNull( $job->overlap_key( $args ) );
		self::assertSame( 3, $retry->max_attempts );
		self::assertSame( \MINUTE_IN_SECONDS, $retry->base_delay );
		self::assertSame( 2, $retry->multiplier );
		self::assertSame( \HOUR_IN_SECONDS, $retry->max_delay );
	}

	/**
	 * Explicit invariant values override the inherited defaults unchanged.
	 *
	 * @return  void
	 */
	public function test_explicit_invariants_are_returned_unchanged(): void {
		$retry       = new RetryPolicy( max_attempts: 1, base_delay: 5, multiplier: 1, max_delay: 5 );
		$overlap_key = static fn ( array $args ): ?string => \is_string( $args['tenant'] ?? null ) ? $args['tenant'] : null;
		$job         = new CallableJob( 'refresh-index', static function ( array $args ): void {}, 42, $retry, OverlapPolicy::Allow, $overlap_key );

		self::assertSame( 42, $job->max_callback_runtime() );
		self::assertSame( $retry, $job->get_retry_policy() );
		self::assertSame( OverlapPolicy::Allow, $job->overlap_policy() );
		self::assertSame( 'tenant-7', $job->overlap_key( array( 'tenant' => 'tenant-7' ) ) );
	}
}
