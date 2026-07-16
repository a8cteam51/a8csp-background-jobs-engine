<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\NonRetryableTaskException;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\EngineFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the owner-bound front door and its lifecycle contract.
 *
 */
#[CoversFunction( 'a8csp_bgte' )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ApiTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads the WordPress lifecycle and persistence seams with the public front door.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-cron-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-time-constant-stubs.php';
		require_once __DIR__ . '/Engine/Backends/wp-json-encode-stub.php';
		require_once \dirname( __DIR__, 2 ) . '/functions.php';
	}

	/**
	 * Resets the lifecycle and storage ledgers, then simulates the eager plugin boot.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_did_actions']          = array();
		$GLOBALS['a8csp_bgte_test_doing_actions']        = array();
		$GLOBALS['a8csp_bgte_test_hooks']                = array();
		$GLOBALS['a8csp_bgte_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_filter_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_fired_actions']        = array();
		$GLOBALS['a8csp_bgte_test_action_callbacks']     = array();
		$GLOBALS['a8csp_bgte_test_action_throwables']    = array();
		$GLOBALS['a8csp_bgte_test_filter_values']        = array();
		$GLOBALS['a8csp_bgte_test_options']              = array();
		$GLOBALS['a8csp_bgte_test_option_calls']         = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']      = array();
		$GLOBALS['a8csp_bgte_test_cron_array']           = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']           = array();
		$GLOBALS['a8csp_bgte_test_cron_results']         = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence']  = 0;
		$GLOBALS['a8csp_bgte_test_blog_id']              = 1;
		$GLOBALS['wpdb']                                 = new WpdbLockSpy();

		\a8csp_bgte_plugin();
	}

	// endregion.

	// region TESTS.

	/**
	 * Access before the earliest safe hook fails with the exact availability contract.
	 *
	 * @return  void
	 */
	public function test_access_before_init_throws_with_the_earliest_safe_hook(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs( 'The background tasks consumer is available from the init hook; call a8csp_bgte() from an init callback or later.' );

		\a8csp_bgte( 'consumer-plugin' );
	}

	/**
	 * Resolution from inside init returns a usable owner-bound facade at any priority.
	 *
	 * @return  void
	 */
	public function test_access_during_init_returns_a_working_consumer_at_any_priority(): void {
		$GLOBALS['a8csp_bgte_test_did_actions']   = array( 'init' => 1 );
		$GLOBALS['a8csp_bgte_test_doing_actions'] = array( 'init' );

		$consumer = \a8csp_bgte( 'consumer-plugin' );
		$task     = new RecordingTask( 'sync' );
		$consumer->tasks()->register( $task );

		self::assertInstanceOf( Consumer::class, $consumer );
		self::assertInstanceOf( EngineFacade::class, Component::get_engine() );
	}

	/**
	 * Resolution remains available after init completes.
	 *
	 * @return  void
	 */
	public function test_access_after_init_returns_a_consumer(): void {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'init' => 1 );

		self::assertInstanceOf( Consumer::class, \a8csp_bgte( 'consumer-plugin' ) );
	}

	/**
	 * Equal local names under different owners retain distinct runtime and storage identities.
	 *
	 * @return  void
	 */
	public function test_two_owners_can_register_enqueue_and_run_the_same_local_task_name(): void {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'init' => 1 );

		$left       = \a8csp_bgte( 'owner-left' );
		$right      = \a8csp_bgte( 'owner-right' );
		$left_task  = new RecordingTask( 'sync' );
		$right_task = new RecordingTask( 'sync' );
		$left->tasks()->register( $left_task );
		$right->tasks()->register( $right_task );

		$left_result  = $left->tasks()->enqueue( 'sync', array( 'owner' => 'left' ) );
		$right_result = $right->tasks()->enqueue( 'sync', array( 'owner' => 'right' ) );
		if ( $left_result->is_failure() ) {
			self::fail( 'The left owner enqueue returned an unexpected failure.' );
		}
		if ( $right_result->is_failure() ) {
			self::fail( 'The right owner enqueue returned an unexpected failure.' );
		}

		/** @var array<string, mixed> $options */
		$options = $GLOBALS['a8csp_bgte_test_options'];
		$wpdb    = $GLOBALS['wpdb'];
		self::assertInstanceOf( WpdbLockSpy::class, $wpdb );
		$identities       = array( 'owner-left:sync', 'owner-right:sync' );
		$option_names     = \array_keys( $options );
		$raw_option_names = \array_keys( $wpdb->rows );
		foreach ( $identities as $identity ) {
			self::assertTrue( self::has_option_with_prefix( $raw_option_names, 'a8csp_bgte_lock_' . $identity . '_' ) );
			self::assertTrue( self::has_option_with_prefix( $option_names, 'a8csp_bgte_run_' . $identity . '_' ) );
			self::assertContains( 'a8csp_bgte_latest_' . $identity, $raw_option_names );
		}

		/** @var list<array{hook_name: string, callback: callable, priority: int, accepted_args: int}> $action_registrations */
		$action_registrations = $GLOBALS['a8csp_bgte_test_action_registrations'];
		$run_callback         = null;
		foreach ( $action_registrations as $registration ) {
			if ( 'a8csp_background_tasks/run' === $registration['hook_name'] ) {
				$run_callback = $registration['callback'];
				break;
			}
		}
		self::assertIsCallable( $run_callback );

		/** @var array<int, array<string, array<int, array{schedule: string|false, args: list<mixed>}>>> $cron */
		$cron       = $GLOBALS['a8csp_bgte_test_cron_array'];
		$run_events = array();
		foreach ( $cron as $hooks ) {
			foreach ( $hooks['a8csp_background_tasks/run'] ?? array() as $event ) {
				$run_events[] = $event['args'];
			}
		}
		\usort( $run_events, static fn ( array $left_event, array $right_event ): int => $left_event[0] <=> $right_event[0] );
		self::assertSame( $identities, \array_column( $run_events, 0 ) );
		foreach ( $run_events as $event_args ) {
			$run_callback( ...$event_args );
		}

		self::assertSame( array( array( 'owner' => 'left' ) ), $left_task->calls );
		self::assertSame( array( array( 'owner' => 'right' ) ), $right_task->calls );
		/** @var list<array{hook_name: string, args: list<mixed>}> $fired_actions */
		$fired_actions = $GLOBALS['a8csp_bgte_test_fired_actions'];
		$hook_names    = \array_column( $fired_actions, 'hook_name' );
		foreach ( $identities as $identity ) {
			self::assertContains( 'a8csp_background_tasks/started/' . $identity, $hook_names );
			self::assertContains( 'a8csp_background_tasks/completed/' . $identity, $hook_names );
		}
	}

	/**
	 * Invalid and reserved owners fail at the front door.
	 *
	 * @param   string      $owner   Invalid consumer owner.
	 * @param   string|null $message Exact rejection message when the boundary is part of the contract.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_owners' )]
	public function test_front_door_rejects_invalid_or_reserved_owners( string $owner, ?string $message = null ): void {
		$GLOBALS['a8csp_bgte_test_did_actions']   = array( 'init' => 1 );
		$GLOBALS['a8csp_bgte_test_doing_actions'] = array( 'init' );

		$this->expectException( \InvalidArgumentException::class );
		if ( null !== $message ) {
			$this->expectExceptionMessageIs( $message );
		}
		\a8csp_bgte( $owner );
	}

	/**
	 * Supplies the required owner rejection table.
	 *
	 * @return  array<string, array{owner: string, message?: string}>
	 */
	public static function invalid_owners(): array {
		return array(
			'empty'           => array( 'owner' => '' ),
			'uppercase'       => array( 'owner' => 'Consumer' ),
			'colon'           => array( 'owner' => 'consumer:plugin' ),
			'33 bytes'        => array(
				'owner'   => \str_repeat( 'o', 33 ),
				'message' => 'Background-work owner is invalid; pass 1 to 32 bytes matching [a-z0-9][a-z0-9-]*.',
			),
			'reserved owner'  => array( 'owner' => 'a8csp-bgte' ),
			'reserved prefix' => array( 'owner' => 'a8csp-bgte-addon' ),
		);
	}

	/** Task enqueue maps an unknown registration to the public admission error. */
	public function test_task_enqueue_maps_its_internal_failure_at_the_facade_boundary(): void {
		$result = $this->consumer()->tasks()->enqueue( 'missing-task' );

		self::assert_api_failure( $result, ApiErrorCode::UnknownWork, array( 'name' ) );
	}

	/** Batch start maps an unknown registration to the public admission error. */
	public function test_batch_start_maps_its_internal_failure_at_the_facade_boundary(): void {
		$result = $this->consumer()->batches()->start( 'missing-batch' );

		self::assert_api_failure( $result, ApiErrorCode::UnknownWork, array( 'name' ) );
	}

	/** Schedule sync maps an unsupported backend capability to the public admission error. */
	public function test_schedule_sync_maps_its_internal_failure_at_the_facade_boundary(): void {
		$result = $this->consumer()->schedules()->sync( array( new Schedule( 'calendar', Recurrence::cron( '0 0 * * *' ), 'task' ) ) );

		self::assert_api_failure( $result, ApiErrorCode::UnsupportedOperation, array( 'schedule' ) );
	}

	/** Run-now maps an absent declaration to the public admission error. */
	public function test_schedule_run_now_maps_its_internal_failure_at_the_facade_boundary(): void {
		$result = $this->consumer()->schedules()->run_now( 'missing-schedule' );

		self::assert_api_failure( $result, ApiErrorCode::UnknownSchedule, array( 'owner', 'schedule' ) );
	}

	/** The latest completed run follows terminal recording order. */
	public function test_last_completed_run_returns_the_most_recent_completion(): void {
		$consumer = $this->consumer();
		$task     = new RecordingTask( 'sync' );
		$consumer->tasks()->register( $task );

		self::enqueue_and_run_task( $consumer, 'consumer-plugin:sync', array( 'sequence' => 1 ) );
		$latest = self::enqueue_and_run_task( $consumer, 'consumer-plugin:sync', array( 'sequence' => 2 ) );

		$result = $consumer->runs()->last_completed_run( 'sync' );
		if ( $result->is_failure() ) {
			self::fail( 'The retained completed-run lookup returned an unexpected failure.' );
		}

		self::assertSame( $latest, $result->value );
	}

	/** Completed notifications observe the previously recorded completion. */
	public function test_last_completed_run_inside_a_completed_hook_returns_the_previous_completion(): void {
		$consumer = $this->consumer();
		$consumer->tasks()->register( new RecordingTask( 'sync' ) );
		$observed = array();

		$GLOBALS['a8csp_bgte_test_action_callbacks'] = array(
			'a8csp_background_tasks/completed/consumer-plugin:sync' => static function () use ( $consumer, &$observed ): void {
				$result = $consumer->runs()->last_completed_run( 'sync' );
				if ( $result->is_failure() ) {
					self::fail( 'The completed-hook inspection returned an unexpected failure.' );
				}

				$observed[] = $result->value;
			},
		);

		$first  = self::enqueue_and_run_task( $consumer, 'consumer-plugin:sync', array( 'sequence' => 1 ) );
		$second = self::enqueue_and_run_task( $consumer, 'consumer-plugin:sync', array( 'sequence' => 2 ) );

		self::assertSame( array( null, $first ), $observed );
		$result = $consumer->runs()->last_completed_run( 'sync' );
		if ( $result->is_failure() ) {
			self::fail( 'The post-completion inspection returned an unexpected failure.' );
		}
		self::assertSame( $second, $result->value );
	}

	/** A later failed run does not displace the last completion. */
	public function test_last_completed_run_ignores_a_later_failure(): void {
		$consumer = $this->consumer();
		$task     = new RecordingTask( 'sync' );
		$consumer->tasks()->register( $task );

		$completed       = self::enqueue_and_run_task( $consumer, 'consumer-plugin:sync', array( 'outcome' => 'completed' ) );
		$task->throwable = new NonRetryableTaskException( 'Scripted terminal failure.' );
		self::enqueue_and_run_task( $consumer, 'consumer-plugin:sync', array( 'outcome' => 'failed' ) );

		$result = $consumer->runs()->last_completed_run( 'sync' );
		if ( $result->is_failure() ) {
			self::fail( 'The retained completed-run lookup returned an unexpected failure.' );
		}

		self::assertSame( $completed, $result->value );
	}

	/** An identity without a retained completion returns a successful absence. */
	public function test_last_completed_run_returns_null_when_none_is_retained(): void {
		$result = $this->consumer()->runs()->last_completed_run( 'sync' );
		if ( $result->is_failure() ) {
			self::fail( 'An absent completed run must remain a successful lookup.' );
		}

		self::assertNull( $result->value );
	}

	/** An authoritative history read failure maps to the public storage failure. */
	public function test_last_completed_run_maps_an_authoritative_read_failure(): void {
		$consumer = $this->consumer();
		$wpdb     = $GLOBALS['wpdb'];
		self::assertInstanceOf( WpdbLockSpy::class, $wpdb );
		$wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'consumer-controlled database detail';
			}
		);

		$result = $consumer->runs()->last_completed_run( 'sync' );

		self::assert_api_failure( $result, ApiErrorCode::StorageFailure, array( 'option_name' ) );
		if ( ! $result->is_failure() ) {
			self::fail( 'The storage read failure must remain result data.' );
		}
		self::assertSame( 'Authoritative option-row read failed; repair WordPress option reads and retry.', $result->error->message );
		self::assertSame( array( 'option_name' => 'a8csp_bgte_history_consumer-plugin:sync' ), $result->error->context );
	}

	/** Completed runs under another owner are invisible to the bound facade. */
	public function test_last_completed_run_is_isolated_by_owner(): void {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'init' => 1 );

		$owner_a = \a8csp_bgte( 'owner-a' );
		$owner_b = \a8csp_bgte( 'owner-b' );
		$owner_b->tasks()->register( new RecordingTask( 'sync' ) );
		$owner_b_run = self::enqueue_and_run_task( $owner_b, 'owner-b:sync' );

		$owner_a_result = $owner_a->runs()->last_completed_run( 'sync' );
		$owner_b_result = $owner_b->runs()->last_completed_run( 'sync' );
		if ( $owner_a_result->is_failure() || $owner_b_result->is_failure() ) {
			self::fail( 'Owner-isolated completed-run lookups returned an unexpected failure.' );
		}

		self::assertNull( $owner_a_result->value );
		self::assertSame( $owner_b_run, $owner_b_result->value );
	}

	/** A completion evicted from the configured history window is reported as absent. */
	public function test_last_completed_run_reports_an_evicted_completion_as_absent(): void {
		$GLOBALS['a8csp_bgte_test_filter_values'] = array( 'a8csp_background_tasks/history_size' => 2 );
		$consumer                                 = $this->consumer();
		$consumer->tasks()->register( new RecordingTask( 'sync' ) );

		self::enqueue_and_run_task( $consumer, 'consumer-plugin:sync', array( 'outcome' => 'completed' ) );
		for ( $index = 1; $index <= 2; ++$index ) {
			$run_id = self::enqueue_task( $consumer, array( 'cancelled' => $index ) );
			$cancel = $consumer->runs()->cancel( 'sync', $run_id );
			if ( $cancel->is_failure() ) {
				self::fail( 'The retention fixture could not cancel its pending run.' );
			}
		}

		$result = $consumer->runs()->last_completed_run( 'sync' );
		if ( $result->is_failure() ) {
			self::fail( 'The retained completed-run lookup returned an unexpected failure.' );
		}

		self::assertNull( $result->value );
	}

	/** Failed-run retry maps an absent retained run to the public admission error. */
	public function test_run_retry_maps_its_internal_failure_at_the_facade_boundary(): void {
		$consumer = $this->consumer();
		$consumer->tasks()->register( new RecordingTask( 'sync' ) );

		$result = $consumer->runs()->retry_failed( 'sync', 'missing-run' );

		self::assert_api_failure( $result, ApiErrorCode::RunNotRetained, array( 'name', 'run_id' ) );
	}

	/** Failed-run retry redacts raw storage detail while retaining safe structured context. */
	public function test_run_retry_maps_a_storage_read_failure_without_exposing_database_text(): void {
		$consumer = $this->consumer();
		$consumer->tasks()->register( new RecordingTask( 'sync' ) );
		$wpdb = $GLOBALS['wpdb'];
		self::assertInstanceOf( WpdbLockSpy::class, $wpdb );
		$wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'consumer-controlled database detail';
			}
		);

		$result = $consumer->runs()->retry_failed( 'sync', 'missing-run' );

		self::assert_api_failure( $result, ApiErrorCode::StorageFailure, array( 'option_name' ) );
		if ( ! $result->is_failure() ) {
			self::fail( 'The storage read failure must remain result data.' );
		}
		self::assertSame( 'Authoritative option-row read failed; repair WordPress option reads and retry.', $result->error->message );
		self::assertSame( array( 'option_name' => 'a8csp_bgte_failed_consumer-plugin:sync' ), $result->error->context );
	}

	/** Run cancellation maps an absent retained run to the public admission error. */
	public function test_run_cancel_maps_its_internal_failure_at_the_facade_boundary(): void {
		$consumer = $this->consumer();
		$consumer->tasks()->register( new RecordingTask( 'sync' ) );

		$result = $consumer->runs()->cancel( 'sync', 'missing-run' );

		self::assert_api_failure( $result, ApiErrorCode::RunNotRetained, array( 'name', 'run_id' ) );
	}

	/**
	 * The public surface contains only the owner-bound consumer front door.
	 *
	 * @return  void
	 */
	public function test_old_engine_and_per_concept_functions_are_absent(): void {
		self::assertFalse( \function_exists( 'a8csp_bgte_engine' ) );
		self::assertFalse( \function_exists( 'a8csp_bgte_enqueue_task' ) );
		self::assertFalse( \function_exists( 'a8csp_bgte_start_batch' ) );
		self::assertFalse( \function_exists( 'a8csp_bgte_sync_schedules' ) );
		self::assertFalse( \function_exists( 'a8csp_bgte_run_schedule_now' ) );
		self::assertFalse( \function_exists( 'a8csp_bgte_retry_failed_run' ) );
		self::assertFalse( \function_exists( 'a8csp_bgte_cancel_run' ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns whether any option name begins with the complete expected prefix.
	 *
	 * @phpstan-param list<string> $option_names
	 *
	 * @param   array  $option_names Option names to inspect.
	 * @param   string $prefix       Required option-name prefix.
	 *
	 * @return  bool
	 */
	private static function has_option_with_prefix( array $option_names, string $prefix ): bool {
		foreach ( $option_names as $option_name ) {
			if ( \str_starts_with( $option_name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns a consumer after the public lifecycle boundary is available.
	 *
	 * @return  Consumer
	 */
	private function consumer(): Consumer {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'init' => 1 );

		return \a8csp_bgte( 'consumer-plugin' );
	}

	/**
	 * Enqueues and delivers one task through the public consumer graph.
	 *
	 * @param   Consumer                $consumer Owner-bound public facade.
	 * @param   string                  $identity Complete task identity.
	 * @param   array<array-key, mixed> $args     Task arguments.
	 *
	 * @return  string
	 */
	private static function enqueue_and_run_task( Consumer $consumer, string $identity, array $args = array() ): string {
		$run_id = self::enqueue_task( $consumer, $args );

		/** @var list<array{hook_name: string, callback: callable, priority: int, accepted_args: int}> $registrations */
		$registrations = $GLOBALS['a8csp_bgte_test_action_registrations'];
		foreach ( $registrations as $registration ) {
			if ( 'a8csp_background_tasks/run' !== $registration['hook_name'] ) {
				continue;
			}

			$registration['callback']( $identity, $run_id, 1 );

			return $run_id;
		}

		self::fail( 'The task-delivery callback was not registered.' );
	}

	/**
	 * Enqueues one task and returns its narrowed run identifier.
	 *
	 * @param   Consumer                $consumer Owner-bound public facade.
	 * @param   array<array-key, mixed> $args     Task arguments.
	 *
	 * @return  string
	 */
	private static function enqueue_task( Consumer $consumer, array $args = array() ): string {
		$result = $consumer->tasks()->enqueue( 'sync', $args );
		if ( $result->is_failure() ) {
			self::fail( 'The task fixture returned an unexpected enqueue failure.' );
		}

		return $result->value;
	}

	/**
	 * Asserts one public failure code and its complete structured context shape.
	 *
	 * @phpstan-param AbstractResult<mixed, ApiError> $result
	 * @phpstan-param list<string>                    $context_keys
	 *
	 * @param   AbstractResult $result       Public admission result.
	 * @param   ApiErrorCode   $code         Expected stable code.
	 * @param   array          $context_keys Expected context keys in order.
	 *
	 * @return  void
	 */
	private static function assert_api_failure( AbstractResult $result, ApiErrorCode $code, array $context_keys ): void {
		self::assertInstanceOf( Failure::class, $result );
		if ( ! $result->is_failure() ) {
			self::fail( 'The public command must return a failed result.' );
		}

		self::assertInstanceOf( ApiError::class, $result->error );
		self::assertSame( $code, $result->error->code );
		self::assertSame( $context_keys, \array_keys( $result->error->context ) );
	}

	// endregion.
}
