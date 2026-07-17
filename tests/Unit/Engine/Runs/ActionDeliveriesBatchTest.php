<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchContextInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\NonRetryableExceptionInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\NonRetryableException;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\StoreFixtureBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Exercises batch deliveries through the registered production action graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ActionDeliveries::class )]
final class ActionDeliveriesBatchTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS      = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const string IDENTITY = self::OWNER . ':' . self::NAME;
	private const string NAME     = 'catalog-sync';
	private const int NOW         = 1_700_000_000;
	private const string OWNER    = 'runs-tests';
	private const string RUN_ID   = '00000000001700000000-0000000000000000042';

	private RecordingBatch $batch;
	private Consumer $consumer;
	private StoreFixtureBuilder $fixtures;
	private EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress seams before the production graph is built.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
	}

	/**
	 * Boots one registered batch against deterministic interface fakes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig      = EngineRig::set_up( self::NOW );
		$this->consumer = $this->rig->consumer( self::OWNER );
		$this->batch    = new RecordingBatch( self::NAME );
		$this->consumer->batches()->register( $this->batch );
		$this->fixtures              = StoreFixtureBuilder::for_identity( self::IDENTITY );
		$this->rig->backend()->calls = array();
	}

	/**
	 * Releases request-local engine state after each scenario.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function tearDown(): void {
		try {
			$this->rig->tear_down();
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * The registered start, continue, run, and cleanup actions complete one real batch.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_hooks_wires_all_internal_batch_actions(): void {
		$this->batch->queue = array( array( 'chunk' => 'only' ) );
		$this->start_batch();

		for ( $delivery = 0; $delivery < 5; ++$delivery ) {
			$this->rig->run_due();
		}

		self::assertSame( array( self::ARGS ), $this->batch->generate_calls );
		self::assertSame( array( 'chunk' => 'only' ), $this->batch->process_calls[0]['chunk_args'] ?? null );
		self::assertCount( 1, $this->batch->completed_calls );
		$this->rig->assert_completed();
	}

	/**
	 * Queue generation and the documented filter payload determine the retained real queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_persists_the_filtered_queue_and_schedules_continue(): void {
		$this->batch->queue = array(
			'first'  => array( 'chunk' => 'first' ),
			'second' => array( 'chunk' => 'second' ),
		);
		$filter_args        = null;
		$this->set_filter_value(
			'a8csp_background_tasks/queue/' . self::IDENTITY,
			static function ( array $queue, array $start_args, string $run_id ) use ( &$filter_args ): array {
				$filter_args = \func_get_args();

				return array( array( 'chunk' => 'filtered-first' ), ...$queue, array( 'chunk' => 'filtered-last' ) );
			}
		);
		$this->start_batch();

		$this->rig->run_due();

		self::assertSame( array( self::ARGS ), $this->batch->generate_calls );
		self::assertSame( array( array( 'chunk' => 'first' ), array( 'chunk' => 'second' ) ), $filter_args[0] ?? null );
		self::assertSame( self::ARGS, $filter_args[1] ?? null );
		self::assertSame( self::RUN_ID, $filter_args[2] ?? null );
		self::assertSame( array( array( 'chunk' => 'filtered-first' ), array( 'chunk' => 'first' ), array( 'chunk' => 'second' ), array( 'chunk' => 'filtered-last' ) ), $this->run_state()['queue'] ?? null );
		$this->rig->backend()->assert_scheduled( self::IDENTITY );
	}

	/**
	 * A continuation delivered before enqueue confirmation advances the first chunk once.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The registered continuation re-enters before its scheduling write resolves, preserving the at-least-once chunk contract at the admission race.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_admits_continue_before_enqueue_confirmation(): void {
		$first              = array( 'chunk' => 'first' );
		$this->batch->queue = array( $first, array( 'chunk' => 'second' ) );
		$this->start_batch();
		$this->rig->backend()->before_next(
			'enqueue_async',
			static function (): void {
				\do_action( 'a8csp_background_tasks/continue_batch', self::IDENTITY, self::RUN_ID, 2 );
			}
		);

		$this->rig->run_due();
		\do_action( 'a8csp_background_tasks/continue_batch', self::IDENTITY, self::RUN_ID, 2 );
		\do_action( 'a8csp_background_tasks/run_chunk', self::IDENTITY, self::RUN_ID, $first, 3 );
		\do_action( 'a8csp_background_tasks/run_chunk', self::IDENTITY, self::RUN_ID, $first, 3 );

		self::assertCount( 1, $this->batch->process_calls );
		self::assertSame( $first, $this->batch->process_calls[0]['chunk_args'] );
		self::assertSame( array( array( 'chunk' => 'second' ) ), $this->run_state()['queue'] ?? null );
	}

	/**
	 * A throwing started listener terminalizes before the first continuation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_fails_terminally_when_a_started_listener_throws(): void {
		$this->batch->queue = array( array( 'chunk' => 'first' ) );
		$this->set_action_throwable( 'a8csp_background_tasks/started/' . self::IDENTITY, new \RuntimeException( 'Started listener exploded.' ) );
		$this->start_batch();

		$this->rig->run_due();

		$this->assert_failure( ApiErrorCode::ExecutionFailed, RunFailureStage::Execution, null );
		self::assertCount( 1, $this->batch->failed_calls );
		$this->rig->assert_no_retry();
	}

	/**
	 * A queue generation throwable fails without exposing a started lifecycle.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_fails_terminally_when_queue_generation_throws(): void {
		$this->batch->generate_throwable = new \RuntimeException( 'Queue generation exploded.' );
		$this->start_batch();

		$this->rig->run_due();

		$this->assert_failure( ApiErrorCode::ExecutionFailed, RunFailureStage::QueueGeneration, null );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/started' ) );
	}

	/**
	 * A lazy queue throwable discards every yielded chunk before failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_fails_terminally_when_lazy_queue_iteration_throws(): void {
		$this->batch->generate_queue_factory = static function (): iterable {
			yield array( 'chunk' => 'must-not-persist' );

			throw new \RuntimeException( 'Lazy queue token secret.' );
		};
		$this->start_batch();

		$this->rig->run_due();

		$failure = $this->assert_failure( ApiErrorCode::ExecutionFailed, RunFailureStage::QueueGeneration, null );
		self::assertStringNotContainsString( 'token secret', $failure->summary );
	}

	/**
	 * Generated chunks reject every non-portable leaf without exposing it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $invalid_value Invalid chunk leaf.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_generated_chunk_values' )]
	public function test_handle_start_action_fails_terminally_for_an_invalid_generated_chunk( mixed $invalid_value ): void {
		$this->batch->queue = array( array( 'chunk' => 'valid' ), array( 'private-payload-must-not-leak' => $invalid_value ) );
		$this->start_batch();

		$this->rig->run_due();

		$failure = $this->assert_failure( ApiErrorCode::PayloadRejected, RunFailureStage::QueueGeneration, null );
		self::assertStringNotContainsString( 'private-payload-must-not-leak', $failure->summary );
	}

	/**
	 * Supplies both object-bearing generated chunk leaves.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{invalid_value: mixed}>
	 */
	public static function invalid_generated_chunk_values(): array {
		return array(
			'object'  => array( 'invalid_value' => new \stdClass() ),
			'closure' => array( 'invalid_value' => static fn (): null => null ),
		);
	}

	/**
	 * Valid portable chunks survive generation and filtering byte-for-byte.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_preserves_valid_portable_argument_chunks(): void {
		$queue              = array(
			array(
				'nested' => array(
					'integer' => 7,
					'string'  => 'value',
					'boolean' => true,
					'nothing' => null,
					'list'    => array( 1, 'two', false, null ),
				),
			),
		);
		$this->batch->queue = $queue;
		$this->start_batch();

		$this->rig->run_due();

		self::assertSame( $queue, $this->run_state()['queue'] ?? null );
		self::assertSame( array(), $this->batch->failed_calls );
	}

	/**
	 * Generated chunks accept the byte ceiling and reject its adjacent overflow through failure hooks.
	 *
	 * @param   int  $json_bytes Exact encoded chunk size.
	 * @param   bool $accepted   Whether queue generation succeeds.
	 *
	 * @return  void
	 */
	#[DataProvider( 'bounded_chunk_bytes' )]
	public function test_generated_chunks_observe_the_json_byte_ceiling( int $json_bytes, bool $accepted ): void {
		$chunk              = self::chunk_with_json_bytes( $json_bytes );
		$this->batch->queue = array( $chunk );
		$this->start_batch();

		$this->rig->run_due();

		if ( $accepted ) {
			self::assertSame( array( $chunk ), $this->run_state()['queue'] ?? null );
			self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/failed' ) );

			return;
		}

		$failure = $this->assert_failure( ApiErrorCode::PayloadRejected, RunFailureStage::QueueGeneration, null );
		self::assertSame( 'Batch queue chunk at index 0 contains 8193 JSON bytes; the limit is 8192 bytes.', $failure->summary );
	}

	/**
	 * Supplies both sides of the persisted chunk byte boundary.
	 *
	 * @return  array<string, array{json_bytes: int, accepted: bool}>
	 */
	public static function bounded_chunk_bytes(): array {
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
	 * Materialized queues accept the byte ceiling and reject its adjacent overflow through failure hooks.
	 *
	 * @param   int  $persisted_bytes Exact persisted queue size.
	 * @param   bool $accepted        Whether queue generation succeeds.
	 *
	 * @return  void
	 */
	#[DataProvider( 'bounded_queue_bytes' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_materialized_queues_observe_the_persisted_byte_ceiling( int $persisted_bytes, bool $accepted ): void {
		$this->batch->queue = self::queue_with_persisted_bytes( $persisted_bytes );
		$this->start_batch();

		$this->rig->run_due();
		$this->batch->queue                  = array();
		$this->rig->wpdb()->recorded_queries = array();

		if ( $accepted ) {
			$serialized_queue = \maybe_serialize( $this->run_state()['queue'] ?? null );
			self::assertIsString( $serialized_queue );
			self::assertSame( 1_048_576, \strlen( $serialized_queue ) );
			self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/failed' ) );

			return;
		}

		$failure = $this->assert_failure( ApiErrorCode::PayloadRejected, RunFailureStage::QueueGeneration, null );
		self::assertSame( 'Batch queue contains 1048577 persisted serialization bytes; the limit is 1048576 bytes.', $failure->summary );
	}

	/**
	 * Supplies both sides of the persisted aggregate-queue byte boundary.
	 *
	 * @return  array<string, array{persisted_bytes: int, accepted: bool}>
	 */
	public static function bounded_queue_bytes(): array {
		return array(
			'at limit'   => array(
				'persisted_bytes' => 1_048_576,
				'accepted'        => true,
			),
			'over limit' => array(
				'persisted_bytes' => 1_048_577,
				'accepted'        => false,
			),
		);
	}

	/**
	 * A non-array queue-filter result fails before scheduling continue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_fails_terminally_for_a_non_array_filtered_queue(): void {
		$this->batch->queue = array( array( 'chunk' => 'first' ) );
		$this->set_filter_value( 'a8csp_background_tasks/queue/' . self::IDENTITY, 'invalid queue' );
		$this->start_batch();

		$this->rig->run_due();

		$this->assert_failure( ApiErrorCode::PayloadRejected, RunFailureStage::QueueGeneration, null );
	}

	/**
	 * A throwing queue filter discards the generated queue before failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_fails_terminally_when_queue_filter_throws(): void {
		$this->batch->queue = array( array( 'chunk' => 'must-not-persist' ) );
		$this->set_filter_value(
			'a8csp_background_tasks/queue/' . self::IDENTITY,
			static function (): never {
				throw new \DomainException( 'Queue filter credential secret.' );
			}
		);
		$this->start_batch();

		$this->rig->run_due();

		$failure = $this->assert_failure( ApiErrorCode::ExecutionFailed, RunFailureStage::QueueGeneration, null );
		self::assertStringNotContainsString( 'credential secret', $failure->summary );
	}

	/**
	 * Filter-derived chunks cross the same portable-arguments boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_fails_terminally_for_an_invalid_filtered_chunk(): void {
		$this->batch->queue = array( array( 'chunk' => 'generated' ) );
		$this->set_filter_value( 'a8csp_background_tasks/queue/' . self::IDENTITY, array( array( 'chunk' => 'valid' ), array( 'filtered-private-payload' => new \stdClass() ) ) );
		$this->start_batch();

		$this->rig->run_due();

		$failure = $this->assert_failure( ApiErrorCode::PayloadRejected, RunFailureStage::QueueGeneration, null );
		self::assertStringNotContainsString( 'filtered-private-payload', $failure->summary );
	}

	/**
	 * Ownership loss during queue generation supersedes without touching the foreign owner.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built foreign lock and pointer generations replace authority inside the real queue callback before post-callback fencing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_supersedes_after_queue_generation_loses_ownership(): void {
		$this->batch->queue       = array( array( 'chunk' => 'generated' ) );
		$this->batch->on_generate = function (): void {
			$this->install_foreign_generation( self::NOW );
		};
		$this->start_batch();

		$this->rig->run_due();

		$this->assert_foreign_superseded();
	}

	/**
	 * A throwing generator that loses ownership supersedes instead of failing.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The foreign generation wins inside the throwing callback, so failure adjudication must fence the expired delivery before retention.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_supersedes_when_throwing_queue_generation_loses_ownership(): void {
		$this->batch->generate_throwable = new \RuntimeException( 'Queue generation exploded.' );
		$this->batch->on_generate        = function (): void {
			$this->install_foreign_generation( self::NOW );
		};
		$this->start_batch();

		$this->rig->run_due();

		$this->assert_foreign_superseded();
	}

	/**
	 * Ownership loss in the started listener fences the first continuation.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The legitimate started-hook boundary installs a fixture-built foreign generation before delivery schedules its successor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_supersedes_when_started_listener_loses_ownership(): void {
		$this->batch->queue = array( array( 'chunk' => 'first' ) );
		$this->observe_action(
			'a8csp_background_tasks/started/' . self::IDENTITY,
			function (): void {
				$this->install_foreign_generation( self::NOW );
			}
		);
		$this->start_batch();

		$this->rig->run_due();

		$this->assert_foreign_superseded();
	}

	/**
	 * A throwing started listener still respects the foreign generation fence.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Foreign ownership is installed before the listener throws, distinguishing supersession from terminal failure after the same hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_supersedes_when_throwing_started_listener_loses_ownership(): void {
		$this->batch->queue = array( array( 'chunk' => 'first' ) );
		$this->observe_action(
			'a8csp_background_tasks/started/' . self::IDENTITY,
			function (): void {
				$this->install_foreign_generation( self::NOW );
			}
		);
		$this->set_action_throwable( 'a8csp_background_tasks/started/' . self::IDENTITY, new \RuntimeException( 'Started listener exploded.' ) );
		$this->start_batch();

		$this->rig->run_due();

		$this->assert_foreign_superseded();
	}

	/**
	 * Continue retains the current head while scheduling its distinct run delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_retains_one_chunk_and_schedules_run(): void {
		$queue = array( array( 'chunk' => 'first' ), array( 'chunk' => 'second' ) );
		$this->prepare_started_batch( $queue );

		$this->rig->run_due();

		self::assertSame( $queue, $this->run_state()['queue'] ?? null );
		self::assertSame( array(), $this->batch->process_calls );
		self::assertCount( 1, $this->calls_for_hook( 'a8csp_background_tasks/run_chunk' ) );
	}

	/**
	 * Continue sends a drained queue to cleanup without invoking chunk work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_schedules_cleanup_for_an_empty_queue(): void {
		$this->prepare_started_batch( array() );

		$this->rig->run_due();

		self::assertSame( array(), $this->batch->process_calls );
		self::assertCount( 1, $this->calls_for_hook( 'a8csp_background_tasks/cleanup_batch' ) );
	}

	/**
	 * A normal chunk commits real context mutations and delays continue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_commits_context_mutations_and_schedules_delayed_continue(): void {
		$current = array( 'chunk' => 'current' );
		$this->prepare_scheduled_chunk( array( $current, array( 'chunk' => 'remaining' ) ) );
		$this->set_filter_value( 'a8csp_background_tasks/continue_delay', 75 );
		$this->batch->on_process       = static function ( array $chunk, BatchContextInterface $context ) use ( $current ): void {
			self::assertSame( $current, $chunk );
			self::assertSame( self::RUN_ID, $context->get_run_id() );
			self::assertSame( self::ARGS, $context->get_start_args() );
			$context->enqueue( array( 'chunk' => 'appended' ) );
			$context->prepend( array( 'chunk' => 'prepended-1' ) );
			$context->prepend( array( 'chunk' => 'prepended-2' ) );
		};
		$this->rig->clock()->timestamp = self::NOW + 120;

		$this->rig->run_due();

		self::assertInstanceOf( BatchContextInterface::class, $this->batch->process_calls[0]['context'] ?? null );
		self::assertSame( array( array( 'chunk' => 'prepended-2' ), array( 'chunk' => 'prepended-1' ), array( 'chunk' => 'remaining' ), array( 'chunk' => 'appended' ) ), $this->run_state()['queue'] ?? null );
		self::assertSame( self::NOW + 195, $this->single_call_for_hook( 'a8csp_background_tasks/continue_batch' )['args']['timestamp'] ?? null );
	}

	/**
	 * Invalid real-context mutations fail transactionally in both directions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $mutation      Context mutation method.
	 * @param   mixed  $invalid_value Invalid chunk leaf.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_context_mutations' )]
	public function test_handle_run_action_rejects_invalid_context_mutations_transactionally( string $mutation, mixed $invalid_value ): void {
		$current                   = array( 'chunk' => 'current' );
		$this->batch->retry_policy = new RetryPolicy( max_attempts: 1 );
		$this->prepare_scheduled_chunk( array( $current, array( 'chunk' => 'remaining' ) ) );
		$this->batch->on_process = static function ( array $chunk, BatchContextInterface $context ) use ( $invalid_value, $mutation ): void {
			if ( 'enqueue' === $mutation ) {
				$context->enqueue( array( 'callback-private-payload' => $invalid_value ) );

				return;
			}

			$context->prepend( array( 'callback-private-payload' => $invalid_value ) );
		};

		$this->rig->run_due();

		$failure = $this->assert_failure( ApiErrorCode::ExecutionFailed, RunFailureStage::Execution, $current );
		self::assertStringNotContainsString( 'callback-private-payload', $failure->summary );
		self::assertCount( 1, $this->batch->failed_calls );
	}

	/**
	 * Context mutations accept bounded chunks and reject adjacent overflow through failure hooks.
	 *
	 * @param   string $mutation  Context mutation method.
	 * @param   int    $json_bytes Exact encoded chunk size.
	 * @param   bool   $accepted  Whether the mutation persists.
	 *
	 * @return  void
	 */
	#[DataProvider( 'bounded_context_chunks' )]
	public function test_context_mutation_chunks_observe_the_json_byte_ceiling( string $mutation, int $json_bytes, bool $accepted ): void {
		$current                   = array( 'chunk' => 'current' );
		$chunk                     = self::chunk_with_json_bytes( $json_bytes );
		$this->batch->retry_policy = new RetryPolicy( max_attempts: 1 );
		$this->prepare_scheduled_chunk( array( $current ) );
		$this->batch->on_process = static function ( array $chunk_args, BatchContextInterface $context ) use ( $chunk, $mutation ): void {
			if ( 'enqueue' === $mutation ) {
				$context->enqueue( $chunk );

				return;
			}

			$context->prepend( $chunk );
		};

		$this->rig->run_due();

		if ( $accepted ) {
			self::assertSame( array( $chunk ), $this->run_state()['queue'] ?? null );
			self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/failed' ) );

			return;
		}

		$this->assert_failure( ApiErrorCode::ExecutionFailed, RunFailureStage::Execution, $current );
	}

	/**
	 * Supplies both context directions on both sides of the chunk byte boundary.
	 *
	 * @return  array<string, array{mutation: 'enqueue'|'prepend', json_bytes: int, accepted: bool}>
	 */
	public static function bounded_context_chunks(): array {
		return array(
			'enqueue at limit'   => array(
				'mutation'   => 'enqueue',
				'json_bytes' => 8_192,
				'accepted'   => true,
			),
			'enqueue over limit' => array(
				'mutation'   => 'enqueue',
				'json_bytes' => 8_193,
				'accepted'   => false,
			),
			'prepend at limit'   => array(
				'mutation'   => 'prepend',
				'json_bytes' => 8_192,
				'accepted'   => true,
			),
			'prepend over limit' => array(
				'mutation'   => 'prepend',
				'json_bytes' => 8_193,
				'accepted'   => false,
			),
		);
	}

	/**
	 * Context mutations accept the persisted queue ceiling and reject its adjacent overflow.
	 *
	 * @param   string $mutation        Context mutation method.
	 * @param   int    $persisted_bytes Exact persisted candidate-queue size.
	 * @param   bool   $accepted        Whether the mutation persists.
	 *
	 * @return  void
	 */
	#[DataProvider( 'bounded_context_queue_bytes' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_context_mutations_observe_the_persisted_queue_byte_ceiling( string $mutation, int $persisted_bytes, bool $accepted ): void {
		$current        = array( 'chunk' => 'current' );
		$candidate      = self::queue_with_persisted_bytes( $persisted_bytes );
		$mutation_chunk = 'prepend' === $mutation ? \array_shift( $candidate ) : \array_pop( $candidate );
		self::assertIsArray( $mutation_chunk );

		$this->batch->retry_policy = new RetryPolicy( max_attempts: 1 );
		$this->prepare_scheduled_chunk( array( $current, ...$candidate ) );
		$this->batch->queue                  = array();
		$this->rig->wpdb()->recorded_queries = array();
		$this->batch->on_process             = static function ( array $chunk_args, BatchContextInterface $context ) use ( $mutation, $mutation_chunk ): void {
			if ( 'enqueue' === $mutation ) {
				$context->enqueue( $mutation_chunk );

				return;
			}

			$context->prepend( $mutation_chunk );
		};

		$this->rig->run_due();

		if ( $accepted ) {
			$serialized_queue = \maybe_serialize( $this->run_state()['queue'] ?? null );
			self::assertIsString( $serialized_queue );
			self::assertSame( 1_048_576, \strlen( $serialized_queue ) );
			self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/failed' ) );

			return;
		}

		$this->assert_failure( ApiErrorCode::ExecutionFailed, RunFailureStage::Execution, $current );
	}

	/**
	 * Supplies both context directions on both sides of the persisted queue byte boundary.
	 *
	 * @return  array<string, array{mutation: 'enqueue'|'prepend', persisted_bytes: int, accepted: bool}>
	 */
	public static function bounded_context_queue_bytes(): array {
		return array(
			'enqueue at limit'   => array(
				'mutation'        => 'enqueue',
				'persisted_bytes' => 1_048_576,
				'accepted'        => true,
			),
			'enqueue over limit' => array(
				'mutation'        => 'enqueue',
				'persisted_bytes' => 1_048_577,
				'accepted'        => false,
			),
			'prepend at limit'   => array(
				'mutation'        => 'prepend',
				'persisted_bytes' => 1_048_576,
				'accepted'        => true,
			),
			'prepend over limit' => array(
				'mutation'        => 'prepend',
				'persisted_bytes' => 1_048_577,
				'accepted'        => false,
			),
		);
	}

	/**
	 * Supplies both context mutation directions with invalid leaves.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{mutation: 'enqueue'|'prepend', invalid_value: mixed}>
	 */
	public static function invalid_context_mutations(): array {
		return array(
			'enqueue closure' => array(
				'mutation'      => 'enqueue',
				'invalid_value' => static fn (): null => null,
			),
			'prepend object'  => array(
				'mutation'      => 'prepend',
				'invalid_value' => new \stdClass(),
			),
		);
	}

	/**
	 * A task-hook delivery for a batch clears its marker for the correctly routed redelivery.
	 *
	 * @load-bearing security
	 * @pin-rationale Direct registered-hook delivery injects the malformed scheduler payload that the public facade cannot express and proves it cannot strand execution.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_task_hook_misdelivery_clears_the_batch_marker(): void {
		$current = array( 'chunk' => 'current' );
		$this->prepare_scheduled_chunk( array( $current ) );
		self::assertNotNull( $this->rig->backend()->take_next_delivery() );

		\do_action( 'a8csp_background_tasks/run_task', self::IDENTITY, self::RUN_ID, 3 );
		$this->rig->assert_no_delivery( self::IDENTITY );
		self::assertSame( array(), $this->batch->process_calls );
		\do_action( 'a8csp_background_tasks/run_chunk', self::IDENTITY, self::RUN_ID, $current, 3 );

		self::assertCount( 1, $this->batch->process_calls );
		self::assertSame( $current, $this->batch->process_calls[0]['chunk_args'] );
	}

	/**
	 * A chunk-hook payload without its sequence is rejected before admission.
	 *
	 * @load-bearing security
	 * @pin-rationale Direct registered-hook delivery proves the typed chunk boundary rejects an incomplete payload without mutating authoritative run state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_chunk_handler_rejects_a_missing_sequence_before_admission(): void {
		$current = array( 'chunk' => 'current' );
		$this->prepare_scheduled_chunk( array( $current ) );
		self::assertNotNull( $this->rig->backend()->take_next_delivery() );
		$before = $this->run_state();
		$thrown = null;

		try {
			\do_action( 'a8csp_background_tasks/run_chunk', self::IDENTITY, self::RUN_ID, $current );
		} catch ( \ArgumentCountError $error ) {
			$thrown = $error;
		}

		self::assertInstanceOf( \ArgumentCountError::class, $thrown );
		self::assertSame( $before, $this->run_state() );
		self::assertSame( array(), $this->batch->process_calls );
	}

	/**
	 * Continue-delay filter rows retain their exact scheduling offsets.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $filtered_delay Filter result.
	 * @param   int   $expected_delay Expected scheduling offset.
	 *
	 * @return  void
	 */
	#[DataProvider( 'continue_delay_filter_values' )]
	public function test_handle_run_action_resolves_continue_delay_filter_values( mixed $filtered_delay, int $expected_delay ): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$this->set_filter_value( 'a8csp_background_tasks/continue_delay', $filtered_delay );
		$this->rig->clock()->timestamp = self::NOW + 120;

		$this->rig->run_due();

		self::assertSame( self::NOW + 120 + $expected_delay, $this->single_call_for_hook( 'a8csp_background_tasks/continue_batch' )['args']['timestamp'] ?? null );
	}

	/**
	 * Supplies accepted and invalid continue-delay filter values.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{filtered_delay: int|string, expected_delay: int}>
	 */
	public static function continue_delay_filter_values(): array {
		return array(
			'zero schedules at now'              => array(
				'filtered_delay' => 0,
				'expected_delay' => 0,
			),
			'negative falls back to the default' => array(
				'filtered_delay' => -1,
				'expected_delay' => 60,
			),
			'non-integer falls back to default'  => array(
				'filtered_delay' => '75',
				'expected_delay' => 60,
			),
		);
	}

	/**
	 * Ownership loss during chunk work supersedes without committing context mutations.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The real context buffers a mutation while fixture-built foreign ownership replaces the executing delivery before commit.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_after_chunk_work_loses_ownership(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ), array( 'chunk' => 'remaining' ) ) );
		$this->batch->on_process = function ( array $chunk, BatchContextInterface $context ): void {
			$context->enqueue( array( 'chunk' => 'discarded' ) );
			$this->install_foreign_generation( self::NOW );
		};

		$this->rig->run_due();

		$this->assert_foreign_superseded();
		self::assertCount( 1, $this->batch->process_calls );
	}

	/**
	 * A throwing chunk that loses ownership supersedes before retry adjudication.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The foreign generation is installed inside the throwing real callback, so no retry or failure may target the expired owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_when_throwing_chunk_loses_ownership(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$this->batch->process_throwable = new \RuntimeException( 'Chunk exploded.' );
		$this->batch->on_process        = function (): void {
			$this->install_foreign_generation( self::NOW );
		};

		$this->rig->run_due();

		$this->assert_foreign_superseded();
		$this->rig->assert_no_retry();
	}

	/**
	 * A failed delayed-continue schedule terminalizes the committed chunk.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The failed continuation write occurs after real chunk commit; an empty delivery boundary proves no successor can replay or stall the queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_fails_terminally_when_continue_scheduling_fails(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ), array( 'chunk' => 'remaining' ) ) );
		$this->batch->on_process                          = static function ( array $chunk, BatchContextInterface $context ): void {
			$context->prepend( array( 'chunk' => 'committed-front' ) );
			$context->enqueue( array( 'chunk' => 'committed-back' ) );
		};
		$this->rig->backend()->results['schedule_single'] = $this->scheduling_failure_result();

		$this->rig->run_due();

		$this->assert_failure( ApiErrorCode::BackendRejected, RunFailureStage::Scheduling, null );
		self::assertCount( 1, $this->batch->failed_calls );
		$this->rig->assert_no_delivery( self::IDENTITY );
	}

	/**
	 * A throwing continue-delay filter terminalizes the committed chunk.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_fails_terminally_when_continue_delay_filter_throws(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$this->batch->on_process = static function ( array $chunk, BatchContextInterface $context ): void {
			$context->enqueue( array( 'chunk' => 'committed' ) );
		};
		$this->set_filter_value(
			'a8csp_background_tasks/continue_delay',
			static function (): never {
				throw new \DomainException( 'Continue-delay filter exploded.' );
			}
		);

		$this->rig->run_due();

		$this->assert_failure( ApiErrorCode::ExecutionFailed, RunFailureStage::Execution, null );
		self::assertCount( 1, $this->batch->failed_calls );
	}

	/**
	 * Ownership loss in the continue-delay filter prevents successor scheduling.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The filter installs a fixture-built foreign generation after chunk commit but before the next delivery write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_when_continue_delay_filter_loses_ownership(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$this->set_filter_value(
			'a8csp_background_tasks/continue_delay',
			function (): int {
				$this->install_foreign_generation( self::NOW );

				return 30;
			}
		);

		$this->rig->run_due();

		$this->assert_foreign_superseded();
		self::assertSame( array(), $this->calls_for_hook( 'a8csp_background_tasks/continue_batch' ) );
	}

	/**
	 * A throwing continue-delay filter still respects the foreign generation.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The same filter boundary both transfers authority and throws, proving supersession wins over terminal error retention.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_when_throwing_continue_delay_filter_loses_ownership(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$this->set_filter_value(
			'a8csp_background_tasks/continue_delay',
			function (): never {
				$this->install_foreign_generation( self::NOW );

				throw new \DomainException( 'Continue-delay filter exploded.' );
			}
		);

		$this->rig->run_due();

		$this->assert_foreign_superseded();
	}

	/**
	 * A throwing chunk discards real-context mutations and retries the same chunk.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_discards_context_mutations_and_reschedules_the_chunk(): void {
		$current                   = array( 'chunk' => 'current' );
		$this->batch->retry_policy = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->prepare_scheduled_chunk( array( $current, array( 'chunk' => 'remaining' ) ) );
		$this->batch->on_process        = static function ( array $chunk, BatchContextInterface $context ): void {
			$context->prepend( array( 'chunk' => 'discarded-front' ) );
			$context->enqueue( array( 'chunk' => 'discarded-back' ) );
		};
		$this->batch->process_throwable = new \RuntimeException( 'Chunk processing exploded.' );
		$this->rig->randomizer()->value = 11;
		$this->rig->randomizer()->calls = array();

		$this->rig->run_due();

		self::assertSame( array( $current, array( 'chunk' => 'remaining' ) ), $this->run_state()['queue'] ?? null );
		self::assertSame(
			array(
				array(
					'min' => 0,
					'max' => 30,
				),
			),
			$this->rig->randomizer()->calls
		);
		$this->rig->assert_retry_scheduled();
		$retry_call = $this->single_call_for_hook( 'a8csp_background_tasks/run_chunk' );
		self::assertSame( 'schedule_single', $retry_call['verb'] );
		self::assertSame( array( self::IDENTITY, self::RUN_ID, $current, 4 ), $retry_call['args']['args'] ?? null );
		self::assertSame( array(), $this->batch->failed_calls );
	}

	/**
	 * A retry-advanced sequence drops an older continue delivery without a successor.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Exact production rows and an empty backend ledger prove the stale generation performs only its authoritative read and creates no successor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_stale_continue_after_retry_advances_sequence_only_reads_authoritative_state(): void {
		$current                        = array( 'chunk' => 'current' );
		$this->batch->retry_policy      = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->batch->process_throwable = new \RuntimeException( 'Chunk processing exploded.' );
		$this->prepare_scheduled_chunk( array( $current ) );
		$this->rig->randomizer()->value = 11;
		$this->rig->run_due();
		$before                              = $this->rig->wpdb()->rows;
		$this->rig->backend()->calls         = array();
		$this->rig->wpdb()->recorded_queries = array();

		\do_action( 'a8csp_background_tasks/continue_batch', self::IDENTITY, self::RUN_ID, 2 );

		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->rig->backend()->calls );
		self::assertCount( 1, $this->rig->wpdb()->recorded_queries );
	}

	/**
	 * A successful retry restores a fresh retry budget for the next chunk.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_resets_retries_before_the_next_chunk(): void {
		$chunk_a                        = array( 'chunk' => 'a' );
		$chunk_b                        = array( 'chunk' => 'b' );
		$this->batch->retry_policy      = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->batch->process_throwable = new \RuntimeException( 'Chunk A failed once.' );
		$this->prepare_scheduled_chunk( array( $chunk_a, $chunk_b ) );
		$this->rig->randomizer()->value = 5;

		$this->rig->run_due();
		$this->batch->process_throwable = null;
		$this->batch->on_process        = static function ( array $chunk ): void {
			if ( 'b' === ( $chunk['chunk'] ?? null ) ) {
				throw new \RuntimeException( 'Chunk B failed once.' );
			}
		};
		$this->rig->run_due();
		$this->rig->run_due();
		$this->rig->run_due();

		self::assertSame( array( $chunk_a, $chunk_a, $chunk_b ), \array_column( $this->batch->process_calls, 'chunk_args' ) );
		self::assertCount( 2, $this->rig->hooks()->fired( 'a8csp_background_tasks/retry_scheduled' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/failed' ) );
	}

	/**
	 * A failed retry schedule terminalizes at the consumed-attempt count.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The retry-scheduled hooks fire before the rejected write; an empty delivery boundary proves no delayed copy survives terminalization.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_chunk_retry_schedule_failure(): void {
		$current                        = array( 'chunk' => 'current' );
		$this->batch->retry_policy      = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->batch->process_throwable = new \RuntimeException( 'Chunk processing exploded.' );
		$this->prepare_scheduled_chunk( array( $current ) );
		$this->rig->randomizer()->value                   = 7;
		$this->rig->backend()->results['schedule_single'] = $this->scheduling_failure_result();

		$this->rig->run_due();

		$failure = $this->assert_failure( ApiErrorCode::BackendRejected, RunFailureStage::Scheduling, $current );
		self::assertSame( 1, $failure->attempts );
		self::assertCount( 1, $this->batch->failed_calls );
		$this->rig->assert_no_delivery( self::IDENTITY );
	}

	/**
	 * A non-retryable chunk bypasses policy on its first attempt.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_non_retryable_chunk_without_rescheduling(): void {
		$current                        = array( 'chunk' => 'current' );
		$this->batch->process_throwable = new NonRetryableException( 'Chunk input is permanently invalid.' );
		$this->prepare_scheduled_chunk( array( $current ) );

		$this->rig->run_due();

		$failure = $this->assert_failure( ApiErrorCode::ExecutionFailed, RunFailureStage::Execution, $current );
		self::assertSame( 1, $failure->attempts );
		$this->rig->assert_no_retry();
	}

	/**
	 * A throwing named failed listener still permits its generic companion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_named_listener_throw_still_fires_generic_hook_and_retains_terminal_row(): void {
		$this->batch->retry_policy      = new RetryPolicy( max_attempts: 1 );
		$this->batch->process_throwable = new \DomainException( 'Chunk failed.' );
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$listener = new \RuntimeException( 'Failed listener exploded.' );
		$this->set_action_throwable( 'a8csp_background_tasks/failed/' . self::IDENTITY, $listener );

		$caught = null;
		try {
			$this->rig->run_due();
		} catch ( \RuntimeException $throwable ) {
			$caught = $throwable;
		}

		self::assertSame( $listener, $caught );
		self::assertCount( 1, $this->batch->failed_calls );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_background_tasks/failed' ) );
	}

	/**
	 * Cleanup fences terminal completion before the callback and public hooks.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Callback-time production state is the only evidence that cleanup publishes its terminal generation before invoking consumer completion code.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_cleanup_action_fences_before_callback_and_preserves_hook_order(): void {
		$this->prepare_cleanup_delivery();
		$observed                  = null;
		$this->batch->on_completed = function () use ( &$observed ): void {
			$observed = $this->run_state();
		};

		$this->rig->run_due();

		self::assertSame( 'completed', $observed['status'] ?? null );
		self::assertTrue( $observed['executing'] ?? false );
		self::assertCount( 1, $this->batch->completed_calls );
		$this->rig->assert_completed();
	}

	/**
	 * An `on_completed()` throwable cannot change the completed outcome.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_cleanup_action_completes_and_logs_when_on_completed_throws(): void {
		$this->prepare_cleanup_delivery();
		$this->batch->completed_throwable = new class( 'on_completed callback exploded.' ) extends \Error implements NonRetryableExceptionInterface {};

		$this->rig->run_due();

		self::assertCount( 1, $this->batch->completed_calls );
		self::assertSame( array(), $this->batch->failed_calls );
		$this->rig->assert_completed();
	}

	/**
	 * A replacement started by `on_completed()` survives the finishing cleanup.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A real facade admission from the `on_completed()` callback creates the successor generation that incumbent cleanup must not delete.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_cleanup_action_completes_when_on_completed_starts_replacement(): void {
		$this->prepare_cleanup_delivery();
		$replacement               = null;
		$this->batch->on_completed = function () use ( &$replacement ): void {
			$replacement = $this->consumer->batches()->start( self::NAME, self::ARGS );
		};

		$this->rig->run_due();

		self::assertInstanceOf( Success::class, $replacement );
		self::assertNotSame( self::RUN_ID, $replacement->value );
		$this->rig->backend()->assert_scheduled( self::IDENTITY );
		$this->rig->assert_completed();
	}

	/**
	 * A sequential duplicate cleanup delivery cannot repeat `on_completed()`.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Redelivering the registered cleanup generation after state consumption proves the terminal callback is idempotent under at-least-once scheduling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_duplicate_cleanup_delivery_fires_on_completed_once(): void {
		$this->prepare_cleanup_delivery();
		$sequence = $this->run_state()['action_seq'] ?? null;
		self::assertIsInt( $sequence );

		$this->rig->run_due();
		\do_action( 'a8csp_background_tasks/cleanup_batch', self::IDENTITY, self::RUN_ID, $sequence );

		self::assertCount( 1, $this->batch->completed_calls );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_background_tasks/completed' ) );
	}

	/**
	 * A failed first-continuation schedule terminates generated work with no successor.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The failed scheduling write occurs after queue persistence; public failure hooks and an empty successor ledger prove the run cannot stall.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_fails_terminally_when_continue_scheduling_fails(): void {
		$this->batch->queue = array( array( 'chunk' => 'first' ) );
		$this->start_batch();
		$this->rig->backend()->results['enqueue_async'] = $this->scheduling_failure_result();

		$this->rig->run_due();

		$this->assert_failure( ApiErrorCode::BackendRejected, RunFailureStage::Scheduling, null );
		self::assertCount( 1, $this->batch->failed_calls );
		$this->rig->assert_no_delivery( self::IDENTITY );
	}

	/**
	 * A failed run schedule retains the current head in terminal diagnostics.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The rejected run write must leave no accepted delivery that could process the terminalized queue head later.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_fails_terminally_when_run_scheduling_fails(): void {
		$current = array( 'chunk' => 'first' );
		$this->prepare_started_batch( array( $current ) );
		$this->rig->backend()->results['enqueue_async'] = $this->scheduling_failure_result();

		$this->rig->run_due();

		$this->assert_failure( ApiErrorCode::BackendRejected, RunFailureStage::Scheduling, $current );
		$this->rig->assert_no_delivery( self::IDENTITY );
	}

	/**
	 * A failed cleanup schedule terminalizes a drained run.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The rejected cleanup write must leave no accepted delivery that could invoke success after terminal failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_fails_terminally_when_cleanup_scheduling_fails(): void {
		$this->prepare_started_batch( array() );
		$this->rig->backend()->results['enqueue_async'] = $this->scheduling_failure_result();

		$this->rig->run_due();

		$this->assert_failure( ApiErrorCode::BackendRejected, RunFailureStage::Scheduling, null );
		$this->rig->assert_no_delivery( self::IDENTITY );
	}

	/**
	 * Continue quietly supersedes after fixture-built lock ownership moves.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The foreign lock generation exists before the registered continuation claims execution, so it must create no successor or callback.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_quietly_supersedes_after_lock_ownership_moves(): void {
		$this->prepare_started_batch( array( array( 'chunk' => 'first' ) ) );
		$this->install_foreign_generation( self::NOW );

		$this->rig->run_due();

		$this->assert_foreign_superseded();
		self::assertSame( array(), $this->batch->process_calls );
	}

	/**
	 * Chunk execution quietly supersedes after fixture-built lock ownership moves.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The foreign lock generation exists before the registered run delivery claims execution, preventing chunk and terminal callbacks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_quietly_supersedes_after_lock_ownership_moves(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$this->install_foreign_generation( self::NOW );

		$this->rig->run_due();

		$this->assert_foreign_superseded();
		self::assertSame( array(), $this->batch->process_calls );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Starts the deterministic batch through its owner-bound facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function start_batch(): string {
		$result = $this->consumer->batches()->start( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		return $result->value;
	}

	/**
	 * Returns one portable chunk with the requested encoded JSON byte length.
	 *
	 * @param   int $json_bytes Exact encoded byte length, including object syntax.
	 *
	 * @return  array{payload: string}
	 */
	private static function chunk_with_json_bytes( int $json_bytes ): array {
		return array( 'payload' => \str_repeat( 'a', $json_bytes - 14 ) );
	}

	/**
	 * Returns a portable 128-chunk queue with the requested PHP serialization length.
	 *
	 * Each full chunk has an 8,178-byte payload and therefore an 8,192-byte JSON representation.
	 * The PHP array grammar contributes the queue header/trailer, integer keys, and each chunk's
	 * `a:1:{s:7:"payload";s:N:"...";}` framing. Subtracting that arithmetic overhead from the
	 * requested total derives the final payload length without probing serialized candidates.
	 *
	 * @param   int $persisted_bytes Exact persisted byte length.
	 *
	 * @return  list<array{payload: string}>
	 */
	private static function queue_with_persisted_bytes( int $persisted_bytes ): array {
		$payload_lengths = \array_fill( 0, 128, 8_178 );
		$overflow        = self::serialized_queue_bytes_for_payload_lengths( $payload_lengths ) - $persisted_bytes;
		$tail_index      = \array_key_last( $payload_lengths );
		$tail_bytes      = $payload_lengths[ $tail_index ] - $overflow;
		if ( 1_000 > $tail_bytes || 9_999 < $tail_bytes ) {
			throw new \LogicException( 'The boundary fixture requires a four-digit final payload length.' );
		}
		$payload_lengths[ $tail_index ] = $tail_bytes;

		return \array_map(
			static fn ( int $payload_bytes ): array => array( 'payload' => \str_repeat( 'a', $payload_bytes ) ),
			$payload_lengths
		);
	}

	/**
	 * Calculates PHP's serialized byte length for the fixture queue grammar.
	 *
	 * @phpstan-param list<int> $payload_lengths
	 *
	 * @param   array $payload_lengths Payload byte lengths in queue order.
	 *
	 * @return  int
	 */
	private static function serialized_queue_bytes_for_payload_lengths( array $payload_lengths ): int {
		$bytes = \strlen( 'a:' . \count( $payload_lengths ) . ':{' ) + 1;
		foreach ( $payload_lengths as $index => $payload_bytes ) {
			$bytes += \strlen( 'i:' . $index . ';' );
			$bytes += \strlen( 'a:1:{s:7:"payload";s:' . $payload_bytes . ':"";}' ) + $payload_bytes;
		}

		return $bytes;
	}

	/**
	 * Delivers start for one queue and leaves its continuation pending.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, array<array-key, mixed>> $queue Initial chunks.
	 *
	 * @return  void
	 */
	private function prepare_started_batch( array $queue ): void {
		$this->batch->queue = $queue;
		$this->start_batch();
		$this->rig->run_due();
		$this->rig->backend()->calls = array();
	}

	/**
	 * Delivers start and continue for one queue, leaving its run action pending.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, array<array-key, mixed>> $queue Initial chunks.
	 *
	 * @return  void
	 */
	private function prepare_scheduled_chunk( array $queue ): void {
		$this->prepare_started_batch( $queue );
		$this->rig->run_due();
		$this->rig->backend()->calls = array();
	}

	/**
	 * Leaves the cleanup action for one drained batch pending.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function prepare_cleanup_delivery(): void {
		$this->prepare_started_batch( array() );
		$this->rig->run_due();
		$this->rig->clock()->timestamp = self::NOW + 120;
	}

	/**
	 * Installs one production-built foreign lock and latest-pointer generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $heartbeat Foreign generation heartbeat.
	 *
	 * @return  void
	 */
	private function install_foreign_generation( int $heartbeat ): void {
		$this->put_fixture(
			$this->fixtures->latest(
				array(
					array(
						'run_id'    => 'run-newer',
						'args_hash' => $this->args_hash(),
					),
				)
			)
		);
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), 'run-newer', $heartbeat, $heartbeat ) );
	}

	/**
	 * Asserts the incumbent superseded while foreign ownership survived.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function assert_foreign_superseded(): void {
		$this->rig->assert_superseded();
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		self::assertSame( array(), $this->batch->failed_calls );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/failed' ) );
	}

	/**
	 * Asserts and returns the latest public failure payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ApiErrorCode                 $code         Expected failure code.
	 * @param   RunFailureStage              $stage        Expected failure stage.
	 * @param   array<array-key, mixed>|null $failed_chunk Expected failed chunk.
	 *
	 * @return  RunFailure
	 */
	private function assert_failure( ApiErrorCode $code, RunFailureStage $stage, ?array $failed_chunk ): RunFailure {
		$events = $this->rig->hooks()->fired( 'a8csp_background_tasks/failed' );
		self::assertNotEmpty( $events );
		$failure = $events[ \count( $events ) - 1 ][3] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( $code, $failure->code );
		self::assertSame( $stage, $failure->stage );
		self::assertSame( $failed_chunk, $failure->failed_chunk );

		return $failure;
	}

	/**
	 * Returns a deterministic scheduling failure for one lifecycle action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function scheduling_failure_result(): Failure {
		return new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore the scheduler before retrying this batch.' ) );
	}

	/**
	 * Stores one production-built raw fixture in the active database.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{string, string} $fixture Option name and raw value.
	 *
	 * @return  void
	 */
	private function put_fixture( array $fixture ): void {
		$this->rig->wpdb()->put( $fixture[0], $fixture[1] );
	}

	/**
	 * Returns the canonical argument identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function args_hash(): string {
		return $this->fixtures->args_hash( self::ARGS );
	}

	/**
	 * Returns the current decoded overlap lock.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>|null
	 */
	private function lock(): ?array {
		$value = $this->decoded_row( 'a8csp_bgte_overlap_lock_' . self::IDENTITY . '_' . $this->args_hash() );

		return \is_array( $value ) ? $value : null;
	}

	/**
	 * Returns the current decoded deterministic run state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>|null
	 */
	private function run_state(): ?array {
		$value = $this->decoded_row( 'a8csp_bgte_run_' . self::IDENTITY . '_' . self::RUN_ID );

		return \is_array( $value ) ? $value : null;
	}

	/**
	 * Decodes one authoritative raw database row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Option name.
	 *
	 * @return  mixed
	 */
	private function decoded_row( string $option_name ): mixed {
		$raw = $this->rig->wpdb()->rows[ $option_name ] ?? null;
		if ( null === $raw ) {
			$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
			self::assertIsArray( $options );

			return $options[ $option_name ] ?? null;
		}

		return \is_string( $raw ) ? \maybe_unserialize( $raw ) : null;
	}

	/**
	 * Returns backend calls that target one lifecycle hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook Lifecycle hook.
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function calls_for_hook( string $hook ): array {
		return \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => ( $call['args']['hook'] ?? null ) === $hook ) );
	}

	/**
	 * Returns the only backend call targeting one lifecycle hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook Lifecycle hook.
	 *
	 * @return  array{verb: string, args: array<string, mixed>}
	 */
	private function single_call_for_hook( string $hook ): array {
		$calls = $this->calls_for_hook( $hook );
		self::assertCount( 1, $calls );

		return $calls[0];
	}

	/**
	 * Scripts one legitimate WordPress filter seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook_name Filter hook name.
	 * @param   mixed  $value     Filter return or callback.
	 *
	 * @return  void
	 */
	private function set_filter_value( string $hook_name, mixed $value ): void {
		$filters = $GLOBALS['a8csp_bgte_test_filter_values'] ?? null;
		self::assertIsArray( $filters );
		$filters[ $hook_name ]                    = $value;
		$GLOBALS['a8csp_bgte_test_filter_values'] = $filters;
	}

	/**
	 * Scripts one legitimate WordPress action failure seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string     $hook_name Action hook name.
	 * @param   \Throwable $throwable Failure raised by the hook seam.
	 *
	 * @return  void
	 */
	private function set_action_throwable( string $hook_name, \Throwable $throwable ): void {
		$throwables = $GLOBALS['a8csp_bgte_test_action_throwables'] ?? null;
		self::assertIsArray( $throwables );
		$throwables[ $hook_name ]                     = $throwable;
		$GLOBALS['a8csp_bgte_test_action_throwables'] = $throwables;
	}

	/**
	 * Observes one legitimate WordPress action boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $hook_name Exact action hook.
	 * @param   \Closure $observe   Observation callback.
	 *
	 * @return  void
	 */
	private function observe_action( string $hook_name, \Closure $observe ): void {
		$observers = $GLOBALS['a8csp_bgte_test_action_observers'] ?? null;
		self::assertIsArray( $observers );
		$observers[]                                 = static function ( string $hook ) use ( $hook_name, $observe ): void {
			if ( $hook_name === $hook ) {
				$observe();
			}
		};
		$GLOBALS['a8csp_bgte_test_action_observers'] = $observers;
	}

	// endregion.
}
