<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Api;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\Batches;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\ExistingRunPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\Runs;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\Tasks;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\AdmissionValidator;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FakeBatchesEngine;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FakeRunsEngine;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FakeSchedulesEngine;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FakeTasksEngine;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the supported owner-bound consumer facade contract.
 *
 */
#[CoversClass( Consumer::class )]
#[CoversClass( Tasks::class )]
#[CoversClass( Batches::class )]
#[CoversClass( Schedules::class )]
#[CoversClass( Runs::class )]
#[UsesClass( AdmissionValidator::class )]
#[UsesClass( WorkIdentity::class )]
final class ConsumerTest extends TestCase {
	/**
	 * Loads the WordPress seams required by API value objects.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/wp-time-constant-stubs.php';
		require_once \dirname( __DIR__ ) . '/Engine/Backends/wp-json-encode-stub.php';
	}

	/**
	 * Consumer accessors retain the owner-bound facades supplied at resolution.
	 *
	 * @return  void
	 */
	public function test_accessors_return_the_bound_facades(): void {
		$tasks     = new Tasks( 'consumer-plugin', new FakeTasksEngine( new Success( 'task-run' ) ) );
		$batches   = new Batches( 'consumer-plugin', new FakeBatchesEngine( new Success( 'batch-run' ) ) );
		$schedules = new Schedules( 'consumer-plugin', new FakeSchedulesEngine( new Success( true ), new Success( 'schedule-run' ) ) );
		$runs      = new Runs( 'consumer-plugin', new FakeRunsEngine( new Success( null ), new Success( 'retry-run' ), new Success( 'cancelled-run' ) ) );
		$consumer  = new Consumer( 'consumer-plugin', $tasks, $batches, $schedules, $runs );

		self::assertSame( $tasks, $consumer->tasks() );
		self::assertSame( $batches, $consumer->batches() );
		self::assertSame( $schedules, $consumer->schedules() );
		self::assertSame( $runs, $consumer->runs() );
	}

	/**
	 * Task operations compose once and preserve the delegated result object and arguments.
	 *
	 * @return  void
	 */
	public function test_tasks_register_and_enqueue_owner_qualified_work(): void {
		$failure = new Failure( new ApiError( ApiErrorCode::BackendRejected, 'Scripted failure.' ) );
		$task    = new RecordingTask( 'sync' );
		$engine  = new FakeTasksEngine( $failure );
		$tasks   = new Tasks( 'consumer-plugin', $engine );

		$tasks->register( $task );
		$result = $tasks->enqueue( 'sync', array( 'site_id' => 7 ), delay: 30, dedup_key: 'site-7-sync', priority: 5 );

		self::assertSame( $failure, $result );
		self::assertSame(
			array(
				array( 'register_task', 'consumer-plugin:sync', $task ),
				array( 'enqueue', 'consumer-plugin:sync', array( 'site_id' => 7 ), 30, 'site-7-sync', 5 ),
			),
			$engine->calls
		);
	}

	/**
	 * Batch operations compose once and preserve the delegated result object and arguments.
	 *
	 * @return  void
	 */
	public function test_batches_register_and_start_owner_qualified_work(): void {
		$success = new Success( 'batch-run' );
		$batch   = new RecordingBatch( 'sync' );
		$engine  = new FakeBatchesEngine( $success );
		$batches = new Batches( 'consumer-plugin', $engine );

		$batches->register( $batch );
		$result = $batches->start( 'sync', array( 'site_id' => 7 ), existing: ExistingRunPolicy::Reject, priority: 5 );

		self::assertSame( $success, $result );
		self::assertSame(
			array(
				array( 'register_batch', 'consumer-plugin:sync', $batch ),
				array( 'start', 'consumer-plugin:sync', array( 'site_id' => 7 ), ExistingRunPolicy::Reject, 5 ),
			),
			$engine->calls
		);
	}

	/**
	 * Task and Batch start arguments accept the byte ceiling and reject its adjacent overflow.
	 *
	 * @param   int  $json_bytes Exact encoded argument size.
	 * @param   bool $accepted   Whether the arguments reach the engine delegate.
	 *
	 * @return  void
	 */
	#[DataProvider( 'bounded_start_arguments' )]
	public function test_task_and_batch_start_arguments_observe_the_json_byte_ceiling( int $json_bytes, bool $accepted ): void {
		$args         = array( 'payload' => \str_repeat( 'a', $json_bytes - 14 ) );
		$task_engine  = new FakeTasksEngine( new Success( 'task-run' ) );
		$batch_engine = new FakeBatchesEngine( new Success( 'batch-run' ) );
		$task_result  = ( new Tasks( 'consumer-plugin', $task_engine ) )->enqueue( 'sync', $args );
		$batch_result = ( new Batches( 'consumer-plugin', $batch_engine ) )->start( 'sync', $args );

		if ( $accepted ) {
			self::assertInstanceOf( Success::class, $task_result );
			self::assertInstanceOf( Success::class, $batch_result );
			self::assertCount( 1, $task_engine->calls );
			self::assertCount( 1, $batch_engine->calls );

			return;
		}

		self::assertInstanceOf( Failure::class, $task_result );
		self::assertInstanceOf( ApiError::class, $task_result->error );
		self::assertSame( ApiErrorCode::PayloadRejected, $task_result->error->code );
		self::assertSame( 'Task "sync" arguments contain 8193 JSON bytes; the limit is 8192 bytes.', $task_result->error->message );
		self::assertInstanceOf( Failure::class, $batch_result );
		self::assertInstanceOf( ApiError::class, $batch_result->error );
		self::assertSame( ApiErrorCode::PayloadRejected, $batch_result->error->code );
		self::assertSame( 'Batch "sync" arguments contain 8193 JSON bytes; the limit is 8192 bytes.', $batch_result->error->message );
		self::assertSame( array(), $task_engine->calls );
		self::assertSame( array(), $batch_engine->calls );
	}

	/**
	 * Supplies both sides of the persisted start-argument byte boundary.
	 *
	 * @return  array<string, array{json_bytes: int, accepted: bool}>
	 */
	public static function bounded_start_arguments(): array {
		return array(
			'at limit'   => array(
				'json_bytes' => 8_192,
				'accepted'   => true,
			),
			'over limit' => array(
				'json_bytes' => 8_193,
				'accepted'   => false,
			),
		);
	}

	/**
	 * Schedule operations qualify both schedule and target identities without accepting an owner.
	 *
	 * @return  void
	 */
	public function test_schedules_sync_and_dispatch_now_with_the_bound_owner_only(): void {
		$schedule  = new Schedule( 'nightly', Recurrence::every( 300 ), 'sync' );
		$engine    = new FakeSchedulesEngine( new Success( true ), new Success( 'schedule-run' ) );
		$schedules = new Schedules( 'consumer-plugin', $engine );

		$sync_result = $schedules->sync( array( $schedule ) );
		$run_result  = $schedules->dispatch_now( 'nightly' );

		self::assertInstanceOf( Success::class, $sync_result );
		self::assertInstanceOf( Success::class, $run_result );
		self::assertSame(
			array(
				array(
					'sync',
					array(
						'consumer-plugin:nightly' => array(
							'schedule' => $schedule,
							'task'     => 'consumer-plugin:sync',
						),
					),
				),
				array( 'dispatch_now', 'consumer-plugin:nightly' ),
			),
			$engine->calls
		);

		foreach ( ( new \ReflectionClass( Schedules::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( '__construct' === $method->getName() ) {
				continue;
			}
			self::assertNotContains( 'owner', \array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->getName(), $method->getParameters() ) );
		}
		self::assertSame( array( 'schedules' ), self::parameter_names( Schedules::class, 'sync' ) );
		self::assertSame( array( 'name' ), self::parameter_names( Schedules::class, 'dispatch_now' ) );
	}

	/**
	 * Run inspection and mutations can address only identities under the bound owner.
	 *
	 * @return  void
	 */
	public function test_runs_inspect_retry_and_cancel_owner_qualified_work(): void {
		$engine = new FakeRunsEngine( new Success( 'completed-run' ), new Success( 'replacement-run' ), new Success( 'live-run' ) );
		$runs   = new Runs( 'consumer-plugin', $engine );

		$completed = $runs->last_completed_run_id( 'sync' );
		$retry     = $runs->retry_failed( 'sync', 'failed-run' );
		$cancel    = $runs->cancel( 'sync', 'live-run' );
		if ( $completed->is_failure() ) {
			self::fail( 'The completed-run facade returned an unexpected failure.' );
		}
		if ( $retry->is_failure() ) {
			self::fail( 'The retry facade returned an unexpected failure.' );
		}
		if ( $cancel->is_failure() ) {
			self::fail( 'The cancel facade returned an unexpected failure.' );
		}

		self::assertSame( 'completed-run', $completed->value );
		self::assertSame( 'replacement-run', $retry->value );
		self::assertSame( 'live-run', $cancel->value );
		self::assertSame(
			array(
				array( 'last_completed_run_id', 'consumer-plugin:sync' ),
				array( 'retry_failed', 'consumer-plugin:sync', 'failed-run' ),
				array( 'cancel', 'consumer-plugin:sync', 'live-run' ),
			),
			$engine->calls
		);

		foreach ( ( new \ReflectionClass( Runs::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( '__construct' === $method->getName() ) {
				continue;
			}
			self::assertNotContains( 'owner', \array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->getName(), $method->getParameters() ) );
		}
		self::assertSame( array( 'name' ), self::parameter_names( Runs::class, 'last_completed_run_id' ) );
	}

	/**
	 * Every operation preserves the exact delegated public failure object.
	 *
	 * @return  void
	 */
	public function test_result_methods_preserve_the_delegated_failure_instance(): void {
		$failure   = new Failure( new ApiError( ApiErrorCode::BackendRejected, 'Scripted failure.' ) );
		$tasks     = new Tasks( 'consumer-plugin', new FakeTasksEngine( $failure ) );
		$batches   = new Batches( 'consumer-plugin', new FakeBatchesEngine( $failure ) );
		$schedules = new Schedules( 'consumer-plugin', new FakeSchedulesEngine( $failure, $failure ) );
		$runs      = new Runs( 'consumer-plugin', new FakeRunsEngine( $failure, $failure, $failure ) );

		self::assertSame( $failure, $tasks->enqueue( 'sync' ) );
		self::assertSame( $failure, $batches->start( 'sync' ) );
		self::assertSame( $failure, $schedules->sync( array() ) );
		self::assertSame( $failure, $schedules->dispatch_now( 'nightly' ) );
		self::assertSame( $failure, $runs->last_completed_run_id( 'sync' ) );
		self::assertSame( $failure, $runs->retry_failed( 'sync', 'failed-run' ) );
		self::assertSame( $failure, $runs->cancel( 'sync', 'live-run' ) );
	}

	/**
	 * Task commands reject deterministic violations before invoking the admission delegate.
	 *
	 * @param   array<array-key, mixed> $args     Task arguments.
	 * @param   int                     $delay    Scheduling delay.
	 * @param   int                     $priority Advisory priority.
	 * @param   string                  $message  Exact corrective exception message.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_task_commands' )]
	public function test_tasks_throw_for_deterministic_contract_violations( array $args, int $delay, int $priority, string $message ): void {
		$engine = new FakeTasksEngine( new Success( 'unexpected-run' ) );
		$tasks  = new Tasks( 'consumer-plugin', $engine );

		self::assert_invalid_argument( static fn () => $tasks->enqueue( 'sync', $args, $delay, priority: $priority ), $message );
		self::assertSame( array(), $engine->calls );
	}

	/**
	 * Supplies every task-command violation reclassified at the public facade.
	 *
	 * @return  array<string, array{args: array<array-key, mixed>, delay: int, priority: int, message: string}>
	 */
	public static function invalid_task_commands(): array {
		$argument_message = 'Task "sync" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.';

		return array(
			'negative priority'      => array(
				'args'     => array(),
				'delay'    => 0,
				'priority' => -1,
				'message'  => 'Task "sync" priority -1 is invalid; pass a value from 0 through 255.',
			),
			'priority above maximum' => array(
				'args'     => array(),
				'delay'    => 0,
				'priority' => 256,
				'message'  => 'Task "sync" priority 256 is invalid; pass a value from 0 through 255.',
			),
			'negative delay'         => array(
				'args'     => array(),
				'delay'    => -1,
				'priority' => 10,
				'message'  => 'Task "sync" delay -1 is invalid; pass a non-negative number of seconds.',
			),
			'non-portable arguments' => array(
				'args'     => array( new \stdClass() ),
				'delay'    => 0,
				'priority' => 10,
				'message'  => $argument_message,
			),
			'non-finite arguments'   => array(
				'args'     => array( \INF ),
				'delay'    => 0,
				'priority' => 10,
				'message'  => $argument_message,
			),
		);
	}

	/**
	 * Task deduplication keys accept opaque bounded bytes and reject invalid lengths.
	 *
	 * @param   string $dedup_key Consumer deduplication key.
	 * @param   bool   $accepted  Whether the key reaches the admission delegate.
	 *
	 * @return  void
	 */
	#[DataProvider( 'task_deduplication_keys' )]
	public function test_tasks_validate_deduplication_keys( string $dedup_key, bool $accepted ): void {
		$engine = new FakeTasksEngine( new Success( 'task-run' ) );
		$tasks  = new Tasks( 'consumer-plugin', $engine );

		if ( ! $accepted ) {
			self::assert_invalid_argument( static fn () => $tasks->enqueue( 'sync', dedup_key: $dedup_key ), 'Task "sync" deduplication key must contain 1 to 64 bytes when provided.' );
			self::assertSame( array(), $engine->calls );

			return;
		}

		$result = $tasks->enqueue( 'sync', dedup_key: $dedup_key );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array( array( 'enqueue', 'consumer-plugin:sync', array(), 0, $dedup_key, 10 ) ), $engine->calls );
	}

	/**
	 * Supplies both accepted boundaries and the adjacent rejected lengths with opaque binary keys.
	 *
	 * @return  array<string, array{dedup_key: string, accepted: bool}>
	 */
	public static function task_deduplication_keys(): array {
		return array(
			'one byte'                   => array(
				'dedup_key' => "\x00",
				'accepted'  => true,
			),
			'empty'                      => array(
				'dedup_key' => '',
				'accepted'  => false,
			),
			'sixty-five bytes'           => array(
				'dedup_key' => \str_repeat( 'a', 65 ),
				'accepted'  => false,
			),
			'sixty-four arbitrary bytes' => array(
				'dedup_key' => \str_repeat( "\x00\xFF", 32 ),
				'accepted'  => true,
			),
		);
	}

	/**
	 * Batch commands reject deterministic violations before invoking the admission delegate.
	 *
	 * @param   array<array-key, mixed> $args     Batch start arguments.
	 * @param   int                     $priority Advisory priority.
	 * @param   string                  $message  Exact corrective exception message.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_batch_commands' )]
	public function test_batches_throw_for_deterministic_contract_violations( array $args, int $priority, string $message ): void {
		$engine  = new FakeBatchesEngine( new Success( 'unexpected-run' ) );
		$batches = new Batches( 'consumer-plugin', $engine );

		self::assert_invalid_argument( static fn () => $batches->start( 'sync', $args, priority: $priority ), $message );
		self::assertSame( array(), $engine->calls );
	}

	/**
	 * Supplies every batch-command violation reclassified at the public facade.
	 *
	 * @return  array<string, array{args: array<array-key, mixed>, priority: int, message: string}>
	 */
	public static function invalid_batch_commands(): array {
		$argument_message = 'Batch "sync" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.';

		return array(
			'negative priority'      => array(
				'args'     => array(),
				'priority' => -1,
				'message'  => 'Batch "sync" priority -1 is invalid; pass a value from 0 through 255.',
			),
			'priority above maximum' => array(
				'args'     => array(),
				'priority' => 256,
				'message'  => 'Batch "sync" priority 256 is invalid; pass a value from 0 through 255.',
			),
			'non-portable arguments' => array(
				'args'     => array( new \stdClass() ),
				'priority' => 10,
				'message'  => $argument_message,
			),
			'non-finite arguments'   => array(
				'args'     => array( \INF ),
				'priority' => 10,
				'message'  => $argument_message,
			),
		);
	}

	/**
	 * Task and batch facade defaults match the supported operation contracts.
	 *
	 * @return  void
	 */
	public function test_dispatch_defaults_match_the_deleted_wrapper_contracts(): void {
		$tasks_engine   = new FakeTasksEngine( new Success( 'task-run' ) );
		$batches_engine = new FakeBatchesEngine( new Success( 'batch-run' ) );
		$tasks          = new Tasks( 'consumer-plugin', $tasks_engine );
		$batches        = new Batches( 'consumer-plugin', $batches_engine );

		self::assertInstanceOf( Success::class, $tasks->enqueue( 'sync' ) );
		self::assertInstanceOf( Success::class, $batches->start( 'sync' ) );

		self::assertSame(
			array(
				array( 'enqueue', 'consumer-plugin:sync', array(), 0, null, 10 ),
				array( 'start', 'consumer-plugin:sync', array(), ExistingRunPolicy::Replace, 10 ),
			),
			\array_merge( $tasks_engine->calls, $batches_engine->calls )
		);
	}

	/**
	 * Every result-returning facade method carries a direct no-discard contract.
	 *
	 * @return  void
	 */
	public function test_result_methods_declare_no_discard_directly(): void {
		$methods = array(
			array( Tasks::class, 'enqueue' ),
			array( Batches::class, 'start' ),
			array( Schedules::class, 'sync' ),
			array( Schedules::class, 'dispatch_now' ),
			array( Runs::class, 'last_completed_run_id' ),
			array( Runs::class, 'retry_failed' ),
			array( Runs::class, 'cancel' ),
		);

		foreach ( $methods as [ $class, $method ] ) {
			self::assertCount( 1, ( new \ReflectionMethod( $class, $method ) )->getAttributes( \NoDiscard::class ) );
		}
	}

	/**
	 * Returns one public method's parameter names in declaration order.
	 *
	 * @param   class-string $class_name Declaring class.
	 * @param   string       $method     Public method name.
	 *
	 * @return  list<string>
	 */
	private static function parameter_names( string $class_name, string $method ): array {
		return \array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->getName(), ( new \ReflectionMethod( $class_name, $method ) )->getParameters() );
	}

	/**
	 * Asserts one deterministic public contract violation.
	 *
	 * @phpstan-param \Closure(): mixed $operation
	 *
	 * @param   \Closure $operation Invalid operation.
	 * @param   string   $message   Exact corrective exception message.
	 *
	 * @return  void
	 */
	private static function assert_invalid_argument( \Closure $operation, string $message ): void {
		try {
			$operation();
			self::fail( 'The deterministic contract violation must throw InvalidArgumentException.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( $message, $exception->getMessage() );
		}
	}
}
