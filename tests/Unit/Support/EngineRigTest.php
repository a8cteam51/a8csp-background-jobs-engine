<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ExistingRunPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\JobType;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shared behavioral harness contract used by engine suites.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversNothing]
final class EngineRigTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS      = array( 'site_id' => 7 );
	private const string IDENTITY = 'rig-tests:job';
	private const int NOW         = 1_700_000_000;
	private const string RUN_ID   = '00000000001700000000-0000000000000000042';

	// endregion.

	// region LIFECYCLE.

	/** Loads the guarded WordPress seams used by the harness. */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
	}

	// endregion.

	// region TESTS.

	/** A completed job traverses the front door, registered run hook, lifecycle hooks, and Runs facade. */
	public function test_job_completion_round_trips_through_the_real_graph(): void {
		$rig = EngineRig::set_up( self::NOW );
		try {
			$client = $rig->client( 'rig-tests' );
			$job    = new RecordingJob( 'job' );
			$client->jobs()->register( $job );
			$result = $client->jobs()->enqueue( 'job', self::ARGS );
			self::assertInstanceOf( Success::class, $result );

			$rig->run_due();

			self::assertSame( array( self::ARGS ), $job->calls );
			$rig->assert_completed();
			$rig->assert_no_retry();
			self::assertSame( array( self::IDENTITY, self::RUN_ID, self::ARGS ), $rig->hooks()->fired( 'a8csp_jobs_engine/completed' )[0] ?? null );
		} finally {
			$rig->tear_down();
		}
	}

	/** A non-retryable job failure exposes RunFailure and retains no retry delivery. */
	public function test_terminal_failure_helpers_observe_real_failure_lifecycle(): void {
		$rig = EngineRig::set_up( self::NOW );
		try {
			$client = $rig->client( 'rig-tests' );
			$job    = new RecordingJob( 'job' );

			$job->throwable = new NonRetryableException( 'Permanent failure.' );
			$client->jobs()->register( $job );
			$result = $client->jobs()->enqueue( 'job', self::ARGS );
			self::assertInstanceOf( Success::class, $result );

			$rig->run_due();

			$rig->assert_failed( ApiErrorCode::ExecutionFailed );
			$rig->assert_no_retry();
		} finally {
			$rig->tear_down();
		}
	}

	/** A retryable job failure emits the retry-scheduled notification and retains the real scheduled redelivery. */
	public function test_retry_helper_observes_real_failure_redelivery(): void {
		$rig = EngineRig::set_up( self::NOW );
		try {
			$client = $rig->client( 'rig-tests' );
			$job    = new RecordingJob( 'job' );

			$job->throwable = new \RuntimeException( 'Transient failure.' );
			$client->jobs()->register( $job );
			$result = $client->jobs()->enqueue( 'job', self::ARGS );
			self::assertInstanceOf( Success::class, $result );

			$rig->run_due();

			$rig->assert_retry_scheduled();
		} finally {
			$rig->tear_down();
		}
	}

	/** Cancellation through the Runs facade emits the terminal cancellation lifecycle. */
	public function test_cancelled_helper_observes_real_runs_facade_cancellation(): void {
		$rig = EngineRig::set_up( self::NOW );
		try {
			$client = $rig->client( 'rig-tests' );
			$client->jobs()->register( new RecordingJob( 'job' ) );
			$enqueued = $client->jobs()->enqueue( 'job', self::ARGS );
			self::assertInstanceOf( Success::class, $enqueued );
			self::assertIsString( $enqueued->value );

			$cancelled = $client->runs()->cancel( 'job', $enqueued->value );
			self::assertInstanceOf( Success::class, $cancelled );
			$rig->assert_cancelled();
		} finally {
			$rig->tear_down();
		}
	}

	/** Replacement through the chunked job facade emits supersession for the fenced incumbent. */
	public function test_superseded_helper_observes_real_chunked_job_replacement(): void {
		$rig = EngineRig::set_up( self::NOW );
		try {
			$client = $rig->client( 'rig-tests' );
			$client->chunked_jobs()->register( new RecordingChunkedJob( 'chunked_job' ) );
			$first = $client->chunked_jobs()->start( 'chunked_job', self::ARGS );
			self::assertInstanceOf( Success::class, $first );
			++$rig->clock()->timestamp;

			$replacement = $client->chunked_jobs()->start( 'chunked_job', self::ARGS, ExistingRunPolicy::Replace );
			self::assertInstanceOf( Success::class, $replacement );
			$rig->run_due();
			$rig->assert_superseded();
		} finally {
			$rig->tear_down();
		}
	}

	/** Every store-family fixture is emitted by its production write path. */
	public function test_store_fixture_builder_uses_production_encoders(): void {
		$fixtures = StoreFixtureBuilder::for_identity( self::IDENTITY );

		$args_hash = $fixtures->args_hash( self::ARGS );

		$state = new RunState( status: RunStatus::Running, kind: JobType::Job, executing: false, start_args: self::ARGS, args_hash: $args_hash, queue: array( self::ARGS ), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( 'run', 10 ) );

		[ $run_name, $run_raw ] = $fixtures->run( self::RUN_ID, $state );
		self::assertSame( 'a8csp_bgje_run_' . self::IDENTITY . '_' . self::RUN_ID, $run_name );
		self::assertSame( 'Job', self::decoded( $run_raw )['kind'] ?? null );
		self::assertSame( self::ARGS, self::decoded( $run_raw )['start_args'] ?? null );

		$failure = new RunFailure( identity: self::IDENTITY, run_id: self::RUN_ID, attempts: 2, stage: RunFailureStage::Execution, code: ApiErrorCode::ExecutionFailed, summary: 'Engine-authored failure.', failed_chunk: null );

		[ $failed_name, $failed_raw ] = $fixtures->failed( self::NOW, self::ARGS, $failure, new EngineError( $failure->summary ) );
		self::assertSame( 'a8csp_bgje_failed_runs_' . self::IDENTITY, $failed_name );
		$failed_entry = self::decoded( $failed_raw )[0] ?? null;
		self::assertIsArray( $failed_entry );
		self::assertSame( self::RUN_ID, $failed_entry['run_id'] ?? null );

		[ $history_name, $history_raw ] = $fixtures->history(
			array(
				array(
					'run_id'    => self::RUN_ID,
					'args_hash' => $args_hash,
				),
			),
			array(
				array(
					'run_id'    => self::RUN_ID,
					'args_hash' => $args_hash,
					'status'    => RunStatus::Completed,
				),
			)
		);
		self::assertSame( 'a8csp_bgje_history_' . self::IDENTITY, $history_name );
		$terminal = self::decoded( $history_raw )['terminal'] ?? null;
		self::assertIsArray( $terminal );
		$terminal_entry = $terminal[0] ?? null;
		self::assertIsArray( $terminal_entry );
		self::assertSame( self::RUN_ID, $terminal_entry['run_id'] ?? null );

		[ $latest_name, $latest_raw ] = $fixtures->latest(
			array(
				array(
					'run_id'    => self::RUN_ID,
					'args_hash' => $args_hash,
				),
			)
		);
		self::assertSame( 'a8csp_bgje_latest_run_' . self::IDENTITY, $latest_name );
		self::assertSame( self::RUN_ID, self::decoded( $latest_raw )['all'] ?? null );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns one decoded raw fixture as an array.
	 *
	 * @param   string $raw Raw serialized fixture.
	 *
	 * @return array<array-key, mixed>
	 */
	private static function decoded( string $raw ): array {
		$value = RawOptionDecoder::decode( $raw );
		self::assertIsArray( $value );

		return $value;
	}

	// endregion.
}
