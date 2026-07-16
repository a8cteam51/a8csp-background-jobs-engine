<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Registry;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\WorkRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the single task-and-batch identity namespace.
 *
 */
#[CoversClass( WorkRegistry::class )]
#[CoversClass( TaskRegistry::class )]
#[CoversClass( BatchRegistry::class )]
final class WorkRegistryTest extends TestCase {
	/**
	 * Satisfies production boot guards before registry contracts are autoloaded.
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
	 * A complete identity records its work kind through registration terminology.
	 *
	 * @return  void
	 */
	public function test_register_kind_records_the_identity_kind(): void {
		$work = new WorkRegistry();

		$work->register_kind( 'consumer:sync', 'task' );

		self::assertSame( 'task', $work->kind( 'consumer:sync' ) );
	}

	/**
	 * A task identity cannot be registered by a batch.
	 *
	 * @return  void
	 */
	public function test_task_then_batch_cross_kind_collision_fails_at_registration(): void {
		$work    = new WorkRegistry();
		$tasks   = new TaskRegistry( $work );
		$batches = new BatchRegistry( $work );
		$tasks->register( 'consumer:sync', new RecordingTask( 'sync' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work identity "consumer:sync" is already registered as a task; it cannot also be registered as a batch.' );

		$batches->register( 'consumer:sync', new RecordingBatch( 'sync' ) );
	}

	/**
	 * A batch identity cannot be registered by a task.
	 *
	 * @return  void
	 */
	public function test_batch_then_task_cross_kind_collision_fails_at_registration(): void {
		$work    = new WorkRegistry();
		$tasks   = new TaskRegistry( $work );
		$batches = new BatchRegistry( $work );
		$batches->register( 'consumer:sync', new RecordingBatch( 'sync' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work identity "consumer:sync" is already registered as a batch; it cannot also be registered as a task.' );

		$tasks->register( 'consumer:sync', new RecordingTask( 'sync' ) );
	}

	/**
	 * Equal local names under different owners remain independent registrations.
	 *
	 * @return  void
	 */
	public function test_different_owners_can_register_the_same_local_name(): void {
		$work  = new WorkRegistry();
		$tasks = new TaskRegistry( $work );
		$left  = new RecordingTask( 'sync' );
		$right = new RecordingTask( 'sync' );

		$tasks->register( 'owner-a:sync', $left );
		$tasks->register( 'owner-b:sync', $right );

		self::assertSame( $left, $tasks->get( 'owner-a:sync' ) );
		self::assertSame( $right, $tasks->get( 'owner-b:sync' ) );
		self::assertSame( 'task', $work->kind( 'owner-a:sync' ) );
		self::assertSame( 'task', $work->kind( 'owner-b:sync' ) );
	}
}
