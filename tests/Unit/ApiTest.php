<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the owner-bound public front door and its stable error boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ApiTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress seams before public functions are resolved.
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

		$this->rig = EngineRig::set_up();
	}

	/**
	 * Releases request-local engine state after each API scenario.
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
	 * Access before init fails with the earliest safe lifecycle contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_front_door_is_unavailable_before_init(): void {
		$GLOBALS['a8csp_bgje_test_did_actions'] = array();

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs( 'The background jobs client is available from the init hook; call a8csp_bgje() from an init callback or later.' );

		\a8csp_bgje( 'consumer-plugin' );
	}

	/**
	 * Access during and after init returns a working owner-bound facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_front_door_flows_during_and_after_init(): void {
		$GLOBALS['a8csp_bgje_test_doing_actions'] = array( 'init' );
		$during                                   = \a8csp_bgje( 'during-init' );
		$during->jobs()->register( new RecordingJob( 'sync' ) );
		self::assertInstanceOf( Success::class, $during->jobs()->enqueue( 'sync' ) );

		$GLOBALS['a8csp_bgje_test_doing_actions'] = array();
		self::assertInstanceOf( Client::class, \a8csp_bgje( 'after-init' ) );
	}

	/**
	 * Equal local names remain isolated by owner across admission, delivery, and completion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_two_owners_run_the_same_local_job_name_independently(): void {
		$left      = $this->rig->client( 'owner-left' );
		$right     = $this->rig->client( 'owner-right' );
		$left_job  = new RecordingJob( 'sync' );
		$right_job = new RecordingJob( 'sync' );
		$left->jobs()->register( $left_job );
		$right->jobs()->register( $right_job );

		self::assertInstanceOf( Success::class, $left->jobs()->enqueue( 'sync', array( 'owner' => 'left' ) ) );
		self::assertInstanceOf( Success::class, $right->jobs()->enqueue( 'sync', array( 'owner' => 'right' ) ) );
		$this->rig->run_due();
		$this->rig->run_due();

		self::assertSame( array( array( 'owner' => 'left' ) ), $left_job->calls );
		self::assertSame( array( array( 'owner' => 'right' ) ), $right_job->calls );
		self::assertInstanceOf( Success::class, $left->runs()->last_completed_run_id( 'sync' ) );
		self::assertInstanceOf( Success::class, $right->runs()->last_completed_run_id( 'sync' ) );
	}

	/**
	 * Every invalid or reserved owner is rejected at the single public front door.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Invalid owner.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_owners' )]
	public function test_front_door_rejects_invalid_or_reserved_owners( string $owner ): void {
		$this->expectException( \InvalidArgumentException::class );

		\a8csp_bgje( $owner );
	}

	/**
	 * Public concept facades map internal admission failures to stable API codes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_concept_facades_map_internal_failures_to_public_codes(): void {
		$client = $this->rig->client( 'consumer-plugin' );

		self::assert_api_failure( $client->jobs()->enqueue( 'missing-job' ), ApiErrorCode::UnknownWork, array( 'name' ) );
		self::assert_api_failure( $client->chunked_jobs()->start( 'missing-chunked-job' ), ApiErrorCode::UnknownWork, array( 'name' ) );
		self::assert_api_failure( $client->schedules()->dispatch_now( 'missing-schedule' ), ApiErrorCode::UnknownSchedule, array( 'owner', 'schedule' ) );
	}

	/**
	 * Last-completed lookup follows terminal order and ignores a later failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_last_completed_run_id_retains_the_latest_successful_terminal(): void {
		$client = $this->rig->client( 'consumer-plugin' );
		$job    = new RecordingJob( 'sync' );
		$client->jobs()->register( $job );
		$first = $this->enqueue_and_run( $client, array( 'sequence' => 1 ) );
		$last  = $this->enqueue_and_run( $client, array( 'sequence' => 2 ) );
		self::assertNotSame( $first, $last );

		$job->throwable = new NonRetryableException( 'Terminal failure.' );
		$this->enqueue_and_run( $client, array( 'sequence' => 3 ) );
		$result = $client->runs()->last_completed_run_id( 'sync' );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $last, $result->value );
	}

	/**
	 * Completed-hook clients observe the previous completion before the current pointer advances.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_last_completed_run_id_inside_a_completed_hook_returns_the_previous_completion(): void {
		$client = $this->rig->client( 'consumer-plugin' );
		$client->jobs()->register( new RecordingJob( 'sync' ) );
		$observed  = array();
		$callbacks = $GLOBALS['a8csp_bgje_test_action_callbacks'] ?? null;
		self::assertIsArray( $callbacks );
		$callbacks['a8csp_jobs_engine/completed/consumer-plugin:sync'] = static function ( string $run_id, array $start_args, ?string $previous_completed_run_id ) use ( $client, &$observed ): void {
			$result = $client->runs()->last_completed_run_id( 'sync' );
			self::assertInstanceOf( Success::class, $result );
			$observed[] = array( $previous_completed_run_id, $result->value );
		};

		$GLOBALS['a8csp_bgje_test_action_callbacks'] = $callbacks;

		$first  = $this->enqueue_and_run( $client, array( 'sequence' => 1 ) );
		$second = $this->enqueue_and_run( $client, array( 'sequence' => 2 ) );

		self::assertSame( array( array( null, null ), array( $first, $first ) ), $observed );
		$result = $client->runs()->last_completed_run_id( 'sync' );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $second, $result->value );
	}

	/**
	 * Callback replay retains the predecessor frozen before a later same-identity completion.
	 *
	 * @load-bearing durability
	 * @pin-rationale Five scripted marker-CAS losses leave a production terminal row for real maintenance replay; no public result exposes the callback marker or frozen terminal snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_callback_replay_uses_the_frozen_previous_completion(): void {
		$identity = 'consumer-plugin:sync';
		$client   = $this->rig->client( 'consumer-plugin' );
		$job      = new RecordingJob( 'sync' );
		$client->jobs()->register( $job );
		$seed_run_id = $this->enqueue_and_run( $client, array( 'sequence' => 'seed' ) );

		++$this->rig->clock()->timestamp;
		$target = $client->jobs()->enqueue( 'sync', array( 'sequence' => 'target' ) );
		self::assertInstanceOf( Success::class, $target );
		self::assertIsString( $target->value );
		$target_run_id        = $target->value;
		$intervening_run_id   = null;
		$target_callback_runs = 0;
		$job->on_completed    = function ( string $run_id ) use ( $client, $target_run_id, &$intervening_run_id, &$target_callback_runs ): void {
			if ( $target_run_id !== $run_id || 1 !== ++$target_callback_runs ) {
				return;
			}

			++$this->rig->clock()->timestamp;
			$intervening = $client->jobs()->enqueue( 'sync', array( 'sequence' => 'intervening' ) );
			self::assertInstanceOf( Success::class, $intervening );
			self::assertIsString( $intervening->value );
			$intervening_run_id = $intervening->value;
			$this->rig->run_due();

			for ( $attempt = 0; 5 > $attempt; ++$attempt ) {
				$this->rig->wpdb()->before_next(
					'update',
					static function ( WpdbLockSpy $database ): void {
						$database->script_result( 'update', false );
					}
				);
			}
		};

		$this->rig->run_due();

		$run_store = new RunStore( $identity, $this->rig->clock(), new OptionRows( $this->rig->wpdb() ) );
		$terminal  = $run_store->get( $target_run_id );
		self::assertNotNull( $terminal );
		self::assertSame( $seed_run_id, $terminal->previous_completed_run_id );
		self::assertSame( array(), $terminal->effects );
		$latest_before_replay = $client->runs()->last_completed_run_id( 'sync' );
		self::assertInstanceOf( Success::class, $latest_before_replay );
		self::assertSame( $intervening_run_id, $latest_before_replay->value );
		$this->rig->clock()->timestamp = $terminal->heartbeat_at + 3_601;

		$this->rig->run_maintenance();

		self::assertIsString( $intervening_run_id );
		$target_callbacks = \array_values( \array_filter( $job->completed_calls, static fn ( array $call ): bool => $target_run_id === $call['run_id'] ) );
		self::assertCount( 2, $target_callbacks );
		self::assertSame( array( $seed_run_id, $seed_run_id ), \array_column( $target_callbacks, 'previous_completed_run_id' ) );
		$intervening_callbacks = \array_values( \array_filter( $job->completed_calls, static fn ( array $call ): bool => $intervening_run_id === $call['run_id'] ) );
		self::assertCount( 1, $intervening_callbacks );
		self::assertSame( $seed_run_id, $intervening_callbacks[0]['previous_completed_run_id'] );
		self::assertNull( $run_store->get( $target_run_id ) );
	}

	/**
	 * Raw database detail never crosses the public failure boundary.
	 *
	 * @load-bearing security
	 * @pin-rationale The scripted database string is attacker- or operator-controlled detail; the public result must expose only a stable message and the safe option-name context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_storage_failure_redacts_database_detail(): void {
		$client = $this->rig->client( 'consumer-plugin' );
		$client->jobs()->register( new RecordingJob( 'sync' ) );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'client-controlled database detail';
			}
		);

		$result = $client->runs()->retry_failed( 'sync', '00000000001700000000-0000000000000000042' );

		self::assert_api_failure( $result, ApiErrorCode::StorageFailure, array( 'option_name' ) );
		if ( ! $result instanceof Failure || ! $result->error instanceof ApiError ) {
			throw new \LogicException( 'The storage failure did not retain its public API error.' );
		}
		self::assertSame( 'Authoritative option-row read failed; repair WordPress option reads and retry.', $result->error->message );
		self::assertSame( array( 'option_name' => 'a8csp_bgje_failed_runs_consumer-plugin:sync' ), $result->error->context );
		self::assertStringNotContainsString( 'client-controlled', $result->error->message );
	}

	/**
	 * The public function surface contains only the owner-bound client front door.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_legacy_global_functions_are_absent(): void {
		foreach ( array( 'a8csp_bgje_engine', 'a8csp_bgje_enqueue_job', 'a8csp_bgje_start_chunked_job', 'a8csp_bgje_sync_schedules', 'a8csp_bgje_run_schedule_now', 'a8csp_bgje_retry_failed_run', 'a8csp_bgje_cancel_run' ) as $function ) {
			self::assertFalse( \function_exists( $function ), $function );
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Enqueues and delivers one job through the public graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Client             $client Owner-bound public facade.
	 * @param   array<string, mixed> $args     Job arguments.
	 *
	 * @return  string
	 */
	private function enqueue_and_run( Client $client, array $args ): string {
		++$this->rig->clock()->timestamp;
		$result = $client->jobs()->enqueue( 'sync', $args );
		self::assertInstanceOf( Success::class, $result );
		if ( ! \is_string( $result->value ) ) {
			throw new \LogicException( 'A successful enqueue must publish a run identifier.' );
		}
		$this->rig->run_due();

		return $result->value;
	}

	/**
	 * Asserts one stable public failure contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   AbstractResult $result       Public API result.
	 * @param   ApiErrorCode   $code         Expected stable error code.
	 * @param   array          $context_keys Expected public context keys.
	 *
	 * @return  void
	 *
	 * @phpstan-param AbstractResult<mixed, \A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ErrorInterface> $result
	 * @phpstan-param list<string> $context_keys
	 */
	private static function assert_api_failure( AbstractResult $result, ApiErrorCode $code, array $context_keys ): void {
		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( $code, $result->error->code );
		self::assertSame( $context_keys, \array_keys( $result->error->context ) );
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Supplies every invalid or reserved client owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{owner: string}>
	 */
	public static function invalid_owners(): array {
		return array(
			'empty'           => array( 'owner' => '' ),
			'uppercase'       => array( 'owner' => 'Consumer' ),
			'colon'           => array( 'owner' => 'consumer:plugin' ),
			'33 bytes'        => array( 'owner' => \str_repeat( 'o', 33 ) ),
			'reserved owner'  => array( 'owner' => 'a8csp-jobs-engine' ),
			'reserved prefix' => array( 'owner' => 'a8csp-jobs-engine-addon' ),
		);
	}

	// endregion.
}
