<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Internal;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the procedural facade through the production engine graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversFunction( 'a8csp_bgje_register' )]
#[CoversFunction( 'a8csp_bgje_enqueue' )]
#[CoversFunction( 'a8csp_bgje_start' )]
#[CoversFunction( 'a8csp_bgje_sync_schedules' )]
#[CoversFunction( 'a8csp_bgje_dispatch_schedule' )]
#[CoversFunction( 'a8csp_bgje_inspect_run' )]
#[CoversFunction( 'a8csp_bgje_last_completed_run' )]
#[CoversFunction( 'a8csp_bgje_retry_failed_run' )]
#[CoversFunction( 'a8csp_bgje_cancel_run' )]
final class ProceduralFacadeTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const int NOW      = 1_700_000_000;
	private const string OWNER = 'procedural-facade';

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

		self::assertTrue( \a8csp_bgje_register( self::OWNER, $job ) );
		self::assertTrue( \a8csp_bgje_register( self::OWNER, $chunked_job ) );
		self::assert_wp_error( \a8csp_bgje_register( self::OWNER, $job ), 'already_registered' );
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
		self::assertTrue( \a8csp_bgje_register( self::OWNER, self::job( 'job' ) ) );
		self::assertTrue( \a8csp_bgje_register( self::OWNER, self::chunked_job( 'chunked-job' ) ) );
		$job_args   = array( 'site_id' => 7 );
		$start_args = array( 'scope' => 'all' );

		$job_run     = self::assert_run( \a8csp_bgje_enqueue( self::OWNER, 'job', $job_args, 15, 23 ), self::OWNER . ':job', RunStatus::Running );
		$chunked_run = self::assert_run( \a8csp_bgje_start( self::OWNER, 'chunked-job', $start_args, 31 ), self::OWNER . ':chunked-job', RunStatus::Running );

		self::assertNotSame( '', (string) $job_run->id );
		self::assertNotSame( '', (string) $chunked_run->id );
		self::assertSame( self::NOW + 15, self::latest_backend_call( $this->rig, 'schedule_single' )['args']['timestamp'] ?? null );
		self::assertSame( 23, self::latest_backend_call( $this->rig, 'schedule_single' )['args']['priority'] ?? null );
		self::assertSame( 31, self::latest_backend_call( $this->rig, 'enqueue_async' )['args']['priority'] ?? null );
		self::assertEquals( array( array( $job_run->id, $job_args ) ), $this->rig->hooks()->fired( 'a8csp_jobs_engine/started/' . self::OWNER . ':job' ) );

		$this->rig->run_due();
		self::assertEquals( array( array( $chunked_run->id, $start_args ) ), $this->rig->hooks()->fired( 'a8csp_jobs_engine/started/' . self::OWNER . ':chunked-job' ) );
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
		self::assertTrue( \a8csp_bgje_register( self::OWNER, self::job( 'scheduled-job' ) ) );
		$schedules = array(
			array(
				'name'     => 'nightly',
				'every'    => 300,
				'job'      => 'scheduled-job',
				'args'     => array( 'scope' => 'all' ),
				'catch_up' => 'skip',
				'priority' => 41,
			),
		);

		self::assertTrue( \a8csp_bgje_sync_schedules( self::OWNER, $schedules ) );
		$run = self::assert_run( \a8csp_bgje_dispatch_schedule( self::OWNER, 'nightly' ), self::OWNER . ':scheduled-job', RunStatus::Running );

		self::assertNotSame( '', (string) $run->id );
		self::assertSame( 300, self::latest_backend_call( $this->rig, 'schedule_recurring' )['args']['interval'] ?? null );
		self::assertSame( 41, self::latest_backend_call( $this->rig, 'schedule_recurring' )['args']['priority'] ?? null );
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
	public function test_sync_rejects_malformed_entries( array $schedules ): void {
		self::assert_wp_error( \a8csp_bgje_sync_schedules( self::OWNER, $schedules ), 'invalid_argument' );
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
		self::assertTrue( \a8csp_bgje_register( self::OWNER, self::job( 'inspect' ) ) );
		self::assertNull( \a8csp_bgje_last_completed_run( self::OWNER, 'inspect' ) );

		$admitted = self::assert_run( \a8csp_bgje_enqueue( self::OWNER, 'inspect' ), self::OWNER . ':inspect', RunStatus::Running );
		self::assert_run( \a8csp_bgje_inspect_run( self::OWNER, 'inspect', (string) $admitted->id ), self::OWNER . ':inspect', RunStatus::Running, $admitted->id );
		$this->rig->run_due();
		self::assert_run( \a8csp_bgje_inspect_run( self::OWNER, 'inspect', (string) $admitted->id ), self::OWNER . ':inspect', RunStatus::Completed, $admitted->id );
		self::assert_run( \a8csp_bgje_last_completed_run( self::OWNER, 'inspect' ), self::OWNER . ':inspect', RunStatus::Completed, $admitted->id );
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
		self::assert_wp_error( \a8csp_bgje_inspect_run( self::OWNER, 'inspect', 'malformed' ), 'invalid_argument' );
		self::assert_wp_error( \a8csp_bgje_retry_failed_run( self::OWNER, 'failed', 'malformed' ), 'invalid_argument' );
		self::assert_wp_error( \a8csp_bgje_cancel_run( self::OWNER, 'cancel', 'malformed' ), 'invalid_argument' );
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
			static function ( array $args, RunContext $context ): void {
				throw new NonRetryableException( 'Retain this failed run.' );
			}
		);
		self::assertTrue( \a8csp_bgje_register( self::OWNER, $failed_job ) );
		$failed = self::assert_run( \a8csp_bgje_enqueue( self::OWNER, 'failed', array( 'site_id' => 7 ) ), self::OWNER . ':failed', RunStatus::Running );
		$this->rig->run_due();

		++$this->rig->clock()->timestamp;
		$retry = self::assert_run( \a8csp_bgje_retry_failed_run( self::OWNER, 'failed', (string) $failed->id ), self::OWNER . ':failed', RunStatus::Running );
		self::assertNotSame( (string) $failed->id, (string) $retry->id );

		self::assertTrue( \a8csp_bgje_register( self::OWNER, self::job( 'cancel' ) ) );
		$pending   = self::assert_run( \a8csp_bgje_enqueue( self::OWNER, 'cancel', delay_seconds: 60 ), self::OWNER . ':cancel', RunStatus::Running );
		$cancelled = self::assert_run( \a8csp_bgje_cancel_run( self::OWNER, 'cancel', (string) $pending->id ), self::OWNER . ':cancel', RunStatus::Cancelled, $pending->id );
		self::assertSame( (string) $pending->id, (string) $cancelled->id );
	}

	/**
	 * All aliases expose the exact capability-manager verb signatures and NoDiscard attributes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_function_signatures_and_no_discard_contracts(): void {
		$signatures = array(
			'a8csp_bgje_register'           => '(string $owner, A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition $definition): WP_Error|true',
			'a8csp_bgje_enqueue'            => '(string $owner, string $name, array $args = array(), int $delay_seconds = 0, int $priority = 10): A8C\SpecialProjects\BackgroundJobsEngine\Run\Run|WP_Error',
			'a8csp_bgje_start'              => '(string $owner, string $name, array $start_args = array(), int $priority = 10): A8C\SpecialProjects\BackgroundJobsEngine\Run\Run|WP_Error',
			'a8csp_bgje_sync_schedules'     => '(string $owner, array $schedules): WP_Error|true',
			'a8csp_bgje_dispatch_schedule'  => '(string $owner, string $name): A8C\SpecialProjects\BackgroundJobsEngine\Run\Run|WP_Error',
			'a8csp_bgje_inspect_run'        => '(string $owner, string $name, string $run_id): A8C\SpecialProjects\BackgroundJobsEngine\Run\Run|WP_Error',
			'a8csp_bgje_last_completed_run' => '(string $owner, string $name): A8C\SpecialProjects\BackgroundJobsEngine\Run\Run|WP_Error|null',
			'a8csp_bgje_retry_failed_run'   => '(string $owner, string $name, string $run_id): A8C\SpecialProjects\BackgroundJobsEngine\Run\Run|WP_Error',
			'a8csp_bgje_cancel_run'         => '(string $owner, string $name, string $run_id): A8C\SpecialProjects\BackgroundJobsEngine\Run\Run|WP_Error',
		);

		foreach ( $signatures as $function => $signature ) {
			$reflection = new \ReflectionFunction( $function );
			self::assertSame( $signature, self::reflection_signature( $reflection ) );
			self::assertCount( 1, $reflection->getAttributes( \NoDiscard::class ) );
		}
	}

	// endregion.

	// region DATA PROVIDERS.

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
			'unknown key'      => array(
				'schedules' => array(
					array(
						'name'      => 'nightly',
						'every'     => 300,
						'job'       => 'job',
						'prioritry' => 5,
					),
				),
			),
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
	 * @return  JobDefinition
	 */
	private static function job( string $name, ?\Closure $handler = null ): JobDefinition {
		return JobDefinition::closure( $name, $handler ?? static function ( array $args, RunContext $context ): void {} );
	}

	/**
	 * Returns a minimal consumer-authored chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Stable owner-local chunked job name.
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
	 * @param   string     $identity Expected owner-qualified identity.
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

	/**
	 * Normalizes one reflected public function signature for an exact contract assertion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
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
				if ( \is_int( $default ) ) {
					return $signature . ' = ' . (string) $default;
				}

				throw new \LogicException( 'Facade signatures use only array or integer default values.' );
			},
			$reflection->getParameters()
		);

		return '(' . \implode( ', ', $parameters ) . '): ' . (string) $reflection->getReturnType();
	}

	// endregion.
}
