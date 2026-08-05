<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies cancellation fences live deliveries and isolates runs sharing a scheduler group.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class CancellationTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Client scope isolated to cancellation coverage. */
	private const string SCOPE = 'integration-cancellation';

	/** Job identity isolated to the executing-refusal race. */
	private const string EXECUTING_NAME = 'integration-cancel-executing-refusal';

	/** Scope-qualified job identity isolated to the executing-refusal race. */
	private const string EXECUTING_IDENTITY = self::SCOPE . ':' . self::EXECUTING_NAME;

	/** Job identity isolated to retry-backoff cancellation. */
	private const string BACKOFF_NAME = 'integration-cancel-backoff';

	/** Scope-qualified job identity isolated to retry-backoff cancellation. */
	private const string BACKOFF_IDENTITY = self::SCOPE . ':' . self::BACKOFF_NAME;

	/** Chunked Job identity isolated to between-chunks cancellation. */
	private const string CHUNKED_JOB_NAME = 'integration-cancel-between-chunks';

	/** Scope-qualified chunked job identity isolated to between-chunks cancellation. */
	private const string CHUNKED_JOB_IDENTITY = self::SCOPE . ':' . self::CHUNKED_JOB_NAME;

	/** Job identity isolated to sibling-run cancellation. */
	private const string SIBLING_NAME = 'integration-cancel-sibling-isolation';

	/** Scope-qualified job identity isolated to sibling-run cancellation. */
	private const string SIBLING_IDENTITY = self::SCOPE . ':' . self::SIBLING_NAME;

	/** Job identity isolated to degraded-backend cancellation. */
	private const string DEGRADED_NAME = 'integration-cancel-degraded-backend';

	/** Scope-qualified job identity isolated to degraded-backend cancellation. */
	private const string DEGRADED_IDENTITY = self::SCOPE . ':' . self::DEGRADED_NAME;

	// endregion.

	// region TESTS.

	/**
	 * Cancellation refuses an admitted job while the runner completes it normally.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Cancellation is invoked from inside an admitted real Action Scheduler delivery; the public result cannot prove the executing marker and scheduler-running state overlapped at the refusal instant.
	 *
	 * @return  void
	 */
	public function test_cancel_refuses_an_executing_job_and_the_run_completes(): void {
		$args = array( 'account_id' => 41 );
		$job  = new RecordingJob( self::EXECUTING_NAME );

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::SCOPE );
		$client->register( $job->definition() );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::EXECUTING_IDENTITY );

		$enqueued = $client->dispatch( self::EXECUTING_NAME, $args );
		self::assertInstanceOf( Run::class, $enqueued );
		$run_id    = (string) $enqueued->id;
		$group     = self::EXECUTING_IDENTITY;
		$action_id = $this->assert_pending_job_action( self::EXECUTING_IDENTITY, $run_id, $group );
		$store     = $this->action_scheduler_store();

		$observed_status = null;
		$observed_state  = null;
		$cancel_result   = null;
		$job->on_handle  = static function ( array $received_args ) use ( $action_id, $client, $run_id, $store, &$cancel_result, &$observed_state, &$observed_status ): void {
			$observed_status = $store->get_status( $action_id );
			$observed_state  = \get_option( 'a8csp_bgje_active_run_' . self::EXECUTING_IDENTITY . '_' . $run_id, null );
			$cancel_result   = $client->cancel( self::EXECUTING_NAME, $run_id );
		};

		$completed_action_ids = array();
		$completed_hook       = static function ( int $completed_action_id ) use ( $action_id, &$completed_action_ids ): void {
			if ( (int) $action_id === $completed_action_id ) {
				$completed_action_ids[] = $completed_action_id;
			}
		};
		\add_action( 'action_scheduler_completed_action', $completed_hook, 10, 1 );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must admit and execute the target job' );
		\remove_action( 'action_scheduler_completed_action', $completed_hook, 10 );

		self::assertSame( \ActionScheduler_Store::STATUS_RUNNING, $observed_status );
		self::assertIsArray( $observed_state );
		self::assertTrue( $observed_state['executing'] ?? false, 'The admitted delivery must persist its executing marker' );
		self::assertInstanceOf( \WP_Error::class, $cancel_result );
		self::assertSame( \sprintf( 'Run "%s" is executing; a run in flight completes or fails on its own.', $run_id ), $cancel_result->get_error_message() );
		self::assertSame( array( $args ), $job->calls, 'Refusal must leave the admitted job invocation intact' );
		self::assertSame( array( (int) $action_id ), $completed_action_ids );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $action_id ) );
		self::assertSame(
			array(
				array(
					'run_id' => $run_id,
					'status' => 'completed',
				),
			),
			self::terminal_entries( self::EXECUTING_IDENTITY )
		);
		self::assert_run_storage_cleared( self::EXECUTING_IDENTITY, $run_id, $args );
	}

	/**
	 * A failed attempt can be cancelled while its retry waits in backoff.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Cancellation races a retained run against its staged retry delivery; public history cannot prove the exact retry row was pending and then cleared before execution.
	 *
	 * @return  void
	 */
	public function test_cancel_during_backoff_clears_the_retry_and_records_cancelled_history(): void {
		$this->expectOutputRegex( '/Run attempt failed and was scheduled for retry/' );
		$args           = array( 'account_id' => 42 );
		$job            = new RecordingJob( self::BACKOFF_NAME );
		$job->throwable = new \RuntimeException( 'Retry after the upstream recovers.' );
		// A priority that is neither zero nor the engine default distinguishes a resolved value from both a coerced null
		// and a scheduler-supplied default, which is what makes the stored action's priority evidence.
		$options = new JobOptions( retry: new RetryPolicy( max_attempts: 2, base_delay: 300, multiplier: 1, max_delay: 300 ), priority: 37 );

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::SCOPE );
		$client->register( $job->definition( $options ) );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::BACKOFF_IDENTITY );

		$enqueued = $client->dispatch( self::BACKOFF_NAME, $args );
		self::assertInstanceOf( Run::class, $enqueued );
		$run_id            = (string) $enqueued->id;
		$group             = self::BACKOFF_IDENTITY;
		$initial_action_id = $this->assert_pending_job_action( self::BACKOFF_IDENTITY, $run_id, $group, 37 );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute only the first failed attempt' );

		$store = $this->action_scheduler_store();
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $initial_action_id ) );
		$retry_action_id = $this->assert_sole_pending_action( 'a8csp_bgje/internal/deliver', $group, array( self::BACKOFF_IDENTITY, $run_id, 2 ) );
		$run_state       = \get_option( 'a8csp_bgje_active_run_' . self::BACKOFF_IDENTITY . '_' . $run_id, null );
		self::assertIsArray( $run_state );
		self::assertSame( 'running', $run_state['status'] ?? null );
		self::assertSame( 1, $run_state['failed_attempts'] ?? null );
		self::assertSame( 2, $run_state['action_sequence'] ?? null );
		self::assertFalse( $run_state['executing'] ?? true, 'The persisted backoff window must be cancellable' );

		$cancelled = $client->cancel( self::BACKOFF_NAME, $run_id );

		self::assertInstanceOf( Run::class, $cancelled );
		self::assertSame( $run_id, (string) $cancelled->id );
		self::assertSame( array( $args ), $job->calls, 'Cancellation must prevent the pending retry attempt' );
		self::assertSame( \ActionScheduler_Store::STATUS_CANCELED, $store->get_status( $retry_action_id ) );
		self::assertSame(
			array(),
			$store->query_actions(
				array(
					'group'    => $group,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				)
			),
			'The run clear must remove the pending retry'
		);
		self::assertSame(
			array(
				array(
					'run_id' => $run_id,
					'status' => 'cancelled',
				),
			),
			self::terminal_entries( self::BACKOFF_IDENTITY )
		);
		self::assert_run_storage_cleared( self::BACKOFF_IDENTITY, $run_id, $args );
	}

	/**
	 * A chunked job remains cancellable after one chunk and before its next queue advance.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Cancellation is staged between a processed chunk and the next real queue-advance delivery; public lifecycle state cannot expose the retained queue head and pending continuation at that instant.
	 *
	 * @return  void
	 */
	public function test_cancel_between_chunks_preserves_the_retained_head_and_fires_only_cancelled_hooks(): void {
		$start_args         = array( 'account_id' => 43 );
		$first_chunk        = array( 'chunk' => 'one' );
		$next_chunk         = array( 'chunk' => 'two' );
		$chunked_job        = new RecordingChunkedJob( self::CHUNKED_JOB_NAME );
		$chunked_job->queue = array( $first_chunk, $next_chunk );

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::SCOPE );
		$client->register( $chunked_job->definition() );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::CHUNKED_JOB_IDENTITY );

		$terminal_hooks = array();
		\add_action(
			'a8csp_bgje/cancelled/' . self::CHUNKED_JOB_IDENTITY,
			static function ( RunId $run_id, array $args ) use ( &$terminal_hooks ): void {
				$terminal_hooks[] = array( 'cancelled-named', (string) $run_id, $args );
			},
			10,
			2
		);
		\add_action(
			'a8csp_bgje/cancelled',
			static function ( string $name, RunId $run_id, array $args ) use ( &$terminal_hooks ): void {
				if ( self::CHUNKED_JOB_IDENTITY === $name ) {
					$terminal_hooks[] = array( 'cancelled-generic', $name, (string) $run_id, $args );
				}
			},
			10,
			3
		);
		\add_action(
			'a8csp_bgje/completed/' . self::CHUNKED_JOB_IDENTITY,
			static function ( RunId $run_id, array $args, ?RunId $previous_completed_run_id ) use ( &$terminal_hooks ): void {
				$terminal_hooks[] = array( 'completed', (string) $run_id, $args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
			},
			10,
			3
		);
		\add_action(
			'a8csp_bgje/failed',
			static function ( RunFailure $failure ) use ( &$terminal_hooks ): void {
				if ( self::CHUNKED_JOB_IDENTITY === $failure->identity ) {
					$terminal_hooks[] = array( 'failed', $failure );
				}
			},
			10,
			1
		);

		$started = $client->dispatch( self::CHUNKED_JOB_NAME, $start_args );
		self::assertInstanceOf( Run::class, $started );
		$run_id = (string) $started->id;
		$group  = self::CHUNKED_JOB_IDENTITY;

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must materialize the chunked job queue' );

		$pending_state = \get_option( 'a8csp_bgje_active_run_' . self::CHUNKED_JOB_IDENTITY . '_' . $run_id, null );
		self::assertIsArray( $pending_state );
		self::assertSame( array( $first_chunk, $next_chunk ), $pending_state['kind_state'] ?? null );
		self::assertSame( 2, $pending_state['action_sequence'] ?? null );
		self::assertFalse( $pending_state['executing'] ?? true, 'The queued continuation must retain a cancellable head' );
		$first_action_id = $this->assert_pending_chunk_continuation( self::CHUNKED_JOB_IDENTITY, $run_id, $group, $first_chunk );

		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must process the first chunk inline' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $this->action_scheduler_store()->get_status( $first_action_id ), 'Action Scheduler must complete the first continuation' );

		$run_state = \get_option( 'a8csp_bgje_active_run_' . self::CHUNKED_JOB_IDENTITY . '_' . $run_id, null );
		self::assertIsArray( $run_state );
		self::assertSame( array( $next_chunk ), $run_state['kind_state'] ?? null );
		self::assertSame( 3, $run_state['action_sequence'] ?? null );
		self::assertFalse( $run_state['executing'] ?? true, 'The inter-chunk state must be cancellable' );
		$continue_action_id = $this->assert_sole_pending_action( 'a8csp_bgje/internal/deliver', $group, array( self::CHUNKED_JOB_IDENTITY, $run_id, 3 ) );

		$cancelled = $client->cancel( self::CHUNKED_JOB_NAME, $run_id );

		self::assertInstanceOf( Run::class, $cancelled );
		self::assertSame( $run_id, (string) $cancelled->id );
		self::assertSame( array( $first_chunk ), \array_column( $chunked_job->process_calls, 'chunk_args' ) );
		self::assertSame(
			array(
				array( 'cancelled-named', $run_id, $start_args ),
				array( 'cancelled-generic', self::CHUNKED_JOB_IDENTITY, $run_id, $start_args ),
			),
			$terminal_hooks,
			'Cancellation must publish only the identity-specific and generic cancelled hooks in that order'
		);
		$store = $this->action_scheduler_store();
		self::assertSame( \ActionScheduler_Store::STATUS_CANCELED, $store->get_status( $continue_action_id ) );
		self::assertSame(
			array(),
			$store->query_actions(
				array(
					'group'    => $group,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				)
			)
		);
		self::assertSame(
			array(
				array(
					'run_id' => $run_id,
					'status' => 'cancelled',
				),
			),
			self::terminal_entries( self::CHUNKED_JOB_IDENTITY )
		);
		self::assert_run_storage_cleared( self::CHUNKED_JOB_IDENTITY, $run_id, $start_args );
	}

	/**
	 * Cancelling one run leaves a sibling sharing its identity group executable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Two sibling deliveries share one real scheduler group, so their persisted arguments are the only cancellation discriminator proving the sibling row survived.
	 *
	 * @return  void
	 */
	public function test_cancel_clears_only_the_target_run_from_a_shared_identity_group(): void {
		$args_a = array( 'account_id' => 44 );
		$args_b = array( 'account_id' => 45 );
		$job    = new RecordingJob( self::SIBLING_NAME );

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::SCOPE );
		$client->register( $job->definition() );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::SIBLING_IDENTITY );

		$enqueued_a = $client->dispatch( self::SIBLING_NAME, $args_a );
		$enqueued_b = $client->dispatch( self::SIBLING_NAME, $args_b );
		self::assertInstanceOf( Run::class, $enqueued_a );
		self::assertInstanceOf( Run::class, $enqueued_b );
		$run_a    = (string) $enqueued_a->id;
		$run_b    = (string) $enqueued_b->id;
		$group    = self::SIBLING_IDENTITY;
		$action_a = $this->assert_pending_job_action( self::SIBLING_IDENTITY, $run_a, $group );
		$action_b = $this->assert_pending_job_action( self::SIBLING_IDENTITY, $run_b, $group );

		$cancelled = $client->cancel( self::SIBLING_NAME, $run_a );

		self::assertInstanceOf( Run::class, $cancelled );
		self::assertSame( $run_a, (string) $cancelled->id );
		$store = $this->action_scheduler_store();
		self::assertSame( \ActionScheduler_Store::STATUS_CANCELED, $store->get_status( $action_a ) );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_b ) );
		self::assertSame(
			array( $action_b ),
			$store->query_actions(
				array(
					'group'    => $group,
					'args'     => array( self::SIBLING_IDENTITY, $run_b, 1 ),
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				)
			),
			'The shared identity group must retain only the sibling run action'
		);
		self::assertSame( 1, $this->run_matching_due_action( static fn ( string $hook, array $action_args ): bool => 'a8csp_bgje/internal/deliver' === $hook && ( $action_args[1] ?? null ) === $run_b ), 'Action Scheduler must execute the surviving sibling' );

		self::assertSame( array( $args_b ), $job->calls );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $action_b ) );
		$history = \get_option( 'a8csp_bgje_run_history_' . self::SIBLING_IDENTITY, null );
		self::assertIsArray( $history );
		self::assertSame( array( $run_a, $run_b ), $history['started'] ?? null );
		self::assertSame(
			array(
				array(
					'run_id' => $run_a,
					'status' => 'cancelled',
				),
				array(
					'run_id' => $run_b,
					'status' => 'completed',
				),
			),
			$history['terminal'] ?? null
		);
		self::assertSame(
			array(
				'by_hash' => array(
					self::args_hash( $args_a ) => $run_a,
					self::args_hash( $args_b ) => $run_b,
				),
			),
			\get_option( 'a8csp_bgje_latest_run_' . self::SIBLING_IDENTITY, null )
		);
		self::assert_run_storage_cleared( self::SIBLING_IDENTITY, $run_a, $args_a );
		self::assert_run_storage_cleared( self::SIBLING_IDENTITY, $run_b, $args_b );
	}

	/**
	 * Cancellation removes the named run's pending delivery from the active backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The real backend row is observed before and after cancellation because the public terminal result cannot prove scheduler clearance.
	 *
	 * @return  void
	 */
	#[Group( 'degraded' )]
	public function test_cancel_removes_the_pending_delivery_on_each_backend(): void {
		$args = array( 'account_id' => 46 );
		$job  = new RecordingJob( self::DEGRADED_NAME );

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::SCOPE );
		$client->register( $job->definition() );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::DEGRADED_IDENTITY );

		$raw_deliveries = array();
		\add_action(
			'a8csp_bgje/internal/deliver',
			static function ( string $name, string $run_id, int $action_sequence ) use ( &$raw_deliveries ): void {
				if ( self::DEGRADED_IDENTITY === $name ) {
					$raw_deliveries[] = array( $name, $run_id, $action_sequence );
				}
			},
			1,
			3
		);

		$enqueued = $client->dispatch( self::DEGRADED_NAME, $args );
		self::assertInstanceOf( Run::class, $enqueued );
		$run_id      = (string) $enqueued->id;
		$group       = self::DEGRADED_IDENTITY;
		$action_args = array( self::DEGRADED_IDENTITY, $run_id, 1 );
		$action_id   = null;
		if ( \class_exists( \ActionScheduler::class ) ) {
			$action_id = $this->assert_pending_job_action( self::DEGRADED_IDENTITY, $run_id, $group );
		} else {
			$cron_before = $this->wordpress_cron_events( 'a8csp_bgje/internal/deliver', $action_args );
			self::assertCount( 1, $cron_before, 'The degraded backend must retain one pending WP-Cron single' );
			self::assertFalse( $cron_before[0]['schedule'] );
		}

		$cancelled = $client->cancel( self::DEGRADED_NAME, $run_id );
		self::assertInstanceOf( Run::class, $cancelled );
		self::assertSame( $run_id, (string) $cancelled->id );

		if ( null !== $action_id ) {
			self::assertSame( \ActionScheduler_Store::STATUS_CANCELED, $this->action_scheduler_store()->get_status( $action_id ) );
		} else {
			self::assertSame( array(), $this->wordpress_cron_events( 'a8csp_bgje/internal/deliver', $action_args ) );
		}

		self::assertSame( array(), $raw_deliveries );
		self::assertSame( array(), $job->calls, 'Cancellation must prevent the pending backend delivery from invoking user work' );
		self::assertSame(
			array(
				array(
					'run_id' => $run_id,
					'status' => 'cancelled',
				),
			),
			self::terminal_entries( self::DEGRADED_IDENTITY )
		);
		self::assert_run_storage_cleared( self::DEGRADED_IDENTITY, $run_id, $args );
		self::assertSame(
			array(
				'a8csp_bgje_latest_run_' . self::DEGRADED_IDENTITY,
				'a8csp_bgje_run_history_' . self::DEGRADED_IDENTITY,
			),
			\array_column( $this->engine_option_rows(), 'option_name' ),
			'Cancelled degraded state must retain only history and the latest pointer'
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Asserts and returns the sole pending Action Scheduler row for one exact hook, group, and argument list.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $hook          Action hook.
	 * @param   string                  $group         Identity action group.
	 * @param   array<array-key, mixed> $expected_args Expected action arguments.
	 *
	 * @return  string
	 */
	private function assert_sole_pending_action( string $hook, string $group, array $expected_args ): string {
		$store      = $this->action_scheduler_store();
		$action_ids = $store->query_actions(
			array(
				'hook'     => $hook,
				'args'     => $expected_args,
				'group'    => $group,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );
		self::assertCount( 1, $action_ids );
		self::assertIsString( $action_ids[0] ?? null );
		$action_id = $action_ids[0];
		$action    = $store->fetch_action( $action_id );

		self::assertInstanceOf( \ActionScheduler_Action::class, $action );
		self::assertSame( $hook, $action->get_hook() );
		self::assertSame( $expected_args, $action->get_args() );
		self::assertSame( $group, $action->get_group() );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $action_id ) );

		return $action_id;
	}

	/**
	 * Returns the terminal entries for one background-work history.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Stable job or chunked job name.
	 *
	 * @return  array<array-key, mixed>
	 */
	private static function terminal_entries( string $name ): array {
		$history = \get_option( 'a8csp_bgje_run_history_' . $name, null );
		self::assertIsArray( $history );
		$entries = $history['terminal'] ?? null;
		self::assertIsArray( $entries );

		return $entries;
	}

	/**
	 * Asserts that a terminal run leaves no active, lock, or failed-run state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name     Stable job or chunked job name.
	 * @param   string                  $run_id   Run identifier.
	 * @param   array<array-key, mixed> $args     Start arguments.
	 *
	 * @return  void
	 */
	private static function assert_run_storage_cleared( string $name, string $run_id, array $args ): void {
		self::assertFalse( \get_option( 'a8csp_bgje_active_run_' . $name . '_' . $run_id, false ) );
		self::assertFalse( \get_option( 'a8csp_bgje_overlap_lock_' . $name . '_' . self::args_hash( $args ), false ) );
		self::assertFalse( \get_option( 'a8csp_bgje_failed_runs_' . $name, false ) );
	}

	// endregion.
}
