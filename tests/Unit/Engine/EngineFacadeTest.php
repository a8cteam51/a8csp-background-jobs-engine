<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\ExistingRunPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\ApiAdapter;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\EngineFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\StoreFixtureBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises internal facade behavior through owner-bound production flows.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( EngineFacade::class )]
#[CoversClass( ApiAdapter::class )]
final class EngineFacadeTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const int NOW = 1_700_000_000;

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
	 * Task registration, admission, delivery, and completion cross the complete facade stack.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_task_facade_round_trips_one_public_run(): void {
		$client = $this->rig->client( 'facade-tests' );
		$task   = new RecordingTask( 'email-digest' );
		$client->tasks()->register( $task );

		$result = $client->tasks()->enqueue( 'email-digest', array( 'site_id' => 7 ), delay: 300, dedup_key: 'site-7', priority: 5 );

		self::assertInstanceOf( Success::class, $result );
		$this->rig->backend()->assert_scheduled( 'facade-tests:email-digest' );
		$this->rig->run_due();
		self::assertSame( array( array( 'site_id' => 7 ) ), $task->calls );
		$this->rig->assert_completed();
	}

	/**
	 * Batch registration, admission, queue generation, and completion cross the same facade stack.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_batch_facade_round_trips_one_public_run(): void {
		$client = $this->rig->client( 'facade-tests' );
		$batch  = new RecordingBatch( 'catalog-sync' );
		$client->batches()->register( $batch );

		$result = $client->batches()->start( 'catalog-sync', array( 'site_id' => 7 ), existing: ExistingRunPolicy::Reject, priority: 23 );

		self::assertInstanceOf( Success::class, $result );
		$this->rig->run_due();
		$this->rig->run_due();
		$this->rig->run_due();
		self::assertSame( array( array( 'site_id' => 7 ) ), $batch->generate_calls );
		self::assertCount( 1, $batch->completed_calls );
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
		$client                      = $this->rig->client( 'facade-tests' );
		$this->rig->backend()->calls = array();
		$unknown                     = $client->tasks()->enqueue( 'missing' );
		self::assertInstanceOf( Failure::class, $unknown );
		if ( ! $unknown->error instanceof ApiError ) {
			throw new \LogicException( 'Unknown work must produce a public API error.' );
		}
		self::assertSame( ApiErrorCode::UnknownWork, $unknown->error->code );
		self::assertSame( array(), $this->rig->backend()->calls );

		$client->tasks()->register( new RecordingTask( 'shared' ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work identity "facade-tests:shared" is already registered as a task; it cannot also be registered as a batch.' );
		$client->batches()->register( new RecordingBatch( 'shared' ) );
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
		$identity = 'facade-tests:email-digest';
		$client   = $this->rig->client( 'facade-tests' );
		$client->tasks()->register( new RecordingTask( 'email-digest' ) );
		$failure               = new RunFailure( identity: $identity, run_id: 'failed-run', attempts: 1, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'Handler failed.', failed_chunk: null );
		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( $identity )->failed( self::NOW - 1, array( 'site_id' => 7 ), $failure, new EngineError( 'Handler failed.' ) );
		$this->rig->wpdb()->put( $option_name, $raw );
		$GLOBALS['a8csp_bgte_test_option_calls'] = array();

		$result = $client->runs()->retry_failed( 'email-digest', 'failed-run' );

		self::assertInstanceOf( Success::class, $result );
		$remaining = new FailedRunStore( $identity, new OptionRows( $this->rig->wpdb() ), $this->rig->logger() )->all();
		self::assertInstanceOf( Success::class, $remaining );
		self::assertSame( array(), $remaining->value );
		$this->assert_option_functions_did_not_write( $option_name );
		$this->rig->backend()->assert_scheduled( $identity );
	}

	/**
	 * Cancellation through the owner-bound facade terminalizes retained waiting work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_terminalizes_a_waiting_public_run(): void {
		$client = $this->rig->client( 'facade-tests' );
		$client->tasks()->register( new RecordingTask( 'email-digest' ) );
		$enqueued = $client->tasks()->enqueue( 'email-digest' );
		self::assertInstanceOf( Success::class, $enqueued );
		if ( ! \is_string( $enqueued->value ) ) {
			throw new \LogicException( 'A successful enqueue must publish a run identifier.' );
		}

		$cancelled = $client->runs()->cancel( 'email-digest', $enqueued->value );

		self::assertInstanceOf( Success::class, $cancelled );
		self::assertSame( 'cancelled', $this->rig->inspection()->runs( 'facade-tests:email-digest' )['history'][0]['outcome'] ?? null );
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
		$calls = $GLOBALS['a8csp_bgte_test_option_calls'] ?? null;
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
