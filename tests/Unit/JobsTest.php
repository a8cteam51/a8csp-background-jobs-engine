<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\Engine;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobKind;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Jobs;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FaultingOverlapKeyResolverProvider;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProviderExternal;

/**
 * Exercises the owner-bound jobs manager through the production engine graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Jobs::class )]
final class JobsTest extends AbstractCapabilityManagerTestCase {
	// region TESTS.

	/**
	 * Handle and manager construction defer malformed-owner and unavailable-graph failures to the first verb.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_accessor_is_lazy_and_infallible(): void {
		$malformed = \a8csp_bgje( 'Invalid Owner' );
		self::assertInstanceOf( Engine::class, $malformed );
		self::assert_wp_error( $malformed->jobs()->dispatch( 'job' ), 'invalid_argument' );

		$this->rig->tear_down();
		$not_ready = \a8csp_bgje( self::OWNER );
		self::assertInstanceOf( Engine::class, $not_ready );
		self::assert_wp_error( $not_ready->jobs()->dispatch( 'job' ), ErrorCode::EngineUnavailable->value );
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
		$jobs        = \a8csp_bgje( self::OWNER )->jobs();
		$job         = self::job( 'job' );
		$chunked_job = self::chunked_job( 'chunked-job' );

		self::assertTrue( $jobs->register( $job ) );
		self::assertTrue( $jobs->register( $chunked_job ) );

		$job_run     = self::assert_run( $jobs->dispatch( 'job', array( 'site_id' => 7 ), 15, 23 ), self::OWNER . ':job', RunStatus::Running );
		$chunked_run = self::assert_run( $jobs->dispatch( 'chunked-job', array( 'scope' => 'all' ), priority: 31 ), self::OWNER . ':chunked-job', RunStatus::Running );

		self::assertNotSame( '', (string) $job_run->id );
		self::assertNotSame( '', (string) $chunked_run->id );
		self::assertSame( 23, self::latest_backend_call( $this->rig, 'schedule_single' )['args']['priority'] ?? null );
		self::assertSame( 31, self::latest_backend_call( $this->rig, 'enqueue_async' )['args']['priority'] ?? null );
		self::assert_wp_error( $jobs->register( $job ), 'already_registered' );
		self::assert_wp_error( $jobs->register( $chunked_job ), 'already_registered' );
	}

	/**
	 * One portal dispatch verb executes registered plain and chunked jobs through their resolved kinds.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_executes_registered_plain_and_chunked_jobs_through_the_portal(): void {
		$jobs               = \a8csp_bgje( self::OWNER )->jobs();
		$job                = new RecordingJob( 'plain-job' );
		$chunked_job        = new RecordingChunkedJob( 'chunked-job' );
		$chunked_job->queue = array( array( 'page' => 1 ) );

		self::assertTrue( $jobs->register( $job->definition() ) );
		self::assertTrue( $jobs->register( $chunked_job->definition() ) );
		$job_args     = array( 'site_id' => 7 );
		$chunked_args = array( 'scope' => 'all' );

		self::assert_run( $jobs->dispatch( 'plain-job', $job_args ), self::OWNER . ':plain-job', RunStatus::Running );
		self::assert_run( $jobs->dispatch( 'chunked-job', $chunked_args ), self::OWNER . ':chunked-job', RunStatus::Running );
		for ( $delivery = 0; 5 > $delivery; ++$delivery ) {
			$this->rig->run_due();
		}

		self::assertSame( array( $job_args ), $job->calls );
		self::assertSame( array( $chunked_args ), $chunked_job->generate_calls );
		self::assertSame( array( array( 'page' => 1 ) ), \array_column( $chunked_job->process_calls, 'chunk_args' ) );
	}

	/**
	 * Registration rejects an uninstalled grammar-valid kind without invoking its execution object.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_rejects_an_uninstalled_kind_and_names_it(): void {
		$kind   = JobKind::from( 'future_kind' );
		$result = \a8csp_bgje( self::OWNER )->jobs()->register( JobDefinition::for_kind( 'future-work', $kind, new \stdClass() ) );
		$error  = self::assert_wp_error( $result, ErrorCode::InvalidArgument->value );

		self::assertStringContainsString( 'future_kind', $error->get_error_message() );
	}

	/**
	 * The installed handler rejects an execution object that does not implement its role.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_rejects_an_incompatible_execution_and_names_the_contract(): void {
		$result = \a8csp_bgje( self::OWNER )->jobs()->register( JobDefinition::for_kind( 'wrong-execution', JobKind::job(), new \stdClass() ) );
		$error  = self::assert_wp_error( $result, ErrorCode::InvalidArgument->value );

		self::assertStringContainsString( 'job', $error->get_error_message() );
		self::assertStringContainsString( JobExecutionInterface::class, $error->get_error_message() );
	}

	/**
	 * Closure definitions register and execute with the engine's runtime and overlap defaults.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_closure_definition_registers_dispatches_and_executes_with_engine_defaults(): void {
		$handled            = array();
		$observed_heartbeat = null;
		$jobs               = \a8csp_bgje( self::OWNER )->jobs();
		$definition         = JobDefinition::closure(
			'closure-defaults',
			function ( array $start_args, RunContextInterface $context ) use ( &$handled, &$observed_heartbeat ): void {
				$handled[]          = array( $start_args, (string) $context->get_run_id() );
				$snapshot           = $this->rig->inspection()->runs( self::OWNER . ':closure-defaults' );
				$observed_heartbeat = $snapshot['live'][0]['heartbeat_at'] ?? null;
			}
		);

		self::assertTrue( $jobs->register( $definition ) );
		$args = array( 'site_id' => 7 );
		$run  = self::assert_run( $jobs->dispatch( 'closure-defaults', $args ), self::OWNER . ':closure-defaults', RunStatus::Running );
		++$this->rig->randomizer()->value;
		self::assert_wp_error( $jobs->dispatch( 'closure-defaults', $args ), ErrorCode::OverlapHeld->value );

		$this->rig->run_due();

		self::assertSame( array( array( $args, (string) $run->id ) ), $handled );
		self::assertSame( self::NOW + 300, $observed_heartbeat );
	}

	/**
	 * Job options control runtime credit, retries, overlap policy, and overlap identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_job_options_drive_runtime_retry_and_overlap_policies(): void {
		/** @var list<array<array-key, mixed>> $overlap_args */
		$overlap_args       = array();
		$observed_heartbeat = null;
		$observed_run_ids   = array();
		$jobs               = \a8csp_bgje( self::OWNER )->jobs();
		$handler            = function ( array $start_args, RunContextInterface $context ) use ( &$observed_heartbeat, &$observed_run_ids ): void {
			$observed_run_ids[] = $context->get_run_id();
			if ( null === $observed_heartbeat ) {
				$snapshot           = $this->rig->inspection()->runs( self::OWNER . ':configured' );
				$observed_heartbeat = $snapshot['live'][0]['heartbeat_at'] ?? null;
			}
			if ( true === ( $start_args['fail'] ?? false ) ) {
				throw new \RuntimeException( 'Retry this callable failure.' );
			}
		};

		$overlap_key = static function ( array $args ) use ( &$overlap_args ): string {
			$overlap_args[] = $args;
			$site_id        = $args['site_id'] ?? null;
			if ( ! \is_int( $site_id ) ) {
				throw new \UnexpectedValueException( 'The test overlap key requires an integer site_id.' );
			}

			return 'site-' . $site_id;
		};
		$execution   = new class( $handler ) implements JobExecutionInterface {
			/**
			 * Constructor.
			 *
			 * @param   \Closure $handler Job handler.
			 */
			public function __construct(
				private \Closure $handler,
			) {}

			/** {@inheritDoc} */
			#[\Override]
			public function handle( array $start_args, RunContextInterface $context ): void {
				( $this->handler )( $start_args, $context );
			}
		};
		$options     = new JobOptions(
			max_runtime: 42,
			retry: new RetryPolicy(
				max_attempts: 3,
				base_delay: 50,
				multiplier: 3,
				max_delay: 150,
			),
			overlap: OverlapPolicy::Reject,
			overlap_key: $overlap_key,
		);

		self::assertTrue( $jobs->register( JobDefinition::job( 'configured', $execution, $options ) ) );
		$completed_args = array( 'site_id' => 7 );
		$completed_run  = self::assert_run( $jobs->dispatch( 'configured', $completed_args ), self::OWNER . ':configured', RunStatus::Running );
		++$this->rig->clock()->timestamp;
		$overlap_error = self::assert_wp_error( $jobs->dispatch( 'configured', $completed_args ), ErrorCode::OverlapHeld->value );
		self::assertSame( array( 'run_id' => (string) $completed_run->id ), $overlap_error->get_error_data() );

		$this->rig->run_due();
		++$this->rig->clock()->timestamp;
		$failed_args = array(
			'site_id' => 8,
			'fail'    => true,
		);
		$failed_run  = self::assert_run( $jobs->dispatch( 'configured', $failed_args ), self::OWNER . ':configured', RunStatus::Running );

		$this->rig->randomizer()->calls = array();
		for ( $attempt = 0; 3 > $attempt; ++$attempt ) {
			$this->rig->run_due();
		}

		self::assertSame( self::NOW + 43, $observed_heartbeat );
		self::assertEquals( array( $completed_run->id, $failed_run->id, $failed_run->id, $failed_run->id ), $observed_run_ids );
		self::assertSame( array( $completed_args, $completed_args, $failed_args ), $overlap_args );
		$completed_hooks = $this->rig->hooks()->fired( 'a8csp_bgje/completed' );
		self::assertCount( 1, $completed_hooks );
		self::assertSame( self::OWNER . ':configured', $completed_hooks[0][0] ?? null );
		$failed_hooks = $this->rig->hooks()->fired( 'a8csp_bgje/failed' );
		self::assertCount( 1, $failed_hooks );
		$failure = $failed_hooks[0][0] ?? null;
		self::assertInstanceOf( RunFailure::class, $failure );
		self::assertSame( (string) $failed_run->id, (string) $failure->run_id );
		self::assertSame( 3, $failure->attempts );
		self::assertSame( ErrorCode::ExecutionFailed, $failure->code );
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
	 * Consumer overlap-key failures remain typed at the public dispatch boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Closure $resolver Faulting overlap-key resolver.
	 *
	 * @return  void
	 */
	#[DataProviderExternal( FaultingOverlapKeyResolverProvider::class, 'resolvers' )]
	public function test_dispatch_contains_overlap_key_resolver_failures( \Closure $resolver ): void {
		$jobs = \a8csp_bgje( self::OWNER )->jobs();
		$job  = new RecordingJob( 'faulting-overlap-key' );

		self::assertTrue( $jobs->register( $job->definition( new JobOptions( overlap_key: $resolver ) ) ) );
		self::assert_wp_error( $jobs->dispatch( 'faulting-overlap-key', array( 'site_id' => 7 ) ), ErrorCode::ExecutionFailed->value );
	}

	/**
	 * An unregistered dispatch surfaces its composed identity alongside the code and engine-authored message in WP_Error.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unregistered_dispatch_exposes_its_identity_in_error_data(): void {
		$error = self::assert_wp_error( \a8csp_bgje( self::OWNER )->jobs()->dispatch( 'missing' ), ErrorCode::UnknownJob->value );
		$data  = $error->get_error_data();

		self::assertSame( 'Background-work "engine-test:missing" is not registered; register it before dispatching.', $error->get_error_message() );
		self::assertSame( array( 'identity' => self::OWNER . ':missing' ), $data );
		self::assertArrayNotHasKey( 'name', $data );
	}

	// endregion.
}
