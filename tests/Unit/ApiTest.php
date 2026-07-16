<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\NonRetryableTaskException;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
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
		$GLOBALS['a8csp_bgte_test_did_actions'] = array();

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs( 'The background tasks consumer is available from the init hook; call a8csp_bgte() from an init callback or later.' );

		\a8csp_bgte( 'consumer-plugin' );
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
		$GLOBALS['a8csp_bgte_test_doing_actions'] = array( 'init' );
		$during                                   = \a8csp_bgte( 'during-init' );
		$during->tasks()->register( new RecordingTask( 'sync' ) );
		self::assertInstanceOf( Success::class, $during->tasks()->enqueue( 'sync' ) );

		$GLOBALS['a8csp_bgte_test_doing_actions'] = array();
		self::assertInstanceOf( Consumer::class, \a8csp_bgte( 'after-init' ) );
	}

	/**
	 * Equal local names remain isolated by owner across admission, delivery, and completion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_two_owners_run_the_same_local_task_name_independently(): void {
		$left       = $this->rig->consumer( 'owner-left' );
		$right      = $this->rig->consumer( 'owner-right' );
		$left_task  = new RecordingTask( 'sync' );
		$right_task = new RecordingTask( 'sync' );
		$left->tasks()->register( $left_task );
		$right->tasks()->register( $right_task );

		self::assertInstanceOf( Success::class, $left->tasks()->enqueue( 'sync', array( 'owner' => 'left' ) ) );
		self::assertInstanceOf( Success::class, $right->tasks()->enqueue( 'sync', array( 'owner' => 'right' ) ) );
		$this->rig->run_due();
		$this->rig->run_due();

		self::assertSame( array( array( 'owner' => 'left' ) ), $left_task->calls );
		self::assertSame( array( array( 'owner' => 'right' ) ), $right_task->calls );
		self::assertInstanceOf( Success::class, $left->runs()->last_completed_run( 'sync' ) );
		self::assertInstanceOf( Success::class, $right->runs()->last_completed_run( 'sync' ) );
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

		\a8csp_bgte( $owner );
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
		$consumer = $this->rig->consumer( 'consumer-plugin' );

		self::assert_api_failure( $consumer->tasks()->enqueue( 'missing-task' ), ApiErrorCode::UnknownWork, array( 'name' ) );
		self::assert_api_failure( $consumer->batches()->start( 'missing-batch' ), ApiErrorCode::UnknownWork, array( 'name' ) );
		self::assert_api_failure( $consumer->schedules()->sync( array( new Schedule( 'calendar', Recurrence::cron( '0 0 * * *' ), 'task' ) ) ), ApiErrorCode::UnsupportedOperation, array( 'schedule' ) );
		self::assert_api_failure( $consumer->schedules()->run_now( 'missing-schedule' ), ApiErrorCode::UnknownSchedule, array( 'owner', 'schedule' ) );
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
		$consumer = $this->rig->consumer( 'consumer-plugin' );
		$task     = new RecordingTask( 'sync' );
		$consumer->tasks()->register( $task );
		$first = $this->enqueue_and_run( $consumer, array( 'sequence' => 1 ) );
		$last  = $this->enqueue_and_run( $consumer, array( 'sequence' => 2 ) );
		self::assertNotSame( $first, $last );

		$task->throwable = new NonRetryableTaskException( 'Terminal failure.' );
		$this->enqueue_and_run( $consumer, array( 'sequence' => 3 ) );
		$result = $consumer->runs()->last_completed_run( 'sync' );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $last, $result->value );
	}

	/**
	 * Completed-hook consumers observe the previous completion before the current pointer advances.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_last_completed_run_inside_a_completed_hook_returns_the_previous_completion(): void {
		$consumer = $this->rig->consumer( 'consumer-plugin' );
		$consumer->tasks()->register( new RecordingTask( 'sync' ) );
		$observed  = array();
		$callbacks = $GLOBALS['a8csp_bgte_test_action_callbacks'] ?? null;
		self::assertIsArray( $callbacks );
		$callbacks['a8csp_background_tasks/completed/consumer-plugin:sync'] = static function () use ( $consumer, &$observed ): void {
			$result = $consumer->runs()->last_completed_run( 'sync' );
			self::assertInstanceOf( Success::class, $result );
			$observed[] = $result->value;
		};

		$GLOBALS['a8csp_bgte_test_action_callbacks'] = $callbacks;

		$first  = $this->enqueue_and_run( $consumer, array( 'sequence' => 1 ) );
		$second = $this->enqueue_and_run( $consumer, array( 'sequence' => 2 ) );

		self::assertSame( array( null, $first ), $observed );
		$result = $consumer->runs()->last_completed_run( 'sync' );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $second, $result->value );
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
		$consumer = $this->rig->consumer( 'consumer-plugin' );
		$consumer->tasks()->register( new RecordingTask( 'sync' ) );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'consumer-controlled database detail';
			}
		);

		$result = $consumer->runs()->retry_failed( 'sync', 'missing-run' );

		self::assert_api_failure( $result, ApiErrorCode::StorageFailure, array( 'option_name' ) );
		if ( ! $result instanceof Failure || ! $result->error instanceof ApiError ) {
			throw new \LogicException( 'The storage failure did not retain its public API error.' );
		}
		self::assertSame( 'Authoritative option-row read failed; repair WordPress option reads and retry.', $result->error->message );
		self::assertSame( array( 'option_name' => 'a8csp_bgte_failed_consumer-plugin:sync' ), $result->error->context );
		self::assertStringNotContainsString( 'consumer-controlled', $result->error->message );
	}

	/**
	 * The public function surface contains only the owner-bound consumer front door.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_legacy_global_functions_are_absent(): void {
		foreach ( array( 'a8csp_bgte_engine', 'a8csp_bgte_enqueue_task', 'a8csp_bgte_start_batch', 'a8csp_bgte_sync_schedules', 'a8csp_bgte_run_schedule_now', 'a8csp_bgte_retry_failed_run', 'a8csp_bgte_cancel_run' ) as $function ) {
			self::assertFalse( \function_exists( $function ), $function );
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Enqueues and delivers one task through the public graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Consumer             $consumer Owner-bound public facade.
	 * @param   array<string, mixed> $args     Task arguments.
	 *
	 * @return  string
	 */
	private function enqueue_and_run( Consumer $consumer, array $args ): string {
		++$this->rig->clock()->timestamp;
		$result = $consumer->tasks()->enqueue( 'sync', $args );
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
	 * @phpstan-param AbstractResult<mixed, \A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ErrorInterface> $result
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
	 * Supplies every invalid or reserved consumer owner.
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
			'reserved owner'  => array( 'owner' => 'a8csp-bgte' ),
			'reserved prefix' => array( 'owner' => 'a8csp-bgte-addon' ),
		);
	}

	// endregion.
}
