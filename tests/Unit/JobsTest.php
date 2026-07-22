<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\Engine;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Batch\AbstractBatchJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Jobs;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exercises the owner-bound jobs manager through the production engine graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Jobs::class )]
final class JobsTest extends CapabilityManagerTestCase {
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
		self::assert_wp_error( $malformed->jobs()->enqueue( 'job' ), 'invalid_argument' );

		$this->rig->tear_down();
		$not_ready = \a8csp_bgje( self::OWNER );
		self::assertInstanceOf( Engine::class, $not_ready );
		self::assert_wp_error( $not_ready->jobs()->enqueue( 'job' ), ErrorCode::EngineUnavailable->value );
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

		$job_run     = self::assert_run( $jobs->enqueue( 'job', array( 'site_id' => 7 ), 15, 23 ), self::OWNER . ':job', RunStatus::Running );
		$chunked_run = self::assert_run( $jobs->start( 'chunked-job', array( 'scope' => 'all' ), 31 ), self::OWNER . ':chunked-job', RunStatus::Running );

		self::assertNotSame( '', (string) $job_run->id );
		self::assertNotSame( '', (string) $chunked_run->id );
		self::assertSame( 23, self::latest_backend_call( $this->rig, 'schedule_single' )['args']['priority'] ?? null );
		self::assertSame( 31, self::latest_backend_call( $this->rig, 'enqueue_async' )['args']['priority'] ?? null );
		self::assert_wp_error( $jobs->register( $job ), 'already_registered' );
		self::assert_wp_error( $jobs->register( $chunked_job ), 'already_registered' );
	}

	/**
	 * Typed callable values control runtime credit, retries, overlap identity, and terminal notifications.
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
		$jobs               = \a8csp_bgje( self::OWNER )->jobs();
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

		$overlap_key  = static function ( array $args ) use ( &$overlap_args ): string {
			$overlap_args[] = $args;
			$site_id        = $args['site_id'] ?? null;
			if ( ! \is_int( $site_id ) ) {
				throw new \UnexpectedValueException( 'The test overlap key requires an integer site_id.' );
			}

			return 'site-' . $site_id;
		};
		$on_completed = static function ( string $run_id, array $args, ?string $previous_completed_run_id ) use ( &$completed ): void {
			$completed[] = array(
				'run_id'   => $run_id,
				'args'     => $args,
				'previous' => $previous_completed_run_id,
			);
		};
		$on_failed    = static function ( string $run_id, array $args, RunFailure $failure ) use ( &$failed ): void {
			$failed[] = array(
				'run_id'  => $run_id,
				'args'    => $args,
				'failure' => $failure,
			);
		};

		self::assertTrue(
			$jobs->register_callable(
				name: 'callable',
				handler: $handler,
				max_runtime: 42,
				retry: new RetryPolicy(
					max_attempts: 3,
					base_delay: 50,
					multiplier: 3,
					max_delay: 150,
				),
				overlap: OverlapPolicy::Reject,
				overlap_key: $overlap_key,
				on_completed: $on_completed,
				on_failed: $on_failed,
			)
		);
		$completed_args = array( 'site_id' => 7 );
		$completed_run  = self::assert_run( $jobs->enqueue( 'callable', $completed_args ), self::OWNER . ':callable', RunStatus::Running );
		++$this->rig->clock()->timestamp;
		$overlap_error = self::assert_wp_error( $jobs->enqueue( 'callable', $completed_args ), ErrorCode::OverlapHeld->value );
		self::assertSame( array( 'run_id' => (string) $completed_run->id ), $overlap_error->get_error_data() );

		$this->rig->run_due();
		++$this->rig->clock()->timestamp;
		$failed_args = array(
			'site_id' => 8,
			'fail'    => true,
		);
		$failed_run  = self::assert_run( $jobs->enqueue( 'callable', $failed_args ), self::OWNER . ':callable', RunStatus::Running );

		$this->rig->randomizer()->calls = array();
		for ( $attempt = 0; 3 > $attempt; ++$attempt ) {
			$this->rig->run_due();
		}

		self::assertSame( self::NOW + 43, $observed_heartbeat );
		self::assertEquals( array( $completed_run->id, $failed_run->id, $failed_run->id, $failed_run->id ), $observed_run_ids );
		self::assertSame( array( $completed_args, $completed_args, $failed_args ), $overlap_args );
		self::assertSame(
			array(
				array(
					'run_id'   => (string) $completed_run->id,
					'args'     => $completed_args,
					'previous' => null,
				),
			),
			$completed
		);
		self::assertCount( 1, $failed );
		self::assertSame( (string) $failed_run->id, $failed[0]['run_id'] );
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
	 * Internal failures preserve code, engine-authored message, and structured context in WP_Error.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_internal_failure_maps_every_api_error_field(): void {
		$error = self::assert_wp_error( \a8csp_bgje( self::OWNER )->jobs()->enqueue( 'missing' ), ErrorCode::UnknownWork->value );

		self::assertSame( 'job "engine-test:missing" is not registered; register it before enqueueing.', $error->get_error_message() );
		self::assertSame( array( 'name' => self::OWNER . ':missing' ), $error->get_error_data() );
	}

	/**
	 * The batch kind registers nowhere: rejection happens before any engine call.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_rejects_the_batch_kind(): void {
		$batch = new class() extends AbstractBatchJob {
			public function get_name(): string {
				return 'batch-probe';
			}
		};

		$result = \a8csp_bgje( self::OWNER )->jobs()->register( $batch );
		self::assertSame( 'Batch jobs are not supported.', self::assert_wp_error( $result, ErrorCode::InvalidArgument->value )->get_error_message() );
	}

	// endregion.
}
