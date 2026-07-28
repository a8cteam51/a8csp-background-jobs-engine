<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Includes;

use A8C\SpecialProjects\BackgroundJobsEngine\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the procedural facade through the production engine graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversFunction( 'a8csp_bgje_register_job' )]
#[CoversFunction( 'a8csp_bgje_dispatch_job' )]
#[CoversFunction( 'a8csp_bgje_dispatch_job_at' )]
#[CoversFunction( 'a8csp_bgje_sync_schedules' )]
#[CoversFunction( 'a8csp_bgje_dispatch_schedule' )]
#[CoversFunction( 'a8csp_bgje_inspect_run' )]
#[CoversFunction( 'a8csp_bgje_last_completed_run' )]
#[CoversFunction( 'a8csp_bgje_retry_failed_run' )]
#[CoversFunction( 'a8csp_bgje_cancel_run' )]
final class ProceduralFacadeTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const int NOW      = 1_700_000_000;
	private const string SCOPE = 'procedural-facade';

	private EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads the public functions, deterministic engine seams, and WordPress error stand-in.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
		require_once \dirname( __DIR__ ) . '/wp-cron-stubs.php';
	}

	/**
	 * Publishes one isolated production graph.
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
	 * Clears request-local engine and WordPress state.
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
	 * Registration aliases preserve the public success and error result shapes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registration_aliases_delegate_to_the_bound_engine(): void {
		$job         = self::job( 'job' );
		$chunked_job = self::chunked_job( 'chunked-job' );

		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, $job ) );
		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, $chunked_job ) );
		self::assert_wp_error( \a8csp_bgje_register_job( self::SCOPE, $job ), 'already_registered' );
	}

	/**
	 * Admission aliases preserve arguments and return running run projections.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_admission_aliases_delegate_every_argument(): void {
		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, self::job( 'job' ) ) );
		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, self::chunked_job( 'chunked-job' ) ) );
		$job_args   = array( 'site_id' => 7 );
		$start_args = array( 'scope' => 'all' );

		$job_run     = self::assert_run( \a8csp_bgje_dispatch_job_at( self::SCOPE, 'job', self::NOW + 15, $job_args, 23 ), self::SCOPE . ':job', RunStatus::Running );
		$chunked_run = self::assert_run( \a8csp_bgje_dispatch_job( self::SCOPE, 'chunked-job', $start_args, priority: 31 ), self::SCOPE . ':chunked-job', RunStatus::Running );

		self::assertNotSame( '', (string) $job_run->id );
		self::assertNotSame( '', (string) $chunked_run->id );
		self::assertSame( self::NOW + 15, self::latest_backend_call( $this->rig, 'schedule_single' )['args']['timestamp'] ?? null );
		self::assertSame( 23, self::latest_backend_call( $this->rig, 'schedule_single' )['args']['priority'] ?? null );
		self::assertSame( 31, self::latest_backend_call( $this->rig, 'enqueue_async' )['args']['priority'] ?? null );
		self::assertEquals( array( array( $job_run->id, $job_args ) ), $this->rig->hooks()->fired( 'a8csp_bgje/started/' . self::SCOPE . ':job' ) );

		$this->rig->run_due();
		self::assertEquals( array( array( $chunked_run->id, $start_args ) ), $this->rig->hooks()->fired( 'a8csp_bgje/started/' . self::SCOPE . ':chunked-job' ) );
	}

	/**
	 * Past absolute dispatch aliases use the asynchronous admission lane for both installed work kinds.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_job_at_routes_past_job_and_chunked_job_admissions_to_the_async_lane(): void {
		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, self::job( 'job' ) ) );
		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, self::chunked_job( 'chunked-job' ) ) );
		$this->rig->backend()->calls = array();

		$job_run     = self::assert_run( \a8csp_bgje_dispatch_job_at( self::SCOPE, 'job', self::NOW - 1, array( 'site_id' => 7 ), 23 ), self::SCOPE . ':job', RunStatus::Running );
		$chunked_run = self::assert_run( \a8csp_bgje_dispatch_job_at( self::SCOPE, 'chunked-job', self::NOW - 1, array( 'scope' => 'all' ), 31 ), self::SCOPE . ':chunked-job', RunStatus::Running );
		$job_state   = \get_option( 'a8csp_bgje_active_run_' . self::SCOPE . ':job_' . $job_run->id );
		$chunk_state = \get_option( 'a8csp_bgje_active_run_' . self::SCOPE . ':chunked-job_' . $chunked_run->id );

		self::assertIsArray( $job_state );
		self::assertIsArray( $chunk_state );
		self::assertSame(
			array(
				'stage'    => 'run',
				'mode'     => 'async',
				'fire_at'  => null,
				'priority' => 23,
			),
			$job_state['pending'] ?? null
		);
		self::assertSame(
			array(
				'stage'    => 'start',
				'mode'     => 'async',
				'fire_at'  => null,
				'priority' => 31,
			),
			$chunk_state['pending'] ?? null
		);

		$delivery_calls = \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => \in_array( $call['verb'], array( 'enqueue_async', 'schedule_single' ), true ) ) );
		self::assertSame(
			array(
				array(
					'verb'     => 'enqueue_async',
					'identity' => self::SCOPE . ':job',
				),
				array(
					'verb'     => 'enqueue_async',
					'identity' => self::SCOPE . ':chunked-job',
				),
			),
			\array_map(
				static function ( array $call ): array {
					$call_args = $call['args']['args'] ?? null;

					return array(
						'verb'     => $call['verb'],
						'identity' => \is_array( $call_args ) ? ( $call_args[0] ?? null ) : null,
					);
				},
				$delivery_calls
			)
		);
	}

	/**
	 * One procedural dispatch verb executes registered plain and chunked jobs through their resolved kinds.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_executes_registered_plain_and_chunked_jobs_through_the_procedural_facade(): void {
		$job                = new RecordingJob( 'plain-job' );
		$chunked_job        = new RecordingChunkedJob( 'chunked-job' );
		$chunked_job->queue = array( array( 'page' => 1 ) );

		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, $job->definition() ) );
		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, $chunked_job->definition() ) );
		$job_args     = array( 'site_id' => 7 );
		$chunked_args = array( 'scope' => 'all' );

		self::assert_run( \a8csp_bgje_dispatch_job( self::SCOPE, 'plain-job', $job_args ), self::SCOPE . ':plain-job', RunStatus::Running );
		self::assert_run( \a8csp_bgje_dispatch_job( self::SCOPE, 'chunked-job', $chunked_args ), self::SCOPE . ':chunked-job', RunStatus::Running );
		for ( $delivery = 0; 5 > $delivery; ++$delivery ) {
			$this->rig->run_due();
		}

		self::assertSame( array( $job_args ), $job->calls );
		self::assertSame( array( $chunked_args ), $chunked_job->generate_calls );
		self::assertSame( array( array( 'page' => 1 ) ), \array_column( $chunked_job->process_calls, 'chunk_args' ) );
	}

	/**
	 * Schedule aliases preserve declarations and return the dispatched run projection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedule_aliases_delegate_to_the_bound_engine(): void {
		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, self::job( 'scheduled-job' ) ) );
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'scheduled-job', array( 'scope' => 'all' ), CatchUpPolicy::Skip, 41 );

		self::assertTrue( \a8csp_bgje_sync_schedules( self::SCOPE, $schedule ) );
		$run = self::assert_run( \a8csp_bgje_dispatch_schedule( self::SCOPE, 'nightly' ), self::SCOPE . ':scheduled-job', RunStatus::Running );

		self::assertNotSame( '', (string) $run->id );
		self::assertSame( 300, self::latest_backend_call( $this->rig, 'schedule_recurring' )['args']['interval'] ?? null );
		self::assertSame( 0, self::latest_backend_call( $this->rig, 'schedule_recurring' )['args']['priority'] ?? null );
		self::assertSame( 41, self::latest_backend_call( $this->rig, 'enqueue_async' )['args']['priority'] ?? null );
	}

	/**
	 * A priority-only schedule edit preserves the engine tick without backend churn.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_priority_only_schedule_edit_does_not_recreate_the_tick(): void {
		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, self::job( 'scheduled-job' ) ) );
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'scheduled-job' );

		self::assertTrue( \a8csp_bgje_sync_schedules( self::SCOPE, $schedule ) );
		self::assertSame( 0, self::latest_backend_call( $this->rig, 'schedule_recurring' )['args']['priority'] ?? null );
		self::assert_run( \a8csp_bgje_dispatch_schedule( self::SCOPE, 'nightly' ), self::SCOPE . ':scheduled-job', RunStatus::Running );
		self::assertSame( 10, self::latest_backend_call( $this->rig, 'enqueue_async' )['args']['priority'] ?? null );

		$this->rig->backend()->calls = array();
		$schedule                    = new Schedule( 'nightly', Recurrence::every( 300 ), 'scheduled-job', priority: 41 );
		self::assertTrue( \a8csp_bgje_sync_schedules( self::SCOPE, $schedule ) );
		$writes = \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => \in_array( $call['verb'], array( 'unschedule', 'schedule_recurring' ), true ) ) );
		self::assertSame( array(), $writes );

		$this->rig->clock()->timestamp = self::NOW + 300;
		$this->rig->run_due();
		$this->rig->run_due();
		$this->rig->run_due();
		self::assertSame( 41, self::latest_backend_call( $this->rig, 'enqueue_async' )['args']['priority'] ?? null );
	}

	/**
	 * Inspection aliases preserve null and retained run projections.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_inspection_aliases_delegate_to_the_bound_engine(): void {
		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, self::job( 'inspect' ) ) );
		self::assertNull( \a8csp_bgje_last_completed_run( self::SCOPE, 'inspect' ) );

		$admitted = self::assert_run( \a8csp_bgje_dispatch_job( self::SCOPE, 'inspect' ), self::SCOPE . ':inspect', RunStatus::Running );
		self::assert_run( \a8csp_bgje_inspect_run( self::SCOPE, 'inspect', (string) $admitted->id ), self::SCOPE . ':inspect', RunStatus::Running, $admitted->id );
		$this->rig->run_due();
		self::assert_run( \a8csp_bgje_inspect_run( self::SCOPE, 'inspect', (string) $admitted->id ), self::SCOPE . ':inspect', RunStatus::Completed, $admitted->id );
		self::assert_run( \a8csp_bgje_last_completed_run( self::SCOPE, 'inspect' ), self::SCOPE . ':inspect', RunStatus::Completed, $admitted->id );
	}

	/**
	 * Run aliases retain the invalid-argument boundary for malformed wire identifiers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_aliases_reject_malformed_identifiers(): void {
		self::assert_wp_error( \a8csp_bgje_inspect_run( self::SCOPE, 'inspect', 'malformed' ), 'invalid_argument' );
		self::assert_wp_error( \a8csp_bgje_retry_failed_run( self::SCOPE, 'failed', 'malformed' ), 'invalid_argument' );
		self::assert_wp_error( \a8csp_bgje_cancel_run( self::SCOPE, 'cancel', 'malformed' ), 'invalid_argument' );
	}

	/**
	 * Mutation aliases preserve replacement and terminal run projections.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_mutation_aliases_delegate_to_the_bound_engine(): void {
		$failed_job = self::job(
			'failed',
			static function ( array $start_args, RunContextInterface $context ): void {
				throw new NonRetryableException( 'Retain this failed run.' );
			}
		);
		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, $failed_job ) );
		$failed = self::assert_run( \a8csp_bgje_dispatch_job( self::SCOPE, 'failed', array( 'site_id' => 7 ) ), self::SCOPE . ':failed', RunStatus::Running );
		$this->rig->run_due();

		++$this->rig->clock()->timestamp;
		$retry = self::assert_run( \a8csp_bgje_retry_failed_run( self::SCOPE, 'failed', (string) $failed->id ), self::SCOPE . ':failed', RunStatus::Running );
		self::assertNotSame( (string) $failed->id, (string) $retry->id );

		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, self::job( 'cancel' ) ) );
		$pending   = self::assert_run( \a8csp_bgje_dispatch_job_at( self::SCOPE, 'cancel', self::NOW + 61 ), self::SCOPE . ':cancel', RunStatus::Running );
		$cancelled = self::assert_run( \a8csp_bgje_cancel_run( self::SCOPE, 'cancel', (string) $pending->id ), self::SCOPE . ':cancel', RunStatus::Cancelled, $pending->id );
		self::assertSame( (string) $pending->id, (string) $cancelled->id );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a minimal consumer-authored one-off job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param (\Closure(array<array-key, mixed>, RunContextInterface): void)|null $handler
	 *
	 * @param   string        $name    Stable scope-local job name.
	 * @param   \Closure|null $handler Optional invocation behavior.
	 *
	 * @return  JobDefinition
	 */
	private static function job( string $name, ?\Closure $handler = null ): JobDefinition {
		return JobDefinition::closure( $name, $handler ?? static function ( array $start_args, RunContextInterface $context ): void {} );
	}

	/**
	 * Returns a minimal consumer-authored chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Stable scope-local chunked job name.
	 *
	 * @return  JobDefinition
	 */
	private static function chunked_job( string $name ): JobDefinition {
		return ( new RecordingChunkedJob( $name ) )->definition();
	}

	/**
	 * Asserts one public run projection and returns it for identity chaining.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed      $value    Expected run value.
	 * @param   string     $identity Expected scope-qualified identity.
	 * @param   RunStatus  $status   Expected public lifecycle state.
	 * @param   RunId|null $id       Expected run identifier, or null to accept the generated identifier.
	 *
	 * @return  Run
	 */
	private static function assert_run( mixed $value, string $identity, RunStatus $status, ?RunId $id = null ): Run {
		self::assertInstanceOf( Run::class, $value );
		self::assertSame( $identity, $value->identity );
		self::assertSame( $status, $value->status );
		self::assertNotSame( '', (string) $value->id );
		if ( null !== $id ) {
			self::assertSame( (string) $id, (string) $value->id );
		}

		return $value;
	}

	/**
	 * Asserts one WordPress error result.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed  $value Expected error value.
	 * @param   string $code  Expected stable error code.
	 *
	 * @return  \WP_Error
	 */
	private static function assert_wp_error( mixed $value, string $code ): \WP_Error {
		self::assertInstanceOf( \WP_Error::class, $value );
		self::assertSame( $code, $value->get_error_code(), $value->get_error_message() );

		return $value;
	}

	/**
	 * Returns the latest scheduler call for one write verb.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   EngineRig $rig  Active production graph rig.
	 * @param   string    $verb Scheduler write verb.
	 *
	 * @return  array{verb: string, args: array<string, mixed>}
	 */
	private static function latest_backend_call( EngineRig $rig, string $verb ): array {
		foreach ( \array_reverse( $rig->backend()->calls ) as $call ) {
			if ( $verb === $call['verb'] ) {
				return $call;
			}
		}

		self::fail( 'Expected a backend call for verb ' . $verb . '.' );
	}

	// endregion.
}
