<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\Job;
use A8C\SpecialProjects\BackgroundJobsEngine\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\OverlapPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the defaults inherited by job implementations.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Job::class )]
#[UsesClass( RetryPolicy::class )]
#[UsesClass( OverlapPolicy::class )]
final class JobTest extends TestCase {
	/**
	 * A job inherits the shared execution and overlap invariants.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_default_execution_and_overlap_invariants_are_applied(): void {
		$job = new class() extends Job {

			/**
			 * {@inheritDoc}
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @return  string
			 */
			#[\Override]
			public function get_name(): string {
				return 'refresh-index';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $args    Invocation arguments.
			 * @param   RunContext              $context Controlled access to this run.
			 */
			#[\Override]
			public function handle( array $args, RunContext $context ): void {}
		};

		self::assertSame( 300, $job->max_callback_runtime() );
		self::assertSame( OverlapPolicy::Reject, $job->overlap_policy() );
		self::assertNull( $job->overlap_key( array( 'site_id' => 7 ) ) );
	}

	/**
	 * Loads WordPress constants before the default retry policy is first instantiated.
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

		require_once __DIR__ . '/wp-time-constant-stubs.php';
	}

	/**
	 * A job that supplies only its identity and handler receives a fresh default policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_default_policy_matches_a_new_retry_policy_field_for_field(): void {
		$job      = new class() extends Job {

			/**
			 * {@inheritDoc}
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @return  string
			 */
			#[\Override]
			public function get_name(): string {
				return 'refresh-index';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $args    Invocation arguments.
			 * @param   RunContext              $context Controlled access to this run.
			 */
			#[\Override]
			public function handle( array $args, RunContext $context ): void {}

		};
		$expected = new RetryPolicy();
		$actual   = $job->get_retry_policy();

		self::assertSame( $expected->max_attempts, $actual->max_attempts );
		self::assertSame( $expected->base_delay, $actual->base_delay );
		self::assertSame( $expected->multiplier, $actual->multiplier );
		self::assertSame( $expected->max_delay, $actual->max_delay );
	}
}
