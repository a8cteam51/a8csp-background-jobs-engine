<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\ScopeOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingCompletionJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises scope-bound operations through the production graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ScopeOperations::class )]
final class ScopeOperationsTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string FAILED_RUN_ID = '00000000001699999999-0000000000000000041';
	private const int NOW              = 1_700_000_000;

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
	 * Boots one deterministic production graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig = EngineRig::set_up( self::NOW );
	}

	/**
	 * Releases request-local engine state after each facade scenario.
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
	 * Job registration, admission, delivery, and completion cross the complete facade stack.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_job_facade_round_trips_one_public_run(): void {
		$client = $this->rig->operations( 'facade-tests' );
		$job    = new RecordingJob( 'email-digest' );
		$client->register( $job->definition() );

		$result = $client->dispatch( 'email-digest', array( 'site_id' => 7 ), fire_at: self::NOW + 300, priority: 5 );

		self::assertInstanceOf( Run::class, $result );
		self::assertSame( 'facade-tests:email-digest', $result->identity );
		self::assertSame( RunStatus::Running, $result->status );
		$this->rig->backend()->assert_scheduled( 'facade-tests:email-digest' );
		$this->rig->run_due();
		self::assertSame( array( array( 'site_id' => 7 ) ), $job->calls );
		$this->rig->assert_completed();
	}

	/**
	 * Chunked Job registration, admission, queue generation, and completion cross the same facade stack.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_chunked_job_facade_round_trips_one_public_run(): void {
		$client      = $this->rig->operations( 'facade-tests' );
		$chunked_job = new RecordingChunkedJob( 'catalog-sync' );
		$client->register( $chunked_job->definition() );

		$result = $client->dispatch( 'catalog-sync', array( 'site_id' => 7 ), priority: 23 );

		self::assertInstanceOf( Run::class, $result );
		$this->rig->run_due();
		$this->rig->run_due();
		self::assertSame( array( array( 'site_id' => 7 ) ), $chunked_job->generate_calls );
		$this->rig->assert_completed();
	}

	/**
	 * Registration accepts null crash-reclamation and priority policy defaults.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_accepts_null_job_policy_defaults(): void {
		$client = $this->rig->operations( 'facade-tests' );
		$job    = new RecordingJob( 'nullable-defaults' );

		$client->register( $job->definition( new JobOptions( max_runtime: null, priority: null ) ) );
		$result = $client->dispatch( 'nullable-defaults' );

		self::assertInstanceOf( Run::class, $result );
	}

	/**
	 * Registration rejects each job-default priority outside the supported range with job context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $priority Invalid job-default priority.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_priority_provider' )]
	public function test_register_rejects_job_default_priorities_outside_the_supported_range( int $priority ): void {
		$client  = $this->rig->operations( 'facade-tests' );
		$options = new JobOptions( priority: $priority );
		$job     = new RecordingJob( 'priority-job' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( \sprintf( 'Background-work "priority-job" priority %d is invalid; pass a value from 0 through 255.', $priority ) );

		$client->register( $job->definition( $options ) );
	}

	/**
	 * Registration accepts both inclusive job-default priority boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_accepts_both_job_default_priority_boundaries(): void {
		$client = $this->rig->operations( 'facade-tests' );
		$client->register( ( new RecordingJob( 'lowest-priority' ) )->definition( new JobOptions( priority: 0 ) ) );
		$client->register( ( new RecordingJob( 'highest-priority' ) )->definition( new JobOptions( priority: 255 ) ) );

		$lowest  = $client->dispatch( 'lowest-priority' );
		$highest = $client->dispatch( 'highest-priority' );

		self::assertInstanceOf( Run::class, $lowest );
		self::assertInstanceOf( Run::class, $highest );
		$calls = \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => 'enqueue_async' === $call['verb'] ) );
		self::assertCount( 2, $calls );
		self::assertSame( 0, $calls[0]['args']['priority'] ?? null );
		self::assertSame( 255, $calls[1]['args']['priority'] ?? null );
	}

	/**
	 * Registration rejects non-positive crash-reclamation windows with job context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $max_runtime Invalid maximum runtime.
	 *
	 * @return  void
	 */
	#[DataProvider( 'non_positive_max_runtime_provider' )]
	public function test_register_rejects_non_positive_max_runtime( int $max_runtime ): void {
		$client  = $this->rig->operations( 'facade-tests' );
		$options = new JobOptions( max_runtime: $max_runtime );
		$job     = new RecordingJob( 'runtime-job' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( \sprintf( 'Background-work "runtime-job" max_runtime %d is invalid; the crash-reclamation window must be at least one second, or pass null for the engine default.', $max_runtime ) );

		$client->register( $job->definition( $options ) );
	}

	/**
	 * A null schedule priority remains deferred to the registered job default.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_preserves_a_null_schedule_priority_for_job_default_resolution(): void {
		$client   = $this->rig->operations( 'facade-tests' );
		$job      = new RecordingJob( 'scheduled-job' );
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'scheduled-job', priority: null );
		$client->register( $job->definition( new JobOptions( priority: 37 ) ) );

		$synced = $client->sync( array( $schedule ) );
		self::assertTrue( $synced );
		$this->rig->backend()->calls = array();

		$dispatched = $client->dispatch_now( 'nightly' );

		self::assertInstanceOf( Run::class, $dispatched );
		$calls = \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => 'enqueue_async' === $call['verb'] ) );
		self::assertCount( 1, $calls );
		self::assertSame( 37, $calls[0]['args']['priority'] ?? null );
	}

	/**
	 * Dispatch returns a payload rejection when start arguments exceed the persisted JSON ceiling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_rejects_start_arguments_above_the_json_byte_ceiling(): void {
		$client = $this->rig->operations( 'facade-tests' );
		$result = $client->dispatch( 'oversized', array( 'payload' => \str_repeat( 'a', 8_193 - 14 ) ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ErrorCode::PayloadRejected->value, $result->get_error_code() );
		self::assertSame( 'Background-work "oversized" arguments contain 8193 JSON bytes; the limit is 8192 bytes.', $result->get_error_message() );
		self::assertNull( $result->get_error_data() );
	}

	/**
	 * Sync rejects each schedule priority outside the supported range with declaration context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $priority Invalid schedule priority.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_priority_provider' )]
	public function test_sync_rejects_schedule_priorities_outside_the_supported_range( int $priority ): void {
		$client   = $this->rig->operations( 'facade-tests' );
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', priority: $priority );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( \sprintf( 'Schedule "nightly" priority %d is invalid; pass a value from 0 through 255.', $priority ) );

		(void) $client->sync( array( $schedule ) );
	}

	/**
	 * Sync accepts both inclusive schedule priority boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_accepts_both_schedule_priority_boundaries(): void {
		$client  = $this->rig->operations( 'facade-tests' );
		$job     = new RecordingJob( 'refresh-index' );
		$lowest  = new Schedule( 'lowest-priority', Recurrence::every( 300 ), 'refresh-index', priority: 0 );
		$highest = new Schedule( 'highest-priority', Recurrence::every( 300 ), 'refresh-index', priority: 255 );
		$client->register( $job->definition() );

		$result = $client->sync( array( $lowest, $highest ) );

		self::assertTrue( $result );
	}

	/**
	 * Sync accepts the persisted JSON ceiling and returns a payload rejection for adjacent overflow.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_observes_the_schedule_argument_json_byte_ceiling(): void {
		$client   = $this->rig->operations( 'facade-tests' );
		$job      = new RecordingJob( 'refresh-index' );
		$accepted = new Schedule( 'accepted', Recurrence::every( 300 ), 'refresh-index', array( 'payload' => \str_repeat( 'a', 8_192 - 14 ) ) );
		$client->register( $job->definition() );

		self::assertTrue( $client->sync( array( $accepted ) ) );

		$rejected = new Schedule( 'rejected', Recurrence::every( 300 ), 'refresh-index', array( 'payload' => \str_repeat( 'a', 8_193 - 14 ) ) );
		$result   = $client->sync( array( $rejected ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ErrorCode::PayloadRejected->value, $result->get_error_code() );
		self::assertSame( 'Schedule "rejected" arguments contain 8193 JSON bytes; the limit is 8192 bytes.', $result->get_error_message() );
	}

	/**
	 * Sync rejects invalid schedule names with declaration context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Invalid schedule name.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_schedule_name_provider' )]
	public function test_sync_rejects_invalid_schedule_names_with_declaration_context( string $name ): void {
		$client   = $this->rig->operations( 'facade-tests' );
		$schedule = new Schedule( $name, Recurrence::every( 300 ), 'refresh-index' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( \sprintf( 'Schedule "%s": Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.', $name ) );

		(void) $client->sync( array( $schedule ) );
	}

	/**
	 * Sync names the repeated schedule when a declaration reuses a scope-local name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_rejects_a_repeated_schedule_name_and_names_the_declaration(): void {
		$client = $this->rig->operations( 'facade-tests' );
		$first  = new Schedule( 'nightly-rebuild', Recurrence::every( 300 ), 'refresh-index' );
		$second = new Schedule( 'nightly-rebuild', Recurrence::every( 900 ), 'refresh-index' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Schedule "nightly-rebuild" is declared more than once; schedule sync accepts each scope-local schedule name exactly once.' );

		(void) $client->sync( array( $first, $second ) );
	}

	/**
	 * Sync applies the inclusive 64-byte schedule-name ceiling with declaration context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_accepts_64_schedule_name_bytes_and_rejects_65(): void {
		$client   = $this->rig->operations( 'facade-tests' );
		$job      = new RecordingJob( 'refresh-index' );
		$accepted = new Schedule( \str_repeat( 'a', 64 ), Recurrence::every( 300 ), 'refresh-index' );
		$client->register( $job->definition() );

		self::assertTrue( $client->sync( array( $accepted ) ) );

		$name     = \str_repeat( 'a', 65 );
		$rejected = new Schedule( $name, Recurrence::every( 300 ), 'refresh-index' );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( \sprintf( 'Schedule "%s": Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.', $name ) );

		(void) $client->sync( array( $rejected ) );
	}

	/**
	 * Sync rejects an invalid target job name with declaration context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_rejects_an_invalid_target_job_name_with_declaration_context(): void {
		$client   = $this->rig->operations( 'facade-tests' );
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'Refresh Index' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Schedule "nightly" target job: Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		(void) $client->sync( array( $schedule ) );
	}

	/**
	 * Unknown work and cross-kind collisions remain observable at public boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_facade_rejects_unknown_and_ambiguous_work_before_scheduling(): void {
		$client                      = $this->rig->operations( 'facade-tests' );
		$this->rig->backend()->calls = array();
		$unknown                     = $client->dispatch( 'missing' );
		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertSame( ErrorCode::UnknownJob->value, $unknown->get_error_code() );
		self::assertSame( array(), $this->rig->backend()->calls );

		$client->register( ( new RecordingJob( 'shared' ) )->definition() );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work identity "facade-tests:shared" is already registered as a job; it cannot also be registered as a chunked_job.' );
		$client->register( ( new RecordingChunkedJob( 'shared' ) )->definition() );
	}

	/**
	 * Retrying a retained failure consumes it and schedules a fresh run.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The fixture-built failed row and option-function ledger prove the facade consumes authoritative storage through exact SQL CAS instead of a non-atomic WordPress option write.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_consumes_authoritative_storage_without_option_function_writes(): void {
		$work_identity = Identity::compose( 'facade-tests', 'email-digest' );
		$identity      = (string) $work_identity;
		$client        = $this->rig->operations( 'facade-tests' );
		$client->register( ( new RecordingJob( 'email-digest' ) )->definition() );
		$failure               = new RunFailure( identity: $identity, run_id: RunId::from( self::FAILED_RUN_ID ), attempts: 1, stage: RunFailureStage::execution(), code: ErrorCode::ExecutionFailed, summary: 'Handler failed.', details: null );
		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( $identity )->failed( self::NOW - 1, array( 'site_id' => 7 ), $failure, new EngineError( 'Handler failed.' ) );
		$this->rig->wpdb()->put( $option_name, $raw );
		$GLOBALS['a8csp_bgje_test_option_calls'] = array();

		$result = $client->retry_failed( 'email-digest', self::FAILED_RUN_ID );

		self::assertInstanceOf( Run::class, $result );
		self::assertSame( $identity, $result->identity );
		self::assertNotSame( self::FAILED_RUN_ID, (string) $result->id );
		self::assertSame( RunStatus::Running, $result->status );
		$remaining = new FailedRunStore( $work_identity, new OptionRows( $this->rig->wpdb() ), $this->rig->logger() )->all();
		self::assertInstanceOf( Success::class, $remaining );
		self::assertSame( array(), $remaining->value );
		$this->assert_option_functions_did_not_write( $option_name );
		$this->rig->backend()->assert_scheduled( $identity );
	}

	/**
	 * Cancellation through the scope-bound facade terminalizes retained waiting work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_terminalizes_a_waiting_public_run(): void {
		$client = $this->rig->operations( 'facade-tests' );
		$client->register( ( new RecordingJob( 'email-digest' ) )->definition() );
		$enqueued = $client->dispatch( 'email-digest' );
		self::assertInstanceOf( Run::class, $enqueued );

		$cancelled = $client->cancel( 'email-digest', (string) $enqueued->id );

		self::assertInstanceOf( Run::class, $cancelled );
		self::assertSame( $enqueued->identity, $cancelled->identity );
		self::assertSame( (string) $enqueued->id, (string) $cancelled->id );
		self::assertSame( RunStatus::Cancelled, $cancelled->status );
		self::assertSame( 'cancelled', $this->rig->inspection()->runs( Identity::compose( 'facade-tests', 'email-digest' ) )['history'][0]['outcome'] ?? null );
	}

	/**
	 * Scope-bound inspection projects retained statuses and retention absence as public runs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scope_run_inspection_projects_public_status_and_retention_absence(): void {
		$client = $this->rig->operations( 'facade-tests' );
		$client->register( ( new RecordingJob( 'email-digest' ) )->definition() );

		$missing = $client->inspect( 'email-digest', self::FAILED_RUN_ID );
		self::assertNull( $missing );

		$last_completed = $client->last_completed_run( 'email-digest' );
		self::assertNull( $last_completed );

		$dispatched = $client->dispatch( 'email-digest' );
		self::assertInstanceOf( Run::class, $dispatched );

		$running = $client->inspect( 'email-digest', (string) $dispatched->id );
		self::assertInstanceOf( Run::class, $running );
		self::assertSame( $dispatched->identity, $running->identity );
		self::assertSame( (string) $dispatched->id, (string) $running->id );
		self::assertSame( RunStatus::Running, $running->status );

		$this->rig->run_due();

		$completed = $client->inspect( 'email-digest', (string) $dispatched->id );
		self::assertInstanceOf( Run::class, $completed );
		self::assertSame( RunStatus::Completed, $completed->status );

		$last_completed = $client->last_completed_run( 'email-digest' );
		self::assertInstanceOf( Run::class, $last_completed );
		self::assertSame( $completed->identity, $last_completed->identity );
		self::assertSame( (string) $completed->id, (string) $last_completed->id );
		self::assertSame( $completed->status, $last_completed->status );
	}

	/**
	 * A completion outlives its eviction from the capped terminal history window.
	 *
	 * The buffer holds every terminal outcome, so on an identity that fails often the completion is
	 * carried out of it by later failures — at exactly the moment a caller most wants to know when
	 * the work last succeeded. The non-evicting slot is what keeps the answer available, while
	 * per-run inspection still stops at the retained window.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_last_completed_run_survives_eviction_from_the_retained_window(): void {
		$filters = $GLOBALS['a8csp_bgje_test_filter_values'] ?? null;
		self::assertIsArray( $filters );
		$filters['a8csp_bgje/history_size']       = 1;
		$GLOBALS['a8csp_bgje_test_filter_values'] = $filters;

		$client = $this->rig->operations( 'facade-tests' );
		$client->register( ( new RecordingJob( 'email-digest' ) )->definition() );

		$completed = $client->dispatch( 'email-digest' );
		self::assertInstanceOf( Run::class, $completed );
		$this->rig->run_due();

		$retained = $client->last_completed_run( 'email-digest' );
		self::assertInstanceOf( Run::class, $retained );
		self::assertSame( (string) $completed->id, (string) $retained->id );
		self::assertSame( RunStatus::Completed, $retained->status );
		self::assertSame( self::NOW, $retained->terminal_at );

		++$this->rig->clock()->timestamp;
		$newer = $client->dispatch( 'email-digest' );
		self::assertInstanceOf( Run::class, $newer );
		self::assertNotSame( (string) $completed->id, (string) $newer->id );
		$cancelled = $client->cancel( 'email-digest', (string) $newer->id );
		self::assertInstanceOf( Run::class, $cancelled );

		$evicted_completion = $client->inspect( 'email-digest', (string) $completed->id );
		self::assertNull( $evicted_completion );

		$still_answered = $client->last_completed_run( 'email-digest' );
		self::assertInstanceOf( Run::class, $still_answered );
		self::assertSame( (string) $completed->id, (string) $still_answered->id );
		self::assertSame( RunStatus::Completed, $still_answered->status );
		self::assertSame( self::NOW, $still_answered->terminal_at );
	}

	/**
	 * Every scope operation preserves its existing failure triple when it returns a WordPress error directly.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string               $method  Scope operation under test.
	 * @param   string               $code    Stable public error code.
	 * @param   string               $message Engine-authored public message.
	 * @param   array<string, mixed> $data    Sanitised public error data.
	 *
	 * @return  void
	 */
	#[DataProvider( 'operation_failure_triples' )]
	public function test_operations_return_wordpress_errors_without_changing_failure_triples( string $method, string $code, string $message, ?array $data ): void {
		$client = $this->rig->operations( 'triple-tests' );
		$run_id = '00000000001700000000-0000000000000000042';
		if ( 'sync' === $method ) {
			$client->register( ( new RecordingJob( 'target' ) )->definition() );
		} elseif ( \in_array( $method, array( 'inspect', 'last_completed_run', 'retry_failed', 'cancel' ), true ) ) {
			$client->register( ( new RecordingJob( 'job' ) )->definition() );
		}
		if ( \in_array( $method, array( 'inspect', 'last_completed_run' ), true ) ) {
			$this->rig->wpdb()->before_next(
				'select',
				static function ( WpdbLockSpy $database ): void {
					$database->last_error = 'private database detail';
				}
			);
		}

		$result = match ( $method ) {
			'dispatch'           => $client->dispatch( 'missing' ),
			'sync'               => $client->sync( array( new Schedule( 'oversized', Recurrence::every( 300 ), 'target', array( 'payload' => \str_repeat( 'a', 8_193 - 14 ) ) ) ) ),
			'dispatch_now'       => $client->dispatch_now( 'missing' ),
			'inspect'            => $client->inspect( 'job', $run_id ),
			'last_completed_run' => $client->last_completed_run( 'job' ),
			'retry_failed'       => $client->retry_failed( 'job', $run_id ),
			'cancel'             => $client->cancel( 'job', $run_id ),
			default              => self::fail( \sprintf( 'Unsupported scope operation "%s".', $method ) ),
		};

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code() );
		self::assertSame( $message, $result->get_error_message() );
		self::assertSame( $data, $result->get_error_data() );
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Supplies priorities immediately outside both inclusive boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  iterable<string, array{priority: int}>
	 */
	public static function invalid_priority_provider(): iterable {
		yield 'below minimum' => array( 'priority' => -1 );
		yield 'above maximum' => array( 'priority' => 256 );
	}

	/**
	 * Supplies non-positive maximum runtimes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  iterable<string, array{max_runtime: int}>
	 */
	public static function non_positive_max_runtime_provider(): iterable {
		yield 'zero' => array( 'max_runtime' => 0 );
		yield 'negative' => array( 'max_runtime' => -1 );
	}

	/**
	 * Supplies one captured failure triple for every result-returning scope operation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{method: string, code: string, message: string, data: array<string, mixed>|null}>
	 */
	public static function operation_failure_triples(): array {
		return array(
			'dispatch'           => array(
				'method'  => 'dispatch',
				'code'    => 'unknown_job',
				'message' => 'Background-work "triple-tests:missing" is not registered; register it before dispatching.',
				'data'    => array( 'identity' => 'triple-tests:missing' ),
			),
			'sync'               => array(
				'method'  => 'sync',
				'code'    => 'payload_rejected',
				'message' => 'Schedule "oversized" arguments contain 8193 JSON bytes; the limit is 8192 bytes.',
				'data'    => null,
			),
			'dispatch now'       => array(
				'method'  => 'dispatch_now',
				'code'    => 'unknown_schedule',
				'message' => 'Schedule "missing" for scope "triple-tests" is not synchronized; declare it with sync() before running it now.',
				'data'    => array(
					'scope'    => 'triple-tests',
					'schedule' => 'missing',
				),
			),
			'inspect'            => array(
				'method'  => 'inspect',
				'code'    => 'storage_failed',
				'message' => 'Authoritative option-row read failed; repair WordPress option reads and retry.',
				'data'    => array( 'option_name' => 'a8csp_bgje_active_run_triple-tests:job_00000000001700000000-0000000000000000042' ),
			),
			'last completed run' => array(
				'method'  => 'last_completed_run',
				'code'    => 'storage_failed',
				'message' => 'Authoritative option-row read failed; repair WordPress option reads and retry.',
				'data'    => array( 'option_name' => 'a8csp_bgje_run_history_triple-tests:job' ),
			),
			'retry failed'       => array(
				'method'  => 'retry_failed',
				'code'    => 'run_not_retained',
				'message' => 'Failed run "00000000001700000000-0000000000000000042" for background-work "triple-tests:job" is not retained; retry a run identifier returned by the failed-run store after a terminal failure is recorded.',
				'data'    => array(
					'identity' => 'triple-tests:job',
					'run_id'   => '00000000001700000000-0000000000000000042',
				),
			),
			'cancel'             => array(
				'method'  => 'cancel',
				'code'    => 'run_not_retained',
				'message' => 'Run "00000000001700000000-0000000000000000042" for background-work "triple-tests:job" is not retained; nothing remains to cancel.',
				'data'    => array(
					'identity' => 'triple-tests:job',
					'run_id'   => '00000000001700000000-0000000000000000042',
				),
			),
		);
	}

	/**
	 * Supplies names outside the complete stable-name grammar.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{name: string}>
	 */
	public static function invalid_schedule_name_provider(): array {
		return array(
			'empty'     => array( 'name' => '' ),
			'uppercase' => array( 'name' => 'RefreshIndex' ),
			'space'     => array( 'name' => 'refresh index' ),
			'period'    => array( 'name' => 'refresh.index' ),
			'non-ASCII' => array( 'name' => 'réindex' ),
		);
	}

	/**
	 * Registration subscribes a declared completion role to the identity's completed hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_drives_a_declared_completion_role_when_the_run_completes(): void {
		$client = $this->rig->operations( 'facade-tests' );
		$job    = new RecordingCompletionJob( 'digest' );
		$client->register( $job->definition() );
		$this->rig->activate_registered_hooks();

		$run = $client->dispatch( 'digest', array( 'site_id' => 7 ) );

		self::assertInstanceOf( Run::class, $run );
		$this->rig->run_due();
		$this->rig->assert_completed();
		self::assertCount( 1, $job->completions );
		self::assertSame( (string) $run->id, (string) $job->completions[0]['run_id'] );
		self::assertSame( array( 'site_id' => 7 ), $job->completions[0]['start_args'] );
		self::assertNull( $job->completions[0]['previous_completed_run_id'] );
	}

	/**
	 * The completion role receives the hook's previous-completed argument, not a null placeholder.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_declared_completion_role_receives_the_previous_completed_run(): void {
		$client = $this->rig->operations( 'facade-tests' );
		$job    = new RecordingCompletionJob( 'digest' );
		$client->register( $job->definition() );
		$this->rig->activate_registered_hooks();

		$first = $client->dispatch( 'digest' );
		self::assertInstanceOf( Run::class, $first );
		$this->rig->run_due();

		$this->rig->randomizer()->value = 4_242;
		$second                         = $client->dispatch( 'digest' );
		self::assertInstanceOf( Run::class, $second );
		$this->rig->run_due();

		self::assertCount( 2, $job->completions );
		self::assertNull( $job->completions[0]['previous_completed_run_id'] );
		self::assertSame( (string) $first->id, (string) $job->completions[1]['previous_completed_run_id'] );
		self::assertSame( (string) $second->id, (string) $job->completions[1]['run_id'] );
	}

	/**
	 * Schedule-registration inspection reports each declared registration's observable live state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_schedules_reports_declared_registrations(): void {
		$client = $this->rig->operations( 'facade-tests' );
		$client->register( ( new RecordingJob( 'refresh-index' ) )->definition() );
		$client->register( ( new RecordingJob( 'prune' ) )->definition() );
		self::assertTrue(
			$client->sync(
				array(
					new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' ),
					new Schedule( 'weekly', Recurrence::every( 900 ), 'prune' ),
				)
			)
		);

		// The backend answers occurrence visibility, so drive it rather than asserting its default.
		$this->rig->backend()->scheduled = true;

		$registered = $client->registered_schedules();

		self::assertIsArray( $registered );
		self::assertSame( self::NOW, $registered['observed_at'] );
		self::assertFalse( $registered['dormant_backend'] );
		self::assertCount( 2, $registered['schedules'] );
		self::assertSame(
			array(
				'name'               => 'nightly',
				'identity'           => 'facade-tests:nightly',
				'recurrence'         => 300,
				'next_due'           => self::NOW + 300,
				'last_fired'         => null,
				'misfire_skips'      => 0,
				'overlap_skips'      => 0,
				'occurrence_visible' => true,
			),
			$registered['schedules'][0]
		);
		self::assertSame( 'weekly', $registered['schedules'][1]['name'] );
		self::assertSame( 900, $registered['schedules'][1]['recurrence'] );
	}

	/**
	 * Schedule-registration inspection is confined to the bound scope.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_schedules_excludes_another_scope(): void {
		$client = $this->rig->operations( 'facade-tests' );
		$client->register( ( new RecordingJob( 'refresh-index' ) )->definition() );
		self::assertTrue( $client->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' ) ) ) );

		$neighbour = $this->rig->operations( 'other-scope' );
		$neighbour->register( ( new RecordingJob( 'refresh-index' ) )->definition() );
		self::assertTrue( $neighbour->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' ) ) ) );

		$registered = $client->registered_schedules();

		self::assertIsArray( $registered );
		self::assertSame( array( 'facade-tests:nightly' ), \array_column( $registered['schedules'], 'identity' ) );
	}

	/**
	 * A registration the current request stopped declaring reports a null recurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_schedules_reports_a_null_recurrence_for_an_undeclared_registration(): void {
		// A registry row with no request-local declaration is the state a request that did not sync sees.
		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( 'facade-tests:refresh-index' )->schedule_registration(
			array(
				'scope'         => 'facade-tests',
				'declarations'  => array(),
				'registrations' => array(
					'facade-tests:nightly' => StoreFixtureBuilder::schedule_registration_state( 'stale-fingerprint', self::NOW + 300, last_fired: self::NOW - 120, misfire_skips: 2, overlap_skips: 1 ),
				),
			)
		);
		$this->rig->wpdb()->put( $option_name, $raw );

		$registered = $this->rig->operations( 'facade-tests' )->registered_schedules();

		self::assertIsArray( $registered );
		self::assertSame(
			array(
				'name'               => 'nightly',
				'identity'           => 'facade-tests:nightly',
				'recurrence'         => null,
				'next_due'           => self::NOW + 300,
				'last_fired'         => self::NOW - 120,
				'misfire_skips'      => 2,
				'overlap_skips'      => 1,
				'occurrence_visible' => false,
			),
			$registered['schedules'][0]
		);
	}

	/**
	 * An unreadable schedule registry surfaces as a storage failure rather than an empty list.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registered_schedules_reports_an_unreadable_registry(): void {
		$client = $this->rig->operations( 'facade-tests' );
		$client->register( ( new RecordingJob( 'refresh-index' ) )->definition() );
		self::assertTrue( $client->sync( array( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' ) ) ) );

		$this->rig->wpdb()->fail_next_read_at( 'query_filtered' );
		$result = $client->registered_schedules();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ErrorCode::StorageFailed->value, $result->get_error_code() );
		self::assertSame( 'The schedule registry could not be read; repair WordPress option reads and retry.', $result->get_error_message() );
	}

	/**
	 * The completed hook's previous-completion argument survives eviction from the history buffer.
	 *
	 * It is frozen from the same non-evicting slot the boundary verb reads, so the hook payload and
	 * `runs()->last_completed()` cannot disagree about which run completed last.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_previous_completed_run_survives_eviction_from_the_retained_window(): void {
		$filters = $GLOBALS['a8csp_bgje_test_filter_values'] ?? null;
		self::assertIsArray( $filters );
		$filters['a8csp_bgje/history_size']       = 1;
		$GLOBALS['a8csp_bgje_test_filter_values'] = $filters;

		$client = $this->rig->operations( 'facade-tests' );
		$job    = new RecordingCompletionJob( 'digest' );
		$client->register( $job->definition() );
		$this->rig->activate_registered_hooks();

		$first = $client->dispatch( 'digest' );
		self::assertInstanceOf( Run::class, $first );
		$this->rig->run_due();

		// A later terminal outcome fills the one-entry buffer and carries the completion out of it.
		++$this->rig->clock()->timestamp;
		$this->rig->randomizer()->value = 4_242;
		$cancelled                      = $client->dispatch( 'digest' );
		self::assertInstanceOf( Run::class, $cancelled );
		self::assertInstanceOf( Run::class, $client->cancel( 'digest', (string) $cancelled->id ) );

		++$this->rig->clock()->timestamp;
		$this->rig->randomizer()->value = 8_484;
		$second                         = $client->dispatch( 'digest' );
		self::assertInstanceOf( Run::class, $second );
		$this->rig->run_due();

		self::assertCount( 2, $job->completions );
		self::assertSame( (string) $second->id, (string) $job->completions[1]['run_id'] );
		self::assertSame( (string) $first->id, (string) $job->completions[1]['previous_completed_run_id'] );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Asserts WordPress option helpers never wrote one authoritative row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Authoritative option name.
	 *
	 * @return  void
	 */
	private function assert_option_functions_did_not_write( string $option_name ): void {
		$calls = $GLOBALS['a8csp_bgje_test_option_calls'] ?? null;
		self::assertIsArray( $calls );
		foreach ( $calls as $call ) {
			if ( ! \is_array( $call ) ) {
				throw new \LogicException( 'The option-call ledger contains a malformed entry.' );
			}
			$args = $call['args'] ?? null;
			self::assertNotSame( $option_name, \is_array( $args ) ? ( $args[0] ?? null ) : null );
		}
	}

	// endregion.
}
