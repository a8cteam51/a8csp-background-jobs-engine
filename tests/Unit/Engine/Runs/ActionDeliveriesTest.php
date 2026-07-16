<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises task deliveries through the registered production action graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ActionDeliveries::class )]
final class ActionDeliveriesTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const ARGS     = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const IDENTITY = self::OWNER . ':' . self::NAME;
	private const NAME     = 'email-digest';
	private const NOW      = 1_700_000_000;
	private const OWNER    = 'runs-tests';
	private const RUN_ID   = '00000000001700000000-0000000000000000042';

	private Consumer $consumer;
	private StoreFixtureBuilder $fixtures;
	private EngineRig $rig;
	private RecordingTask $task;

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
	 * Boots one registered task against deterministic interface fakes.
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
		$this->task     = new RecordingTask( self::NAME );
		$this->consumer->tasks()->register( $this->task );
		$this->fixtures = StoreFixtureBuilder::for_identity( self::IDENTITY );
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
	public function test_registered_delivery_hooks_drive_every_batch_stage(): void {
		$batch        = new RecordingBatch( 'hook-registration-probe' );
		$batch->queue = array( array( 'chunk' => 'only' ) );
		$this->consumer->batches()->register( $batch );
		$result = $this->consumer->batches()->start( $batch->get_name(), self::ARGS );
		self::assertInstanceOf( Success::class, $result );

		for ( $delivery = 0; $delivery < 5; ++$delivery ) {
			$this->rig->run_due();
		}

		self::assertSame( array( self::ARGS ), $batch->generate_calls );
		self::assertCount( 1, $batch->process_calls );
		self::assertSame( array( 'chunk' => 'only' ), $batch->process_calls[0]['chunk_args'] );
		self::assertCount( 1, $batch->success_calls );
		$this->rig->assert_completed();
	}

	/**
	 * One explicit key blocks differing payloads only until its incumbent completes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dedup_key_collapses_different_arguments_until_completion_then_allows_reuse(): void {
		$dedup_key      = 'site-7-digest';
		$successor_args = array(
			'site_id' => 8,
			'mode'    => 'delta',
		);
		$first          = $this->consumer->tasks()->enqueue( self::NAME, self::ARGS, dedup_key: $dedup_key );
		self::assertInstanceOf( Success::class, $first );

		$this->rig->clock()->timestamp = self::NOW + 1;
		$duplicate                     = $this->consumer->tasks()->enqueue( self::NAME, $successor_args, dedup_key: $dedup_key );
		$this->assert_failure_code( $duplicate, ApiErrorCode::OverlapHeld );
		self::assertCount( 1, $this->run_delivery_calls() );

		$this->rig->run_due();
		self::assertSame( array( self::ARGS ), $this->task->calls );
		$this->rig->clock()->timestamp = self::NOW + 2;
		$reused                        = $this->consumer->tasks()->enqueue( self::NAME, $successor_args, dedup_key: $dedup_key );
		self::assertInstanceOf( Success::class, $reused );
		$this->rig->run_due();
		self::assertSame( array( self::ARGS, $successor_args ), $this->task->calls );
	}

	/**
	 * A delivered task executes once and exposes its completed public lifecycle.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_delivery_executes_and_completes_the_task(): void {
		$run_id = $this->enqueue_task();

		$this->rig->run_due();

		self::assertSame( array( self::ARGS ), $this->task->calls );
		self::assertSame(
			array(
				array( $run_id, self::ARGS ),
			),
			$this->rig->hooks()->fired( 'a8csp_background_tasks/completed/' . self::IDENTITY )
		);
		self::assertSame(
			array(
				array( self::IDENTITY, $run_id, self::ARGS ),
			),
			$this->rig->hooks()->fired( 'a8csp_background_tasks/completed' )
		);
		$this->rig->assert_completed();
	}

	/**
	 * A task without an override receives the shared callback liveness credit.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The callback observes the credited lock generation while production delivery owns it; no public result exposes an in-flight lease.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_delivery_credits_the_default_runtime_before_task_execution(): void {
		$this->assert_callback_lease( WorkInterface::DEFAULT_MAX_RUNTIME );
	}

	/**
	 * Delivery clamps invalid and runaway declarations before crediting callback liveness.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The callback-time lock generation is the concurrency contract that prevents a long-running owner from being reclaimed early.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $declared       Declared callback runtime.
	 * @param   int $expected_lease Expected credited runtime.
	 *
	 * @return  void
	 */
	#[DataProvider( 'bounded_runtime_values' )]
	public function test_run_delivery_bounds_the_declared_runtime( int $declared, int $expected_lease ): void {
		$this->task->max_runtime = $declared;
		$this->assert_callback_lease( $expected_lease );
	}

	/**
	 * A throwing runtime declaration falls back to the default lease.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The declaration failure occurs before the callback lease exists, so the in-flight lock bytes are the only evidence that fallback liveness was credited.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_delivery_defaults_the_lease_when_the_runtime_declaration_throws(): void {
		$this->task->max_runtime_throwable = new \RuntimeException( 'Runtime ceiling lookup exploded.' );
		$this->assert_callback_lease( WorkInterface::DEFAULT_MAX_RUNTIME );
	}

	/**
	 * An indeterminate admission fence performs no task, terminal hook, or storage write.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The nested read failure occurs between run-marker admission and lock-heartbeat authority; exact pre/post bytes prove the fail-closed path makes no write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_delivery_fails_closed_when_lock_heartbeat_read_fails(): void {
		$this->enqueue_task();
		$before                              = $this->relevant_rows();
		$this->rig->wpdb()->recorded_queries = array();
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->before_next(
					'select',
					static function ( WpdbLockSpy $lock_reader ): void {
						$lock_reader->last_error = 'transient heartbeat read failure';
					}
				);
			}
		);

		$this->rig->run_due();

		self::assertSame( array(), $this->task->calls );
		self::assertSame( $before, $this->relevant_rows() );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/completed' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_background_tasks/failed' ) );
		foreach ( $this->rig->wpdb()->recorded_queries as $query ) {
			self::assertStringStartsWith( 'SELECT ', $query );
		}
	}

	/**
	 * A reentrant same-sequence delivery cannot enter user code twice.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Re-entering the registered action while its execution marker is held proves duplicate scheduler delivery cannot cross the user-code fence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_delivery_drops_a_reentrant_same_sequence_delivery(): void {
		$this->enqueue_task();
		$reentered             = false;
		$this->task->on_handle = function () use ( &$reentered ): void {
			if ( $reentered ) {
				return;
			}

			$reentered = true;
			\do_action( 'a8csp_background_tasks/run', self::IDENTITY, self::RUN_ID, 1 );
		};

		$this->rig->run_due();

		self::assertTrue( $reentered );
		self::assertSame( array( self::ARGS ), $this->task->calls );
		$this->rig->assert_completed();
	}

	/**
	 * An expired delivery cannot shorten the execution lease credited to its replacement.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built run and lock generations stage the replacement between callback entry and incumbent completion, the race production generation fences must preserve.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_delivery_preserves_a_replacement_execution_lease(): void {
		$this->assert_replacement_generation_survives( null );
	}

	/**
	 * An expired throwing delivery cannot shorten its replacement's execution lease.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built run and lock generations stage replacement ownership before the incumbent enters failure adjudication.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_run_delivery_preserves_a_replacement_execution_lease(): void {
		$this->assert_replacement_generation_survives( new \RuntimeException( 'Expired attempt failed after replacement admission.' ) );
	}

	/**
	 * Retry-policy resolution cannot shorten a replacement admitted by the policy hook.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The legitimate retry-policy hook stages a fixture-built replacement after user code fails but before the incumbent failure transition writes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_policy_preserves_a_replacement_execution_lease(): void {
		$this->enqueue_task();
		$this->task->throwable = new \RuntimeException( 'Attempt failed before retry-policy resolution.' );
		$this->set_filter_value(
			'a8csp_background_tasks/retry_policy/' . self::IDENTITY,
			function ( RetryPolicy $policy ): RetryPolicy {
				$this->install_replacement_generation();

				return $policy;
			}
		);

		$this->rig->run_due();

		$this->assert_replacement_generation_is_retained();
	}

	/**
	 * Batch-shaped task arguments clear the marker so the accepted delivery can still run.
	 *
	 * @load-bearing security
	 * @pin-rationale A malformed scheduler payload is injected through the registered action boundary to prove it cannot strand the execution marker or reach user code.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_batch_argument_misdelivery_does_not_strand_the_task(): void {
		$this->enqueue_task();

		\do_action( 'a8csp_background_tasks/run', self::IDENTITY, self::RUN_ID, array( 'chunk' => 'misdelivered' ), 1 );
		self::assertSame( array(), $this->task->calls );
		$this->rig->run_due();

		self::assertSame( array( self::ARGS ), $this->task->calls );
		$this->rig->assert_completed();
	}

	/**
	 * An unregistered task delivery fails its live run instead of orphaning it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unregistered_task_delivery_terminalizes_the_live_run(): void {
		$this->rig->tear_down();
		$this->rig      = EngineRig::set_up( self::NOW );
		$this->consumer = $this->rig->consumer( self::OWNER );
		$this->fixtures = StoreFixtureBuilder::for_identity( self::IDENTITY );
		$this->seed_pending_run();

		\do_action( 'a8csp_background_tasks/run', self::IDENTITY, self::RUN_ID, 1 );

		$this->rig->assert_failed( ApiErrorCode::UnknownWork );
		$retry = $this->consumer->runs()->retry_failed( self::NAME, self::RUN_ID );
		$this->assert_failure_code( $retry, ApiErrorCode::UnknownWork );
	}

	/**
	 * Ownership loss during task work supersedes only the incumbent.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Production-built latest-pointer and foreign-lock bytes stage ownership loss inside user code so post-callback fencing can be observed without replacing production logic.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_task_work_that_loses_ownership_is_superseded(): void {
		$this->enqueue_task();
		$this->task->on_handle = function (): void {
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
			$this->put_fixture( $this->fixtures->lock( $this->args_hash(), 'run-newer', self::NOW + 90, self::NOW + 90 ) );
		};

		$this->rig->run_due();

		self::assertSame( array( self::ARGS ), $this->task->calls );
		$this->rig->assert_superseded();
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		$last_completed = $this->consumer->runs()->last_completed_run( self::NAME );
		self::assertInstanceOf( Success::class, $last_completed );
		self::assertNull( $last_completed->value );
	}

	/**
	 * Supplies invalid and runaway runtime declarations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{declared: int, expected_lease: int}>
	 */
	public static function bounded_runtime_values(): array {
		return array(
			'zero uses default'      => array(
				'declared'       => 0,
				'expected_lease' => 300,
			),
			'negative uses default'  => array(
				'declared'       => -1,
				'expected_lease' => 300,
			),
			'twenty-four hours caps' => array(
				'declared'       => 24 * 60 * 60,
				'expected_lease' => 6 * 60 * 60,
			),
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Enqueues the deterministic task through the owner-bound facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function enqueue_task(): string {
		$result = $this->consumer->tasks()->enqueue( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		return $result->value;
	}

	/**
	 * Asserts the task callback observes one exact credited lease.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $lease Expected runtime credit.
	 *
	 * @return  void
	 */
	private function assert_callback_lease( int $lease ): void {
		$observed              = null;
		$this->task->on_handle = function () use ( &$observed ): void {
			$observed = $this->lock();
		};
		$this->enqueue_task();
		$this->rig->clock()->timestamp = self::NOW + 90;

		$this->rig->run_due();

		self::assertIsArray( $observed );
		self::assertSame( self::NOW + 90 + $lease, $observed['heartbeat_at'] ?? null );
		$this->rig->assert_completed();
	}

	/**
	 * Runs one incumbent outcome after staging a replacement in its callback.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Throwable|null $throwable Optional incumbent failure.
	 *
	 * @return  void
	 */
	private function assert_replacement_generation_survives( ?\Throwable $throwable ): void {
		$this->enqueue_task();
		$this->task->throwable = $throwable;
		$this->task->on_handle = function (): void {
			$this->install_replacement_generation();
		};

		$this->rig->run_due();

		$this->assert_replacement_generation_is_retained();
	}

	/**
	 * Installs a production-serialized replacement generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function install_replacement_generation(): void {
		$credit = self::NOW + 90 + WorkInterface::DEFAULT_MAX_RUNTIME + 901 + WorkInterface::DEFAULT_MAX_RUNTIME;
		$state  = new RunState( status: RunStatus::Running, executing: true, start_args: self::ARGS, args_hash: $this->args_hash(), queue: array( self::ARGS ), failed_attempts: 0, action_seq: 1, created_at: self::NOW, heartbeat_at: $credit );
		$this->put_fixture( $this->fixtures->run( self::RUN_ID, $state ) );
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), self::RUN_ID, self::NOW, $credit ) );
	}

	/**
	 * Asserts the replacement generation remains executing with its future credit.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function assert_replacement_generation_is_retained(): void {
		$credit = self::NOW + 90 + WorkInterface::DEFAULT_MAX_RUNTIME + 901 + WorkInterface::DEFAULT_MAX_RUNTIME;
		$lock   = $this->lock();
		self::assertIsArray( $lock );
		self::assertSame( self::RUN_ID, $lock['run_id'] ?? null );
		self::assertSame( $credit, $lock['heartbeat_at'] ?? null );
		$run = $this->decoded_row( 'a8csp_bgte_run_' . self::IDENTITY . '_' . self::RUN_ID );
		self::assertIsArray( $run );
		self::assertTrue( $run['executing'] ?? false );
		self::assertSame( $credit, $run['heartbeat_at'] ?? null );
	}

	/**
	 * Seeds one pending task run without registering its implementation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function seed_pending_run(): void {
		$state = new RunState( status: RunStatus::Running, executing: false, start_args: self::ARGS, args_hash: $this->args_hash(), queue: array( self::ARGS ), failed_attempts: 0, action_seq: 1, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( 'run', 10 ) );
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
		$value = $this->decoded_row( 'a8csp_bgte_lock_' . self::IDENTITY . '_' . $this->args_hash() );

		return \is_array( $value ) ? $value : null;
	}

	/**
	 * Decodes one authoritative raw row.
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

		return \is_string( $raw ) ? \maybe_unserialize( $raw ) : null;
	}

	/**
	 * Returns all rows owned by the deterministic run and its lock.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, string>
	 */
	private function relevant_rows(): array {
		return \array_filter( $this->rig->wpdb()->rows, static fn ( string $key ): bool => \str_contains( $key, self::IDENTITY ), \ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Returns backend calls that accepted task-run delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function run_delivery_calls(): array {
		return \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => 'enqueue_async' === $call['verb'] && 'a8csp_background_tasks/run' === ( $call['args']['hook'] ?? null ) ) );
	}

	/**
	 * Asserts one mapped facade failure code.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed        $result Facade result.
	 * @param   ApiErrorCode $code   Expected public code.
	 *
	 * @return  ApiError
	 */
	private function assert_failure_code( mixed $result, ApiErrorCode $code ): ApiError {
		self::assertInstanceOf( Failure::class, $result );
		$error = $result->error;
		self::assertInstanceOf( ApiError::class, $error );
		self::assertSame( $code, $error->code );

		return $error;
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

	// endregion.
}
