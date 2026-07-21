<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\AbstractChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\AbstractJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the owner-bound public handle through the production engine graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Engine::class )]
final class EngineTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string MISSING_RUN_ID = '00000000001700000001-0000000000000000043';
	private const int NOW               = 1_700_000_000;
	private const string OWNER          = 'engine-test';

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
		require_once __DIR__ . '/wp-cron-stubs.php';
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
	 * Handle construction defers malformed-owner and unavailable-graph failures to the first verb.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_accessor_is_lazy_and_infallible(): void {
		$malformed = \a8csp_bgje( 'Invalid Owner' );
		self::assertInstanceOf( Engine::class, $malformed );
		self::assert_wp_error( $malformed->enqueue( 'job' ), 'invalid_argument' );

		$this->rig->tear_down();
		$not_ready = \a8csp_bgje( self::OWNER );
		self::assertInstanceOf( Engine::class, $not_ready );
		self::assert_wp_error( $not_ready->enqueue( 'job' ), ErrorCode::EngineUnavailable->value );
	}

	/**
	 * Object registration infers each work kind and both admission verbs project running runs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_routes_each_work_kind_and_projects_admitted_runs(): void {
		$engine      = \a8csp_bgje( self::OWNER );
		$job         = self::job( 'job' );
		$chunked_job = self::chunked_job( 'chunked-job' );

		self::assertTrue( $engine->register( $job ) );
		self::assertTrue( $engine->register( $chunked_job ) );

		$job_run     = self::assert_run( $engine->enqueue( 'job', array( 'site_id' => 7 ), 15, 23 ), self::OWNER . ':job', RunStatus::Running );
		$chunked_run = self::assert_run( $engine->start( 'chunked-job', array( 'scope' => 'all' ), 31 ), self::OWNER . ':chunked-job', RunStatus::Running );

		self::assertNotSame( '', $job_run->run_id );
		self::assertNotSame( '', $chunked_run->run_id );
		self::assertSame( 23, self::latest_backend_call( $this->rig, 'schedule_single' )['args']['priority'] ?? null );
		self::assertSame( 31, self::latest_backend_call( $this->rig, 'enqueue_async' )['args']['priority'] ?? null );
		self::assert_wp_error( $engine->register( $job ), 'already_registered' );
		self::assert_wp_error( $engine->register( $chunked_job ), 'already_registered' );
	}

	/**
	 * Callable options control runtime credit, retries, overlap identity, and terminal notifications.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_callable_materializes_policies_and_terminal_callbacks(): void {
		/** @var list<array<array-key, mixed>> $overlap_args */
		$overlap_args = array();
		/** @var list<array{run_id: string, args: array<array-key, mixed>, previous: string|null}> $completed */
		$completed = array();
		/** @var list<array{run_id: string, args: array<array-key, mixed>, failure: RunFailure}> $failed */
		$failed             = array();
		$observed_heartbeat = null;
		$observed_run_ids   = array();
		$engine             = \a8csp_bgje( self::OWNER );
		$handler            = function ( array $args, RunContext $context ) use ( &$observed_heartbeat, &$observed_run_ids ): void {
			$observed_run_ids[] = $context->get_run_id();
			if ( null === $observed_heartbeat ) {
				$snapshot           = $this->rig->inspection()->runs( self::OWNER . ':callable' );
				$observed_heartbeat = $snapshot['live'][0]['heartbeat_at'] ?? null;
			}
			if ( true === ( $args['fail'] ?? false ) ) {
				throw new \RuntimeException( 'Retry this callable failure.' );
			}
		};

		$options = array(
			'max_runtime'  => 42,
			'retry'        => array(
				'max_attempts' => 3,
				'base_delay'   => 50,
				'multiplier'   => 3,
				'max_delay'    => 150,
			),
			'overlap'      => 'reject',
			'overlap_key'  => static function ( array $args ) use ( &$overlap_args ): string {
				$overlap_args[] = $args;
				$site_id        = $args['site_id'] ?? null;
				if ( ! \is_int( $site_id ) ) {
					throw new \UnexpectedValueException( 'The test overlap key requires an integer site_id.' );
				}

				return 'site-' . $site_id;
			},
			'on_completed' => static function ( string $run_id, array $args, ?string $previous_completed_run_id ) use ( &$completed ): void {
				$completed[] = array(
					'run_id'   => $run_id,
					'args'     => $args,
					'previous' => $previous_completed_run_id,
				);
			},
			'on_failed'    => static function ( string $run_id, array $args, RunFailure $failure ) use ( &$failed ): void {
				$failed[] = array(
					'run_id'  => $run_id,
					'args'    => $args,
					'failure' => $failure,
				);
			},
		);

		self::assertTrue( $engine->register_callable( 'callable', $handler, $options ) );
		$completed_args = array( 'site_id' => 7 );
		$completed_run  = self::assert_run( $engine->enqueue( 'callable', $completed_args ), self::OWNER . ':callable', RunStatus::Running );
		++$this->rig->clock()->timestamp;
		$overlap_error = self::assert_wp_error( $engine->enqueue( 'callable', $completed_args ), ErrorCode::OverlapHeld->value );
		self::assertSame( array( 'run_id' => $completed_run->run_id ), $overlap_error->get_error_data() );

		$this->rig->run_due();
		++$this->rig->clock()->timestamp;
		$failed_args = array(
			'site_id' => 8,
			'fail'    => true,
		);
		$failed_run  = self::assert_run( $engine->enqueue( 'callable', $failed_args ), self::OWNER . ':callable', RunStatus::Running );

		$this->rig->randomizer()->calls = array();
		for ( $attempt = 0; 3 > $attempt; ++$attempt ) {
			$this->rig->run_due();
		}

		self::assertSame( self::NOW + 43, $observed_heartbeat );
		self::assertSame( array( $completed_run->run_id, $failed_run->run_id, $failed_run->run_id, $failed_run->run_id ), $observed_run_ids );
		self::assertSame( array( $completed_args, $completed_args, $failed_args ), $overlap_args );
		self::assertSame(
			array(
				array(
					'run_id'   => $completed_run->run_id,
					'args'     => $completed_args,
					'previous' => null,
				),
			),
			$completed
		);
		self::assertCount( 1, $failed );
		self::assertSame( $failed_run->run_id, $failed[0]['run_id'] );
		self::assertSame( $failed_args, $failed[0]['args'] );
		self::assertSame( 3, $failed[0]['failure']->attempts );
		self::assertSame( ErrorCode::ExecutionFailed, $failed[0]['failure']->code );
		self::assertSame(
			array(
				array(
					'min' => 0,
					'max' => 50,
				),
				array(
					'min' => 0,
					'max' => 150,
				),
			),
			$this->rig->randomizer()->calls
		);
	}

	/**
	 * Invalid callable options remain inside the stable invalid-argument boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $options Invalid callable-job options.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_callable_options' )]
	public function test_register_callable_rejects_invalid_options( array $options ): void {
		$result = \a8csp_bgje( self::OWNER )->register_callable( 'callable', static function ( array $args, RunContext $context ): void {}, $options );

		self::assert_wp_error( $result, 'invalid_argument' );
	}

	/**
	 * Complete schedule specifications synchronize and immediate dispatch projects the schedule run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_schedules_and_dispatch_schedule_project_public_results(): void {
		/** @var list<array<array-key, mixed>> $handled */
		$handled = array();
		$engine  = \a8csp_bgje( self::OWNER );
		$job     = self::job(
			'scheduled-job',
			static function ( array $args ) use ( &$handled ): void {
				$handled[] = $args;
			}
		);
		self::assertTrue( $engine->register( $job ) );
		$args = array( 'scope' => 'all' );
		$spec = array(
			'name'     => 'nightly',
			'every'    => 300,
			'anchor'   => 650,
			'job'      => 'scheduled-job',
			'args'     => $args,
			'catch_up' => 'skip',
			'priority' => 41,
		);

		self::assertTrue( $engine->sync_schedules( array( $spec ) ) );
		$schedule_call = self::latest_backend_call( $this->rig, 'schedule_recurring' );
		self::assertSame( 300, $schedule_call['args']['interval'] ?? null );
		self::assertSame( 41, $schedule_call['args']['priority'] ?? null );
		self::assertIsInt( $schedule_call['args']['first_run_timestamp'] ?? null );
		self::assertSame( 50, ( $schedule_call['args']['first_run_timestamp'] ?? 0 ) % 300 );

		$run = self::assert_run( $engine->dispatch_schedule( 'nightly' ), self::OWNER . ':nightly', RunStatus::Running );
		$this->rig->run_due();

		self::assertNotSame( '', $run->run_id );
		self::assertSame( array( $args ), $handled );
	}

	/**
	 * Malformed schedule declarations remain inside the invalid-argument boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $schedules Invalid schedule specifications.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_schedules' )]
	public function test_sync_schedules_rejects_malformed_entries( array $schedules ): void {
		self::assert_wp_error( \a8csp_bgje( self::OWNER )->sync_schedules( $schedules ), 'invalid_argument' );
	}

	/**
	 * Inspection distinguishes a live run, its terminal outcome, and an absent retained run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_inspect_run_and_last_completed_run_project_retained_state(): void {
		$engine = \a8csp_bgje( self::OWNER );
		self::assertTrue( $engine->register( self::job( 'inspect' ) ) );
		self::assertNull( $engine->last_completed_run( 'inspect' ) );

		$admitted = self::assert_run( $engine->enqueue( 'inspect' ), self::OWNER . ':inspect', RunStatus::Running );
		$live     = self::assert_run( $engine->inspect_run( 'inspect', $admitted->run_id ), self::OWNER . ':inspect', RunStatus::Running, $admitted->run_id );
		$this->rig->run_due();
		$terminal = self::assert_run( $engine->inspect_run( 'inspect', $admitted->run_id ), self::OWNER . ':inspect', RunStatus::Completed, $admitted->run_id );
		$last     = self::assert_run( $engine->last_completed_run( 'inspect' ), self::OWNER . ':inspect', RunStatus::Completed, $admitted->run_id );

		self::assertSame( $live->run_id, $terminal->run_id );
		self::assertSame( $terminal->run_id, $last->run_id );
		self::assert_wp_error( $engine->inspect_run( 'inspect', 'malformed' ), 'invalid_argument' );
		self::assert_wp_error( $engine->inspect_run( 'inspect', self::MISSING_RUN_ID ), ErrorCode::RunNotRetained->value );
	}

	/**
	 * Failed-run retry and live-run cancellation project the specified replacement states and IDs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_run_and_cancel_run_project_running_and_cancelled_runs(): void {
		$engine     = \a8csp_bgje( self::OWNER );
		$failed_job = self::job(
			'failed',
			static function (): void {
				throw new NonRetryableException( 'Retain this failed run.' );
			}
		);
		self::assertTrue( $engine->register( $failed_job ) );
		$failed = self::assert_run( $engine->enqueue( 'failed', array( 'site_id' => 7 ) ), self::OWNER . ':failed', RunStatus::Running );
		$this->rig->run_due();
		self::assert_run( $engine->inspect_run( 'failed', $failed->run_id ), self::OWNER . ':failed', RunStatus::Failed, $failed->run_id );

		++$this->rig->clock()->timestamp;
		$retry = self::assert_run( $engine->retry_failed_run( 'failed', $failed->run_id ), self::OWNER . ':failed', RunStatus::Running );
		self::assertNotSame( $failed->run_id, $retry->run_id );
		self::assert_wp_error( $engine->retry_failed_run( 'failed', self::MISSING_RUN_ID ), ErrorCode::RunNotRetained->value );

		self::assertTrue( $engine->register( self::job( 'cancel' ) ) );
		$pending   = self::assert_run( $engine->enqueue( 'cancel', delay_seconds: 60 ), self::OWNER . ':cancel', RunStatus::Running );
		$cancelled = self::assert_run( $engine->cancel_run( 'cancel', $pending->run_id ), self::OWNER . ':cancel', RunStatus::Cancelled, $pending->run_id );
		self::assertSame( $pending->run_id, $cancelled->run_id );
		self::assert_wp_error( $engine->cancel_run( 'cancel', $pending->run_id ), ErrorCode::RunNotRetained->value );
	}

	/**
	 * Internal failures preserve code, engine-authored message, and structured context in WP_Error.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_internal_failure_maps_every_api_error_field(): void {
		$error = self::assert_wp_error( \a8csp_bgje( self::OWNER )->enqueue( 'missing' ), ErrorCode::UnknownWork->value );

		self::assertSame( 'Job "engine-test:missing" is not registered; register it before enqueueing.', $error->get_error_message() );
		self::assertSame( array( 'name' => self::OWNER . ':missing' ), $error->get_error_data() );
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Supplies invalid option names, types, and retry fields.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{options: array<array-key, mixed>}>
	 */
	public static function invalid_callable_options(): array {
		return array(
			'unknown option'      => array( 'options' => array( 'jitter' => 1 ) ),
			'max runtime type'    => array( 'options' => array( 'max_runtime' => '42' ) ),
			'retry type'          => array( 'options' => array( 'retry' => 'once' ) ),
			'retry field type'    => array( 'options' => array( 'retry' => array( 'max_attempts' => '3' ) ) ),
			'unknown retry field' => array( 'options' => array( 'retry' => array( 'jitter' => 1 ) ) ),
			'overlap declaration' => array( 'options' => array( 'overlap' => 'parallel' ) ),
			'overlap key type'    => array( 'options' => array( 'overlap_key' => 7 ) ),
			'completed callback'  => array( 'options' => array( 'on_completed' => 7 ) ),
			'failed callback'     => array( 'options' => array( 'on_failed' => 7 ) ),
		);
	}

	/**
	 * Supplies representative malformed schedule shapes and fields.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{schedules: array<array-key, mixed>}>
	 */
	public static function invalid_schedules(): array {
		return array(
			'non-array entry'  => array( 'schedules' => array( 'nightly' ) ),
			'missing name'     => array(
				'schedules' => array(
					array(
						'every' => 300,
						'job'   => 'job',
					),
				),
			),
			'invalid catch up' => array(
				'schedules' => array(
					array(
						'name'     => 'nightly',
						'every'    => 300,
						'job'      => 'job',
						'catch_up' => 'replay_all',
					),
				),
			),
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a minimal consumer-authored one-off job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param (\Closure(array<array-key, mixed>, RunContext): void)|null $handler
	 *
	 * @param   string        $name    Stable owner-local job name.
	 * @param   \Closure|null $handler Optional invocation behavior.
	 *
	 * @return  AbstractJob
	 */
	private static function job( string $name, ?\Closure $handler = null ): AbstractJob {
		return new class( $name, $handler ) extends AbstractJob {
			/**
			 * Constructor.
			 *
			 * @param   string        $name    Stable owner-local job name.
			 * @param   \Closure|null $handler Optional invocation behavior.
			 */
			public function __construct(
				private string $name,
				private ?\Closure $handler,
			) {}

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return $this->name;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function handle( array $args, RunContext $context ): void {
				$this->handler?->__invoke( $args, $context );
			}
		};
	}

	/**
	 * Returns a minimal consumer-authored chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Stable owner-local chunked job name.
	 *
	 * @return  AbstractChunkedJob
	 */
	private static function chunked_job( string $name ): AbstractChunkedJob {
		return new class( $name ) extends AbstractChunkedJob {
			/**
			 * Constructor.
			 *
			 * @param   string $name Stable owner-local chunked job name.
			 */
			public function __construct(
				private string $name,
			) {}

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return $this->name;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function generate_queue( array $start_args, RunContext $context ): iterable {
				return array();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function process_chunk( array $chunk_args, ChunkContext $context ): void {}
		};
	}

	/**
	 * Asserts one public run projection and returns it for identity chaining.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed      $value   Expected run value.
	 * @param   string     $identity Expected owner-qualified identity.
	 * @param   RunStatus  $status   Expected public lifecycle state.
	 * @param   string|null $run_id  Expected run identifier, or null to accept the generated identifier.
	 *
	 * @return  Run
	 */
	private static function assert_run( mixed $value, string $identity, RunStatus $status, ?string $run_id = null ): Run {
		self::assertInstanceOf( Run::class, $value );
		self::assertSame( $identity, $value->identity );
		self::assertSame( $status, $value->status );
		self::assertNotSame( '', $value->run_id );
		if ( null !== $run_id ) {
			self::assertSame( $run_id, $value->run_id );
		}

		return $value;
	}

	/**
	 * Asserts and returns one WordPress error result.
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
