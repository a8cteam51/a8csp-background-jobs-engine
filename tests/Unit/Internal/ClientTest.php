<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Internal;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\ChunkedJob\ChunkedJobs;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Run\Runs;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Schedule\Schedules;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Job\Jobs;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\AdmissionValidator;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FakeChunkedJobsEngine;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FakeRunsEngine;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FakeSchedulesEngine;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FakeJobsEngine;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the supported owner-bound client facade contract.
 *
 */
#[CoversClass( Client::class )]
#[CoversClass( Jobs::class )]
#[CoversClass( ChunkedJobs::class )]
#[CoversClass( Schedules::class )]
#[CoversClass( Runs::class )]
#[UsesClass( AdmissionValidator::class )]
#[UsesClass( JobIdentity::class )]
final class ClientTest extends TestCase {
	/**
	 * Loads the WordPress seams required by API value objects.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/wp-time-constant-stubs.php';
		require_once \dirname( __DIR__ ) . '/Runtime/Backends/wp-json-encode-stub.php';
	}

	/**
	 * Client accessors retain the owner-bound facades supplied at resolution.
	 *
	 * @return  void
	 */
	public function test_accessors_return_the_bound_facades(): void {
		$jobs         = new Jobs( 'consumer-plugin', new FakeJobsEngine( new Success( 'job-run' ) ) );
		$chunked_jobs = new ChunkedJobs( 'consumer-plugin', new FakeChunkedJobsEngine( new Success( 'chunked-job-run' ) ) );
		$schedules    = new Schedules(
			'consumer-plugin',
			new FakeSchedulesEngine(
				new Success( true ),
				new Success(
					array(
						'identity' => 'consumer-plugin:scheduled-job',
						'run_id'   => 'schedule-run',
					)
				)
			)
		);
		$runs         = new Runs( 'consumer-plugin', new FakeRunsEngine( new Success( null ), new Success( null ), new Success( 'retry-run' ), new Success( 'cancelled-run' ) ) );
		$client       = new Client( 'consumer-plugin', $jobs, $chunked_jobs, $schedules, $runs );

		self::assertSame( $jobs, $client->jobs() );
		self::assertSame( $chunked_jobs, $client->chunked_jobs() );
		self::assertSame( $schedules, $client->schedules() );
		self::assertSame( $runs, $client->runs() );
	}

	/**
	 * Job operations compose once and preserve the delegated result object and arguments.
	 *
	 * @return  void
	 */
	public function test_jobs_register_and_enqueue_owner_qualified_work(): void {
		$failure    = new Failure( new ApiError( ErrorCode::BackendRejected, 'Scripted failure.' ) );
		$job        = new RecordingJob( 'sync' );
		$definition = $job->definition();
		$engine     = new FakeJobsEngine( $failure );
		$jobs       = new Jobs( 'consumer-plugin', $engine );

		$jobs->register( $definition );
		$result = $jobs->enqueue( 'sync', array( 'site_id' => 7 ), delay: 30, priority: 5 );

		self::assertSame( $failure, $result );
		self::assertSame(
			array(
				array( 'register', 'consumer-plugin:sync', $definition ),
				array( 'enqueue', 'consumer-plugin:sync', array( 'site_id' => 7 ), 30, 5 ),
			),
			$engine->calls
		);
		self::assertSame( array( 'name', 'args', 'delay', 'priority' ), self::parameter_names( Jobs::class, 'enqueue' ) );
	}

	/**
	 * Chunked Job operations preserve the delegated result object and arguments.
	 *
	 * @return  void
	 */
	public function test_chunked_jobs_start_owner_qualified_work(): void {
		$success      = new Success( 'chunked-job-run' );
		$engine       = new FakeChunkedJobsEngine( $success );
		$chunked_jobs = new ChunkedJobs( 'consumer-plugin', $engine );

		$result = $chunked_jobs->start( 'sync', array( 'site_id' => 7 ), priority: 5 );

		self::assertSame( $success, $result );
		self::assertSame(
			array(
				array( 'start', 'consumer-plugin:sync', array( 'site_id' => 7 ), 5 ),
			),
			$engine->calls
		);
		self::assertSame( array( 'name', 'start_args', 'priority' ), self::parameter_names( ChunkedJobs::class, 'start' ) );
	}

	/**
	 * Job and Chunked Job start arguments accept the byte ceiling and reject its adjacent overflow.
	 *
	 * @param   int  $json_bytes Exact encoded argument size.
	 * @param   bool $accepted   Whether the arguments reach the engine delegate.
	 *
	 * @return  void
	 */
	#[DataProvider( 'bounded_start_arguments' )]
	public function test_job_and_chunked_job_start_arguments_observe_the_json_byte_ceiling( int $json_bytes, bool $accepted ): void {
		$args               = array( 'payload' => \str_repeat( 'a', $json_bytes - 14 ) );
		$job_engine         = new FakeJobsEngine( new Success( 'job-run' ) );
		$chunked_job_engine = new FakeChunkedJobsEngine( new Success( 'chunked-job-run' ) );
		$job_result         = ( new Jobs( 'consumer-plugin', $job_engine ) )->enqueue( 'sync', $args );
		$chunked_job_result = ( new ChunkedJobs( 'consumer-plugin', $chunked_job_engine ) )->start( 'sync', $args );

		if ( $accepted ) {
			self::assertInstanceOf( Success::class, $job_result );
			self::assertInstanceOf( Success::class, $chunked_job_result );
			self::assertCount( 1, $job_engine->calls );
			self::assertCount( 1, $chunked_job_engine->calls );

			return;
		}

		self::assertInstanceOf( Failure::class, $job_result );
		self::assertInstanceOf( ApiError::class, $job_result->error );
		self::assertSame( ErrorCode::PayloadRejected, $job_result->error->code );
		self::assertSame( 'Job "sync" arguments contain 8193 JSON bytes; the limit is 8192 bytes.', $job_result->error->message );
		self::assertInstanceOf( Failure::class, $chunked_job_result );
		self::assertInstanceOf( ApiError::class, $chunked_job_result->error );
		self::assertSame( ErrorCode::PayloadRejected, $chunked_job_result->error->code );
		self::assertSame( 'Chunked Job "sync" arguments contain 8193 JSON bytes; the limit is 8192 bytes.', $chunked_job_result->error->message );
		self::assertSame( array(), $job_engine->calls );
		self::assertSame( array(), $chunked_job_engine->calls );
	}

	/**
	 * Supplies both sides of the persisted start-argument byte boundary.
	 *
	 * @return  array<string, array{json_bytes: int, accepted: bool}>
	 */
	public static function bounded_start_arguments(): array {
		return array(
			'at limit'   => array(
				'json_bytes' => 8_192,
				'accepted'   => true,
			),
			'over limit' => array(
				'json_bytes' => 8_193,
				'accepted'   => false,
			),
		);
	}

	/**
	 * Schedule operations qualify both schedule and target identities without accepting an owner.
	 *
	 * @return  void
	 */
	public function test_schedules_sync_and_dispatch_now_with_the_bound_owner_only(): void {
		$schedule  = new Schedule( 'nightly', Recurrence::every( 300 ), 'sync' );
		$engine    = new FakeSchedulesEngine(
			new Success( true ),
			new Success(
				array(
					'identity' => 'consumer-plugin:sync',
					'run_id'   => 'schedule-run',
				)
			)
		);
		$schedules = new Schedules( 'consumer-plugin', $engine );

		$sync_result = $schedules->sync( array( $schedule ) );
		$run_result  = $schedules->dispatch_now( 'nightly' );

		self::assertInstanceOf( Success::class, $sync_result );
		self::assertInstanceOf( Success::class, $run_result );
		self::assertSame(
			array(
				array(
					'sync',
					array(
						'consumer-plugin:nightly' => array(
							'schedule' => $schedule,
							'job'      => 'consumer-plugin:sync',
						),
					),
				),
				array( 'dispatch_now', 'consumer-plugin:nightly' ),
			),
			$engine->calls
		);

		foreach ( ( new \ReflectionClass( Schedules::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( '__construct' === $method->getName() ) {
				continue;
			}
			self::assertNotContains( 'owner', \array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->getName(), $method->getParameters() ) );
		}
		self::assertSame( array( 'schedules' ), self::parameter_names( Schedules::class, 'sync' ) );
		self::assertSame( array( 'name' ), self::parameter_names( Schedules::class, 'dispatch_now' ) );
	}

	/**
	 * Run inspection and mutations can address only identities under the bound owner.
	 *
	 * @return  void
	 */
	public function test_runs_inspect_retry_and_cancel_owner_qualified_work(): void {
		$failed_run_id    = '00000000001700000000-0000000000000000042';
		$live_run_id      = '00000000001700000001-0000000000000000043';
		$inspected_run_id = '00000000001700000002-0000000000000000044';
		$engine           = new FakeRunsEngine( new Success( RunStatus::Running ), new Success( 'completed-run' ), new Success( 'replacement-run' ), new Success( 'live-run' ) );
		$runs             = new Runs( 'consumer-plugin', $engine );

		$inspected = $runs->inspect( 'sync', $inspected_run_id );
		$completed = $runs->last_completed_run_id( 'sync' );
		$retry     = $runs->retry_failed( 'sync', $failed_run_id );
		$cancel    = $runs->cancel( 'sync', $live_run_id );
		if ( $inspected->is_failure() ) {
			self::fail( 'The run-inspection facade returned an unexpected failure.' );
		}
		if ( $completed->is_failure() ) {
			self::fail( 'The completed-run facade returned an unexpected failure.' );
		}
		if ( $retry->is_failure() ) {
			self::fail( 'The retry facade returned an unexpected failure.' );
		}
		if ( $cancel->is_failure() ) {
			self::fail( 'The cancel facade returned an unexpected failure.' );
		}

		self::assertSame( RunStatus::Running, $inspected->value );
		self::assertSame( 'completed-run', $completed->value );
		self::assertSame( 'replacement-run', $retry->value );
		self::assertSame( 'live-run', $cancel->value );
		self::assertSame(
			array(
				array( 'inspect_run', 'consumer-plugin:sync', $inspected_run_id ),
				array( 'last_completed_run_id', 'consumer-plugin:sync' ),
				array( 'retry_failed', 'consumer-plugin:sync', $failed_run_id ),
				array( 'cancel', 'consumer-plugin:sync', $live_run_id ),
			),
			$engine->calls
		);

		foreach ( ( new \ReflectionClass( Runs::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( '__construct' === $method->getName() ) {
				continue;
			}
			self::assertNotContains( 'owner', \array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->getName(), $method->getParameters() ) );
		}
		self::assertSame( array( 'name', 'run_id' ), self::parameter_names( Runs::class, 'inspect' ) );
		self::assertSame( array( 'name' ), self::parameter_names( Runs::class, 'last_completed_run_id' ) );
	}

	/**
	 * Every operation preserves the exact delegated public failure object.
	 *
	 * @return  void
	 */
	public function test_result_methods_preserve_the_delegated_failure_instance(): void {
		$failure      = new Failure( new ApiError( ErrorCode::BackendRejected, 'Scripted failure.' ) );
		$run_id       = '00000000001700000000-0000000000000000042';
		$jobs         = new Jobs( 'consumer-plugin', new FakeJobsEngine( $failure ) );
		$chunked_jobs = new ChunkedJobs( 'consumer-plugin', new FakeChunkedJobsEngine( $failure ) );
		$schedules    = new Schedules( 'consumer-plugin', new FakeSchedulesEngine( $failure, $failure ) );
		$runs         = new Runs( 'consumer-plugin', new FakeRunsEngine( $failure, $failure, $failure, $failure ) );

		self::assertSame( $failure, $jobs->enqueue( 'sync' ) );
		self::assertSame( $failure, $chunked_jobs->start( 'sync' ) );
		self::assertSame( $failure, $schedules->sync( array() ) );
		self::assertSame( $failure, $schedules->dispatch_now( 'nightly' ) );
		self::assertSame( $failure, $runs->inspect( 'sync', $run_id ) );
		self::assertSame( $failure, $runs->last_completed_run_id( 'sync' ) );
		self::assertSame( $failure, $runs->retry_failed( 'sync', $run_id ) );
		self::assertSame( $failure, $runs->cancel( 'sync', $run_id ) );
	}

	/**
	 * Job commands reject deterministic violations before invoking the admission delegate.
	 *
	 * @param   array<array-key, mixed> $args     Job arguments.
	 * @param   int                     $delay    Scheduling delay.
	 * @param   int                     $priority Advisory priority.
	 * @param   string                  $message  Exact corrective exception message.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_job_commands' )]
	public function test_jobs_throw_for_deterministic_contract_violations( array $args, int $delay, int $priority, string $message ): void {
		$engine = new FakeJobsEngine( new Success( 'unexpected-run' ) );
		$jobs   = new Jobs( 'consumer-plugin', $engine );

		self::assert_invalid_argument( static fn () => $jobs->enqueue( 'sync', $args, $delay, priority: $priority ), $message );
		self::assertSame( array(), $engine->calls );
	}

	/**
	 * Supplies every job-command violation reclassified at the public facade.
	 *
	 * @return  array<string, array{args: array<array-key, mixed>, delay: int, priority: int, message: string}>
	 */
	public static function invalid_job_commands(): array {
		$argument_message = 'Job "sync" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.';

		return array(
			'negative priority'      => array(
				'args'     => array(),
				'delay'    => 0,
				'priority' => -1,
				'message'  => 'Job "sync" priority -1 is invalid; pass a value from 0 through 255.',
			),
			'priority above maximum' => array(
				'args'     => array(),
				'delay'    => 0,
				'priority' => 256,
				'message'  => 'Job "sync" priority 256 is invalid; pass a value from 0 through 255.',
			),
			'negative delay'         => array(
				'args'     => array(),
				'delay'    => -1,
				'priority' => 10,
				'message'  => 'Job "sync" delay -1 is invalid; pass a non-negative number of seconds.',
			),
			'non-portable arguments' => array(
				'args'     => array( new \stdClass() ),
				'delay'    => 0,
				'priority' => 10,
				'message'  => $argument_message,
			),
			'non-finite arguments'   => array(
				'args'     => array( \INF ),
				'delay'    => 0,
				'priority' => 10,
				'message'  => $argument_message,
			),
		);
	}

	/**
	 * Chunked Job commands reject deterministic violations before invoking the admission delegate.
	 *
	 * @param   array<array-key, mixed> $args     Chunked Job start arguments.
	 * @param   int                     $priority Advisory priority.
	 * @param   string                  $message  Exact corrective exception message.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_chunked_job_commands' )]
	public function test_chunked_jobs_throw_for_deterministic_contract_violations( array $args, int $priority, string $message ): void {
		$engine       = new FakeChunkedJobsEngine( new Success( 'unexpected-run' ) );
		$chunked_jobs = new ChunkedJobs( 'consumer-plugin', $engine );

		self::assert_invalid_argument( static fn () => $chunked_jobs->start( 'sync', $args, priority: $priority ), $message );
		self::assertSame( array(), $engine->calls );
	}

	/**
	 * Supplies every chunked-job-command violation reclassified at the public facade.
	 *
	 * @return  array<string, array{args: array<array-key, mixed>, priority: int, message: string}>
	 */
	public static function invalid_chunked_job_commands(): array {
		$argument_message = 'Chunked Job "sync" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.';

		return array(
			'negative priority'      => array(
				'args'     => array(),
				'priority' => -1,
				'message'  => 'Chunked Job "sync" priority -1 is invalid; pass a value from 0 through 255.',
			),
			'priority above maximum' => array(
				'args'     => array(),
				'priority' => 256,
				'message'  => 'Chunked Job "sync" priority 256 is invalid; pass a value from 0 through 255.',
			),
			'non-portable arguments' => array(
				'args'     => array( new \stdClass() ),
				'priority' => 10,
				'message'  => $argument_message,
			),
			'non-finite arguments'   => array(
				'args'     => array( \INF ),
				'priority' => 10,
				'message'  => $argument_message,
			),
		);
	}

	/**
	 * Job and chunked job facade defaults match the supported operation contracts.
	 *
	 * @return  void
	 */
	public function test_dispatch_defaults_match_the_supported_operation_contracts(): void {
		$jobs_engine         = new FakeJobsEngine( new Success( 'job-run' ) );
		$chunked_jobs_engine = new FakeChunkedJobsEngine( new Success( 'chunked-job-run' ) );
		$jobs                = new Jobs( 'consumer-plugin', $jobs_engine );
		$chunked_jobs        = new ChunkedJobs( 'consumer-plugin', $chunked_jobs_engine );

		self::assertInstanceOf( Success::class, $jobs->enqueue( 'sync' ) );
		self::assertInstanceOf( Success::class, $chunked_jobs->start( 'sync' ) );

		self::assertSame(
			array(
				array( 'enqueue', 'consumer-plugin:sync', array(), 0, 10 ),
				array( 'start', 'consumer-plugin:sync', array(), 10 ),
			),
			\array_merge( $jobs_engine->calls, $chunked_jobs_engine->calls )
		);
	}

	/**
	 * Every result-returning facade method carries a direct no-discard contract.
	 *
	 * @return  void
	 */
	public function test_result_methods_declare_no_discard_directly(): void {
		$methods = array(
			array( Jobs::class, 'enqueue' ),
			array( ChunkedJobs::class, 'start' ),
			array( Schedules::class, 'sync' ),
			array( Schedules::class, 'dispatch_now' ),
			array( Runs::class, 'inspect' ),
			array( Runs::class, 'last_completed_run_id' ),
			array( Runs::class, 'retry_failed' ),
			array( Runs::class, 'cancel' ),
		);

		foreach ( $methods as [ $class, $method ] ) {
			self::assertCount( 1, ( new \ReflectionMethod( $class, $method ) )->getAttributes( \NoDiscard::class ) );
		}
	}

	/**
	 * Returns one public method's parameter names in declaration order.
	 *
	 * @param   class-string $class_name Declaring class.
	 * @param   string       $method     Public method name.
	 *
	 * @return  list<string>
	 */
	private static function parameter_names( string $class_name, string $method ): array {
		return \array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->getName(), ( new \ReflectionMethod( $class_name, $method ) )->getParameters() );
	}

	/**
	 * Asserts one deterministic public contract violation.
	 *
	 * @phpstan-param \Closure(): mixed $operation
	 *
	 * @param   \Closure $operation Invalid operation.
	 * @param   string   $message   Exact corrective exception message.
	 *
	 * @return  void
	 */
	private static function assert_invalid_argument( \Closure $operation, string $message ): void {
		try {
			$operation();
			self::fail( 'The deterministic contract violation must throw InvalidArgumentException.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( $message, $exception->getMessage() );
		}
	}
}
