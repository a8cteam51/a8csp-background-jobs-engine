<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\ScopeOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
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

		self::assertInstanceOf( Success::class, $result );
		self::assertInstanceOf( Run::class, $result->value );
		self::assertSame( 'facade-tests:email-digest', $result->value->identity );
		self::assertSame( RunStatus::Running, $result->value->status );
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

		self::assertInstanceOf( Success::class, $result );
		$this->rig->run_due();
		$this->rig->run_due();
		$this->rig->run_due();
		self::assertSame( array( array( 'site_id' => 7 ) ), $chunked_job->generate_calls );
		$this->rig->assert_completed();
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
		self::assertInstanceOf( Failure::class, $unknown );
		if ( ! $unknown->error instanceof BoundaryError ) {
			throw new \LogicException( 'Unknown work must produce a public API error.' );
		}
		self::assertSame( ErrorCode::UnknownJob, $unknown->error->code );
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

		self::assertInstanceOf( Success::class, $result );
		self::assertInstanceOf( Run::class, $result->value );
		self::assertSame( $identity, $result->value->identity );
		self::assertNotSame( self::FAILED_RUN_ID, (string) $result->value->id );
		self::assertSame( RunStatus::Running, $result->value->status );
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
		self::assertInstanceOf( Success::class, $enqueued );
		self::assertInstanceOf( Run::class, $enqueued->value );

		$cancelled = $client->cancel( 'email-digest', (string) $enqueued->value->id );

		self::assertInstanceOf( Success::class, $cancelled );
		self::assertInstanceOf( Run::class, $cancelled->value );
		self::assertSame( $enqueued->value->identity, $cancelled->value->identity );
		self::assertSame( (string) $enqueued->value->id, (string) $cancelled->value->id );
		self::assertSame( RunStatus::Cancelled, $cancelled->value->status );
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
		self::assertInstanceOf( Success::class, $missing );
		self::assertNull( $missing->value );

		$last_completed = $client->last_completed_run( 'email-digest' );
		self::assertInstanceOf( Success::class, $last_completed );
		self::assertNull( $last_completed->value );

		$dispatched = $client->dispatch( 'email-digest' );
		self::assertInstanceOf( Success::class, $dispatched );
		self::assertInstanceOf( Run::class, $dispatched->value );

		$running = $client->inspect( 'email-digest', (string) $dispatched->value->id );
		self::assertInstanceOf( Success::class, $running );
		self::assertInstanceOf( Run::class, $running->value );
		self::assertSame( $dispatched->value->identity, $running->value->identity );
		self::assertSame( (string) $dispatched->value->id, (string) $running->value->id );
		self::assertSame( RunStatus::Running, $running->value->status );

		$this->rig->run_due();

		$completed = $client->inspect( 'email-digest', (string) $dispatched->value->id );
		self::assertInstanceOf( Success::class, $completed );
		self::assertInstanceOf( Run::class, $completed->value );
		self::assertSame( RunStatus::Completed, $completed->value->status );

		$last_completed = $client->last_completed_run( 'email-digest' );
		self::assertInstanceOf( Success::class, $last_completed );
		self::assertInstanceOf( Run::class, $last_completed->value );
		self::assertSame( $completed->value->identity, $last_completed->value->identity );
		self::assertSame( (string) $completed->value->id, (string) $last_completed->value->id );
		self::assertSame( $completed->value->status, $last_completed->value->status );
	}

	/**
	 * A completed run outside the retained history window is absent at the scope boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_last_completed_run_is_null_after_completion_leaves_the_retained_window(): void {
		$filters = $GLOBALS['a8csp_bgje_test_filter_values'] ?? null;
		self::assertIsArray( $filters );
		$filters['a8csp_bgje/history_size']       = 1;
		$GLOBALS['a8csp_bgje_test_filter_values'] = $filters;

		$client = $this->rig->operations( 'facade-tests' );
		$client->register( ( new RecordingJob( 'email-digest' ) )->definition() );

		$completed = $client->dispatch( 'email-digest' );
		self::assertInstanceOf( Success::class, $completed );
		self::assertInstanceOf( Run::class, $completed->value );
		$this->rig->run_due();

		$retained = $client->last_completed_run( 'email-digest' );
		self::assertInstanceOf( Success::class, $retained );
		self::assertInstanceOf( Run::class, $retained->value );
		self::assertSame( (string) $completed->value->id, (string) $retained->value->id );
		self::assertSame( RunStatus::Completed, $retained->value->status );

		++$this->rig->clock()->timestamp;
		$newer = $client->dispatch( 'email-digest' );
		self::assertInstanceOf( Success::class, $newer );
		self::assertInstanceOf( Run::class, $newer->value );
		self::assertNotSame( (string) $completed->value->id, (string) $newer->value->id );
		$cancelled = $client->cancel( 'email-digest', (string) $newer->value->id );
		self::assertInstanceOf( Success::class, $cancelled );

		$evicted_completion = $client->inspect( 'email-digest', (string) $completed->value->id );
		self::assertInstanceOf( Success::class, $evicted_completion );
		self::assertNull( $evicted_completion->value );

		$evicted = $client->last_completed_run( 'email-digest' );
		self::assertInstanceOf( Success::class, $evicted );
		self::assertNull( $evicted->value );
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
