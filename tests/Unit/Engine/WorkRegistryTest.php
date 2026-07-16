<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchContextInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\WorkRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins registration channels, typed lookup, and the shared work-identity namespace.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( WorkRegistry::class )]
final class WorkRegistryTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies production boot guards before work contracts are autoloaded.
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
	 * Typed lookups return exact registered instances and reject unknown or wrong-kind identities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_typed_lookups_return_registered_instances_and_null_for_unknown_or_wrong_kind(): void {
		$task  = new RecordingTask( 'refresh_index-2' );
		$batch = new RecordingBatch( 'rebuild-index' );
		$work  = new WorkRegistry();

		$work->register_task( 'consumer:refresh_index-2', $task );
		$work->register_batch( 'consumer:rebuild-index', $batch );

		self::assertSame( $task, $work->task( 'consumer:refresh_index-2' ) );
		self::assertNull( $work->batch( 'consumer:refresh_index-2' ) );
		self::assertSame( $batch, $work->batch( 'consumer:rebuild-index' ) );
		self::assertNull( $work->task( 'consumer:rebuild-index' ) );
		self::assertNull( $work->task( 'consumer:unknown' ) );
		self::assertNull( $work->batch( 'consumer:unknown' ) );
	}

	/**
	 * Kind lookup returns the registration-channel tag and null for an unknown identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_kind_returns_the_registration_channel_tag_and_null_for_unknown_identity(): void {
		$work = new WorkRegistry();

		$work->register_task( 'consumer:sync-task', new RecordingTask( 'sync-task' ) );
		$work->register_batch( 'consumer:sync-batch', new RecordingBatch( 'sync-batch' ) );

		self::assertSame( 'task', $work->kind( 'consumer:sync-task' ) );
		self::assertSame( 'batch', $work->kind( 'consumer:sync-batch' ) );
		self::assertNull( $work->kind( 'consumer:unknown' ) );
	}

	/**
	 * Task registration accepts the 64-byte local-name boundary and rejects 65 bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_task_accepts_64_name_bytes_and_rejects_65(): void {
		$name     = \str_repeat( 'a', 64 );
		$accepted = new RecordingTask( $name );
		$work     = new WorkRegistry();

		$work->register_task( 'consumer:' . $name, $accepted );
		self::assertSame( $accepted, $work->task( 'consumer:' . $name ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		$work->register_task( 'consumer:valid', new RecordingTask( \str_repeat( 'a', 65 ) ) );
	}

	/**
	 * Batch registration accepts the 64-byte local-name boundary and rejects 65 bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_batch_accepts_64_name_bytes_and_rejects_65(): void {
		$name     = \str_repeat( 'a', 64 );
		$accepted = new RecordingBatch( $name );
		$work     = new WorkRegistry();

		$work->register_batch( 'consumer:' . $name, $accepted );
		self::assertSame( $accepted, $work->batch( 'consumer:' . $name ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		$work->register_batch( 'consumer:valid', new RecordingBatch( \str_repeat( 'a', 65 ) ) );
	}

	/**
	 * Both registration channels reject declarations outside the local-name grammar.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'batch'|'task' $channel Registration channel under test.
	 * @param   string         $name    Invalid declared local name.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_names' )]
	public function test_registration_rejects_invalid_declared_names( string $channel, string $name ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		$work = new WorkRegistry();
		if ( 'task' === $channel ) {
			$work->register_task( 'consumer:valid', new RecordingTask( $name ) );
			return;
		}

		$work->register_batch( 'consumer:valid', new RecordingBatch( $name ) );
	}

	/**
	 * Both channels reject non-canonical or name-mismatched identities with their exact diagnostics.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'batch'|'task' $channel  Registration channel under test.
	 * @param   string         $identity Invalid or mismatched identity.
	 * @param   string         $message  Expected channel-specific diagnostic.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_identities' )]
	public function test_registration_rejects_invalid_or_mismatched_identities( string $channel, string $identity, string $message ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( $message );

		$work = new WorkRegistry();
		if ( 'task' === $channel ) {
			$work->register_task( $identity, new RecordingTask( 'valid' ) );
			return;
		}

		$work->register_batch( $identity, new RecordingBatch( 'valid' ) );
	}

	/**
	 * A task identity rejects a second task registration without replacing the first.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_task_rejects_a_same_kind_duplicate(): void {
		$work = new WorkRegistry();
		$work->register_task( 'consumer:sync', new RecordingTask( 'sync' ) );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs( 'Task name is already registered; register each task name exactly once.' );

		$work->register_task( 'consumer:sync', new RecordingTask( 'sync' ) );
	}

	/**
	 * A batch identity rejects a second batch registration without replacing the first.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_batch_rejects_a_same_kind_duplicate(): void {
		$work = new WorkRegistry();
		$work->register_batch( 'consumer:sync', new RecordingBatch( 'sync' ) );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs( 'Batch name is already registered; register each batch name exactly once.' );

		$work->register_batch( 'consumer:sync', new RecordingBatch( 'sync' ) );
	}

	/**
	 * A task-owned identity cannot also be registered through the batch channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_task_then_batch_cross_kind_collision_uses_the_existing_diagnostic(): void {
		$work = new WorkRegistry();
		$work->register_task( 'consumer:sync', new RecordingTask( 'sync' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work identity "consumer:sync" is already registered as a task; it cannot also be registered as a batch.' );

		$work->register_batch( 'consumer:sync', new RecordingBatch( 'sync' ) );
	}

	/**
	 * A batch-owned identity cannot also be registered through the task channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_batch_then_task_cross_kind_collision_uses_the_existing_diagnostic(): void {
		$work = new WorkRegistry();
		$work->register_batch( 'consumer:sync', new RecordingBatch( 'sync' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work identity "consumer:sync" is already registered as a batch; it cannot also be registered as a task.' );

		$work->register_task( 'consumer:sync', new RecordingTask( 'sync' ) );
	}

	/**
	 * Equal local names under different owners remain independent registrations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_different_owners_can_register_the_same_local_name(): void {
		$task  = new RecordingTask( 'sync' );
		$batch = new RecordingBatch( 'sync' );
		$work  = new WorkRegistry();

		$work->register_task( 'owner-a:sync', $task );
		$work->register_batch( 'owner-b:sync', $batch );

		self::assertSame( $task, $work->task( 'owner-a:sync' ) );
		self::assertSame( $batch, $work->batch( 'owner-b:sync' ) );
		self::assertSame( 'task', $work->kind( 'owner-a:sync' ) );
		self::assertSame( 'batch', $work->kind( 'owner-b:sync' ) );
	}

	/**
	 * A dual-interface contract registered as a task is visible only through the task channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dual_interface_contract_registered_as_task_uses_the_task_channel(): void {
		$dual = $this->dual_work( 'sync' );
		$work = new WorkRegistry();

		$work->register_task( 'consumer:sync', $dual );

		self::assertSame( $dual, $work->task( 'consumer:sync' ) );
		self::assertNull( $work->batch( 'consumer:sync' ) );
		self::assertSame( 'task', $work->kind( 'consumer:sync' ) );
	}

	/**
	 * A dual-interface contract registered as a batch is visible only through the batch channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dual_interface_contract_registered_as_batch_uses_the_batch_channel(): void {
		$dual = $this->dual_work( 'sync' );
		$work = new WorkRegistry();

		$work->register_batch( 'consumer:sync', $dual );

		self::assertSame( $dual, $work->batch( 'consumer:sync' ) );
		self::assertNull( $work->task( 'consumer:sync' ) );
		self::assertSame( 'batch', $work->kind( 'consumer:sync' ) );
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Supplies invalid names for both registration channels.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{channel: 'batch'|'task', name: string}>
	 */
	public static function invalid_names(): array {
		$cases = array();
		foreach ( array( '', 'RefreshIndex', 'refresh index', 'refresh.index', 'réindex' ) as $name ) {
			$key = '' === $name ? 'empty' : $name;

			$cases[ 'task-' . $key ]  = array(
				'channel' => 'task',
				'name'    => $name,
			);
			$cases[ 'batch-' . $key ] = array(
				'channel' => 'batch',
				'name'    => $name,
			);
		}

		return $cases;
	}

	/**
	 * Supplies non-canonical and declared-name-mismatched identities for both channels.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{channel: 'batch'|'task', identity: string, message: string}>
	 */
	public static function invalid_identities(): array {
		return array(
			'task-non-canonical'  => array(
				'channel'  => 'task',
				'identity' => 'not-canonical',
				'message'  => 'Task identity must be canonical and end with the task\'s declared local name.',
			),
			'task-name-mismatch'  => array(
				'channel'  => 'task',
				'identity' => 'consumer:other',
				'message'  => 'Task identity must be canonical and end with the task\'s declared local name.',
			),
			'batch-non-canonical' => array(
				'channel'  => 'batch',
				'identity' => 'not-canonical',
				'message'  => 'Batch identity must be canonical and end with the batch\'s declared local name.',
			),
			'batch-name-mismatch' => array(
				'channel'  => 'batch',
				'identity' => 'consumer:other',
				'message'  => 'Batch identity must be canonical and end with the batch\'s declared local name.',
			),
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Creates one contract implementing both work interfaces.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Declared local work name.
	 *
	 * @return  TaskInterface&BatchInterface
	 */
	private function dual_work( string $name ): TaskInterface&BatchInterface {
		return new class( $name ) implements TaskInterface, BatchInterface {
			/**
			 * Constructor.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string $name Declared local work name.
			 */
			public function __construct(
				private readonly string $name,
			) {}

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return $this->name;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function max_callback_runtime(): int {
				return self::DEFAULT_MAX_CALLBACK_RUNTIME;
			}

			/**
			 * Accepts an unused task invocation.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $args Unused task arguments.
			 *
			 * @return  void
			 */
			#[\Override]
			public function handle( array $args ): void {}

			/**
			 * Returns an empty queue for the registry-only contract.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $start_args Unused start arguments.
			 *
			 * @return  iterable<array<array-key, mixed>>
			 */
			#[\Override]
			public function generate_queue( array $start_args ): iterable {
				return array();
			}

			/**
			 * Accepts an unused batch chunk.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $chunk_args Unused chunk arguments.
			 * @param   BatchContextInterface   $context    Unused batch context.
			 *
			 * @return  void
			 */
			#[\Override]
			public function process_chunk( array $chunk_args, BatchContextInterface $context ): void {}

			/**
			 * Accepts an unused batch completion.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $run_id     Unused run identifier.
			 * @param   array<array-key, mixed> $start_args Unused start arguments.
			 *
			 * @return  void
			 */
			#[\Override]
			public function on_completed( string $run_id, array $start_args ): void {}

			/**
			 * Accepts an unused failed batch outcome.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $run_id     Unused run identifier.
			 * @param   array<array-key, mixed> $start_args Unused start arguments.
			 * @param   RunFailure              $failure    Unused terminal failure.
			 *
			 * @return  void
			 */
			#[\Override]
			public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void {}

			/** {@inheritDoc} */
			#[\Override]
			public function get_retry_policy(): RetryPolicy {
				return new RetryPolicy();
			}
		};
	}

	// endregion.
}
