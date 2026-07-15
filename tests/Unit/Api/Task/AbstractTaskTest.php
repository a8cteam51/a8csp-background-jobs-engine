<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Api\Task;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\AbstractTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the retry policy inherited by task implementations.
 *
 */
#[CoversClass( AbstractTask::class )]
#[UsesClass( RetryPolicy::class )]
final class AbstractTaskTest extends TestCase {
	/**
	 * A task inherits the shared five-minute ceiling for one handler invocation.
	 *
	 * @return  void
	 */
	public function test_default_max_runtime_is_five_minutes(): void {
		$task = new class() extends AbstractTask {

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return 'refresh-index';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param   array<array-key, mixed> $args Invocation arguments.
			 */
			#[\Override]
			public function handle( array $args ): void {}
		};

		self::assertSame( 300, $task->max_runtime() );
	}

	/**
	 * Loads WordPress constants before the default retry policy is first instantiated.
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
	 * A task that supplies only its identity and handler receives a fresh default policy.
	 *
	 * @return  void
	 */
	public function test_default_policy_matches_a_new_retry_policy_field_for_field(): void {
		$task     = new class() extends AbstractTask {

			/**
			 * {@inheritDoc}
			 *
			 */
			#[\Override]
			public function get_name(): string {
				return 'refresh-index';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param   array<array-key, mixed> $args Invocation arguments.
			 */
			#[\Override]
			public function handle( array $args ): void {}

		};
		$expected = new RetryPolicy();
		$actual   = $task->get_retry_policy();

		self::assertSame( $expected->max_attempts, $actual->max_attempts );
		self::assertSame( $expected->base_delay, $actual->base_delay );
		self::assertSame( $expected->multiplier, $actual->multiplier );
		self::assertSame( $expected->max_delay, $actual->max_delay );
	}
}
