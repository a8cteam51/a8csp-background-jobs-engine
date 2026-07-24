<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\OwnerOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
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
		require_once __DIR__ . '/wp-cron-stubs.php';
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
	 * Access before init returns a lazy owner-bound handle.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_front_door_is_available_before_init(): void {
		$GLOBALS['a8csp_bgje_test_did_actions'] = array();

		self::assertInstanceOf( Engine::class, \a8csp_bgje( 'consumer-plugin' ) );
	}

	/**
	 * Access during and after init returns lazy owner-bound handles.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_front_door_is_independent_of_init_progress(): void {
		$GLOBALS['a8csp_bgje_test_doing_actions'] = array( 'init' );
		$during                                   = \a8csp_bgje( 'during-init' );
		self::assertInstanceOf( Engine::class, $during );

		$GLOBALS['a8csp_bgje_test_doing_actions'] = array();
		self::assertInstanceOf( Engine::class, \a8csp_bgje( 'after-init' ) );
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
		$left      = $this->rig->operations( 'owner-left' );
		$right     = $this->rig->operations( 'owner-right' );
		$left_job  = new RecordingJob( 'sync' );
		$right_job = new RecordingJob( 'sync' );
		$left->register( $left_job->definition() );
		$right->register( $right_job->definition() );

		self::assertInstanceOf( Success::class, $left->dispatch( 'sync', array( 'owner' => 'left' ) ) );
		self::assertInstanceOf( Success::class, $right->dispatch( 'sync', array( 'owner' => 'right' ) ) );
		$this->rig->run_due();
		$this->rig->run_due();

		self::assertSame( array( array( 'owner' => 'left' ) ), $left_job->calls );
		self::assertSame( array( array( 'owner' => 'right' ) ), $right_job->calls );
		self::assertInstanceOf( Success::class, $left->last_completed_run( 'sync' ) );
		self::assertInstanceOf( Success::class, $right->last_completed_run( 'sync' ) );
	}

	/**
	 * Every invalid or reserved owner is rejected by the first handle operation.
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
		$result = \a8csp_bgje( $owner )->jobs()->dispatch( 'sync' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'invalid_argument', $result->get_error_code() );
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
		$client = $this->rig->operations( 'consumer-plugin' );

		self::assert_api_failure( $client->dispatch( 'missing-job' ), ErrorCode::UnknownJob, array( 'identity' ) );
		self::assert_api_failure( $client->dispatch( 'missing-chunked-job' ), ErrorCode::UnknownJob, array( 'identity' ) );
		self::assert_api_failure( $client->dispatch_now( 'missing-schedule' ), ErrorCode::UnknownSchedule, array( 'owner', 'schedule' ) );
	}

	/**
	 * Last-completed lookup follows terminal order and ignores a later failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_last_completed_run_retains_the_latest_successful_terminal(): void {
		$client = $this->rig->operations( 'consumer-plugin' );
		$job    = new RecordingJob( 'sync' );
		$client->register( $job->definition() );
		$first = $this->enqueue_and_run( $client, array( 'sequence' => 1 ) );
		$last  = $this->enqueue_and_run( $client, array( 'sequence' => 2 ) );
		self::assertNotSame( $first, $last );

		$job->throwable = new NonRetryableException( 'Terminal failure.' );
		$this->enqueue_and_run( $client, array( 'sequence' => 3 ) );
		$result = $client->last_completed_run( 'sync' );

		self::assertInstanceOf( Success::class, $result );
		self::assertInstanceOf( Run::class, $result->value );
		self::assertSame( 'consumer-plugin:sync', $result->value->identity );
		self::assertSame( $last, (string) $result->value->id );
		self::assertSame( RunStatus::Completed, $result->value->status );
	}

	/**
	 * Completed-hook clients observe the previous completion before the current pointer advances.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_last_completed_run_inside_a_completed_hook_returns_the_previous_completion(): void {
		$client = $this->rig->operations( 'consumer-plugin' );
		$client->register( ( new RecordingJob( 'sync' ) )->definition() );
		$observed  = array();
		$callbacks = $GLOBALS['a8csp_bgje_test_action_callbacks'] ?? null;
		self::assertIsArray( $callbacks );
		$callbacks['a8csp_bgje/completed/consumer-plugin:sync'] = static function ( RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ) use ( $client, &$observed ): void {
			$result = $client->last_completed_run( 'sync' );
			self::assertInstanceOf( Success::class, $result );
			if ( null !== $result->value ) {
				self::assertInstanceOf( Run::class, $result->value );
			}
			$observed[] = array( null === $previous_completed_run_id ? null : (string) $previous_completed_run_id, null === $result->value ? null : (string) $result->value->id );
		};

		$GLOBALS['a8csp_bgje_test_action_callbacks'] = $callbacks;

		$first  = $this->enqueue_and_run( $client, array( 'sequence' => 1 ) );
		$second = $this->enqueue_and_run( $client, array( 'sequence' => 2 ) );

		self::assertSame( array( array( null, null ), array( $first, $first ) ), $observed );
		$result = $client->last_completed_run( 'sync' );
		self::assertInstanceOf( Success::class, $result );
		self::assertInstanceOf( Run::class, $result->value );
		self::assertSame( $second, (string) $result->value->id );
	}

	/**
	 * A malformed retained history identifier cannot suppress the next completed hook.
	 *
	 * @load-bearing durability
	 * @pin-rationale The malformed predecessor is injected below the typed store boundary; the public hook payload proves hydration excludes it before terminal state is frozen.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_hook_ignores_a_malformed_persisted_history_run_id(): void {
		$identity = 'consumer-plugin:sync';
		$client   = $this->rig->operations( 'consumer-plugin' );
		$client->register( ( new RecordingJob( 'sync' ) )->definition() );

		$completed = array();
		$callbacks = $GLOBALS['a8csp_bgje_test_action_callbacks'] ?? null;
		self::assertIsArray( $callbacks );
		$callbacks[ 'a8csp_bgje/completed/' . $identity ] = static function ( RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ) use ( &$completed ): void {
			$completed[] = array(
				'run_id'                    => (string) $run_id,
				'start_args'                => $start_args,
				'previous_completed_run_id' => null === $previous_completed_run_id ? null : (string) $previous_completed_run_id,
			);
		};
		$GLOBALS['a8csp_bgje_test_action_callbacks']      = $callbacks;

		$args = array( 'sequence' => 'after-corruption' );
		++$this->rig->clock()->timestamp;
		$dispatched = $client->dispatch( 'sync', $args );
		self::assertInstanceOf( Success::class, $dispatched );
		self::assertInstanceOf( Run::class, $dispatched->value );
		$run_id = (string) $dispatched->value->id;
		$raw    = \maybe_serialize(
			array(
				'started'  => array( $run_id ),
				'terminal' => array(
					array(
						'run_id' => 'malformed-run-id',
						'status' => 'completed',
					),
				),
				'by_hash'  => array(),
			)
		);
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( RunHistory::OPTION_PREFIX . $identity, $raw );

		$this->rig->run_due();

		self::assertSame(
			array(
				array(
					'run_id'                    => $run_id,
					'start_args'                => $args,
					'previous_completed_run_id' => null,
				),
			),
			$completed
		);
		$latest = $client->last_completed_run( 'sync' );
		self::assertInstanceOf( Success::class, $latest );
		self::assertInstanceOf( Run::class, $latest->value );
		self::assertSame( $run_id, (string) $latest->value->id );
		$history = $this->rig->inspection()->runs( Identity::tryFrom( $identity ) ?? throw new \LogicException( 'The test identity must be canonical.' ) )['history'];
		self::assertIsArray( $history );
		self::assertSame( array( $run_id ), \array_column( $history, 'run_id' ) );
		self::assertNotContains( 'malformed-run-id', \array_column( $history, 'run_id' ) );
	}

	/**
	 * Completed-hook replay retains the predecessor frozen before a later same-identity completion.
	 *
	 * @load-bearing durability
	 * @pin-rationale Five scripted marker-CAS losses leave a production terminal row for real maintenance replay; no public result exposes the hook marker or frozen terminal snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_hook_replay_uses_the_frozen_previous_completion(): void {
		$identity = 'consumer-plugin:sync';
		$client   = $this->rig->operations( 'consumer-plugin' );
		$job      = new RecordingJob( 'sync' );
		$client->register( $job->definition() );
		$seed_run_id = $this->enqueue_and_run( $client, array( 'sequence' => 'seed' ) );

		++$this->rig->clock()->timestamp;
		$target = $client->dispatch( 'sync', array( 'sequence' => 'target' ) );
		self::assertInstanceOf( Success::class, $target );
		self::assertInstanceOf( Run::class, $target->value );
		$target_run_id      = (string) $target->value->id;
		$intervening_run_id = null;
		$target_hook_runs   = 0;
		$completed_calls    = array();
		$callbacks          = $GLOBALS['a8csp_bgje_test_action_callbacks'] ?? null;
		self::assertIsArray( $callbacks );
		$callbacks[ 'a8csp_bgje/completed/' . $identity ] = function ( RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ) use ( $client, $target_run_id, &$intervening_run_id, &$target_hook_runs, &$completed_calls ): void {
			$completed_calls[] = array(
				'run_id'                    => (string) $run_id,
				'previous_completed_run_id' => null === $previous_completed_run_id ? null : (string) $previous_completed_run_id,
			);
			if ( $target_run_id !== (string) $run_id || 1 !== ++$target_hook_runs ) {
				return;
			}

			++$this->rig->clock()->timestamp;
			$intervening = $client->dispatch( 'sync', array( 'sequence' => 'intervening' ) );
			self::assertInstanceOf( Success::class, $intervening );
			self::assertInstanceOf( Run::class, $intervening->value );
			$intervening_run_id = (string) $intervening->value->id;
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
		$GLOBALS['a8csp_bgje_test_action_callbacks']      = $callbacks;

		$this->rig->run_due();

		$run_store = new RunStore( $identity, $this->rig->clock(), new OptionRows( $this->rig->wpdb() ) );
		$terminal  = $run_store->get( $target_run_id );
		self::assertNotNull( $terminal );
		self::assertSame( $seed_run_id, $terminal->previous_completed_run_id );
		self::assertSame( array(), $terminal->effects );
		$latest_before_replay = $client->last_completed_run( 'sync' );
		self::assertInstanceOf( Success::class, $latest_before_replay );
		self::assertInstanceOf( Run::class, $latest_before_replay->value );
		self::assertSame( $intervening_run_id, (string) $latest_before_replay->value->id );
		$this->rig->clock()->timestamp = $terminal->heartbeat_at + 3_601;

		$this->rig->run_maintenance();

		self::assertIsString( $intervening_run_id );
		$target_hooks = \array_values( \array_filter( $completed_calls, static fn ( array $call ): bool => $target_run_id === $call['run_id'] ) );
		self::assertCount( 2, $target_hooks );
		self::assertSame( array( $seed_run_id, $seed_run_id ), \array_column( $target_hooks, 'previous_completed_run_id' ) );
		$intervening_hooks = \array_values( \array_filter( $completed_calls, static fn ( array $call ): bool => $intervening_run_id === $call['run_id'] ) );
		self::assertCount( 1, $intervening_hooks );
		self::assertSame( $seed_run_id, $intervening_hooks[0]['previous_completed_run_id'] );
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
		$client = $this->rig->operations( 'consumer-plugin' );
		$client->register( ( new RecordingJob( 'sync' ) )->definition() );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'client-controlled database detail';
			}
		);

		$result = $client->retry_failed( 'sync', '00000000001700000000-0000000000000000042' );

		self::assert_api_failure( $result, ErrorCode::StorageFailed, array( 'option_name' ) );
		if ( ! $result instanceof Failure || ! $result->error instanceof BoundaryError ) {
			throw new \LogicException( 'The storage failure did not retain its public API error.' );
		}
		self::assertSame( 'Authoritative option-row read failed; repair WordPress option reads and retry.', $result->error->message );
		self::assertSame( array( 'option_name' => 'a8csp_bgje_failed_runs_consumer-plugin:sync' ), $result->error->context );
		self::assertStringNotContainsString( 'client-controlled', $result->error->message );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Enqueues and delivers one job through the public graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OwnerOperations      $client   Owner-bound public facade.
	 * @param   array<string, mixed> $args     Job arguments.
	 *
	 * @return  string
	 */
	private function enqueue_and_run( OwnerOperations $client, array $args ): string {
		++$this->rig->clock()->timestamp;
		$result = $client->dispatch( 'sync', $args );
		self::assertInstanceOf( Success::class, $result );
		self::assertInstanceOf( Run::class, $result->value );
		$this->rig->run_due();

		return (string) $result->value->id;
	}

	/**
	 * Asserts one stable public failure contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   AbstractResult $result       Public API result.
	 * @param   ErrorCode      $code         Expected stable error code.
	 * @param   array          $context_keys Expected public context keys.
	 *
	 * @return  void
	 *
	 * @phpstan-param AbstractResult<mixed, \A8C\SpecialProjects\BackgroundJobsEngine\Boundary\ErrorInterface> $result
	 * @phpstan-param list<string> $context_keys
	 */
	private static function assert_api_failure( AbstractResult $result, ErrorCode $code, array $context_keys ): void {
		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( BoundaryError::class, $result->error );
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
			'reserved owner'  => array( 'owner' => 'a8csp-bgje' ),
			'reserved prefix' => array( 'owner' => 'a8csp-bgje-addon' ),
		);
	}

	// endregion.
}
