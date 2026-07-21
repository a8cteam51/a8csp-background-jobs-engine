<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\ChunkContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkedJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\OneOffJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\InvalidChunkException;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises retry adjudication and failure transitions through real job deliveries.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( FailureLifecycle::class )]
final class FailureLifecycleTest extends TestCase {
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

		$this->rig    = EngineRig::set_up( self::NOW );
		$this->client = $this->rig->client( self::OWNER );
		$this->job    = new RecordingJob( self::NAME );
		$this->client->jobs()->register( $this->job );
		$this->fixtures                 = StoreFixtureBuilder::for_identity( self::IDENTITY );
		$this->rig->backend()->calls    = array();
		$this->rig->randomizer()->calls = array();
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
	 * A throwing job that loses ownership supersedes before retry adjudication.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Fixture-built foreign lock and pointer generations replace authority inside the real throwing callback before failure fencing.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_when_throwing_job_loses_ownership(): void {
		$this->job->throwable = new \RuntimeException( 'Job exploded.' );
		$this->job->on_handle = function (): void {
			$this->install_foreign_generation();
		};
		$this->enqueue_job();

		$this->rig->run_due();

		self::assertSame( array( self::ARGS ), $this->job->calls );
		$this->assert_foreign_superseded();
	}

	/**
	 * Ownership loss in the retry-policy filter wins over its terminal cap.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The policy hook installs a production-built foreign generation before returning a terminal policy, proving the expired attempt cannot fail it.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_before_retry_policy_cap_failure_after_ownership_loss(): void {
		$this->job->retry_policy = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->job->throwable    = new \RuntimeException( 'Transient failure.' );
		$this->set_filter_value(
			'a8csp_jobs_engine/retry_policy/' . self::IDENTITY,
			function (): RetryPolicy {
				$this->install_foreign_generation();

				return new RetryPolicy( max_attempts: 1 );
			}
		);
		$this->enqueue_job();

		$this->rig->run_due();

		self::assertSame( array(), $this->rig->randomizer()->calls );
		$this->assert_foreign_superseded();
	}

	/**
	 * Ownership loss in retry-scheduled listeners fences the retry successor.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The public retry-scheduled hook installs a fixture-built foreign generation after delay selection but before the retry scheduling write.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_before_retry_schedule_after_retry_scheduled_listener_ownership_loss(): void {
		$this->job->retry_policy        = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->job->throwable           = new \RuntimeException( 'Transient failure.' );
		$this->rig->randomizer()->value = 7;
		$this->observe_action(
			'a8csp_jobs_engine/retry_scheduled/' . self::IDENTITY,
			function (): void {
				$this->install_foreign_generation();
			}
		);
		$this->enqueue_job();

		$this->rig->run_due();

		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/retry_scheduled' ) );
		$this->assert_foreign_superseded();
	}

	/**
	 * An ordinary throwable below the cap schedules the same real run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_reschedules_an_ordinary_failure_below_the_cap(): void {
		$this->job->retry_policy        = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = 17;
		$this->enqueue_job();

		$this->rig->run_due();

		self::assertSame(
			array(
				array(
					'min' => 0,
					'max' => 30,
				),
			),
			$this->rig->randomizer()->calls
		);
		self::assertSame( array( self::IDENTITY, self::RUN_ID, self::ARGS, 1, 17 ), $this->latest_retry() );
		$this->rig->assert_retry_scheduled();
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' ) );
		self::assertCount( 1, $this->rig->logger()->records );
		self::assertSame( 'warning', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->rig->logger()->records[0]['context']['name'] ?? null );
		self::assertSame( self::RUN_ID, $this->rig->logger()->records[0]['context']['run_id'] ?? null );
		self::assertSame( 1, $this->rig->logger()->records[0]['context']['attempt'] ?? null );
		self::assertSame( 2, $this->rig->logger()->records[0]['context']['max_attempts'] ?? null );
		self::assertSame( 17, $this->rig->logger()->records[0]['context']['delay'] ?? null );
		self::assertSame( \RuntimeException::class, $this->rig->logger()->records[0]['context']['error_class'] ?? null );
	}

	/**
	 * A dual-interface contract registered as a job retains job retry routing.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The work registry records job while the object satisfies both interfaces; the exact retry hook and payload prove scheduling follows the registered kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_uses_the_registered_job_kind_for_a_dual_interface_contract(): void {
		$name                = 'dual-kind-job';
		$identity            = self::OWNER . ':' . $name;
		$terminal_failures   = 0;
		$on_terminal_failure = static function () use ( &$terminal_failures ): void {
			++$terminal_failures;
		};
		$this->client->jobs()->register( $this->dual_kind_job( $name, $on_terminal_failure ) );
		$this->rig->randomizer()->value = 42;
		$result                         = $this->client->jobs()->enqueue( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		$this->rig->randomizer()->value = 7;
		$this->rig->backend()->calls    = array();

		$this->rig->run_due();

		$calls = \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => 'schedule_single' === $call['verb'] ) );
		self::assertCount( 1, $calls );
		self::assertSame( 'a8csp_jobs_engine/run_job', $calls[0]['args']['hook'] ?? null );
		self::assertSame( array( $identity, $result->value, 2 ), $calls[0]['args']['args'] ?? null );

		$this->rig->run_due();

		self::assertSame( 1, $terminal_failures );
		$this->rig->assert_failed( ErrorCode::ExecutionFailed );
	}

	/**
	 * The retry call site requests full-jitter bounds and passes the recorded delay to the retry hook and schedule.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $recorded_delay Deterministic randomizer result.
	 *
	 * @return  void
	 */
	#[DataProvider( 'recorded_jitter_delay' )]
	public function test_retry_call_site_requests_full_jitter_bounds_and_passes_recorded_delay_to_retry_hook_and_schedule( int $recorded_delay ): void {
		$this->job->retry_policy        = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = $recorded_delay;
		$this->enqueue_job();

		$this->rig->run_due();

		self::assertSame(
			array(
				array(
					'min' => 0,
					'max' => 30,
				),
			),
			$this->rig->randomizer()->calls
		);
		self::assertSame( $recorded_delay, $this->latest_retry()[4] ?? null );
		self::assertSame( self::NOW + $recorded_delay, $this->single_retry_call()['args']['timestamp'] ?? null );
	}

	/**
	 * Supplies one deterministic delay for the retry-hook and scheduling pass-through assertions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{recorded_delay: int}>
	 */
	public static function recorded_jitter_delay(): array {
		return array(
			'recorded delay passes through' => array(
				'recorded_delay' => 19,
			),
		);
	}

	/**
	 * A two-attempt policy executes exactly twice and publishes the exhausted count.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_stops_exactly_at_the_max_attempts_boundary(): void {
		$this->job->retry_policy        = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = 5;
		$this->enqueue_job();

		$this->rig->run_due();
		$this->rig->run_due();

		self::assertSame( array( self::ARGS, self::ARGS ), $this->job->calls );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/retry_scheduled' ) );
		$failure = $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::Execution );
		self::assertSame( 2, $failure->attempts );
	}

	/**
	 * The identity-specific RetryPolicy replacement controls the terminal cap.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_honors_the_name_specific_retry_policy_filter(): void {
		$contract                = new RetryPolicy( max_attempts: 3 );
		$observed                = null;
		$this->job->retry_policy = $contract;
		$this->job->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value(
			'a8csp_jobs_engine/retry_policy/' . self::IDENTITY,
			static function ( RetryPolicy $policy ) use ( &$observed ): RetryPolicy {
				$observed = $policy;

				return new RetryPolicy( max_attempts: 1 );
			}
		);
		$this->enqueue_job();

		$this->rig->run_due();

		self::assertSame( $contract, $observed );
		self::assertSame( 1, $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::Execution )->attempts );
		$this->rig->assert_no_retry();
	}

	/**
	 * Failure adjudication resets callback credit before policy resolution.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Callback-time production lock and run bytes are the only evidence that long-runtime credit is removed before user-controlled policy code runs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_resets_callback_credit_before_retry_policy_resolution(): void {
		$lock                            = null;
		$run                             = null;
		$this->job->max_callback_runtime = 1_200;
		$this->job->retry_policy         = new RetryPolicy( max_attempts: 1 );
		$this->job->throwable            = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value(
			'a8csp_jobs_engine/retry_policy/' . self::IDENTITY,
			function ( RetryPolicy $policy ) use ( &$lock, &$run ): RetryPolicy {
				$lock = $this->lock();
				$run  = $this->run_state();

				return $policy;
			}
		);
		$this->enqueue_job();
		$this->rig->clock()->timestamp = self::NOW + 90;

		$this->rig->run_due();

		self::assertSame( self::NOW + 90, $lock['heartbeat_at'] ?? null );
		self::assertSame( self::NOW + 90, $run['heartbeat_at'] ?? null );
		self::assertTrue( $run['executing'] ?? false );
	}

	/**
	 * A foreign policy value falls back to the contract and records its warning.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_falls_back_and_warns_for_a_foreign_retry_policy(): void {
		$this->job->retry_policy        = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = 7;
		$this->set_filter_value( 'a8csp_jobs_engine/retry_policy/' . self::IDENTITY, 'invalid-policy' );
		$this->enqueue_job();

		$this->rig->run_due();

		self::assertSame( 7, $this->latest_retry()[4] ?? null );
		self::assertNotEmpty( $this->rig->logger()->records );
		self::assertSame( 'warning', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->rig->logger()->records[0]['context']['name'] ?? null );
		self::assertSame( 'string', $this->rig->logger()->records[0]['context']['returned_type'] ?? null );
	}

	/**
	 * A throwing retry-policy filter terminalizes instead of stalling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_throwing_retry_policy_filter(): void {
		$this->job->retry_policy = new RetryPolicy( max_attempts: 2 );
		$this->job->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value(
			'a8csp_jobs_engine/retry_policy/' . self::IDENTITY,
			static function (): never {
				throw new \DomainException( 'Retry policy filter exploded.' );
			}
		);
		$this->enqueue_job();

		$this->rig->run_due();

		$failure = $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::Execution );
		self::assertSame( 1, $failure->attempts );
		self::assertSame( array(), $this->rig->randomizer()->calls );
	}

	/**
	 * A throwing policy filter that loses ownership supersedes instead.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The policy filter installs fixture-built foreign ownership before throwing, so terminal retention must not target the expired generation.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_when_throwing_retry_policy_filter_loses_ownership(): void {
		$this->job->retry_policy = new RetryPolicy( max_attempts: 2 );
		$this->job->throwable    = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value(
			'a8csp_jobs_engine/retry_policy/' . self::IDENTITY,
			function (): never {
				$this->install_foreign_generation();

				throw new \DomainException( 'Retry policy filter exploded.' );
			}
		);
		$this->enqueue_job();

		$this->rig->run_due();

		$this->assert_foreign_superseded();
		self::assertSame( array(), $this->rig->randomizer()->calls );
	}

	/**
	 * A throwing retry-scheduled listener terminalizes after both public retry-scheduled hooks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_throwing_retry_scheduled_listener(): void {
		$this->job->retry_policy        = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = 7;
		$this->set_action_throwable( 'a8csp_jobs_engine/retry_scheduled/' . self::IDENTITY, new \RuntimeException( 'Retry-scheduled listener exploded.' ) );
		$this->enqueue_job();

		$this->rig->run_due();

		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/retry_scheduled' ) );
		$this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::Execution );
	}

	/**
	 * Ownership loss after a retry-scheduled listener error supersedes before failure retention.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The public retry-scheduled hook installs a fixture-built foreign generation before its scripted throwable reaches failure preparation.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_supersedes_after_retry_preparation_error_loses_ownership(): void {
		$this->job->retry_policy        = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = 7;
		$this->observe_action(
			'a8csp_jobs_engine/retry_scheduled/' . self::IDENTITY,
			function (): void {
				$this->install_foreign_generation();
			}
		);
		$this->set_action_throwable( 'a8csp_jobs_engine/retry_scheduled/' . self::IDENTITY, new \RuntimeException( 'Retry-scheduled listener exploded.' ) );
		$this->enqueue_job();

		$this->rig->run_due();

		$this->assert_foreign_superseded();
	}

	/**
	 * A retry scheduling failure identifies its public failure stage.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The retry-scheduled hooks precede the rejected write; an empty delivery boundary proves terminalization leaves no delayed retry generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_terminalizes_a_retry_reschedule_failure(): void {
		$this->job->retry_policy                          = new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
		$this->job->throwable                             = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value                   = 7;
		$this->rig->backend()->results['schedule_single'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore the scheduler before retrying the job.' ) );
		$this->enqueue_job();

		$this->rig->run_due();

		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_jobs_engine/retry_scheduled' ) );
		$this->assert_failure( ErrorCode::BackendRejected, RunFailureStage::Scheduling );
		$this->rig->assert_no_delivery( self::IDENTITY );
	}

	/**
	 * A one-attempt ordinary policy enters terminal failure immediately.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_fails_terminally_when_the_policy_has_no_retry(): void {
		$this->job->retry_policy = new RetryPolicy( max_attempts: 1 );

		$this->assert_terminal_job_failure( new \RuntimeException( 'Database unavailable.' ) );
	}

	/**
	 * A non-retryable throwable enters terminal failure immediately.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_fails_terminally_for_a_non_retryable_exception(): void {
		$this->assert_terminal_job_failure( new NonRetryableException( 'The request is permanently invalid.' ) );
	}

	/**
	 * A chunked-job-only validation subtype remains a generic job failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_chunked_job_validation_exception_is_not_reclassified_on_the_job_path(): void {
		$this->job->retry_policy = new RetryPolicy( max_attempts: 1 );

		$this->assert_terminal_job_failure( InvalidChunkException::nonPortable() );
	}

	/**
	 * A chunked job validation failure retains its engine-authored byte-limit diagnostic.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_chunked_job_validation_exception_retains_its_engine_authored_diagnostic(): void {
		$chunked_job                    = new RecordingChunkedJob( 'bounded-chunked-job' );
		$chunked_job->queue             = array( array( 'chunk' => 'current' ) );
		$chunked_job->retry_policy      = new RetryPolicy( max_attempts: 1 );
		$chunked_job->process_throwable = InvalidChunkException::chunkTooLarge( 8_193, 8_192 );
		$this->client->chunked_jobs()->register( $chunked_job );
		$result = $this->client->chunked_jobs()->start( 'bounded-chunked-job', self::ARGS );
		self::assertInstanceOf( Success::class, $result );

		for ( $delivery = 0; $delivery < 2; ++$delivery ) {
			$this->rig->run_due();
		}

		$failure = $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::Execution );
		self::assertSame( 'Chunked Job chunk arguments contain 8193 JSON bytes; the limit is 8192 bytes.', $failure->summary );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Enqueues the deterministic job through its owner-bound facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function enqueue_job(): string {
		$retry_value                    = $this->rig->randomizer()->value;
		$this->rig->randomizer()->value = 42;
		$result                         = $this->client->jobs()->enqueue( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$this->rig->randomizer()->value = $retry_value;
		$this->rig->randomizer()->calls = array();

		return $result->value;
	}

	/**
	 * Executes and asserts one immediate terminal job failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Throwable $throwable Job failure.
	 *
	 * @return  void
	 */
	private function assert_terminal_job_failure( \Throwable $throwable ): void {
		$this->job->throwable = $throwable;
		$this->enqueue_job();

		$this->rig->run_due();

		$failure = $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::Execution );
		self::assertSame( 1, $failure->attempts );
		$this->rig->assert_no_retry();
	}

	/**
	 * Creates one throwing contract that satisfies both work-kind interfaces.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $name                Job name.
	 * @param   \Closure $on_terminal_failure Records the terminal callback.
	 *
	 * @return  OneOffJobInterface
	 */
	private function dual_kind_job( string $name, \Closure $on_terminal_failure ): OneOffJobInterface {
		return new class( $name, $on_terminal_failure ) implements OneOffJobInterface, ChunkedJobInterface {
			/**
			 * Constructor.
			 *
			 * @param   string   $name                Job name.
			 * @param   \Closure $on_terminal_failure Records the terminal callback.
			 */
			public function __construct(
				private readonly string $name,
				private readonly \Closure $on_terminal_failure,
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

			/** {@inheritDoc} */
			#[\Override]
			public function overlap_policy(): OverlapPolicy {
				return OverlapPolicy::Reject;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function overlap_key( array $start_args ): ?string {
				return null;
			}

			/**
			 * Fails every attempt with a retryable throwable.
			 *
			 * @param   array<array-key, mixed> $args    Job arguments.
			 * @param   RunContext     $context Controlled access to this run.
			 *
			 * @return  void
			 */
			#[\Override]
			public function handle( array $args, RunContext $context ): void {
				throw new \RuntimeException( 'Database unavailable.' );
			}

			/** {@inheritDoc} */
			#[\Override]
			public function get_retry_policy(): RetryPolicy {
				return new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 );
			}

			/**
			 * Returns an empty queue.
			 *
			 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
			 * @param   RunContext     $context    Controlled access to this run.
			 *
			 * @return  iterable<array<array-key, mixed>>
			 */
			#[\Override]
			public function generate_queue( array $start_args, RunContext $context ): iterable {
				return array();
			}

			/**
			 * Processes nothing.
			 *
			 * @param   array<array-key, mixed> $chunk_args Arguments for this chunk.
			 * @param   ChunkContext   $context    Controlled access to this chunk's run.
			 *
			 * @return  void
			 */
			#[\Override]
			public function process_chunk( array $chunk_args, ChunkContext $context ): void {}

			/**
			 * Observes nothing.
			 *
			 * @param   string                  $run_id                    Run identifier.
			 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
			 * @param   string|null             $previous_completed_run_id Previous completed run identifier for this identity, or null.
			 *
			 * @return  void
			 */
			#[\Override]
			public function on_completed( string $run_id, array $start_args, ?string $previous_completed_run_id ): void {}

			/**
			 * Records the terminal callback.
			 *
			 * @param   string                  $run_id     Run identifier.
			 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
			 * @param   RunFailure              $failure    Persisted terminal-failure value.
			 *
			 * @return  void
			 */
			#[\Override]
			public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void {
				( $this->on_terminal_failure )();
			}
		};
	}

	/**
	 * Installs one production-built foreign lock and latest-pointer generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function install_foreign_generation(): void {
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
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), 'run-newer', self::NOW, self::NOW ) );
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
	 * @param   ErrorCode    $code  Expected failure code.
	 * @param   RunFailureStage $stage Expected failure stage.
	 *
	 * @return  RunFailure
	 */
	private function assert_failure( ErrorCode $code, RunFailureStage $stage ): RunFailure {
		$events = $this->rig->hooks()->fired( 'a8csp_jobs_engine/failed' );
		self::assertNotEmpty( $events );
		$latest = $events[ \count( $events ) - 1 ];
		self::assertCount( 1, $latest );
		$failure = $latest[0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( $code, $failure->code );
		self::assertSame( $stage, $failure->stage );

		return $failure;
	}

	/**
	 * Returns the latest generic retry-scheduled hook payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<mixed>
	 */
	private function latest_retry(): array {
		$events = $this->rig->hooks()->fired( 'a8csp_jobs_engine/retry_scheduled' );
		self::assertNotEmpty( $events );

		return $events[ \count( $events ) - 1 ];
	}

	/**
	 * Returns the only accepted job-retry scheduling call.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{verb: string, args: array<string, mixed>}
	 */
	private function single_retry_call(): array {
		$calls = \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => 'schedule_single' === $call['verb'] && 'a8csp_jobs_engine/run_job' === ( $call['args']['hook'] ?? null ) ) );
		self::assertCount( 1, $calls );
		self::assertSame( array( self::IDENTITY, self::RUN_ID, 2 ), $calls[0]['args']['args'] ?? null );

		return $calls[0];
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
	 * Decodes one authoritative row or its initial option-seam value.
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
		if ( null !== $raw ) {
			return \is_string( $raw ) ? \maybe_unserialize( $raw ) : null;
		}

		$options = $GLOBALS['a8csp_bgje_test_options'] ?? null;
		self::assertIsArray( $options );

		return $options[ $option_name ] ?? null;
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
	 * @param   \Closure $observe   Observation callback.
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
