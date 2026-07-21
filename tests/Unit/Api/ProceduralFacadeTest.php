<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Api;

use A8C\SpecialProjects\BackgroundJobsEngine\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WPErrorStub;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the procedural public seam through the production engine graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversFunction( 'a8csp_bgje_job_register' )]
#[CoversFunction( 'a8csp_bgje_job_register_object' )]
#[CoversFunction( 'a8csp_bgje_job_enqueue' )]
#[CoversFunction( 'a8csp_bgje_chunked_job_register' )]
#[CoversFunction( 'a8csp_bgje_chunked_job_start' )]
#[CoversFunction( 'a8csp_bgje_schedule_sync' )]
#[CoversFunction( 'a8csp_bgje_schedule_dispatch' )]
#[CoversFunction( 'a8csp_bgje_run_last_completed' )]
#[CoversFunction( 'a8csp_bgje_run_retry_failed' )]
#[CoversFunction( 'a8csp_bgje_run_cancel' )]
final class ProceduralFacadeTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string MISSING_RUN_ID = '00000000001700000001-0000000000000000043';
	private const int NOW               = 1_700_000_000;
	private const string OWNER          = 'procedural-facade';

	private EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads the public functions, production graph seams, and DB-less WordPress error stand-in.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		require_once \dirname( __DIR__, 2 ) . '/Support/WPErrorStub.php';
		if ( ! \class_exists( 'WP_Error' ) ) {
			\class_alias( WPErrorStub::class, 'WP_Error' );
		}

		EngineRig::bootstrap();
	}

	/** Builds one isolated production graph. */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig = EngineRig::set_up( self::NOW );
	}

	/** Clears the isolated graph and WordPress seams. */
	#[\Override]
	protected function tearDown(): void {
		$this->rig->tear_down();

		parent::tearDown();
	}

	// endregion.

	// region TESTS.

	/**
	 * Callable registration and enqueue preserve arguments, run identity, delay, and priority.
	 *
	 * @return  void
	 */
	public function test_job_registration_and_enqueue_round_trip_every_command_argument(): void {
		/** @var list<array{args: array<array-key, mixed>, run_id: string}> $calls */
		$calls   = array();
		$args    = array(
			'site_id' => 7,
			'scope'   => 'full',
		);
		$handler = static function ( array $handler_args, string $handler_run_id ) use ( &$calls ): void {
			$calls[] = array(
				'args'   => $handler_args,
				'run_id' => $handler_run_id,
			);
		};
		$options = array(
			'max_runtime' => 42,
			'retry'       => array( 'max_attempts' => 1 ),
			'overlap_key' => static fn ( array $overlap_args ): ?string => \is_int( $overlap_args['site_id'] ?? null ) ? 'site-' . $overlap_args['site_id'] : null,
		);

		self::assertTrue( \a8csp_bgje_job_register( self::OWNER, 'job', $handler, $options ) );

		$run_id = \a8csp_bgje_job_enqueue( self::OWNER, 'job', $args, 37, priority: 23 );
		self::assertIsString( $run_id );
		$schedule_call = $this->latest_backend_call( 'schedule_single' );
		self::assertSame( self::NOW + 37, $schedule_call['args']['timestamp'] ?? null );
		self::assertSame( 23, $schedule_call['args']['priority'] ?? null );

		++$this->rig->clock()->timestamp;
		$overlap_args = array(
			'site_id' => 7,
			'scope'   => 'delta',
		);
		$overlap      = \a8csp_bgje_job_enqueue( self::OWNER, 'job', $overlap_args, priority: 99 );
		$error        = self::assert_wp_error( $overlap, 'overlap_held' );
		self::assertSame( array( 'run_id' => $run_id ), $error->get_error_data() );

		$this->rig->run_due();
		self::assertSame(
			array(
				array(
					'args'   => $args,
					'run_id' => $run_id,
				),
			),
			$calls
		);
	}

	/**
	 * Duplicate job and chunked job registrations expose the same stable WordPress error.
	 *
	 * @return  void
	 */
	public function test_duplicate_registrations_return_already_registered_errors(): void {
		$handler     = static function ( array $args, string $run_id ): void {};
		$chunked_job = self::chunked_job( 'chunked_job' );

		self::assertTrue( \a8csp_bgje_job_register( self::OWNER, 'job', $handler ) );
		self::assert_wp_error( \a8csp_bgje_job_register( self::OWNER, 'job', $handler ), 'already_registered' );
		self::assertTrue( \a8csp_bgje_chunked_job_register( self::OWNER, $chunked_job ) );
		self::assert_wp_error( \a8csp_bgje_chunked_job_register( self::OWNER, $chunked_job ), 'already_registered' );
	}

	/**
	 * Invalid callable-job option types and policy declarations stay inside the WordPress error boundary.
	 *
	 * @param   array<array-key, mixed> $options Invalid registration options.
	 * @param   string                  $message Exact corrective message.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_job_options' )]
	public function test_job_registration_returns_invalid_argument_for_invalid_options( array $options, string $message ): void {
		$error = self::assert_wp_error( \a8csp_bgje_job_register( self::OWNER, 'job', static function ( array $args, string $run_id ): void {}, $options ), 'invalid_argument' );

		self::assertSame( $message, $error->get_error_message() );
	}

	/**
	 * Callable registration accepts every recognized overlap declaration.
	 *
	 * @param   string $overlap Recognized overlap declaration.
	 *
	 * @return  void
	 */
	#[DataProvider( 'valid_overlap_declarations' )]
	public function test_job_registration_accepts_valid_overlap_declarations( string $overlap ): void {
		self::assertTrue( \a8csp_bgje_job_register( self::OWNER, 'job', static function ( array $args, string $run_id ): void {}, array( 'overlap' => $overlap ) ) );
	}

	/**
	 * Object registration translates invalid retry declarations for both job kinds.
	 *
	 * @return  void
	 */
	public function test_object_registrations_return_invalid_argument_for_invalid_retry_declarations(): void {
		$job         = new class() extends \A8CSP_Job {
			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return 'job';
			}

			/** {@inheritDoc} */
			#[\Override]
			public function handle( array $args, string $run_id ): void {}

			/** {@inheritDoc} */
			#[\Override]
			public function retry(): array {
				return array( 'base_delay' => 0 );
			}
		};
		$chunked_job = new class() extends \A8CSP_ChunkedJob {
			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return 'chunked-job';
			}

			/** {@inheritDoc} */
			#[\Override]
			public function generate_queue( array $start_args, string $run_id ): iterable {
				return array();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function process_chunk( array $chunk_args, \A8CSP_ChunkContext $context ): void {}

			/** {@inheritDoc} */
			#[\Override]
			public function retry(): array {
				return array( 'multiplier' => 0 );
			}
		};

		$job_error     = self::assert_wp_error( \a8csp_bgje_job_register_object( self::OWNER, $job ), 'invalid_argument' );
		$chunked_error = self::assert_wp_error( \a8csp_bgje_chunked_job_register( self::OWNER, $chunked_job ), 'invalid_argument' );
		self::assertSame( 'Retry policy requires at least one attempt and a positive base delay.', $job_error->get_error_message() );
		self::assertSame( 'Retry policy requires a multiplier of at least one.', $chunked_error->get_error_message() );
	}

	/**
	 * Object registration eagerly translates invalid overlap declarations for both job kinds.
	 *
	 * @return  void
	 */
	public function test_object_registrations_return_invalid_argument_for_invalid_overlap_declarations(): void {
		$job         = new class() extends \A8CSP_Job {
			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return 'invalid-overlap-job';
			}

			/** {@inheritDoc} */
			#[\Override]
			public function handle( array $args, string $run_id ): void {}

			/** {@inheritDoc} */
			#[\Override]
			public function overlap_policy(): string {
				return 'parallel';
			}
		};
		$chunked_job = new class() extends \A8CSP_ChunkedJob {
			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return 'invalid-overlap-chunked-job';
			}

			/** {@inheritDoc} */
			#[\Override]
			public function generate_queue( array $start_args, string $run_id ): iterable {
				return array();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function process_chunk( array $chunk_args, \A8CSP_ChunkContext $context ): void {}

			/** {@inheritDoc} */
			#[\Override]
			public function overlap_policy(): string {
				return 'parallel';
			}
		};

		$job_error     = self::assert_wp_error( \a8csp_bgje_job_register_object( self::OWNER, $job ), 'invalid_argument' );
		$chunked_error = self::assert_wp_error( \a8csp_bgje_chunked_job_register( self::OWNER, $chunked_job ), 'invalid_argument' );

		self::assertSame( 'Overlap policy accepts only allow, reject, or replace.', $job_error->get_error_message() );
		self::assertSame( 'Overlap policy accepts only allow, reject, or replace.', $chunked_error->get_error_message() );
	}

	/**
	 * The object adapter honors overlap and passes arguments to the model's collision identity.
	 *
	 * @return  void
	 */
	public function test_job_object_registration_honors_overlap_and_forwards_overlap_key_arguments(): void {
		$job = new class() extends \A8CSP_Job {
			/** @var list<array<array-key, mixed>> */
			public array $overlap_args = array();

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return 'overlap-job';
			}

			/** {@inheritDoc} */
			#[\Override]
			public function handle( array $args, string $run_id ): void {}

			/** {@inheritDoc} */
			#[\Override]
			public function overlap_policy(): string {
				return 'allow';
			}

			/** {@inheritDoc} */
			#[\Override]
			public function overlap_key( array $start_args ): string {
				$this->overlap_args[] = $start_args;

				return 'tenant-7';
			}
		};

		self::assertTrue( \a8csp_bgje_job_register_object( self::OWNER, $job ) );
		$args     = array( 'tenant' => 7 );
		$first_id = \a8csp_bgje_job_enqueue( self::OWNER, 'overlap-job', $args );
		self::assertIsString( $first_id );

		++$this->rig->clock()->timestamp;
		$second_id = \a8csp_bgje_job_enqueue( self::OWNER, 'overlap-job', $args );

		self::assertIsString( $second_id );
		self::assertNotSame( $first_id, $second_id );
		self::assertSame( array( $args, $args ), $job->overlap_args );
	}

	/**
	 * Callable lifecycle options receive terminal payloads from the durable engine effect.
	 *
	 * @return  void
	 */
	public function test_callable_registration_wires_completed_and_failed_options(): void {
		/** @var list<array{run_id: string, args: array<array-key, mixed>, previous_completed_run_id: string|null}> $completed */
		$completed = array();
		/** @var list<array{run_id: string, args: array<array-key, mixed>, failure: array<string, mixed>}> $failed */
		$failed  = array();
		$options = array(
			'on_completed' => static function ( string $run_id, array $args, ?string $previous_completed_run_id ) use ( &$completed ): void {
				$completed[] = array(
					'run_id'                    => $run_id,
					'args'                      => $args,
					'previous_completed_run_id' => $previous_completed_run_id,
				);
			},
			'on_failed'    => static function ( string $run_id, array $args, array $failure ) use ( &$failed ): void {
				$failed[] = array(
					'run_id'  => $run_id,
					'args'    => $args,
					'failure' => $failure,
				);
			},
		);

		$handler = static function ( array $args, string $run_id ): void {
			if ( true === ( $args['fail'] ?? false ) ) {
				throw new NonRetryableException( 'Callable terminal failure.' );
			}
		};
		self::assertTrue( \a8csp_bgje_job_register( self::OWNER, 'job', $handler, $options ) );

		$completed_args = array( 'site_id' => 7 );
		$completed_id   = \a8csp_bgje_job_enqueue( self::OWNER, 'job', $completed_args );
		self::assertIsString( $completed_id );
		$this->rig->run_due();

		++$this->rig->clock()->timestamp;
		$next_completed_args = array( 'site_id' => 8 );
		$next_completed_id   = \a8csp_bgje_job_enqueue( self::OWNER, 'job', $next_completed_args );
		self::assertIsString( $next_completed_id );
		$this->rig->run_due();

		++$this->rig->clock()->timestamp;
		$failed_args = array(
			'site_id' => 9,
			'fail'    => true,
		);
		$failed_id   = \a8csp_bgje_job_enqueue( self::OWNER, 'job', $failed_args );
		self::assertIsString( $failed_id );
		$this->rig->run_due();

		self::assertSame(
			array(
				array(
					'run_id'                    => $completed_id,
					'args'                      => $completed_args,
					'previous_completed_run_id' => null,
				),
				array(
					'run_id'                    => $next_completed_id,
					'args'                      => $next_completed_args,
					'previous_completed_run_id' => $completed_id,
				),
			),
			$completed
		);
		self::assertCount( 1, $failed );
		self::assertSame( $failed_id, $failed[0]['run_id'] );
		self::assertSame( $failed_args, $failed[0]['args'] );
		self::assertSame( $failed_id, $failed[0]['failure']['run_id'] ?? null );
		self::assertSame( ErrorCode::ExecutionFailed->value, $failed[0]['failure']['code'] ?? null );
	}

	/**
	 * Consumer job subclasses receive lifecycle callbacks through the internal adapter.
	 *
	 * @return  void
	 */
	public function test_job_object_registration_adapts_work_and_lifecycle_callbacks(): void {
		$job = new class() extends \A8CSP_Job {
			/** @var list<int> */
			public array $handle_arities = array();

			/** @var list<array{run_id: string, args: array<array-key, mixed>}> */
			public array $handles = array();

			/** @var list<array{run_id: string, args: array<array-key, mixed>, previous_completed_run_id: string|null}> */
			public array $completed = array();

			/** @var list<int> */
			public array $completed_arities = array();

			/** @var list<array{run_id: string, args: array<array-key, mixed>, failure: array<string, mixed>}> */
			public array $failed = array();

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return 'object-job';
			}

			/** {@inheritDoc} */
			#[\Override]
			public function handle( array $args, string $run_id ): void {
				$this->handle_arities[] = \func_num_args();
				$this->handles[]        = array(
					'run_id' => $run_id,
					'args'   => $args,
				);
				if ( true === ( $args['fail'] ?? false ) ) {
					throw new NonRetryableException( 'Object terminal failure.' );
				}
			}

			/** {@inheritDoc} */
			#[\Override]
			public function on_completed( string $run_id, array $args, ?string $previous_completed_run_id ): void {
				$this->completed_arities[] = \func_num_args();
				$this->completed[]         = array(
					'run_id'                    => $run_id,
					'args'                      => $args,
					'previous_completed_run_id' => $previous_completed_run_id,
				);
			}

			/** {@inheritDoc} */
			#[\Override]
			public function on_failed( string $run_id, array $args, array $failure ): void {
				$this->failed[] = array(
					'run_id'  => $run_id,
					'args'    => $args,
					'failure' => $failure,
				);
			}
		};

		self::assertTrue( \a8csp_bgje_job_register_object( self::OWNER, $job ) );

		$completed_args = array( 'site_id' => 8 );
		$completed_id   = \a8csp_bgje_job_enqueue( self::OWNER, 'object-job', $completed_args );
		self::assertIsString( $completed_id );
		$this->rig->run_due();

		++$this->rig->clock()->timestamp;
		$failed_args = array(
			'site_id' => 9,
			'fail'    => true,
		);
		$failed_id   = \a8csp_bgje_job_enqueue( self::OWNER, 'object-job', $failed_args );
		self::assertIsString( $failed_id );
		$this->rig->run_due();

		self::assertSame(
			array(
				array(
					'run_id'                    => $completed_id,
					'args'                      => $completed_args,
					'previous_completed_run_id' => null,
				),
			),
			$job->completed
		);
		self::assertSame( array( 2, 2 ), $job->handle_arities );
		self::assertSame(
			array(
				array(
					'run_id' => $completed_id,
					'args'   => $completed_args,
				),
				array(
					'run_id' => $failed_id,
					'args'   => $failed_args,
				),
			),
			$job->handles
		);
		self::assertSame( array( 3 ), $job->completed_arities );
		self::assertCount( 1, $job->failed );
		self::assertSame( $failed_id, $job->failed[0]['run_id'] );
		self::assertSame( $failed_args, $job->failed[0]['args'] );
		self::assertSame( $failed_id, $job->failed[0]['failure']['run_id'] ?? null );
		self::assertSame( ErrorCode::ExecutionFailed->value, $job->failed[0]['failure']['code'] ?? null );
	}

	/**
	 * Supplies invalid callable-job option types and policy declarations.
	 *
	 * @return  array<string, array{options: array<array-key, mixed>, message: string}>
	 */
	public static function invalid_job_options(): array {
		return array(
			'max runtime type' => array(
				'options' => array( 'max_runtime' => '300' ),
				'message' => 'max_runtime must be an integer or null',
			),
			'retry type'       => array(
				'options' => array( 'retry' => 'once' ),
				'message' => 'retry must be an array or null',
			),
			'overlap type'     => array(
				'options' => array( 'overlap' => array() ),
				'message' => 'overlap must be a string or null',
			),
			'invalid overlap'  => array(
				'options' => array( 'overlap' => 'parallel' ),
				'message' => 'Overlap policy accepts only allow, reject, or replace.',
			),
			'overlap key type' => array(
				'options' => array( 'overlap_key' => 'tenant-7' ),
				'message' => 'overlap_key must be callable or null',
			),
			'completed type'   => array(
				'options' => array( 'on_completed' => 'callback' ),
				'message' => 'on_completed must be callable or null',
			),
			'failed type'      => array(
				'options' => array( 'on_failed' => 'callback' ),
				'message' => 'on_failed must be callable or null',
			),
			'retry field type' => array(
				'options' => array( 'retry' => array( 'max_attempts' => '3' ) ),
				'message' => 'Retry declarations accept only integer max_attempts, base_delay, multiplier, and max_delay fields.',
			),
			'unknown retry'    => array(
				'options' => array( 'retry' => array( 'jitter' => 1 ) ),
				'message' => 'Retry declarations accept only integer max_attempts, base_delay, multiplier, and max_delay fields.',
			),
			'retry invariant'  => array(
				'options' => array( 'retry' => array( 'max_attempts' => 0 ) ),
				'message' => 'Retry policy requires at least one attempt and a positive base delay.',
			),
		);
	}

	/**
	 * Supplies every recognized overlap declaration.
	 *
	 * @return  array<string, array{overlap: string}>
	 */
	public static function valid_overlap_declarations(): array {
		return array(
			'allow'   => array( 'overlap' => 'allow' ),
			'reject'  => array( 'overlap' => 'reject' ),
			'replace' => array( 'overlap' => 'replace' ),
		);
	}

	/**
	 * Chunked Job starts use the registered job's default rejection policy and preserve priority.
	 *
	 * @return  void
	 */
	public function test_chunked_job_start_rejects_overlap_and_preserves_priority(): void {
		$chunked_job = self::chunked_job( 'chunked_job' );
		self::assertTrue( \a8csp_bgje_chunked_job_register( self::OWNER, $chunked_job ) );

		$run_id = \a8csp_bgje_chunked_job_start( self::OWNER, 'chunked_job', array( 'scope' => 'all' ), priority: 31 );
		self::assertIsString( $run_id );
		$enqueue_call = $this->latest_backend_call( 'enqueue_async' );
		self::assertSame( 31, $enqueue_call['args']['priority'] ?? null );

		++$this->rig->clock()->timestamp;
		$overlap = \a8csp_bgje_chunked_job_start( self::OWNER, 'chunked_job', array( 'scope' => 'all' ) );
		$error   = self::assert_wp_error( $overlap, 'overlap_held' );
		self::assertSame( array( 'run_id' => $run_id ), $error->get_error_data() );
	}

	/**
	 * The chunked-job adapter forwards run identity and completion data to the public model.
	 *
	 * @return  void
	 */
	public function test_chunked_job_object_adapter_forwards_run_and_completion_arguments(): void {
		$chunked_job = new class() extends \A8CSP_ChunkedJob {
			/** @var list<array<array-key, mixed>> */
			public array $generate_args = array();

			/** @var list<int> */
			public array $generate_arities = array();

			/** @var list<string> */
			public array $generate_run_ids = array();

			/** @var list<array{run_id: string, args: array<array-key, mixed>, previous_completed_run_id: string|null}> */
			public array $completed = array();

			/** @var list<int> */
			public array $completed_arities = array();

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return 'adapter-arity';
			}

			/** {@inheritDoc} */
			#[\Override]
			public function generate_queue( array $start_args, string $run_id ): iterable {
				$this->generate_arities[] = \func_num_args();
				$this->generate_args[]    = $start_args;
				$this->generate_run_ids[] = $run_id;

				return array();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function process_chunk( array $chunk_args, \A8CSP_ChunkContext $context ): void {}

			/** {@inheritDoc} */
			#[\Override]
			public function on_completed( string $run_id, array $args, ?string $previous_completed_run_id ): void {
				$this->completed_arities[] = \func_num_args();
				$this->completed[]         = array(
					'run_id'                    => $run_id,
					'args'                      => $args,
					'previous_completed_run_id' => $previous_completed_run_id,
				);
			}
		};

		self::assertTrue( \a8csp_bgje_chunked_job_register( self::OWNER, $chunked_job ) );
		$args   = array( 'scope' => 'public-adapter' );
		$run_id = \a8csp_bgje_chunked_job_start( self::OWNER, 'adapter-arity', $args );
		self::assertIsString( $run_id );

		for ( $delivery = 0; $delivery < 3; ++$delivery ) {
			$this->rig->run_due();
		}

		self::assertSame( array( 2 ), $chunked_job->generate_arities );
		self::assertSame( array( $args ), $chunked_job->generate_args );
		self::assertSame( array( $run_id ), $chunked_job->generate_run_ids );
		self::assertSame( array( 3 ), $chunked_job->completed_arities );
		self::assertSame(
			array(
				array(
					'run_id'                    => $run_id,
					'args'                      => $args,
					'previous_completed_run_id' => null,
				),
			),
			$chunked_job->completed
		);
	}

	/**
	 * An anchored schedule specification reduces to the corresponding Client value object.
	 *
	 * @return  void
	 */
	public function test_schedule_sync_matches_the_equivalent_client_value_object_and_dispatches(): void {
		/** @var list<array<array-key, mixed>> $calls */
		$calls   = array();
		$args    = array( 'scope' => 'all' );
		$handler = static function ( array $handler_args, string $run_id ) use ( &$calls ): void {
			$calls[] = $handler_args;
		};
		self::assertTrue( \a8csp_bgje_job_register( self::OWNER, 'scheduled-job', $handler ) );

		$spec = array(
			'name'     => 'recurring',
			'every'    => 300,
			'anchor'   => 650,
			'job'      => 'scheduled-job',
			'args'     => $args,
			'catch_up' => 'skip',
			'priority' => 41,
		);
		self::assertTrue( \a8csp_bgje_schedule_sync( self::OWNER, array( $spec ) ) );
		$facade_snapshot = $this->rig->inspection()->schedules( self::OWNER );
		$write_count     = $this->backend_call_count( 'schedule_recurring' );

		$schedule      = new Schedule( 'recurring', Recurrence::every_anchored( 300, 50 ), 'scheduled-job', $args, CatchUpPolicy::Skip, 41 );
		$client_result = $this->rig->client( self::OWNER )->schedules()->sync( array( $schedule ) );
		self::assertInstanceOf( Success::class, $client_result );
		self::assertSame( $facade_snapshot, $this->rig->inspection()->schedules( self::OWNER ) );
		self::assertSame( $write_count, $this->backend_call_count( 'schedule_recurring' ) );

		$run_id = \a8csp_bgje_schedule_dispatch( self::OWNER, 'recurring' );
		self::assertIsString( $run_id );
		$this->rig->run_due();
		self::assertSame( array( $args ), $calls );
	}

	/**
	 * Invalid schedule declarations are translated at the procedural boundary.
	 *
	 * @param   array<array-key, mixed> $schedules Invalid schedule specifications.
	 * @param   string                  $message   Exact corrective message.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_schedules' )]
	public function test_schedule_sync_returns_invalid_argument_for_invalid_declarations( array $schedules, string $message ): void {
		$error = self::assert_wp_error( \a8csp_bgje_schedule_sync( self::OWNER, $schedules ), 'invalid_argument' );

		self::assertSame( $message, $error->get_error_message() );
	}

	/**
	 * Supplies missing and invalid schedule fields.
	 *
	 * @return  array<string, array{schedules: array<array-key, mixed>, message: string}>
	 */
	public static function invalid_schedules(): array {
		return array(
			'non-array entry'        => array(
				'schedules' => array( 'schedule' ),
				'message'   => 'schedule entries must be arrays',
			),
			'missing required field' => array(
				'schedules' => array(
					array(
						'every' => 300,
						'job'   => 'job',
					),
				),
				'message'   => 'schedule entries must include name, every, and job',
			),
			'non-string name'        => array(
				'schedules' => array(
					array(
						'name'  => 7,
						'every' => 300,
						'job'   => 'job',
					),
				),
				'message'   => 'name and job must be strings',
			),
			'non-string job'         => array(
				'schedules' => array(
					array(
						'name'  => 'schedule',
						'every' => 300,
						'job'   => 7,
					),
				),
				'message'   => 'name and job must be strings',
			),
			'non-array args'         => array(
				'schedules' => array(
					array(
						'name'  => 'schedule',
						'every' => 300,
						'job'   => 'job',
						'args'  => 'invalid',
					),
				),
				'message'   => 'args must be an array',
			),
			'non-string catch up'    => array(
				'schedules' => array(
					array(
						'name'     => 'schedule',
						'every'    => 300,
						'job'      => 'job',
						'catch_up' => array(),
					),
				),
				'message'   => 'catch_up must be run_once or skip',
			),
			'invalid catch up'       => array(
				'schedules' => array(
					array(
						'name'     => 'schedule',
						'every'    => 300,
						'job'      => 'job',
						'catch_up' => 'invalid',
					),
				),
				'message'   => 'catch_up must be run_once or skip',
			),
			'invalid recurrence'     => array(
				'schedules' => array(
					array(
						'name'  => 'schedule',
						'every' => 0,
						'job'   => 'job',
					),
				),
				'message'   => 'Recurrence interval must be positive; pass a value of at least one second.',
			),
			'fractional recurrence'  => array(
				'schedules' => array(
					array(
						'name'  => 'schedule',
						'every' => '1.9',
						'job'   => 'job',
					),
				),
				'message'   => 'every must be an integer number of seconds',
			),
			'non-integer anchor'     => array(
				'schedules' => array(
					array(
						'name'   => 'schedule',
						'every'  => 300,
						'anchor' => '50',
						'job'    => 'job',
					),
				),
				'message'   => 'anchor must be an integer number of seconds',
			),
			'negative anchor'        => array(
				'schedules' => array(
					array(
						'name'   => 'schedule',
						'every'  => 300,
						'anchor' => -1,
						'job'    => 'job',
					),
				),
				'message'   => 'Recurrence anchor must be non-negative; pass a UTC phase offset of zero seconds or greater.',
			),
			'non-integer priority'   => array(
				'schedules' => array(
					array(
						'name'     => 'schedule',
						'every'    => 300,
						'job'      => 'job',
						'priority' => '10',
					),
				),
				'message'   => 'priority must be an integer',
			),
		);
	}

	/**
	 * Dispatching an undeclared schedule preserves the Client failure classification.
	 *
	 * @return  void
	 */
	public function test_schedule_dispatch_returns_the_client_failure_as_wp_error(): void {
		self::assert_wp_error( \a8csp_bgje_schedule_dispatch( self::OWNER, 'missing' ), 'unknown_schedule' );
	}

	/**
	 * Last-completed lookup returns null before execution and the completed run afterward.
	 *
	 * @return  void
	 */
	public function test_last_completed_run_id_preserves_null_and_string_success_values(): void {
		self::assertTrue( \a8csp_bgje_job_register( self::OWNER, 'job', static function ( array $args, string $run_id ): void {} ) );
		self::assertNull( \a8csp_bgje_run_last_completed( self::OWNER, 'job' ) );

		$run_id = \a8csp_bgje_job_enqueue( self::OWNER, 'job' );
		self::assertIsString( $run_id );
		$this->rig->run_due();

		self::assertSame( $run_id, \a8csp_bgje_run_last_completed( self::OWNER, 'job' ) );
	}

	/**
	 * Manual failed-run retry returns a fresh run and maps an absent retained run to WP_Error.
	 *
	 * @return  void
	 */
	public function test_retry_failed_returns_a_new_run_and_maps_missing_history(): void {
		$handler = static function ( array $args, string $run_id ): void {
			throw new NonRetryableException( 'Permanent failure.' );
		};
		self::assertTrue( \a8csp_bgje_job_register( self::OWNER, 'job', $handler ) );

		$failed_run = \a8csp_bgje_job_enqueue( self::OWNER, 'job', array( 'site_id' => 7 ) );
		self::assertIsString( $failed_run );
		$this->rig->run_due();

		++$this->rig->clock()->timestamp;
		$retry_run = \a8csp_bgje_run_retry_failed( self::OWNER, 'job', $failed_run );
		self::assertIsString( $retry_run );
		self::assertNotSame( $failed_run, $retry_run );
		self::assert_wp_error( \a8csp_bgje_run_retry_failed( self::OWNER, 'job', self::MISSING_RUN_ID ), 'run_not_retained' );
	}

	/**
	 * Cancellation returns the cancelled run ID and exposes a second attempt as not retained.
	 *
	 * @return  void
	 */
	public function test_run_cancel_returns_the_run_id_and_maps_a_second_cancel(): void {
		self::assertTrue( \a8csp_bgje_job_register( self::OWNER, 'job', static function ( array $args, string $run_id ): void {} ) );
		$run_id = \a8csp_bgje_job_enqueue( self::OWNER, 'job', delay_seconds: 60 );
		self::assertIsString( $run_id );

		self::assertSame( $run_id, \a8csp_bgje_run_cancel( self::OWNER, 'job', $run_id ) );
		self::assert_wp_error( \a8csp_bgje_run_cancel( self::OWNER, 'job', $run_id ), 'run_not_retained' );
	}

	/**
	 * Run mutations translate malformed identifiers to the stable WordPress error.
	 *
	 * @return  void
	 */
	public function test_run_mutations_map_malformed_identifiers_to_invalid_argument_errors(): void {
		self::assertTrue( \a8csp_bgje_job_register( self::OWNER, 'job', static function ( array $args, string $run_id ): void {} ) );

		foreach (
			array(
				\a8csp_bgje_run_retry_failed( self::OWNER, 'job', 'malformed_run_id' ),
				\a8csp_bgje_run_cancel( self::OWNER, 'job', 'malformed_run_id' ),
			) as $result
		) {
			$error = self::assert_wp_error( $result, 'invalid_argument' );
			self::assertSame( 'Run identifier is malformed; pass a run ID the engine returned.', $error->get_error_message() );
		}
	}

	/**
	 * Every fallible function translates owner validation while every public signature stays pinned.
	 *
	 * @param   \Closure(): mixed $call Invalid-owner facade call.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_owner_calls' )]
	public function test_fallible_functions_translate_invalid_owner( \Closure $call ): void {
		self::assert_wp_error( $call(), 'invalid_argument' );
	}

	/**
	 * Client-resolution timing failures remain loud instead of becoming WordPress errors.
	 *
	 * @return  void
	 */
	public function test_client_init_timing_exception_propagates(): void {
		$GLOBALS['a8csp_bgje_test_did_actions'] = array( 'plugins_loaded' => 1 );

		$this->expectException( \LogicException::class );

		(void) \a8csp_bgje_job_enqueue( self::OWNER, 'job' );
	}

	/**
	 * Supplies every fallible facade function with an invalid owner.
	 *
	 * @return  array<string, array{call: \Closure(): mixed}>
	 */
	public static function invalid_owner_calls(): array {
		$owner = 'Invalid Owner';

		return array(
			'job register'         => array( 'call' => static fn (): mixed => \a8csp_bgje_job_register( $owner, 'job', static function ( array $args, string $run_id ): void {} ) ),
			'job object register'  => array( 'call' => static fn (): mixed => \a8csp_bgje_job_register_object( $owner, self::job( 'job' ) ) ),
			'job enqueue'          => array( 'call' => static fn (): mixed => \a8csp_bgje_job_enqueue( $owner, 'job' ) ),
			'chunked job register' => array( 'call' => static fn (): mixed => \a8csp_bgje_chunked_job_register( $owner, self::chunked_job( 'chunked_job' ) ) ),
			'chunked job start'    => array( 'call' => static fn (): mixed => \a8csp_bgje_chunked_job_start( $owner, 'chunked_job' ) ),
			'schedule sync'        => array( 'call' => static fn (): mixed => \a8csp_bgje_schedule_sync( $owner, array() ) ),
			'schedule dispatch'    => array( 'call' => static fn (): mixed => \a8csp_bgje_schedule_dispatch( $owner, 'schedule' ) ),
			'last completed'       => array( 'call' => static fn (): mixed => \a8csp_bgje_run_last_completed( $owner, 'job' ) ),
			'retry failed'         => array( 'call' => static fn (): mixed => \a8csp_bgje_run_retry_failed( $owner, 'job', self::MISSING_RUN_ID ) ),
			'cancel'               => array( 'call' => static fn (): mixed => \a8csp_bgje_run_cancel( $owner, 'job', self::MISSING_RUN_ID ) ),
		);
	}

	/**
	 * All ten global functions expose their exact positional contract and fallible attributes.
	 *
	 * @load-bearing operator-contract
	 * @pin-rationale The AS-migrant positional/return contract and the #[\NoDiscard] coverage are SemVer surface that must not silently drift.
	 *
	 * @return  void
	 */
	public function test_public_function_signatures_and_no_discard_contracts(): void {
		$signatures = array(
			'a8csp_bgje_job_register'         => '(string $owner, string $name, callable $handler, array $options = array()): WP_Error|true',
			'a8csp_bgje_job_register_object'  => '(string $owner, A8CSP_Job $job): WP_Error|true',
			'a8csp_bgje_job_enqueue'          => '(string $owner, string $name, array $args = array(), int $delay_seconds = 0, int $priority = 10): WP_Error|string',
			'a8csp_bgje_chunked_job_register' => '(string $owner, A8CSP_ChunkedJob $job): WP_Error|true',
			'a8csp_bgje_chunked_job_start'    => '(string $owner, string $name, array $start_args = array(), int $priority = 10): WP_Error|string',
			'a8csp_bgje_schedule_sync'        => '(string $owner, array $schedules): WP_Error|true',
			'a8csp_bgje_schedule_dispatch'    => '(string $owner, string $name): WP_Error|string',
			'a8csp_bgje_run_last_completed'   => '(string $owner, string $name): WP_Error|string|null',
			'a8csp_bgje_run_retry_failed'     => '(string $owner, string $name, string $run_id): WP_Error|string',
			'a8csp_bgje_run_cancel'           => '(string $owner, string $name, string $run_id): WP_Error|string',
		);

		foreach ( $signatures as $function => $signature ) {
			$reflection = new \ReflectionFunction( $function );
			self::assertSame( $signature, self::reflection_signature( $reflection ) );
		}

		foreach ( \array_slice( \array_keys( $signatures ), 0, 10 ) as $function ) {
			self::assertCount( 1, ( new \ReflectionFunction( $function ) )->getAttributes( \NoDiscard::class ) );
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a minimal consumer-authored one-off job.
	 *
	 * @param   string $name Stable owner-local job name.
	 *
	 * @return  \A8CSP_Job
	 */
	private static function job( string $name ): \A8CSP_Job {
		return new class( $name ) extends \A8CSP_Job {
			/**
			 * Constructor.
			 *
			 * @param   string $name Stable owner-local job name.
			 */
			public function __construct(
				private readonly string $name,
			) {}

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return $this->name;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function handle( array $args, string $run_id ): void {}
		};
	}

	/**
	 * Returns a minimal consumer-authored chunked job.
	 *
	 * @param   string $name Stable owner-local chunked job name.
	 *
	 * @return  \A8CSP_ChunkedJob
	 */
	private static function chunked_job( string $name ): \A8CSP_ChunkedJob {
		return new class( $name ) extends \A8CSP_ChunkedJob {
			/**
			 * Constructor.
			 *
			 * @param   string $name Stable owner-local chunked job name.
			 */
			public function __construct(
				private readonly string $name,
			) {}

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return $this->name;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function generate_queue( array $start_args, string $run_id ): iterable {
				return array();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function process_chunk( array $chunk_args, \A8CSP_ChunkContext $context ): void {}
		};
	}

	/**
	 * Returns the latest scheduler call for one write verb.
	 *
	 * @param   string $verb Backend write verb.
	 *
	 * @return  array{verb: string, args: array<string, mixed>}
	 */
	private function latest_backend_call( string $verb ): array {
		foreach ( \array_reverse( $this->rig->backend()->calls ) as $call ) {
			if ( $verb === $call['verb'] ) {
				return $call;
			}
		}

		self::fail( 'Expected a backend call for verb ' . $verb . '.' );
	}

	/**
	 * Counts scheduler calls for one verb.
	 *
	 * @param   string $verb Backend call verb.
	 *
	 * @return  int
	 */
	private function backend_call_count( string $verb ): int {
		return \count( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => $verb === $call['verb'] ) );
	}

	/**
	 * Asserts and returns one procedural WordPress error.
	 *
	 * @param   mixed  $value Expected error value.
	 * @param   string $code  Stable error code.
	 *
	 * @return  \WP_Error
	 */
	private static function assert_wp_error( mixed $value, string $code ): \WP_Error {
		self::assertInstanceOf( \WP_Error::class, $value );
		self::assertSame( $code, $value->get_error_code(), $value->get_error_message() );

		return $value;
	}

	/**
	 * Normalizes one reflected public function signature for an exact contract assertion.
	 *
	 * @param   \ReflectionFunction $reflection Reflected facade function.
	 *
	 * @throws  \LogicException When a facade adds an unsupported default-value type.
	 *
	 * @return  string
	 */
	private static function reflection_signature( \ReflectionFunction $reflection ): string {
		$parameters = \array_map(
			static function ( \ReflectionParameter $parameter ): string {
				$signature = (string) $parameter->getType() . ' $' . $parameter->getName();
				if ( ! $parameter->isDefaultValueAvailable() ) {
					return $signature;
				}

				$default = $parameter->getDefaultValue();
				if ( \is_array( $default ) ) {
					return $signature . ' = array()';
				}

				if ( null === $default ) {
					return $signature . ' = null';
				}
				if ( \is_string( $default ) ) {
					return $signature . " = '" . $default . "'";
				}
				if ( \is_int( $default ) ) {
					return $signature . ' = ' . (string) $default;
				}

				throw new \LogicException( 'Facade signatures use only array, string, integer, or null default values.' );
			},
			$reflection->getParameters()
		);

		return '(' . \implode( ', ', $parameters ) . '): ' . (string) $reflection->getReturnType();
	}

	// endregion.
}
