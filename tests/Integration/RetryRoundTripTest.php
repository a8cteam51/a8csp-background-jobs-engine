<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;

/**
 * Verifies retry exhaustion, retained failure state, and manual retry through the public API.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class RetryRoundTripTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Client scope isolated to retry round-trip coverage. */
	private const string SCOPE = 'integration-retry';

	/** Job identity unique within the request-persistent integration registry. */
	private const string NAME = 'integration-retry-round-trip';

	/** Scope-qualified job identity persisted by the engine. */
	private const string IDENTITY = self::SCOPE . ':' . self::NAME;

	// endregion.

	// region TESTS.

	/**
	 * Retry-policy filters chain the generic result through the winning identity-specific filter.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_policy_filters_run_generic_then_identity_specific_with_chained_values(): void {
		/** @var list<array{string, string, array<array-key, mixed>}> $log_records */
		$log_records = array();
		\add_filter( 'a8csp_bgje/log_to_error_log', static fn (): bool => false );
		\add_action(
			'a8csp_bgje/log',
			static function ( string $level, string $message, array $context ) use ( &$log_records ): void {
				$log_records[] = array( $level, $message, $context );
			},
			10,
			3
		);
		$name              = 'integration-retry-policy-hooks';
		$identity          = self::SCOPE . ':' . $name;
		$args              = array( 'account_id' => 92 );
		$job               = new RecordingJob( $name );
		$job->throwable    = new \RuntimeException( 'The retry-policy fixture failed.' );
		$definition_policy = new RetryPolicy( max_attempts: 3 );
		$generic_policy    = new RetryPolicy( max_attempts: 2 );
		$specific_policy   = new RetryPolicy( max_attempts: 1 );
		/** @var list<array{hook: string, policy: RetryPolicy, identity?: string, arity: int}> $calls */
		$calls = array();

		\add_filter(
			'a8csp_bgje/retry_policy',
			static function ( RetryPolicy $policy, string $filtered_identity ) use ( $generic_policy, &$calls ): RetryPolicy {
				$calls[] = array(
					'hook'     => 'generic',
					'policy'   => $policy,
					'identity' => $filtered_identity,
					'arity'    => \func_num_args(),
				);

				return $generic_policy;
			},
			10,
			2
		);
		\add_filter(
			'a8csp_bgje/retry_policy/' . $identity,
			static function ( RetryPolicy $policy ) use ( $specific_policy, &$calls ): RetryPolicy {
				$calls[] = array(
					'hook'   => 'specific',
					'policy' => $policy,
					'arity'  => \func_num_args(),
				);

				return $specific_policy;
			},
			10,
			1
		);

		/** @var list<RunFailure> $failed */
		$failed = array();
		\add_action(
			'a8csp_bgje/failed',
			static function ( RunFailure $failure ) use ( &$failed ): void {
				$failed[] = $failure;
			},
			10,
			1
		);

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::SCOPE );
		$client->register( $job->definition( new JobOptions( retry: $definition_policy ) ) );
		$this->expect_option( 'a8csp_bgje_latest_run_' . $identity );
		$this->expect_option( 'a8csp_bgje_failed_runs_' . $identity );

		$result = $client->dispatch( $name, $args );
		self::assertInstanceOf( Run::class, $result );
		self::assertSame( 1, $this->run_next_engine_action() );

		self::assertSame(
			array(
				array(
					'hook'     => 'generic',
					'policy'   => $definition_policy,
					'identity' => $identity,
					'arity'    => 2,
				),
				array(
					'hook'   => 'specific',
					'policy' => $generic_policy,
					'arity'  => 1,
				),
			),
			$calls
		);
		self::assertSame( array( $args ), $job->calls );
		self::assertCount( 1, $failed, 'The identity-specific one-attempt policy must win over the generic two-attempt policy' );
		self::assertSame( 1, $failed[0]->attempts );
		self::assertSame( 0, \did_action( 'a8csp_bgje/retry_scheduled/' . $identity ) );
		self::assertSame( 0, \did_action( 'a8csp_bgje/retry_scheduled' ) );

		self::assertTrue(
			\array_any( $log_records, static fn ( array $record ): bool => \str_contains( $record[1], 'Run failed permanently; correct the cause' ) ),
			'The published log must carry the engine message because an exhausted retry chain must be reported as permanent'
		);
	}

	/**
	 * A two-attempt failure is retained and a manual retry completes as a fresh run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The retry row, run generation, and lock heartbeat must remain correlated across a real claim and manual retry; public lifecycle results cannot expose the CAS generation ownership at the claim cutoff.
	 *
	 * @load-bearing security
	 * @pin-rationale The upstream throwable text is present inside the executing fixture and must remain absent from public RunFailure payloads; a success-only public seam cannot demonstrate that negative disclosure boundary.
	 *
	 * @return  void
	 */
	public function test_retry_exhaustion_round_trips_through_the_failed_store(): void {
		/** @var list<array{string, string, array<array-key, mixed>}> $log_records */
		$log_records = array();
		\add_filter( 'a8csp_bgje/log_to_error_log', static fn (): bool => false );
		\add_action(
			'a8csp_bgje/log',
			static function ( string $level, string $message, array $context ) use ( &$log_records ): void {
				$log_records[] = array( $level, $message, $context );
			},
			10,
			3
		);
		$args                    = array(
			'account_id' => 91,
			'operation'  => 'synchronize',
		);
		$job                     = new RecordingJob( self::NAME );
		$job->throwable          = new \RuntimeException( 'The upstream service remains unavailable.' );
		$definition_retry_policy = new RetryPolicy();

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::SCOPE );
		$client->register( $job->definition( new JobOptions( retry: $definition_retry_policy ) ) );

		$this->expect_option( 'a8csp_bgje_latest_run_' . self::IDENTITY );
		$this->expect_option( 'a8csp_bgje_failed_runs_' . self::IDENTITY );

		$retry_policy = new RetryPolicy( max_attempts: 2, base_delay: 1, multiplier: 1, max_delay: 1 );
		/** @var list<array{arity: int, policy: RetryPolicy}> $retry_policy_calls */
		$retry_policy_calls = array();
		\add_filter(
			'a8csp_bgje/retry_policy/' . self::IDENTITY,
			static function ( RetryPolicy $policy ) use ( $retry_policy, &$retry_policy_calls ): RetryPolicy {
				$retry_policy_calls[] = array(
					'arity'  => \func_num_args(),
					'policy' => $policy,
				);

				return $retry_policy;
			},
			10,
			1
		);

		/** @var list<array{string, array<array-key, mixed>, int, int}> $named_retry_scheduled */
		$named_retry_scheduled = array();
		/** @var list<array{string, string, array<array-key, mixed>, int, int}> $generic_retry_scheduled */
		$generic_retry_scheduled = array();
		/** @var list<RunFailure> $failed */
		$failed = array();
		/** @var list<array{string, array<array-key, mixed>, string|null}> $named_completed */
		$named_completed = array();
		/** @var list<array{string, string, array<array-key, mixed>, string|null}> $generic_completed */
		$generic_completed = array();
		\add_action(
			'a8csp_bgje/retry_scheduled/' . self::IDENTITY,
			static function ( RunId $run_id, array $start_args, int $attempt, int $delay ) use ( &$named_retry_scheduled ): void {
				$named_retry_scheduled[] = array( (string) $run_id, $start_args, $attempt, $delay );
			},
			10,
			4
		);
		\add_action(
			'a8csp_bgje/retry_scheduled',
			static function ( string $name, RunId $run_id, array $start_args, int $attempt, int $delay ) use ( &$generic_retry_scheduled ): void {
				$generic_retry_scheduled[] = array( $name, (string) $run_id, $start_args, $attempt, $delay );
			},
			10,
			5
		);
		\add_action(
			'a8csp_bgje/failed',
			static function ( RunFailure $failure ) use ( &$failed ): void {
				$failed[] = $failure;
			},
			10,
			1
		);
		\add_action(
			'a8csp_bgje/completed/' . self::IDENTITY,
			static function ( RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ) use ( &$named_completed ): void {
				$named_completed[] = array( (string) $run_id, $start_args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
			},
			10,
			3
		);
		\add_action(
			'a8csp_bgje/completed',
			static function ( string $name, RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ) use ( &$generic_completed ): void {
				$generic_completed[] = array( $name, (string) $run_id, $start_args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
			},
			10,
			4
		);

		$result = $client->dispatch( self::NAME, $args );
		self::assertInstanceOf( Run::class, $result, 'The retryable job must enqueue before its handler fails' );
		$failed_run_id     = (string) $result->id;
		$failed_group      = self::IDENTITY;
		$initial_action_id = $this->assert_pending_job_action( self::IDENTITY, $failed_run_id, $failed_group );

		$first_attempt_before = \time();
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the first retryable attempt' );
		$first_attempt_after = \time();

		self::assertSame( array( $args ), $job->calls, 'The first runner drive must execute one job attempt' );
		self::assertCount( 1, $retry_policy_calls, 'The first failure must resolve the filtered retry policy once' );
		self::assertSame( 1, $retry_policy_calls[0]['arity'] );
		self::assertSame( $definition_retry_policy, $retry_policy_calls[0]['policy'] );
		self::assertCount( 1, $named_retry_scheduled, 'The first failure must fire the identity-specific retry-scheduled hook once' );
		self::assertCount( 1, $generic_retry_scheduled, 'The first failure must fire the generic retry-scheduled hook once' );
		self::assertSame( array(), $failed, 'The first failure must remain non-terminal below the retry cap' );

		$delay = $named_retry_scheduled[0][3] ?? null;
		self::assertIsInt( $delay );
		self::assertContains( $delay, array( 0, 1 ), 'Full jitter must stay within the filtered one-second ceiling' );
		self::assertSame( array( array( $failed_run_id, $args, 1, $delay ) ), $named_retry_scheduled, 'The identity-specific retry-scheduled hook must pin the failed attempt number and jittered delay' );
		self::assertSame( array( array( self::IDENTITY, $failed_run_id, $args, 1, $delay ) ), $generic_retry_scheduled, 'The generic retry-scheduled hook must prepend the job name to the same payload' );

		$store = $this->action_scheduler_store();
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $initial_action_id ), 'Action Scheduler must complete the first action after the engine handles its failure' );
		$retry_action_id = $this->assert_pending_retry_action( $failed_run_id, $failed_group );
		$scheduled_at    = $store->get_date( $retry_action_id )->getTimestamp();
		self::assertGreaterThanOrEqual( $first_attempt_before + $delay, $scheduled_at, 'The retry timestamp must not precede the attempt timestamp plus its jittered delay' );
		self::assertLessThanOrEqual( $first_attempt_after + $delay, $scheduled_at, 'The retry timestamp must not exceed the completed attempt timestamp plus its jittered delay' );

		$args_hash = self::args_hash( $args );
		$run_state = \get_option( 'a8csp_bgje_active_run_' . self::IDENTITY . '_' . $failed_run_id, null );
		self::assertIsArray( $run_state );
		self::assertSame( 'running', $run_state['status'] ?? null );
		self::assertSame( 1, $run_state['failed_attempts'] ?? null );
		self::assertSame( 2, $run_state['action_sequence'] ?? null );
		self::assertSame( $scheduled_at, $run_state['heartbeat_at'] ?? null );
		$lock = \get_option( 'a8csp_bgje_overlap_lock_' . self::IDENTITY . '_' . $args_hash, null );
		self::assertIsArray( $lock );
		self::assertSame( $failed_run_id, $lock['run_id'] ?? null );
		self::assertSame( $scheduled_at, $lock['heartbeat_at'] ?? null );

		$this->drive_action_through_claim_cutoff( $retry_action_id );

		self::assertSame( array( $args, $args ), $job->calls, 'The claimed retry action must execute the second attempt exactly once' );
		self::assertCount( 2, $retry_policy_calls, 'Both retryable failures must resolve the filtered retry policy' );
		self::assertSame(
			array(
				array(
					'arity'  => 1,
					'policy' => $definition_retry_policy,
				),
				array(
					'arity'  => 1,
					'policy' => $definition_retry_policy,
				),
			),
			$retry_policy_calls,
			'The retry-policy filter must receive only the definition policy on both attempts'
		);
		self::assertCount( 1, $named_retry_scheduled, 'Retry exhaustion must not announce a nonexistent third attempt' );
		self::assertCount( 1, $generic_retry_scheduled, 'Retry exhaustion must not fire the generic retry-scheduled hook again' );
		/** @var list<RunFailure> $recorded_failed */
		$recorded_failed = $failed;
		self::assertCount( 1, $recorded_failed, 'Retry exhaustion must fire the failed hook once' );

		$failure = $recorded_failed[0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( self::IDENTITY, $failure->identity );
		self::assertSame( $failed_run_id, (string) $failure->run_id );
		self::assertSame( 2, $failure->attempts );
		self::assertSame( RunFailureStage::execution(), $failure->stage );
		self::assertSame( ErrorCode::ExecutionFailed, $failure->code );
		self::assertSame( 'Background-work execution failed because RuntimeException was thrown.', $failure->summary );
		self::assertStringNotContainsString( 'The upstream service remains unavailable.', $failure->summary, 'RunFailure must redact the upstream exception message at the public hook boundary' );
		self::assertNull( $failure->details );
		self::assertSame( array( $failure ), $recorded_failed, 'The failed hook must receive only the self-identifying failure value' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $retry_action_id ), 'Action Scheduler must complete the retry action after terminal engine handling' );
		self::assertSame(
			array( $initial_action_id, $retry_action_id ),
			$store->query_actions(
				array(
					'per_page' => -1,
					'orderby'  => 'action_id',
					'order'    => 'ASC',
				)
			),
			'Retry exhaustion must leave exactly the initial and retry Action Scheduler rows'
		);

		self::assertFalse( \get_option( 'a8csp_bgje_active_run_' . self::IDENTITY . '_' . $failed_run_id, false ), 'Terminal retry exhaustion must delete the active run option' );
		self::assertFalse( \get_option( 'a8csp_bgje_overlap_lock_' . self::IDENTITY . '_' . $args_hash, false ), 'Terminal retry exhaustion must release the overlap lock' );

		$failed_entries = \get_option( 'a8csp_bgje_failed_runs_' . self::IDENTITY, null );
		self::assertIsArray( $failed_entries );
		self::assertCount( 1, $failed_entries, 'Retry exhaustion must retain exactly one failed entry' );
		$failed_entry = $failed_entries[0] ?? null;
		self::assertIsArray( $failed_entry );
		self::assertSame( array( 'run_id', 'kind', 'failed_at', 'start_args', 'priority', 'attempts', 'error' ), \array_keys( $failed_entry ), 'The failed entry must contain exactly the manual-retry fields' );
		self::assertSame( 'job', $failed_entry['kind'] ?? null );
		self::assertSame( $failed_run_id, $failed_entry['run_id'] ?? null );
		self::assertIsInt( $failed_entry['failed_at'] ?? null );
		self::assertSame( $args, $failed_entry['start_args'] ?? null );
		self::assertSame( 10, $failed_entry['priority'] ?? null );
		self::assertSame( 2, $failed_entry['attempts'] ?? null, 'The failed entry must record the exhausted two-attempt cap' );
		self::assertSame(
			array(
				'class'   => \RuntimeException::class,
				'message' => 'Background-work execution failed because RuntimeException was thrown.',
				'stage'   => RunFailureStage::execution()->value,
				'code'    => 'execution_failed',
			),
			$failed_entry['error'] ?? null
		);

		$manual_result = $client->retry_failed( self::NAME, $failed_run_id );
		self::assertInstanceOf( Run::class, $manual_result, 'Manual retry must enqueue a fresh run through the public API' );
		$successful_run_id = (string) $manual_result->id;
		self::assertNotSame( $failed_run_id, $successful_run_id, 'Manual retry must allocate a fresh run identifier' );
		self::assertSame( array( $args, $args ), $job->calls, 'Manual retry must not invoke the job inline' );
		$remaining_failed_entries = \get_option( 'a8csp_bgje_failed_runs_' . self::IDENTITY, null );
		self::assertIsArray( $remaining_failed_entries );
		self::assertSame( array(), $remaining_failed_entries, 'Manual retry must remove the consumed failed entry after fresh enqueue succeeds' );

		$successful_group     = self::IDENTITY;
		$successful_action_id = $this->assert_pending_job_action( self::IDENTITY, $successful_run_id, $successful_group );
		$job->throwable       = null;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the manually retried job' );

		self::assertSame( array( $args, $args, $args ), $job->calls, 'The manually retried job must succeed on its third fixture invocation' );
		self::assertSame( array( array( $successful_run_id, $args, null ) ), $named_completed, 'The identity-specific completed hook must receive the fresh run ID, original arguments, and no prior completion' );
		self::assertSame( array( array( self::IDENTITY, $successful_run_id, $args, null ) ), $generic_completed, 'The generic completed hook must prepend the job name to the same fresh-run payload' );
		self::assertSame( array( array( $failed_run_id, $args, 1, $delay ) ), $named_retry_scheduled, 'The successful manual retry must not repeat the identity-specific retry-scheduled hook' );
		self::assertSame( array( array( self::IDENTITY, $failed_run_id, $args, 1, $delay ) ), $generic_retry_scheduled, 'The successful manual retry must not repeat the generic retry-scheduled hook' );
		self::assertSame( $recorded_failed, $failed, 'The successful manual retry must not repeat the failed hook' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $successful_action_id ), 'Action Scheduler must complete the manually retried job action' );
		self::assertSame(
			array( $initial_action_id, $retry_action_id, $successful_action_id ),
			$store->query_actions(
				array(
					'per_page' => -1,
					'orderby'  => 'action_id',
					'order'    => 'ASC',
				)
			),
			'The complete retry round-trip must leave exactly its three Action Scheduler rows'
		);
		self::assertFalse( \get_option( 'a8csp_bgje_active_run_' . self::IDENTITY . '_' . $successful_run_id, false ), 'The successful manual retry must delete its active run option' );
		self::assertFalse( \get_option( 'a8csp_bgje_overlap_lock_' . self::IDENTITY . '_' . $args_hash, false ), 'The successful manual retry must release its overlap lock' );
		$remaining_failed_entries = \get_option( 'a8csp_bgje_failed_runs_' . self::IDENTITY, null );
		self::assertIsArray( $remaining_failed_entries );
		self::assertSame( array(), $remaining_failed_entries );
		self::assertSame(
			array(
				'by_hash' => array( $args_hash => $successful_run_id ),
			),
			\get_option( 'a8csp_bgje_latest_run_' . self::IDENTITY, null ),
			'Manual retry success must retain the fresh run as the latest pointer'
		);
		self::assertSame(
			array(
				'started'        => array( $failed_run_id, $successful_run_id ),
				'terminal'       => array(
					array(
						'run_id' => $failed_run_id,
						'status' => 'failed',
					),
					array(
						'run_id' => $successful_run_id,
						'status' => 'completed',
					),
				),
				'by_hash'        => array(
					$args_hash => array(
						'started'  => array( $failed_run_id, $successful_run_id ),
						'terminal' => array(
							array(
								'run_id' => $failed_run_id,
								'status' => 'failed',
							),
							array(
								'run_id' => $successful_run_id,
								'status' => 'completed',
							),
						),
					),
				),
				'last_completed' => array( 'run_id' => $successful_run_id ),
			),
			self::history_without_timestamps( \get_option( 'a8csp_bgje_run_history_' . self::IDENTITY, null ) ),
			'History must retain the exhausted run and successful manual retry in lifecycle order'
		);
		self::assertSame(
			array(
				'a8csp_bgje_failed_runs_' . self::IDENTITY,
				'a8csp_bgje_latest_run_' . self::IDENTITY,
				'a8csp_bgje_run_history_' . self::IDENTITY,
			),
			\array_column( $this->engine_option_rows(), 'option_name' ),
			'Retry round-trip state must retain only the empty failed store, history ring, and latest pointer'
		);

		self::assertTrue(
			\array_any( $log_records, static fn ( array $record ): bool => \str_contains( $record[1], 'Run attempt failed and was scheduled for retry' ) ),
			'The published log must carry the engine message because each retried attempt must be reported as scheduled for retry'
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Asserts and returns the sole pending retry action for a job run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 * @param   string $group  Identity Action Scheduler group.
	 *
	 * @return  string
	 */
	private function assert_pending_retry_action( string $run_id, string $group ): string {
		$store      = $this->action_scheduler_store();
		$action_ids = $store->query_actions(
			array(
				'hook'     => 'a8csp_bgje/internal/deliver',
				'args'     => array( self::IDENTITY, $run_id, 2 ),
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );
		self::assertCount( 1, $action_ids, 'The first failed attempt must schedule exactly one pending retry action' );
		self::assertIsString( $action_ids[0] ?? null );
		$action_id = $action_ids[0];
		$action    = $store->fetch_action( $action_id );

		self::assertInstanceOf( \ActionScheduler_Action::class, $action );
		self::assertSame( 'a8csp_bgje/internal/deliver', $action->get_hook() );
		self::assertSame( array( self::IDENTITY, $run_id, 2 ), $action->get_args() );
		self::assertSame( $group, $action->get_group() );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_id ) );

		return $action_id;
	}

	/**
	 * Claims one action through a cutoff after its scheduled date and runs it through Action Scheduler.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $action_id Action Scheduler action identifier.
	 *
	 * @return  void
	 */
	private function drive_action_through_claim_cutoff( string $action_id ): void {
		$store     = $this->action_scheduler_store();
		$timestamp = $store->get_date( $action_id )->getTimestamp();
		$cutoff    = \as_get_datetime_object( $timestamp + 1 );
		$claim     = $store->stake_claim( 1, $cutoff );

		try {
			self::assertSame( array( (int) $action_id ), $claim->get_actions(), 'The future cutoff must claim exactly the pending retry action' );

			$runner = \ActionScheduler::runner();
			self::assertInstanceOf( \ActionScheduler_QueueRunner::class, $runner );
			$runner->process_action( (int) $action_id, 'Integration Test' );
		} finally {
			$store->release_claim( $claim );
		}
	}

	// endregion.
}
