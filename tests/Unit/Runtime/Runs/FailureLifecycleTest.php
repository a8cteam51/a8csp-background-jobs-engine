<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkedJobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\OwnerOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\InvalidChunkException;
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

	private OwnerOperations $client;
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
	 * Boots one job execution fixture against deterministic interface fakes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig                      = EngineRig::set_up( self::NOW );
		$this->client                   = $this->rig->operations( self::OWNER );
		$this->job                      = new RecordingJob( self::NAME );
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
	 * @pin-rationale Fixture-built foreign lock and pointer generations replace authority inside the real throwing execution before failure fencing.
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
		$this->job->throwable = new \RuntimeException( 'Transient failure.' );
		$this->set_filter_value(
			'a8csp_bgje/retry_policy/' . self::IDENTITY,
			function (): RetryPolicy {
				$this->install_foreign_generation();

				return new RetryPolicy( max_attempts: 1 );
			}
		);
		$this->enqueue_job( new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) ) );

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
		$this->job->throwable           = new \RuntimeException( 'Transient failure.' );
		$this->rig->randomizer()->value = 7;
		$this->observe_action(
			'a8csp_bgje/retry_scheduled/' . self::IDENTITY,
			function (): void {
				$this->install_foreign_generation();
			}
		);
		$this->enqueue_job( new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) ) );

		$this->rig->run_due();

		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_bgje/retry_scheduled' ) );
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
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = 17;
		$this->enqueue_job( new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) ) );

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
		$run = $this->run_state();
		self::assertIsArray( $run );
		self::assertSame( 'job', $run['kind'] ?? null );
		$pending = $run['pending'] ?? null;
		self::assertIsArray( $pending );
		self::assertSame( 'run', $pending['stage'] ?? null );
		self::assertSame( 'single', $pending['mode'] ?? null );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_bgje/failed' ) );
		self::assertCount( 1, $this->rig->logger()->records );
		self::assertSame( 'warning', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->rig->logger()->records[0]['context']['identity'] ?? null );
		self::assertSame( self::RUN_ID, $this->rig->logger()->records[0]['context']['run_id'] ?? null );
		self::assertSame( 1, $this->rig->logger()->records[0]['context']['attempt'] ?? null );
		self::assertSame( 2, $this->rig->logger()->records[0]['context']['max_attempts'] ?? null );
		self::assertSame( 17, $this->rig->logger()->records[0]['context']['delay'] ?? null );
		self::assertSame( \RuntimeException::class, $this->rig->logger()->records[0]['context']['error_class'] ?? null );
	}

	/**
	 * A throwing retry-state write records the persisted job kind for reconciliation diagnostics.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_state_persistence_failure_logs_the_job_kind(): void {
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = 7;
		$this->enqueue_job( new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) ) );
		for ( $attempt = 0; 6 > $attempt; ++$attempt ) {
			$this->rig->wpdb()->before_next( 'update', static function (): void {} );
		}
		$this->rig->wpdb()->before_next(
			'update',
			static function (): never {
				throw new \RuntimeException( 'Retry state write exploded.' );
			}
		);

		$this->rig->run_due();

		self::assertCount( 1, $this->rig->logger()->records );
		self::assertSame( 'warning', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( 'Retry state could not be persisted; the reconciliation sweep retains the run until storage recovers.', $this->rig->logger()->records[0]['message'] ?? null );
		self::assertSame( self::IDENTITY, $this->rig->logger()->records[0]['context']['identity'] ?? null );
		self::assertSame( self::RUN_ID, $this->rig->logger()->records[0]['context']['run_id'] ?? null );
		self::assertSame( 'job', $this->rig->logger()->records[0]['context']['kind'] ?? null );
		self::assertSame( \RuntimeException::class, $this->rig->logger()->records[0]['context']['exception_class'] ?? null );
	}

	/**
	 * A dual-role execution registered as a job retains job retry routing.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The definition records job while the execution object satisfies both roles; the persisted lowercase kind and run-stage retry prove the job handler owns the generic delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_uses_the_registered_job_kind_for_a_dual_role_execution(): void {
		$name     = 'dual-kind-job';
		$identity = self::OWNER . ':' . $name;
		$this->client->register( $this->dual_kind_job( $name ) );
		$this->rig->randomizer()->value = 42;
		$result                         = $this->client->dispatch( $name, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );
		$this->rig->randomizer()->value = 7;
		$this->rig->backend()->calls    = array();

		$this->rig->run_due();

		$run = $this->run_state_for( $identity, $result->value );
		self::assertIsArray( $run );
		self::assertSame( 'job', $run['kind'] ?? null );
		$pending = $run['pending'] ?? null;
		self::assertIsArray( $pending );
		self::assertSame( 'run', $pending['stage'] ?? null );
		self::assertSame( 'single', $pending['mode'] ?? null );
		$calls = \array_values(
			\array_filter(
				$this->rig->backend()->calls,
				static function ( array $call ) use ( $identity ): bool {
					$args = $call['args']['args'] ?? null;

					return \is_array( $args )
						&& 'schedule_single' === $call['verb']
						&& ( $args[0] ?? null ) === $identity;
				}
			)
		);
		self::assertCount( 1, $calls );
		self::assertSame( 'a8csp_bgje/internal/deliver', $calls[0]['args']['hook'] ?? null );
		self::assertSame( array( $identity, $result->value, 2 ), $calls[0]['args']['args'] ?? null );

		$this->rig->run_due();

		$failed = $this->rig->hooks()->fired( 'a8csp_bgje/failed' );
		self::assertCount( 1, $failed );
		self::assertCount( 1, $failed[0] );
		$failure = $failed[0][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( $identity, $failure->identity );
		self::assertSame( $result->value, (string) $failure->run_id );
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
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = $recorded_delay;
		$this->enqueue_job( new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) ) );

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
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = 5;
		$this->enqueue_job( new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) ) );

		$this->rig->run_due();
		$this->rig->run_due();

		self::assertSame( array( self::ARGS, self::ARGS ), $this->job->calls );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_bgje/retry_scheduled' ) );
		$failure = $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::execution() );
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
		$definition_policy    = new RetryPolicy( max_attempts: 3 );
		$observed             = null;
		$this->job->throwable = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value(
			'a8csp_bgje/retry_policy/' . self::IDENTITY,
			static function ( RetryPolicy $policy ) use ( &$observed ): RetryPolicy {
				$observed = $policy;

				return new RetryPolicy( max_attempts: 1 );
			}
		);
		$this->enqueue_job( new JobOptions( retry: $definition_policy ) );

		$this->rig->run_due();

		self::assertSame( $definition_policy, $observed );
		self::assertSame( 1, $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::execution() )->attempts );
		$this->rig->assert_no_retry();
	}

	/**
	 * Failure adjudication resets execution lease credit before policy resolution.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Execution-time production lock and run bytes are the only evidence that long-runtime credit is removed before user-controlled policy code runs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_resets_execution_lease_credit_before_retry_policy_resolution(): void {
		$lock                 = null;
		$run                  = null;
		$this->job->throwable = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value(
			'a8csp_bgje/retry_policy/' . self::IDENTITY,
			function ( RetryPolicy $policy ) use ( &$lock, &$run ): RetryPolicy {
				$lock = $this->lock();
				$run  = $this->run_state();

				return $policy;
			}
		);
		$this->enqueue_job( new JobOptions( max_runtime: 1_200, retry: new RetryPolicy( max_attempts: 1 ) ) );
		$this->rig->clock()->timestamp = self::NOW + 90;

		$this->rig->run_due();

		self::assertSame( self::NOW + 90, $lock['heartbeat_at'] ?? null );
		self::assertSame( self::NOW + 90, $run['heartbeat_at'] ?? null );
		self::assertTrue( $run['executing'] ?? false );
	}

	/**
	 * A foreign policy value falls back to the definition policy and records its warning.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handle_run_action_falls_back_and_warns_for_a_foreign_retry_policy(): void {
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = 7;
		$this->set_filter_value( 'a8csp_bgje/retry_policy/' . self::IDENTITY, 'invalid-policy' );
		$this->enqueue_job( new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) ) );

		$this->rig->run_due();

		self::assertSame( 7, $this->latest_retry()[4] ?? null );
		self::assertNotEmpty( $this->rig->logger()->records );
		self::assertSame( 'warning', $this->rig->logger()->records[0]['level'] ?? null );
		self::assertSame( self::IDENTITY, $this->rig->logger()->records[0]['context']['identity'] ?? null );
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
		$this->job->throwable = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value(
			'a8csp_bgje/retry_policy/' . self::IDENTITY,
			static function (): never {
				throw new \DomainException( 'Retry policy filter exploded.' );
			}
		);
		$this->enqueue_job( new JobOptions( retry: new RetryPolicy( max_attempts: 2 ) ) );

		$this->rig->run_due();

		$failure = $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::execution() );
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
		$this->job->throwable = new \RuntimeException( 'Database unavailable.' );
		$this->set_filter_value(
			'a8csp_bgje/retry_policy/' . self::IDENTITY,
			function (): never {
				$this->install_foreign_generation();

				throw new \DomainException( 'Retry policy filter exploded.' );
			}
		);
		$this->enqueue_job( new JobOptions( retry: new RetryPolicy( max_attempts: 2 ) ) );

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
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = 7;
		$this->set_action_throwable( 'a8csp_bgje/retry_scheduled/' . self::IDENTITY, new \RuntimeException( 'Retry-scheduled listener exploded.' ) );
		$this->enqueue_job( new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) ) );

		$this->rig->run_due();

		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_bgje/retry_scheduled' ) );
		$this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::execution() );
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
		$this->job->throwable           = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value = 7;
		$this->observe_action(
			'a8csp_bgje/retry_scheduled/' . self::IDENTITY,
			function (): void {
				$this->install_foreign_generation();
			}
		);
		$this->set_action_throwable( 'a8csp_bgje/retry_scheduled/' . self::IDENTITY, new \RuntimeException( 'Retry-scheduled listener exploded.' ) );
		$this->enqueue_job( new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) ) );

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
		$this->job->throwable                             = new \RuntimeException( 'Database unavailable.' );
		$this->rig->randomizer()->value                   = 7;
		$this->rig->backend()->results['schedule_single'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore the scheduler before retrying the job.' ) );
		$this->enqueue_job( new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) ) );

		$this->rig->run_due();

		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_bgje/retry_scheduled' ) );
		$this->assert_failure( ErrorCode::BackendRejected, RunFailureStage::scheduling() );
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
		$this->assert_terminal_job_failure(
			new \RuntimeException( 'Database unavailable.' ),
			new JobOptions( retry: new RetryPolicy( max_attempts: 1 ) )
		);
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
		$this->assert_terminal_job_failure(
			InvalidChunkException::non_portable(),
			new JobOptions( retry: new RetryPolicy( max_attempts: 1 ) )
		);
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
		$chunked_job->process_throwable = InvalidChunkException::chunk_too_large( 8_193, 8_192 );
		$this->client->register( $chunked_job->definition( new JobOptions( retry: new RetryPolicy( max_attempts: 1 ) ) ) );
		$result = $this->client->dispatch( 'bounded-chunked-job', self::ARGS );
		self::assertInstanceOf( Success::class, $result );

		for ( $delivery = 0; $delivery < 2; ++$delivery ) {
			$this->rig->run_due();
		}

		$failure = $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::execution() );
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
	 * @param   JobOptions|null $options Optional policy declaration.
	 *
	 * @return  string
	 */
	private function enqueue_job( ?JobOptions $options = null ): string {
		$this->client->register( $this->job->definition( $options ) );
		$retry_value                    = $this->rig->randomizer()->value;
		$this->rig->randomizer()->value = 42;
		$result                         = $this->client->dispatch( self::NAME, self::ARGS );
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
	 * @param   \Throwable     $throwable Job failure.
	 * @param   JobOptions|null $options   Optional policy declaration.
	 *
	 * @return  void
	 */
	private function assert_terminal_job_failure( \Throwable $throwable, ?JobOptions $options = null ): void {
		$this->job->throwable = $throwable;
		$this->enqueue_job( $options );

		$this->rig->run_due();

		$failure = $this->assert_failure( ErrorCode::ExecutionFailed, RunFailureStage::execution() );
		self::assertSame( 1, $failure->attempts );
		$this->rig->assert_no_retry();
	}

	/**
	 * Creates one throwing job definition whose execution satisfies both installed roles.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Job name.
	 *
	 * @return  JobDefinition
	 */
	private function dual_kind_job( string $name ): JobDefinition {
		$execution = new class() implements JobExecutionInterface, ChunkedJobExecutionInterface {
			/**
			 * Fails every attempt with a retryable throwable.
			 *
			 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
			 * @param   RunContextInterface     $context    Controlled access to this run.
			 *
			 * @return  void
			 */
			#[\Override]
			public function handle( array $start_args, RunContextInterface $context ): void {
				throw new \RuntimeException( 'Database unavailable.' );
			}

			/**
			 * Returns an empty queue.
			 *
			 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
			 * @param   RunContextInterface     $context    Controlled access to this run.
			 *
			 * @return  iterable<array<array-key, mixed>>
			 */
			#[\Override]
			public function generate_queue( array $start_args, RunContextInterface $context ): iterable {
				return array();
			}

			/**
			 * Processes nothing.
			 *
			 * @param   array<array-key, mixed> $chunk_args Arguments for this chunk.
			 * @param   ChunkContextInterface   $context    Controlled access to this chunk's run.
			 *
			 * @return  void
			 */
			#[\Override]
			public function process_chunk( array $chunk_args, ChunkContextInterface $context ): void {}

		};

		return JobDefinition::job(
			$name,
			$execution,
			new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 30, max_delay: 120 ) )
		);
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
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_bgje/failed' ) );
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
		$events = $this->rig->hooks()->fired( 'a8csp_bgje/failed' );
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
	 * Returns the latest generic retry-scheduled hook payload with the run identifier as its wire value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<mixed>
	 */
	private function latest_retry(): array {
		$events = $this->rig->hooks()->fired( 'a8csp_bgje/retry_scheduled' );
		self::assertNotEmpty( $events );

		$payload = $events[ \count( $events ) - 1 ];
		self::assertInstanceOf( RunId::class, $payload[1] ?? null );
		$payload[1] = (string) $payload[1];

		return $payload;
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
		$calls = \array_values(
			\array_filter(
				$this->rig->backend()->calls,
				static function ( array $call ): bool {
					$args = $call['args']['args'] ?? null;

					return \is_array( $args )
						&& 'schedule_single' === $call['verb']
						&& 'a8csp_bgje/internal/deliver' === ( $call['args']['hook'] ?? null )
						&& self::IDENTITY === ( $args[0] ?? null );
				}
			)
		);
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
		return $this->run_state_for( self::IDENTITY, self::RUN_ID );
	}

	/**
	 * Returns one decoded run state for an explicit work identity and run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified work identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  array<array-key, mixed>|null
	 */
	private function run_state_for( string $identity, string $run_id ): ?array {
		$value = $this->decoded_row( 'a8csp_bgje_active_run_' . $identity . '_' . $run_id );

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
	 * @param   \Closure $observe   Observation callable.
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
