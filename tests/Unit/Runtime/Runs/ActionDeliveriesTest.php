<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises job deliveries through the registered production action graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ActionDeliveries::class )]
final class ActionDeliveriesTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS      = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const string IDENTITY = self::OWNER . ':' . self::NAME;
	private const string NAME     = 'email-digest';
	private const int NOW         = 1_700_000_000;
	private const string OWNER    = 'runs-tests';
	private const string RUN_ID   = '00000000001700000000-0000000000000000042';

	private Client $client;
	private StoreFixtureBuilder $fixtures;
	private EngineRig $rig;
	private RecordingJob $job;

	/** @var (\Closure(array<array-key, mixed>): ?string)|null */
	private ?\Closure $overlap_key_resolver = null;

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
	 * Boots one registered job against deterministic interface fakes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->boot();
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
	public function test_registered_delivery_hooks_drive_every_chunked_job_stage(): void {
		$chunked_job        = new RecordingChunkedJob( 'hook-registration-probe' );
		$chunked_job->queue = array( array( 'chunk' => 'only' ) );
		$this->client->jobs()->register( $chunked_job->definition() );
		$result = $this->client->chunked_jobs()->start( 'hook-registration-probe', self::ARGS );
		self::assertInstanceOf( Success::class, $result );

		for ( $delivery = 0; $delivery < 4; ++$delivery ) {
			$this->rig->run_due();
		}

		self::assertSame( array( self::ARGS ), $chunked_job->generate_calls );
		self::assertCount( 1, $chunked_job->generate_contexts );
		self::assertInstanceOf( RunId::class, $chunked_job->generate_contexts[0]->get_run_id() );
		self::assertSame( $result->value, (string) $chunked_job->generate_contexts[0]->get_run_id() );
		self::assertSame( self::ARGS, $chunked_job->generate_contexts[0]->get_start_args() );
		self::assertCount( 1, $chunked_job->process_calls );
		self::assertSame( array( 'chunk' => 'only' ), $chunked_job->process_calls[0]['chunk_args'] );
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
	public function test_overlap_key_collapses_different_arguments_until_completion_then_allows_reuse(): void {
		$this->overlap_key_resolver = static fn ( array $args ): string => 'site-7-digest';

		$successor_args = array(
			'site_id' => 8,
			'mode'    => 'delta',
		);
		$first          = $this->client->jobs()->enqueue( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $first );

		$this->rig->clock()->timestamp = self::NOW + 1;
		$duplicate                     = $this->client->jobs()->enqueue( self::NAME, $successor_args );
		$this->assert_failure_code( $duplicate, ErrorCode::OverlapHeld );
		self::assertCount( 1, $this->run_delivery_calls() );

		$this->rig->run_due();
		self::assertSame( array( self::ARGS ), $this->job->calls );
		$this->rig->clock()->timestamp = self::NOW + 2;
		$reused                        = $this->client->jobs()->enqueue( self::NAME, $successor_args );
		self::assertInstanceOf( Success::class, $reused );
		$this->rig->run_due();
		self::assertSame( array( self::ARGS, $successor_args ), $this->job->calls );
	}

	/**
	 * A delivered job executes once and exposes its completed public lifecycle.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_delivery_executes_and_completes_the_job(): void {
		$run_id = $this->enqueue_job();

		$this->rig->run_due();

		self::assertSame( array( self::ARGS ), $this->job->calls );
		self::assertCount( 1, $this->job->contexts );
		self::assertSame( $run_id, (string) $this->job->contexts[0]->get_run_id() );
		self::assertSame( self::ARGS, $this->job->contexts[0]->get_start_args() );
		$named_completed = $this->rig->hooks()->fired( 'a8csp_jobs_engine/completed/' . self::IDENTITY );
		$public_run_id   = $named_completed[0][0] ?? null;
		self::assertInstanceOf( RunId::class, $public_run_id );
		self::assertSame( $run_id, (string) $public_run_id );
		self::assertSame(
			array(
				array( $public_run_id, self::ARGS, null ),
			),
			$named_completed
		);
		self::assertSame(
			array(
				array( self::IDENTITY, $public_run_id, self::ARGS, null ),
			),
			$this->rig->hooks()->fired( 'a8csp_jobs_engine/completed' )
		);
		$this->rig->assert_completed();
	}

	/**
	 * A terminal one-off failure emits one public failed hook payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_terminal_failure_emits_the_failed_hook_once(): void {
		$this->restart_with_options( new JobOptions( retry: new RetryPolicy( max_attempts: 1 ) ) );
		$this->job->throwable = new \RuntimeException( 'Terminal job failure.' );
		$run_id               = $this->enqueue_job();

		$this->rig->run_due();

		$failed = $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' );
		self::assertCount( 1, $failed );
		$failure = $failed[0][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( $run_id, (string) $failure->run_id );
		self::assertSame( self::IDENTITY, $failure->identity );
		$this->rig->assert_failed( ErrorCode::ExecutionFailed );
	}

	/**
	 * A job without an override receives the shared execution liveness credit.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The execution observes the credited lock generation while production delivery owns it; no public result exposes an in-flight lease.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_delivery_credits_the_default_runtime_before_job_execution(): void {
		$this->assert_execution_lease( LockWindows::DEFAULT_EXECUTION_LEASE );
	}

	/**
	 * Delivery clamps invalid and runaway declarations before crediting execution liveness.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The execution-time lock generation is the concurrency contract that prevents a long-running owner from being reclaimed early.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $declared       Declared execution runtime.
	 * @param   int $expected_lease Expected credited runtime.
	 *
	 * @return  void
	 */
	#[DataProvider( 'bounded_runtime_values' )]
	public function test_run_delivery_bounds_the_declared_runtime( int $declared, int $expected_lease ): void {
		$this->restart_with_options( new JobOptions( max_runtime: $declared ) );
		$this->assert_execution_lease( $expected_lease );
	}

	/**
	 * An indeterminate admission fence performs no job, terminal hook, or storage write.
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
		$this->enqueue_job();
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

		self::assertSame( array(), $this->job->calls );
		self::assertSame( $before, $this->relevant_rows() );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/completed' ) );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );
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
		$this->enqueue_job();
		$reentered            = false;
		$this->job->on_handle = function () use ( &$reentered ): void {
			if ( $reentered ) {
				return;
			}

			$reentered = true;
			\do_action( ActionDeliveries::DELIVER_HOOK, self::IDENTITY, self::RUN_ID, 1 );
		};

		$this->rig->run_due();

		self::assertTrue( $reentered );
		self::assertSame( array( self::ARGS ), $this->job->calls );
		$this->rig->assert_completed();
	}

	/**
	 * A delivery payload with a non-integer sequence is rejected before admission.
	 *
	 * @load-bearing security
	 * @pin-rationale Direct registered-hook delivery proves the typed job boundary rejects a chunk-shaped payload without mutating authoritative run state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_deliver_handler_rejects_a_non_integer_sequence_before_admission(): void {
		$this->enqueue_job();
		$before = $this->relevant_rows();
		$thrown = null;

		try {
			\do_action( ActionDeliveries::DELIVER_HOOK, self::IDENTITY, self::RUN_ID, array( 'chunk' => 'misdelivered' ) );
		} catch ( \TypeError $error ) {
			$thrown = $error;
		}

		self::assertInstanceOf( \TypeError::class, $thrown );
		self::assertSame( $before, $this->relevant_rows() );
		self::assertSame( array(), $this->job->calls );
	}

	/**
	 * An expired delivery cannot shorten the execution lease credited to its replacement.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built run and lock generations stage the replacement between execution entry and incumbent completion, the race production generation fences must preserve.
	 * @fixture StoreFixtureBuilder
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
	 * @fixture StoreFixtureBuilder
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
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_policy_preserves_a_replacement_execution_lease(): void {
		$this->enqueue_job();
		$this->job->throwable = new \RuntimeException( 'Attempt failed before retry-policy resolution.' );
		$this->set_filter_value(
			'a8csp_jobs_engine/retry_policy/' . self::IDENTITY,
			function ( RetryPolicy $policy ): RetryPolicy {
				$this->install_replacement_generation();

				return $policy;
			}
		);

		$this->rig->run_due();

		$this->assert_replacement_generation_is_retained();
	}

	/**
	 * An unregistered job delivery fails its live run instead of orphaning it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unregistered_job_delivery_terminalizes_the_live_run(): void {
		$this->rig->tear_down();
		$this->rig      = EngineRig::set_up( self::NOW );
		$this->client   = $this->rig->client( self::OWNER );
		$this->fixtures = StoreFixtureBuilder::for_identity( self::IDENTITY );
		$this->seed_pending_run();

		\do_action( ActionDeliveries::DELIVER_HOOK, self::IDENTITY, self::RUN_ID, 1 );

		$this->rig->assert_failed( ErrorCode::UnknownWork );
		$retry = $this->client->runs()->retry_failed( self::NAME, self::RUN_ID );
		$this->assert_failure_code( $retry, ErrorCode::UnknownWork );
	}

	/**
	 * Ownership loss during job work supersedes only the incumbent.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Production-built latest-pointer and foreign-lock bytes stage ownership loss inside user code so post-execution fencing can be observed without replacing production logic.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_job_work_that_loses_ownership_is_superseded(): void {
		$this->enqueue_job();
		$this->job->on_handle = function (): void {
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

		self::assertSame( array( self::ARGS ), $this->job->calls );
		$this->rig->assert_superseded();
		self::assertSame( 'run-newer', $this->lock()['run_id'] ?? null );
		$last_completed = $this->client->runs()->last_completed_run_id( self::NAME );
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
	 * Boots the deterministic graph with one job definition.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobOptions|null $options Optional policy declaration.
	 *
	 * @return  void
	 */
	private function boot( ?JobOptions $options = null ): void {
		$this->overlap_key_resolver = null;
		$this->rig                  = EngineRig::set_up( self::NOW );
		$this->client               = $this->rig->client( self::OWNER );
		$this->job                  = new RecordingJob( self::NAME );
		if ( null === $options ) {
			$options = new JobOptions(
				overlap_key: function ( array $args ): ?string {
					if ( null === $this->overlap_key_resolver ) {
						return null;
					}

					return ( $this->overlap_key_resolver )( $args );
				}
			);
		}
		$this->client->jobs()->register( $this->job->definition( $options ) );
		$this->fixtures = StoreFixtureBuilder::for_identity( self::IDENTITY );
	}

	/**
	 * Rebuilds the graph with one explicit policy declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobOptions $options Policy declaration.
	 *
	 * @return  void
	 */
	private function restart_with_options( JobOptions $options ): void {
		$this->rig->tear_down();
		$this->boot( $options );
	}

	/**
	 * Enqueues the deterministic job through the owner-bound facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function enqueue_job(): string {
		$result = $this->client->jobs()->enqueue( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );

		return $result->value;
	}

	/**
	 * Asserts the job execution observes one exact credited lease.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $lease Expected runtime credit.
	 *
	 * @return  void
	 */
	private function assert_execution_lease( int $lease ): void {
		$observed             = null;
		$this->job->on_handle = function () use ( &$observed ): void {
			$observed = $this->lock();
		};
		$this->enqueue_job();
		$this->rig->clock()->timestamp = self::NOW + 90;

		$this->rig->run_due();

		self::assertIsArray( $observed );
		self::assertSame( self::NOW + 90 + $lease, $observed['heartbeat_at'] ?? null );
		$this->rig->assert_completed();
	}

	/**
	 * Runs one incumbent outcome after staging a replacement during execution.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Throwable|null $throwable Optional incumbent failure.
	 *
	 * @return  void
	 */
	private function assert_replacement_generation_survives( ?\Throwable $throwable ): void {
		$this->enqueue_job();
		$this->job->throwable = $throwable;
		$this->job->on_handle = function (): void {
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
		$credit = self::NOW + 90 + LockWindows::DEFAULT_EXECUTION_LEASE + 901 + LockWindows::DEFAULT_EXECUTION_LEASE;
		$state  = new RunState( status: RunStatus::Running, kind: 'job', executing: true, start_args: self::ARGS, args_hash: $this->args_hash(), kind_state: array(), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: $credit );
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
		$credit = self::NOW + 90 + LockWindows::DEFAULT_EXECUTION_LEASE + 901 + LockWindows::DEFAULT_EXECUTION_LEASE;
		$lock   = $this->lock();
		self::assertIsArray( $lock );
		self::assertSame( self::RUN_ID, $lock['run_id'] ?? null );
		self::assertSame( $credit, $lock['heartbeat_at'] ?? null );
		$run = $this->decoded_row( 'a8csp_bgje_run_' . self::IDENTITY . '_' . self::RUN_ID );
		self::assertIsArray( $run );
		self::assertTrue( $run['executing'] ?? false );
		self::assertSame( $credit, $run['heartbeat_at'] ?? null );
	}

	/**
	 * Seeds one pending job run without registering its implementation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function seed_pending_run(): void {
		$state = new RunState( status: RunStatus::Running, kind: 'job', executing: false, start_args: self::ARGS, args_hash: $this->args_hash(), kind_state: array(), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( 'run', 10 ) );
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
		$value = $this->decoded_row( 'a8csp_bgje_overlap_lock_' . self::IDENTITY . '_' . $this->args_hash() );

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
	 * Returns backend calls that accepted job-run delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function run_delivery_calls(): array {
		return \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => 'enqueue_async' === $call['verb'] && ActionDeliveries::DELIVER_HOOK === ( $call['args']['hook'] ?? null ) ) );
	}

	/**
	 * Asserts one mapped facade failure code.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed        $result Facade result.
	 * @param   ErrorCode $code   Expected public code.
	 *
	 * @return  ApiError
	 */
	private function assert_failure_code( mixed $result, ErrorCode $code ): ApiError {
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
		$filters = $GLOBALS['a8csp_bgje_test_filter_values'] ?? null;
		self::assertIsArray( $filters );
		$filters[ $hook_name ]                    = $value;
		$GLOBALS['a8csp_bgje_test_filter_values'] = $filters;
	}

	// endregion.
}
