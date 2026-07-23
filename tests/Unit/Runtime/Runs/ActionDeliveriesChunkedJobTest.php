<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\OwnerOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Exercises chunked job deliveries through the registered production action graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ActionDeliveries::class )]
final class ActionDeliveriesChunkedJobTest extends TestCase {
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

	private RecordingChunkedJob $chunked_job;
	private OwnerOperations $client;
	private StoreFixtureBuilder $fixtures;
	private JobOptions $options;
	private bool $registered;
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
	 * Boots one chunked job execution against deterministic interface fakes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig         = EngineRig::set_up( self::NOW );
		$this->client      = $this->rig->operations( self::OWNER );
		$this->chunked_job = new RecordingChunkedJob( self::NAME );
		$this->fixtures    = StoreFixtureBuilder::for_identity( self::IDENTITY );
		$this->options     = new JobOptions();
		$this->registered  = false;

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
	 * The registered start, continue, and cleanup actions complete one real chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_hooks_wires_all_internal_chunked_job_actions(): void {
		$this->chunked_job->queue = array( array( 'chunk' => 'only' ) );
		$this->start();

		for ( $delivery = 0; $delivery < 4; ++$delivery ) {
			$this->rig->run_due();
		}

		self::assertSame( array( self::ARGS ), $this->chunked_job->generate_calls );
		self::assertSame( array( 'chunk' => 'only' ), $this->chunked_job->process_calls[0]['chunk_args'] ?? null );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/completed' ) );
		$this->rig->assert_completed();
	}

	/**
	 * Consecutive completions deliver the identity-global predecessor to lifecycle hooks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_hooks_receive_the_previous_chunked_job_completion(): void {
		$this->chunked_job->queue = array();
		$this->register_chunked_job();
		$first_args = array( 'sequence' => 'first' );
		$first      = $this->client->dispatch( self::NAME, $first_args );
		self::assertInstanceOf( Success::class, $first );
		self::assertIsString( $first->value );

		for ( $delivery = 0; $delivery < 3; ++$delivery ) {
			$this->rig->run_due();
		}

		++$this->rig->clock()->timestamp;
		$second_args = array( 'sequence' => 'second' );
		$second      = $this->client->dispatch( self::NAME, $second_args );
		self::assertInstanceOf( Success::class, $second );
		self::assertIsString( $second->value );

		for ( $delivery = 0; $delivery < 3; ++$delivery ) {
			$this->rig->run_due();
		}

		$completed              = $this->rig->hooks()->fired( 'a8csp_jobs_engine/completed/' . self::IDENTITY );
		$first_public_run_id    = $completed[0][0] ?? null;
		$second_public_run_id   = $completed[1][0] ?? null;
		$previous_public_run_id = $completed[1][2] ?? null;
		self::assertInstanceOf( RunId::class, $first_public_run_id );
		self::assertInstanceOf( RunId::class, $second_public_run_id );
		self::assertInstanceOf( RunId::class, $previous_public_run_id );
		self::assertSame( $first->value, (string) $first_public_run_id );
		self::assertSame( $second->value, (string) $second_public_run_id );
		self::assertSame( $first->value, (string) $previous_public_run_id );
		self::assertSame(
			array(
				array( $first_public_run_id, $first_args, null ),
				array( $second_public_run_id, $second_args, $previous_public_run_id ),
			),
			$completed
		);
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
		$this->chunked_job->queue = array(
			'first'  => array( 'chunk' => 'first' ),
			'second' => array( 'chunk' => 'second' ),
		);
		$filter_args              = null;
		$this->set_filter_value(
			'a8csp_jobs_engine/queue/' . self::IDENTITY,
			static function ( array $queue, array $start_args, string $run_id ) use ( &$filter_args ): array {
				$filter_args = \func_get_args();

				return array( array( 'chunk' => 'filtered-first' ), ...$queue, array( 'chunk' => 'filtered-last' ) );
			}
		);
		$this->start();

		$this->rig->run_due();

		self::assertSame( array( self::ARGS ), $this->chunked_job->generate_calls );
		self::assertSame( array( array( 'chunk' => 'first' ), array( 'chunk' => 'second' ) ), $filter_args[0] ?? null );
		self::assertSame( self::ARGS, $filter_args[1] ?? null );
		self::assertSame( self::RUN_ID, $filter_args[2] ?? null );
		self::assertSame( array( array( 'chunk' => 'filtered-first' ), array( 'chunk' => 'first' ), array( 'chunk' => 'second' ), array( 'chunk' => 'filtered-last' ) ), $this->run_state()['kind_state'] ?? null );
		$this->rig->backend()->assert_scheduled( self::IDENTITY );
	}

	/**
	 * A portable payload with the wrong chunked-job shape hydrates before the handler rejects it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_chunked_kind_state_uses_the_handler_failure_path(): void {
		$this->start();
		$state = $this->run_state();
		self::assertIsArray( $state );
		$state['kind_state'] = array( 'queue' => array( array( 'chunk' => 'wrong-wrapper' ) ) );
		$raw                 = \maybe_serialize( $state );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( 'a8csp_bgje_run_' . self::IDENTITY . '_' . self::RUN_ID, $raw );

		$inspection = $this->rig->inspection()->runs( self::IDENTITY );
		self::assertSame( 0, $inspection['live_unreadable'] );
		self::assertNull( $inspection['live'][0]['queue_depth'] ?? null );

		$this->rig->run_due();

		$failure = $this->assert_failure( ErrorCode::PayloadRejected, RunFailureStage::execution(), null );
		self::assertStringContainsString( 'kind_state', $failure->summary );
		self::assertSame( array(), $this->chunked_job->generate_calls );
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
		$first                    = array( 'chunk' => 'first' );
		$this->chunked_job->queue = array( $first, array( 'chunk' => 'second' ) );
		$this->start();
		$this->rig->backend()->before_next(
			'enqueue_async',
			static function (): void {
				\do_action( ActionDeliveries::DELIVER_HOOK, self::IDENTITY, self::RUN_ID, 2 );
			}
		);

		$this->rig->run_due();
		\do_action( ActionDeliveries::DELIVER_HOOK, self::IDENTITY, self::RUN_ID, 2 );
		\do_action( ActionDeliveries::DELIVER_HOOK, self::IDENTITY, self::RUN_ID, 2 );

		self::assertCount( 1, $this->chunked_job->process_calls );
		self::assertSame( $first, $this->chunked_job->process_calls[0]['chunk_args'] );
		self::assertSame( array( array( 'chunk' => 'second' ) ), $this->run_state()['kind_state'] ?? null );
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
		$this->chunked_job->queue = array( array( 'chunk' => 'first' ) );
		$this->set_action_throwable( 'a8csp_jobs_engine/started/' . self::IDENTITY, new \RuntimeException( 'Started listener exploded.' ) );
		$this->start();

		$this->rig->run_due();

		$this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::execution(), null );
		$this->rig->assert_no_retry();
	}

	/**
	 * A queue generation throwable retries the start stage and regenerates the queue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_retries_queue_generation_at_the_start_stage(): void {
		$this->options                         = new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) );
		$this->chunked_job->generate_throwable = new \RuntimeException( 'Queue generation exploded.' );
		$this->chunked_job->queue              = array( array( 'chunk' => 'first' ) );
		$chunked_job                           = $this->chunked_job;
		$this->chunked_job->on_generate        = static function () use ( $chunked_job ): void {
			if ( 1 < \count( $chunked_job->generate_calls ) ) {
				$chunked_job->generate_throwable = null;
			}
		};
		$this->start();
		$this->rig->randomizer()->value = 7;

		$this->rig->run_due();

		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/started' ) );
		$start_calls = $this->calls_for_hook( ActionDeliveries::DELIVER_HOOK );
		self::assertCount( 2, $start_calls );
		self::assertSame( 'schedule_single', $start_calls[1]['verb'] );
		self::assertSame( self::NOW + 7, $start_calls[1]['args']['timestamp'] ?? null );
		self::assertSame( array( self::IDENTITY, self::RUN_ID, 2 ), $start_calls[1]['args']['args'] ?? null );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/retry_scheduled' ) );

		$this->rig->run_due();

		self::assertSame( array( self::ARGS, self::ARGS ), $this->chunked_job->generate_calls );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/started' ) );

		$this->chunked_job->process_throwable = new \RuntimeException( 'First chunk attempt exploded.' );
		$this->rig->run_due();

		$deliver_calls = $this->calls_for_hook( ActionDeliveries::DELIVER_HOOK );
		self::assertCount( 4, $deliver_calls );
		$continue_calls = \array_values(
			\array_filter(
				$deliver_calls,
				static function ( array $call ): bool {
					$args = $call['args']['args'] ?? null;

					return \is_array( $args ) && \in_array( $args[2] ?? null, array( 3, 4 ), true );
				}
			)
		);
		self::assertCount( 2, $continue_calls );
		self::assertSame( 'schedule_single', $continue_calls[1]['verb'] );
		self::assertSame( array( self::IDENTITY, self::RUN_ID, 4 ), $continue_calls[1]['args']['args'] ?? null );
		self::assertCount( 2, $this->rig->hooks()->fired( 'a8csp_jobs_engine/retry_scheduled' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );
	}

	/**
	 * A lazy queue throwable discards every yielded chunk before retrying queue generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_retries_when_lazy_queue_iteration_throws(): void {
		$this->options                             = new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) );
		$generation                                = 0;
		$this->chunked_job->generate_queue_factory = static function () use ( &$generation ): iterable {
			++$generation;
			if ( 1 < $generation ) {
				yield array( 'chunk' => 'replacement' );

				return;
			}

			yield array( 'chunk' => 'must-not-persist' );
			throw new \RuntimeException( 'Lazy queue token secret.' );
		};
		$this->start();
		$this->rig->randomizer()->value = 9;

		$this->rig->run_due();

		$start_calls = $this->calls_for_hook( ActionDeliveries::DELIVER_HOOK );
		self::assertCount( 2, $start_calls );
		self::assertSame( 'schedule_single', $start_calls[1]['verb'] );
		self::assertSame( array( self::IDENTITY, self::RUN_ID, 2 ), $start_calls[1]['args']['args'] ?? null );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/retry_scheduled' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/started' ) );

		$this->rig->run_due();
		$this->rig->run_due();

		self::assertSame( 2, $generation );
		self::assertSame( array( 'chunk' => 'replacement' ), $this->chunked_job->process_calls[0]['chunk_args'] ?? null );
	}

	/**
	 * A chunked job remains cancellable while queue generation waits for its start-stage retry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_start_stage_retry_backoff_remains_cancellable(): void {
		$this->options                         = new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) );
		$this->chunked_job->generate_throwable = new \RuntimeException( 'Queue generation exploded.' );
		$this->start();
		$this->rig->randomizer()->value = 7;
		$this->rig->run_due();

		$result = $this->client->cancel( self::NAME, self::RUN_ID );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$this->rig->assert_cancelled();
	}

	/**
	 * A non-retryable queue generation throwable terminalizes without scheduling another start.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_terminalizes_non_retryable_queue_generation(): void {
		$this->chunked_job->generate_throwable = new NonRetryableException( 'Queue input is permanently invalid.' );
		$this->start();

		$this->rig->run_due();

		$failure = $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::queue_generation(), null );
		self::assertSame( 1, $failure->attempts );
		$this->rig->assert_no_retry();
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/started' ) );
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
		$this->chunked_job->queue = array( array( 'chunk' => 'valid' ), array( 'private-payload-must-not-leak' => $invalid_value ) );
		$this->start();

		$this->rig->run_due();

		$failure = $this->assert_failure( ErrorCode::PayloadRejected, RunFailureStage::queue_generation(), null );
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
		$queue                    = array(
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
		$this->chunked_job->queue = $queue;
		$this->start();

		$this->rig->run_due();

		self::assertSame( $queue, $this->run_state()['kind_state'] ?? null );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );
	}

	/**
	 * Generated chunks cannot retain aliases that let one execution mutate another persisted chunk.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_generated_queue_severs_references_shared_across_chunks(): void {
		$value                         = 'retained';
		$this->chunked_job->queue      = array(
			array( 'value' => &$value ),
			array( 'value' => &$value ),
		);
		$this->chunked_job->on_process = static function ( array $chunk_args ): void {
			$chunk_args['value'] = 'execution-mutated';
		};
		$this->start();

		$this->rig->run_due();
		$value = 'caller-mutated';
		$this->rig->run_due();
		$this->rig->run_due();

		self::assertCount( 2, $this->chunked_job->process_calls );
		self::assertSame( array( 'value' => 'retained' ), $this->chunked_job->process_calls[0]['chunk_args'] ?? null );
		self::assertSame( array( 'value' => 'retained' ), $this->chunked_job->process_calls[1]['chunk_args'] ?? null );
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
		$chunk                    = self::chunk_with_json_bytes( $json_bytes );
		$this->chunked_job->queue = array( $chunk );
		$this->start();

		$this->rig->run_due();

		if ( $accepted ) {
			self::assertSame( array( $chunk ), $this->run_state()['kind_state'] ?? null );
			self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );

			return;
		}

		$failure = $this->assert_failure( ErrorCode::PayloadRejected, RunFailureStage::queue_generation(), null );
		self::assertSame( 'chunked_job queue chunk at index 0 contains 8193 JSON bytes; the limit is 8192 bytes.', $failure->summary );
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
		$this->chunked_job->queue = self::queue_with_persisted_bytes( $persisted_bytes );
		$this->start();

		$this->rig->run_due();
		$this->chunked_job->queue            = array();
		$this->rig->wpdb()->recorded_queries = array();

		if ( $accepted ) {
			$serialized_queue = \maybe_serialize( $this->run_state()['kind_state'] ?? null );
			self::assertIsString( $serialized_queue );
			self::assertSame( 1_048_576, \strlen( $serialized_queue ) );
			self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );

			return;
		}

		$failure = $this->assert_failure( ErrorCode::PayloadRejected, RunFailureStage::queue_generation(), null );
		self::assertSame( 'chunked_job queue contains 1048577 persisted serialization bytes; the limit is 1048576 bytes.', $failure->summary );
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
		$this->chunked_job->queue = array( array( 'chunk' => 'first' ) );
		$this->set_filter_value( 'a8csp_jobs_engine/queue/' . self::IDENTITY, 'invalid queue' );
		$this->start();

		$this->rig->run_due();

		$this->assert_failure( ErrorCode::PayloadRejected, RunFailureStage::queue_generation(), null );
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
		$this->chunked_job->queue = array( array( 'chunk' => 'must-not-persist' ) );
		$this->set_filter_value(
			'a8csp_jobs_engine/queue/' . self::IDENTITY,
			static function (): never {
				throw new \DomainException( 'Queue filter credential secret.' );
			}
		);
		$this->start();

		$this->rig->run_due();

		$failure = $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::queue_generation(), null );
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
		$this->chunked_job->queue = array( array( 'chunk' => 'generated' ) );
		$this->set_filter_value( 'a8csp_jobs_engine/queue/' . self::IDENTITY, array( array( 'chunk' => 'valid' ), array( 'filtered-private-payload' => new \stdClass() ) ) );
		$this->start();

		$this->rig->run_due();

		$failure = $this->assert_failure( ErrorCode::PayloadRejected, RunFailureStage::queue_generation(), null );
		self::assertStringNotContainsString( 'filtered-private-payload', $failure->summary );
	}

	/**
	 * Ownership loss during queue generation supersedes without touching the foreign owner.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built foreign lock and pointer generations replace authority inside real queue generation before its ownership fence.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_supersedes_after_queue_generation_loses_ownership(): void {
		$this->chunked_job->queue       = array( array( 'chunk' => 'generated' ) );
		$this->chunked_job->on_generate = function (): void {
			$this->install_foreign_generation( self::NOW );
		};
		$this->start();

		$this->rig->run_due();

		$this->assert_foreign_superseded();
	}

	/**
	 * A throwing generator that loses ownership supersedes instead of failing.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The foreign generation wins inside throwing queue generation, so failure adjudication must fence the expired delivery before retention.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_supersedes_when_throwing_queue_generation_loses_ownership(): void {
		$this->chunked_job->generate_throwable = new \RuntimeException( 'Queue generation exploded.' );
		$this->chunked_job->on_generate        = function (): void {
			$this->install_foreign_generation( self::NOW );
		};
		$this->start();

		$this->rig->run_due();

		$this->assert_foreign_superseded();
	}

	/**
	 * Ownership loss in the started listener fences the first continuation.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The legitimate started-hook boundary installs a fixture-built foreign generation before delivery schedules its successor.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_supersedes_when_started_listener_loses_ownership(): void {
		$this->chunked_job->queue = array( array( 'chunk' => 'first' ) );
		$this->observe_action(
			'a8csp_jobs_engine/started/' . self::IDENTITY,
			function (): void {
				$this->install_foreign_generation( self::NOW );
			}
		);
		$this->start();

		$this->rig->run_due();

		$this->assert_foreign_superseded();
	}

	/**
	 * A throwing started listener still respects the foreign generation fence.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Foreign ownership is installed before the listener throws, distinguishing supersession from terminal failure after the same hook.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_start_action_supersedes_when_throwing_started_listener_loses_ownership(): void {
		$this->chunked_job->queue = array( array( 'chunk' => 'first' ) );
		$this->observe_action(
			'a8csp_jobs_engine/started/' . self::IDENTITY,
			function (): void {
				$this->install_foreign_generation( self::NOW );
			}
		);
		$this->set_action_throwable( 'a8csp_jobs_engine/started/' . self::IDENTITY, new \RuntimeException( 'Started listener exploded.' ) );
		$this->start();

		$this->rig->run_due();

		$this->assert_foreign_superseded();
	}

	/**
	 * Continue processes the current head and schedules the next continuation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_processes_one_chunk_and_schedules_continue(): void {
		$queue = array( array( 'chunk' => 'first' ), array( 'chunk' => 'second' ) );
		$this->prepare_started_chunked_job( $queue );

		$this->rig->run_due();

		self::assertSame( array( array( 'chunk' => 'second' ) ), $this->run_state()['kind_state'] ?? null );
		self::assertSame( array( array( 'chunk' => 'first' ) ), \array_column( $this->chunked_job->process_calls, 'chunk_args' ) );
		self::assertCount( 1, $this->calls_for_hook( ActionDeliveries::DELIVER_HOOK ) );
	}

	/**
	 * A live continuation for an unregistered chunked job fails against its current queue head.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unregistered_chunked_job_continue_terminalizes_the_current_queue_head(): void {
		$current = array( 'chunk' => 'current' );
		$queue   = array( $current, array( 'chunk' => 'remaining' ) );
		$this->rig->tear_down();
		$this->rig      = EngineRig::set_up( self::NOW );
		$this->client   = $this->rig->operations( self::OWNER );
		$this->fixtures = StoreFixtureBuilder::for_identity( self::IDENTITY );
		$state          = new RunState( status: RunStatus::Running, kind: 'chunked_job', executing: false, start_args: self::ARGS, args_hash: $this->args_hash(), kind_state: $queue, failed_attempts: 0, action_sequence: 2, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( 'continue', 10 ) );
		$this->put_fixture( $this->fixtures->run( self::RUN_ID, $state ) );
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), self::RUN_ID, self::NOW, self::NOW ) );
		$this->put_fixture(
			$this->fixtures->history(
				array(
					array(
						'run_id'    => self::RUN_ID,
						'args_hash' => $this->args_hash(),
					),
				)
			)
		);
		$this->put_fixture(
			$this->fixtures->latest(
				array(
					array(
						'run_id'    => self::RUN_ID,
						'args_hash' => $this->args_hash(),
					),
				)
			)
		);

		\do_action( ActionDeliveries::DELIVER_HOOK, self::IDENTITY, self::RUN_ID, 2 );

		$this->assert_failure( ErrorCode::UnknownWork, RunFailureStage::execution(), $current );
	}

	/**
	 * A chunk exceeding the default heartbeat window retains its declared execution lease.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Re-entering the registered continuation after the default lock-staleness window proves the declared execution lease prevents mid-chunk reclaim and duplicate client work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_credits_long_chunk_execution_before_client_work(): void {
		$this->options = new JobOptions( max_runtime: 1_800 );
		$this->prepare_started_chunked_job( array( array( 'chunk' => 'current' ) ) );
		$reentered                     = false;
		$this->chunked_job->on_process = function () use ( &$reentered ): void {
			if ( $reentered ) {
				return;
			}

			$reentered                      = true;
			$this->rig->clock()->timestamp += 901;
			\do_action( ActionDeliveries::DELIVER_HOOK, self::IDENTITY, self::RUN_ID, 2 );
		};

		$this->rig->run_due();

		self::assertTrue( $reentered );
		self::assertSame( array( array( 'chunk' => 'current' ) ), \array_column( $this->chunked_job->process_calls, 'chunk_args' ) );
	}

	/**
	 * A chunk is delivered from its byte-faithful persisted queue through token-only backend arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_delivers_a_persisted_float_chunk_with_token_only_backend_args(): void {
		$this->prepare_started_chunked_job( array( array( 'value' => 1.0 ) ) );
		$queue = $this->run_state()['kind_state'] ?? null;
		self::assertIsArray( $queue );
		$persisted_chunk = $queue[0] ?? null;
		self::assertIsArray( $persisted_chunk );
		self::assertIsFloat( $persisted_chunk['value'] ?? null );
		self::assertSame( 1.0, $persisted_chunk['value'] );

		$delivery = $this->rig->backend()->take_next_delivery();
		self::assertNotNull( $delivery );
		self::assertSame( ActionDeliveries::DELIVER_HOOK, $delivery['hook'] );
		self::assertSame( array( self::IDENTITY, self::RUN_ID, 2 ), $delivery['args'] );
		\do_action( $delivery['hook'], ...$delivery['args'] );

		self::assertCount( 1, $this->chunked_job->process_calls );
		$delivered_chunk = $this->chunked_job->process_calls[0]['chunk_args'] ?? null;
		self::assertIsArray( $delivered_chunk );
		self::assertIsFloat( $delivered_chunk['value'] ?? null );
		self::assertSame( $persisted_chunk, $delivered_chunk );
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
		$this->prepare_started_chunked_job( array() );

		$this->rig->run_due();

		self::assertSame( array(), $this->chunked_job->process_calls );
		self::assertCount( 1, $this->calls_for_hook( ActionDeliveries::DELIVER_HOOK ) );
	}

	/**
	 * A normal chunk commits real context mutations and delays continue.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_commits_context_mutations_and_schedules_delayed_continue(): void {
		$current = array( 'chunk' => 'current' );
		$this->prepare_scheduled_chunk( array( $current, array( 'chunk' => 'remaining' ) ) );
		$this->set_filter_value( 'a8csp_jobs_engine/continue_delay', 75 );
		$this->chunked_job->on_process = static function ( array $chunk, ChunkContextInterface $context ) use ( $current ): void {
			self::assertSame( $current, $chunk );
			self::assertSame( self::RUN_ID, (string) $context->get_run_id() );
			self::assertSame( self::ARGS, $context->get_start_args() );
			$context->append_chunk( array( 'chunk' => 'appended' ) );
			$context->prepend_chunk( array( 'chunk' => 'prepended-1' ) );
			$context->prepend_chunk( array( 'chunk' => 'prepended-2' ) );
		};
		$this->rig->clock()->timestamp = self::NOW + 120;

		$this->rig->run_due();

		self::assertInstanceOf( ChunkContextInterface::class, $this->chunked_job->process_calls[0]['context'] ?? null );
		self::assertSame( array( array( 'chunk' => 'prepended-2' ), array( 'chunk' => 'prepended-1' ), array( 'chunk' => 'remaining' ), array( 'chunk' => 'appended' ) ), $this->run_state()['kind_state'] ?? null );
		self::assertSame( self::NOW + 195, $this->single_call_for_hook( ActionDeliveries::DELIVER_HOOK )['args']['timestamp'] ?? null );
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
	public function test_handle_continue_action_rejects_invalid_context_mutations_transactionally( string $mutation, mixed $invalid_value ): void {
		$current = array( 'chunk' => 'current' );

		$this->options = new JobOptions( retry: new RetryPolicy( max_attempts: 1 ) );

		$this->prepare_scheduled_chunk( array( $current, array( 'chunk' => 'remaining' ) ) );
		$this->chunked_job->on_process = static function ( array $chunk, ChunkContextInterface $context ) use ( $invalid_value, $mutation ): void {
			if ( 'append_chunk' === $mutation ) {
				$context->append_chunk( array( 'execution-private-payload' => $invalid_value ) );

				return;
			}

			$context->prepend_chunk( array( 'execution-private-payload' => $invalid_value ) );
		};

		$this->rig->run_due();

		$failure = $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::execution(), $current );
		self::assertStringNotContainsString( 'execution-private-payload', $failure->summary );
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
		$current = array( 'chunk' => 'current' );
		$chunk   = self::chunk_with_json_bytes( $json_bytes );

		$this->options = new JobOptions( retry: new RetryPolicy( max_attempts: 1 ) );

		$this->prepare_scheduled_chunk( array( $current ) );
		$this->chunked_job->on_process = static function ( array $chunk_args, ChunkContextInterface $context ) use ( $chunk, $mutation ): void {
			if ( 'append_chunk' === $mutation ) {
				$context->append_chunk( $chunk );

				return;
			}

			$context->prepend_chunk( $chunk );
		};

		$this->rig->run_due();

		if ( $accepted ) {
			self::assertSame( array( $chunk ), $this->run_state()['kind_state'] ?? null );
			self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );

			return;
		}

		$this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::execution(), $current );
	}

	/**
	 * Supplies both context directions on both sides of the chunk byte boundary.
	 *
	 * @return  array<string, array{mutation: 'append_chunk'|'prepend_chunk', json_bytes: int, accepted: bool}>
	 */
	public static function bounded_context_chunks(): array {
		return array(
			'append_chunk at limit'    => array(
				'mutation'   => 'append_chunk',
				'json_bytes' => 8_192,
				'accepted'   => true,
			),
			'append_chunk over limit'  => array(
				'mutation'   => 'append_chunk',
				'json_bytes' => 8_193,
				'accepted'   => false,
			),
			'prepend_chunk at limit'   => array(
				'mutation'   => 'prepend_chunk',
				'json_bytes' => 8_192,
				'accepted'   => true,
			),
			'prepend_chunk over limit' => array(
				'mutation'   => 'prepend_chunk',
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
		$mutation_chunk = 'prepend_chunk' === $mutation ? \array_shift( $candidate ) : \array_pop( $candidate );
		self::assertIsArray( $mutation_chunk );

		$this->options = new JobOptions( retry: new RetryPolicy( max_attempts: 1 ) );
		$this->prepare_scheduled_chunk( array( $current, ...$candidate ) );
		$this->chunked_job->queue            = array();
		$this->rig->wpdb()->recorded_queries = array();
		$this->chunked_job->on_process       = static function ( array $chunk_args, ChunkContextInterface $context ) use ( $mutation, $mutation_chunk ): void {
			if ( 'append_chunk' === $mutation ) {
				$context->append_chunk( $mutation_chunk );

				return;
			}

			$context->prepend_chunk( $mutation_chunk );
		};

		$this->rig->run_due();

		if ( $accepted ) {
			$serialized_queue = \maybe_serialize( $this->run_state()['kind_state'] ?? null );
			self::assertIsString( $serialized_queue );
			self::assertSame( 1_048_576, \strlen( $serialized_queue ) );
			self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );

			return;
		}

		$this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::execution(), $current );
	}

	/**
	 * Supplies both context directions on both sides of the persisted queue byte boundary.
	 *
	 * @return  array<string, array{mutation: 'append_chunk'|'prepend_chunk', persisted_bytes: int, accepted: bool}>
	 */
	public static function bounded_context_queue_bytes(): array {
		return array(
			'append_chunk at limit'    => array(
				'mutation'        => 'append_chunk',
				'persisted_bytes' => 1_048_576,
				'accepted'        => true,
			),
			'append_chunk over limit'  => array(
				'mutation'        => 'append_chunk',
				'persisted_bytes' => 1_048_577,
				'accepted'        => false,
			),
			'prepend_chunk at limit'   => array(
				'mutation'        => 'prepend_chunk',
				'persisted_bytes' => 1_048_576,
				'accepted'        => true,
			),
			'prepend_chunk over limit' => array(
				'mutation'        => 'prepend_chunk',
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
	 * @return array<string, array{mutation: 'append_chunk'|'prepend_chunk', invalid_value: mixed}>
	 */
	public static function invalid_context_mutations(): array {
		return array(
			'append_chunk closure' => array(
				'mutation'      => 'append_chunk',
				'invalid_value' => static fn (): null => null,
			),
			'prepend_chunk object' => array(
				'mutation'      => 'prepend_chunk',
				'invalid_value' => new \stdClass(),
			),
		);
	}

	/**
	 * A kind handler cannot deliver a persisted stage it does not own.
	 *
	 * @load-bearing security
	 * @pin-rationale A lexically valid foreign stage is injected into the authoritative row to prove the generic delivery boundary rejects it before fencing or client work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_deliver_hook_rejects_a_handler_unowned_persisted_stage(): void {
		$current = array( 'chunk' => 'current' );
		$this->prepare_started_chunked_job( array( $current ) );
		$this->replace_pending_stage( 'run' );
		$before                       = $this->run_state();
		$this->rig->logger()->records = array();

		\do_action( ActionDeliveries::DELIVER_HOOK, self::IDENTITY, self::RUN_ID, 2 );

		self::assertSame( $before, $this->run_state() );
		self::assertSame( array(), $this->chunked_job->process_calls );
		self::assertCount( 1, $this->rig->logger()->records );
		self::assertSame( 'warning', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( 'chunked_job', $this->rig->logger()->records[0]['context']['kind'] ?? null );
		self::assertSame( 'run', $this->rig->logger()->records[0]['context']['stage'] ?? null );

		$this->replace_pending_stage( 'continue' );
		$this->rig->run_due();
		self::assertCount( 1, $this->chunked_job->process_calls );
		self::assertSame( $current, $this->chunked_job->process_calls[0]['chunk_args'] );
	}

	/**
	 * A continuation payload without its sequence is rejected before admission.
	 *
	 * @load-bearing security
	 * @pin-rationale Direct registered-hook delivery proves the typed continuation boundary rejects an incomplete payload without mutating authoritative run state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_continue_handler_rejects_a_missing_sequence_before_admission(): void {
		$current = array( 'chunk' => 'current' );
		$this->prepare_scheduled_chunk( array( $current ) );
		self::assertNotNull( $this->rig->backend()->take_next_delivery() );
		$before = $this->run_state();
		$thrown = null;

		try {
			\do_action( ActionDeliveries::DELIVER_HOOK, self::IDENTITY, self::RUN_ID );
		} catch ( \ArgumentCountError $error ) {
			$thrown = $error;
		}

		self::assertInstanceOf( \ArgumentCountError::class, $thrown );
		self::assertSame( $before, $this->run_state() );
		self::assertSame( array(), $this->chunked_job->process_calls );
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
	public function test_handle_continue_action_resolves_continue_delay_filter_values( mixed $filtered_delay, int $expected_delay ): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$this->set_filter_value( 'a8csp_jobs_engine/continue_delay', $filtered_delay );
		$this->rig->clock()->timestamp = self::NOW + 120;

		$this->rig->run_due();

		self::assertSame( self::NOW + 120 + $expected_delay, $this->single_call_for_hook( ActionDeliveries::DELIVER_HOOK )['args']['timestamp'] ?? null );
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
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_supersedes_after_chunk_work_loses_ownership(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ), array( 'chunk' => 'remaining' ) ) );
		$this->chunked_job->on_process = function ( array $chunk, ChunkContextInterface $context ): void {
			$context->append_chunk( array( 'chunk' => 'discarded' ) );
			$this->install_foreign_generation( self::NOW );
		};

		$this->rig->run_due();

		$this->assert_foreign_superseded();
		self::assertCount( 1, $this->chunked_job->process_calls );
	}

	/**
	 * A throwing chunk that loses ownership supersedes before retry adjudication.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The foreign generation is installed inside throwing chunk execution, so no retry or failure may target the expired owner.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_supersedes_when_throwing_chunk_loses_ownership(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$this->chunked_job->process_throwable = new \RuntimeException( 'Chunk exploded.' );
		$this->chunked_job->on_process        = function (): void {
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
	public function test_handle_continue_action_fails_terminally_when_continue_scheduling_fails(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ), array( 'chunk' => 'remaining' ) ) );
		$this->chunked_job->on_process                    = static function ( array $chunk, ChunkContextInterface $context ): void {
			$context->prepend_chunk( array( 'chunk' => 'committed-front' ) );
			$context->append_chunk( array( 'chunk' => 'committed-back' ) );
		};
		$this->rig->backend()->results['schedule_single'] = $this->scheduling_failure_result();

		$this->rig->run_due();

		$this->assert_failure( ErrorCode::BackendRejected, RunFailureStage::scheduling(), null );
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
	public function test_handle_continue_action_fails_terminally_when_continue_delay_filter_throws(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$this->chunked_job->on_process = static function ( array $chunk, ChunkContextInterface $context ): void {
			$context->append_chunk( array( 'chunk' => 'committed' ) );
		};
		$this->set_filter_value(
			'a8csp_jobs_engine/continue_delay',
			static function (): never {
				throw new \DomainException( 'Continue-delay filter exploded.' );
			}
		);

		$this->rig->run_due();

		$this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::execution(), null );
	}

	/**
	 * Ownership loss in the continue-delay filter prevents successor scheduling.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The filter installs a fixture-built foreign generation after chunk commit but before the next delivery write.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_supersedes_when_continue_delay_filter_loses_ownership(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$this->set_filter_value(
			'a8csp_jobs_engine/continue_delay',
			function (): int {
				$this->install_foreign_generation( self::NOW );

				return 30;
			}
		);

		$this->rig->run_due();

		$this->assert_foreign_superseded();
		self::assertSame( array(), $this->calls_for_hook( ActionDeliveries::DELIVER_HOOK ) );
	}

	/**
	 * A throwing continue-delay filter still respects the foreign generation.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The same filter boundary both transfers authority and throws, proving supersession wins over terminal error retention.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_supersedes_when_throwing_continue_delay_filter_loses_ownership(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$this->set_filter_value(
			'a8csp_jobs_engine/continue_delay',
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
	public function test_handle_continue_action_discards_context_mutations_and_reschedules_the_chunk(): void {
		$current = array( 'chunk' => 'current' );

		$this->options = new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) );

		$this->prepare_scheduled_chunk( array( $current, array( 'chunk' => 'remaining' ) ) );
		$this->chunked_job->on_process        = static function ( array $chunk, ChunkContextInterface $context ): void {
			$context->prepend_chunk( array( 'chunk' => 'discarded-front' ) );
			$context->append_chunk( array( 'chunk' => 'discarded-back' ) );
		};
		$this->chunked_job->process_throwable = new \RuntimeException( 'Chunk processing exploded.' );
		$this->rig->randomizer()->value       = 11;
		$this->rig->randomizer()->calls       = array();

		$this->rig->run_due();

		self::assertSame( array( $current, array( 'chunk' => 'remaining' ) ), $this->run_state()['kind_state'] ?? null );
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
		self::assertSame(
			array(
				'stage'    => 'continue',
				'mode'     => 'single',
				'fire_at'  => self::NOW + 11,
				'priority' => 10,
			),
			$this->run_state()['pending'] ?? null
		);
		$retry_call = $this->single_call_for_hook( ActionDeliveries::DELIVER_HOOK );
		self::assertSame( 'schedule_single', $retry_call['verb'] );
		self::assertSame( array( self::IDENTITY, self::RUN_ID, 3 ), $retry_call['args']['args'] ?? null );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );
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
		$current                              = array( 'chunk' => 'current' );
		$this->options                        = new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) );
		$this->chunked_job->process_throwable = new \RuntimeException( 'Chunk processing exploded.' );
		$this->prepare_scheduled_chunk( array( $current ) );
		$this->rig->randomizer()->value = 11;
		$this->rig->run_due();
		$before                              = $this->rig->wpdb()->rows;
		$this->rig->backend()->calls         = array();
		$this->rig->wpdb()->recorded_queries = array();

		\do_action( ActionDeliveries::DELIVER_HOOK, self::IDENTITY, self::RUN_ID, 2 );

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
	public function test_handle_continue_action_resets_retries_before_the_next_chunk(): void {
		$chunk_a                              = array( 'chunk' => 'a' );
		$chunk_b                              = array( 'chunk' => 'b' );
		$this->options                        = new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) );
		$this->chunked_job->process_throwable = new \RuntimeException( 'Chunk A failed once.' );
		$this->prepare_scheduled_chunk( array( $chunk_a, $chunk_b ) );
		$this->rig->randomizer()->value = 5;

		$this->rig->run_due();
		$this->chunked_job->process_throwable = null;
		$this->chunked_job->on_process        = static function ( array $chunk ): void {
			if ( 'b' === ( $chunk['chunk'] ?? null ) ) {
				throw new \RuntimeException( 'Chunk B failed once.' );
			}
		};
		$this->rig->run_due();
		$this->rig->run_due();

		self::assertSame( array( $chunk_a, $chunk_a, $chunk_b ), \array_column( $this->chunked_job->process_calls, 'chunk_args' ) );
		self::assertCount( 2, $this->rig->hooks()->fired( 'a8csp_jobs_engine/retry_scheduled' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );
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
	public function test_handle_continue_action_terminalizes_a_chunk_retry_schedule_failure(): void {
		$current                              = array( 'chunk' => 'current' );
		$this->options                        = new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) );
		$this->chunked_job->process_throwable = new \RuntimeException( 'Chunk processing exploded.' );
		$this->prepare_scheduled_chunk( array( $current ) );
		$this->rig->randomizer()->value                   = 7;
		$this->rig->backend()->results['schedule_single'] = $this->scheduling_failure_result();

		$this->rig->run_due();

		$failure = $this->assert_failure( ErrorCode::BackendRejected, RunFailureStage::scheduling(), $current );
		self::assertSame( 1, $failure->attempts );
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
	public function test_handle_continue_action_terminalizes_a_non_retryable_chunk_without_rescheduling(): void {
		$current                              = array( 'chunk' => 'current' );
		$this->chunked_job->process_throwable = new NonRetryableException( 'Chunk input is permanently invalid.' );
		$this->prepare_scheduled_chunk( array( $current ) );

		$this->rig->run_due();

		$failure = $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::execution(), $current );
		self::assertSame( 1, $failure->attempts );
		$this->rig->assert_no_retry();
	}

	/**
	 * A throwing failed listener leaves the terminal row available for replay.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_listener_throw_retains_terminal_row(): void {
		$this->options                        = new JobOptions( retry: new RetryPolicy( max_attempts: 1 ) );
		$this->chunked_job->process_throwable = new \DomainException( 'Chunk failed.' );
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$listener = new \RuntimeException( 'Failed listener exploded.' );
		$this->set_action_throwable( 'a8csp_jobs_engine/failed', $listener );

		$caught = null;
		try {
			$this->rig->run_due();
		} catch ( \RuntimeException $throwable ) {
			$caught = $throwable;
		}

		self::assertSame( $listener, $caught );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );
		$state = $this->run_state();
		self::assertNotNull( $state );
		self::assertSame( 'failed', $state['status'] ?? null );
		self::assertSame( array( 'retention', 'history' ), $state['effects'] ?? null );
	}

	/**
	 * Cleanup fences terminal completion before public hooks.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Hook-time production state proves cleanup publishes its terminal generation before notifying consumers, while the observed sequence pins identity-specific delivery before the generic hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_cleanup_action_fences_before_completed_hooks_and_preserves_hook_order(): void {
		$this->prepare_cleanup_delivery();
		$observed = null;
		$this->observe_action(
			'a8csp_jobs_engine/completed/' . self::IDENTITY,
			function () use ( &$observed ): void {
				$observed = $this->run_state();
			}
		);

		$this->rig->run_due();

		self::assertSame( 'completed', $observed['status'] ?? null );
		self::assertTrue( $observed['executing'] ?? false );
		self::assertSame(
			array(
				'a8csp_jobs_engine/completed/' . self::IDENTITY,
				'a8csp_jobs_engine/completed',
			),
			\array_slice( $this->rig->hooks()->sequence(), -2 )
		);
		$this->rig->assert_completed();
	}

	/**
	 * Cleanup preserves the persisted attempt count while publishing completion.
	 *
	 * @load-bearing state-transition
	 * @pin-rationale Chunked completion consumes no work attempt, so its terminal CAS retains the exact admitted attempt count.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cleanup_completion_preserves_persisted_failed_attempts(): void {
		$this->prepare_cleanup_delivery();
		$this->replace_failed_attempts( 2 );
		$observed = null;
		$this->observe_action(
			'a8csp_jobs_engine/completed/' . self::IDENTITY,
			function () use ( &$observed ): void {
				$observed = $this->run_state();
			}
		);

		$this->rig->run_due();

		self::assertSame( 2, $observed['failed_attempts'] ?? null );
		$this->rig->assert_completed();
	}

	/**
	 * A throwing completed listener leaves the terminal row available for replay.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_listener_throw_retains_terminal_row(): void {
		$this->prepare_cleanup_delivery();
		$listener = new \RuntimeException( 'Completed listener exploded.' );
		$this->set_action_throwable( 'a8csp_jobs_engine/completed/' . self::IDENTITY, $listener );

		$caught = null;
		try {
			$this->rig->run_due();
		} catch ( \RuntimeException $throwable ) {
			$caught = $throwable;
		}

		self::assertSame( $listener, $caught );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/completed/' . self::IDENTITY ) );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/completed' ) );
		$state = $this->run_state();
		self::assertNotNull( $state );
		self::assertSame( 'completed', $state['status'] ?? null );
		self::assertSame( array( 'history' ), $state['effects'] ?? null );
	}

	/**
	 * A replacement started by the completed hook survives the finishing cleanup.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A real facade admission from the completed hook creates the successor generation that incumbent cleanup must not delete.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_cleanup_action_completes_when_completed_hook_starts_replacement(): void {
		$this->options = new JobOptions( overlap: OverlapPolicy::Replace );
		$this->prepare_cleanup_delivery();
		$replacement = null;
		$this->observe_action(
			'a8csp_jobs_engine/completed/' . self::IDENTITY,
			function () use ( &$replacement ): void {
				$replacement = $this->client->dispatch( self::NAME, self::ARGS );
			}
		);

		$this->rig->run_due();

		self::assertInstanceOf( Success::class, $replacement );
		self::assertNotSame( self::RUN_ID, $replacement->value );
		$this->rig->backend()->assert_scheduled( self::IDENTITY );
		$this->rig->assert_completed();
	}

	/**
	 * A sequential duplicate cleanup delivery cannot repeat completed hooks.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Redelivering the registered cleanup generation after state consumption proves terminal hooks are idempotent under at-least-once scheduling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_duplicate_cleanup_delivery_fires_completed_hooks_once(): void {
		$this->prepare_cleanup_delivery();
		$sequence = $this->run_state()['action_sequence'] ?? null;
		self::assertIsInt( $sequence );

		$this->rig->run_due();
		\do_action( ActionDeliveries::DELIVER_HOOK, self::IDENTITY, self::RUN_ID, $sequence );

		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/completed/' . self::IDENTITY ) );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/completed' ) );
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
		$this->chunked_job->queue = array( array( 'chunk' => 'first' ) );
		$this->start();
		$this->rig->backend()->results['enqueue_async'] = $this->scheduling_failure_result();

		$this->rig->run_due();

		$this->assert_failure( ErrorCode::BackendRejected, RunFailureStage::scheduling(), null );
		$this->rig->assert_no_delivery( self::IDENTITY );
	}

	/**
	 * A failed continuation schedule leaves no accepted delivery for the retained queue.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The rejected continuation write must leave no accepted delivery that could process the retained next queue head later.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_fails_terminally_without_accepting_a_successor(): void {
		$current = array( 'chunk' => 'first' );
		$this->prepare_started_chunked_job( array( $current, array( 'chunk' => 'retained' ) ) );
		$this->rig->backend()->results['schedule_single'] = $this->scheduling_failure_result();

		$this->rig->run_due();

		$this->assert_failure( ErrorCode::BackendRejected, RunFailureStage::scheduling(), null );
		self::assertSame( array( $current ), \array_column( $this->chunked_job->process_calls, 'chunk_args' ) );
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
		$this->prepare_started_chunked_job( array() );
		$this->rig->backend()->results['enqueue_async'] = $this->scheduling_failure_result();

		$this->rig->run_due();

		$this->assert_failure( ErrorCode::BackendRejected, RunFailureStage::scheduling(), null );
		$this->rig->assert_no_delivery( self::IDENTITY );
	}

	/**
	 * Continue quietly supersedes after fixture-built lock ownership moves.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The foreign lock generation exists before the registered continuation claims execution, so it must create no successor or terminal effect.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_quietly_supersedes_after_lock_ownership_moves(): void {
		$this->prepare_started_chunked_job( array( array( 'chunk' => 'first' ) ) );
		$this->install_foreign_generation( self::NOW );

		$this->rig->run_due();

		$this->assert_foreign_superseded();
		self::assertSame( array(), $this->chunked_job->process_calls );
	}

	/**
	 * Chunk execution quietly supersedes after fixture-built lock ownership moves.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The foreign lock generation exists before the registered run delivery claims execution, preventing chunk execution and terminal hooks.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_continue_action_quietly_supersedes_before_chunk_work_after_lock_ownership_moves(): void {
		$this->prepare_scheduled_chunk( array( array( 'chunk' => 'current' ) ) );
		$this->install_foreign_generation( self::NOW );

		$this->rig->run_due();

		$this->assert_foreign_superseded();
		self::assertSame( array(), $this->chunked_job->process_calls );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Starts the deterministic chunked job through its owner-bound facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function start(): string {
		$this->register_chunked_job();
		$result = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		return $result->value;
	}

	/**
	 * Registers this test's chunked definition exactly once.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function register_chunked_job(): void {
		if ( $this->registered ) {
			return;
		}

		$this->client->register( $this->chunked_job->definition( $this->options ) );
		$this->registered = true;
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
	 * Measuring the complete serialized queue yields the overflow removed from the final payload;
	 * the four-digit guard keeps that payload's serialized length field at a stable width.
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
	 * Calculates PHP's serialized byte length for the fixture queue.
	 *
	 * @phpstan-param list<int> $payload_lengths
	 *
	 * @param   array $payload_lengths Payload byte lengths in queue order.
	 *
	 * @return  int
	 */
	private static function serialized_queue_bytes_for_payload_lengths( array $payload_lengths ): int {
		$queue = \array_map(
			static fn ( int $payload_bytes ): array => array( 'payload' => \str_repeat( 'a', $payload_bytes ) ),
			$payload_lengths
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Production persists this queue through WordPress serialization; the boundary fixture measures the same grammar.
		return \strlen( \serialize( $queue ) );
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
	private function prepare_started_chunked_job( array $queue ): void {
		$this->chunked_job->queue = $queue;
		$this->start();
		$this->rig->run_due();
		$this->rig->backend()->calls = array();
	}

	/**
	 * Delivers start for one queue, leaving its chunk-processing continuation pending.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, array<array-key, mixed>> $queue Initial chunks.
	 *
	 * @return  void
	 */
	private function prepare_scheduled_chunk( array $queue ): void {
		$this->prepare_started_chunked_job( $queue );
	}

	/**
	 * Leaves the cleanup action for one drained chunked job pending.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function prepare_cleanup_delivery(): void {
		$this->prepare_started_chunked_job( array() );
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
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );
	}

	/**
	 * Asserts and returns the latest public failure payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ErrorCode                     $code           Expected failure code.
	 * @param   RunFailureStage               $stage          Expected failure stage.
	 * @param   array<array-key, mixed>|null $expected_chunk Expected failed chunk.
	 *
	 * @return  RunFailure
	 */
	private function assert_failure( ErrorCode $code, RunFailureStage $stage, ?array $expected_chunk ): RunFailure {
		$events = $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' );
		self::assertNotEmpty( $events );
		$latest = $events[ \count( $events ) - 1 ];
		self::assertCount( 1, $latest );
		$failure = $latest[0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( $code, $failure->code );
		self::assertSame( $stage, $failure->stage );
		$expected_details = null === $expected_chunk ? null : array( 'failed_chunk' => $expected_chunk );
		self::assertSame( $expected_details, $failure->details );

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
		return new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore the scheduler before retrying this chunked job.' ) );
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
		$value = $this->decoded_row( 'a8csp_bgje_overlap_lock_' . self::IDENTITY . '_' . $this->args_hash() );

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
		$value = $this->decoded_row( 'a8csp_bgje_run_' . self::IDENTITY . '_' . self::RUN_ID );

		return \is_array( $value ) ? $value : null;
	}

	/**
	 * Replaces the authoritative pending stage without changing its delivery token.
	 *
	 * @param   string $stage Persisted lifecycle stage.
	 *
	 * @return  void
	 */
	private function replace_pending_stage( string $stage ): void {
		$state = $this->run_state();
		self::assertIsArray( $state );
		self::assertIsArray( $state['pending'] ?? null );
		$state['pending']['stage'] = $stage;
		$raw                       = \maybe_serialize( $state );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( 'a8csp_bgje_run_' . self::IDENTITY . '_' . self::RUN_ID, $raw );
	}

	/**
	 * Replaces the authoritative failed-attempt count without changing its delivery token.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $failed_attempts Persisted failed-attempt count.
	 *
	 * @return  void
	 */
	private function replace_failed_attempts( int $failed_attempts ): void {
		$state = $this->run_state();
		self::assertIsArray( $state );
		$state['failed_attempts'] = $failed_attempts;
		$raw                      = \maybe_serialize( $state );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( 'a8csp_bgje_run_' . self::IDENTITY . '_' . self::RUN_ID, $raw );
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
			$options = $GLOBALS['a8csp_bgje_test_options'] ?? null;
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
	 * @param   mixed  $value     Filter return or callable.
	 *
	 * @return  void
	 */
	private function set_filter_value( string $hook_name, mixed $value ): void {
		$filters = $GLOBALS['a8csp_bgje_test_filter_values'] ?? null;
		self::assertIsArray( $filters );
		$filters[ $hook_name ]                    = $value;
		$GLOBALS['a8csp_bgje_test_filter_values'] = $filters;
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
		$throwables = $GLOBALS['a8csp_bgje_test_action_throwables'] ?? null;
		self::assertIsArray( $throwables );
		$throwables[ $hook_name ]                     = $throwable;
		$GLOBALS['a8csp_bgje_test_action_throwables'] = $throwables;
	}

	/**
	 * Observes one legitimate WordPress action boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $hook_name Exact action hook.
	 * @param   \Closure $observe   Action observer.
	 *
	 * @return  void
	 */
	private function observe_action( string $hook_name, \Closure $observe ): void {
		$observers = $GLOBALS['a8csp_bgje_test_action_observers'] ?? null;
		self::assertIsArray( $observers );
		$observers[]                                 = static function ( string $hook ) use ( $hook_name, $observe ): void {
			if ( $hook_name === $hook ) {
				$observe();
			}
		};
		$GLOBALS['a8csp_bgje_test_action_observers'] = $observers;
	}

	// endregion.
}
