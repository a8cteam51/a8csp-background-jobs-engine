<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Scheduling\Backends;

use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Action Scheduler backend contract without loading WordPress.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ActionSchedulerBackend::class )]
#[UsesClass( BackendInterface::class )]
#[UsesClass( Success::class )]
#[UsesClass( Failure::class )]
#[UsesClass( SchedulingError::class )]
#[UsesClass( SchedulingErrorReason::class )]
final class ActionSchedulerBackendTest extends TestCase {
	private const HOOK = 'a8csp_bgte_test_hook';

	private const READY_FACTS = array(
		'action_scheduler_functions_exist' => true,
		'action_scheduler_init_fired'      => true,
		'wp_init_fired'                    => true,
	);

	/**
	 * Loads guarded Action Scheduler functions before the backend is autoloaded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/as-function-stubs.php';
	}

	/**
	 * Resets every script and call ledger.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_as_calls']    = array();
		$GLOBALS['a8csp_bgte_test_as_results']  = array();
		$GLOBALS['a8csp_bgte_test_did_actions'] = array(
			'init'                  => 1,
			'action_scheduler_init' => 1,
		);
	}

	/**
	 * The default probe requires the procedural table and Action Scheduler's ready signal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_default_readiness_requires_action_scheduler_init(): void {
		$backend = new ActionSchedulerBackend();

		$GLOBALS['a8csp_bgte_test_did_actions'] = array();
		self::assertFalse( $backend->is_ready() );

		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'init' => 1 );
		self::assertFalse( $backend->is_ready() );

		$GLOBALS['a8csp_bgte_test_did_actions']['action_scheduler_init'] = 1;
		self::assertTrue( $backend->is_ready() );
	}

	/**
	 * A boolean readiness probe is the complete injectable readiness contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_boolean_readiness_probe_controls_readiness(): void {
		$backend = new ActionSchedulerBackend( static fn (): bool => false );

		self::assertFalse( $backend->is_ready() );
	}

	/**
	 * Every write fails with corrective guidance before touching an unready backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_every_write_rejects_an_unready_backend(): void {
		$facts = array(
			'action_scheduler_functions_exist' => false,
			'action_scheduler_init_fired'      => false,
			'wp_init_fired'                    => false,
		);

		$backend = $this->backend( $facts );

		$writes = array(
			static fn ( ActionSchedulerBackend $candidate ): AbstractResult => $candidate->schedule_recurring( self::HOOK, 300 ),
			static fn ( ActionSchedulerBackend $candidate ): AbstractResult => $candidate->schedule_single( self::HOOK, 1_700_000_000 ),
			static fn ( ActionSchedulerBackend $candidate ): AbstractResult => $candidate->enqueue_async( self::HOOK ),
			static fn ( ActionSchedulerBackend $candidate ): AbstractResult => $candidate->unschedule( self::HOOK ),
		);

		foreach ( $writes as $write ) {
			$error = $this->assert_failure_reason( $write( $backend ), SchedulingErrorReason::BackendNotReady );

			self::assertStringContainsString( 'load or activate Action Scheduler', $error->message );
			self::assertStringContainsString( 'action_scheduler_init', $error->message );
			self::assertSame( $facts, $error->context );
		}

		self::assertSame( array(), $this->as_calls() );
	}

	/**
	 * Recurring intervals below one fail before a query or schedule call.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedule_recurring_rejects_intervals_below_one_second(): void {
		$backend = $this->backend( self::READY_FACTS );

		foreach ( array( 0, -1 ) as $interval ) {
			$error = $this->assert_failure_reason(
				$backend->schedule_recurring( self::HOOK, $interval ),
				SchedulingErrorReason::InvalidInterval
			);

			self::assertSame( array( 'interval' => $interval ), $error->context );
			self::assertStringContainsString( 'at least one second', $error->message );
		}

		self::assertSame( array(), $this->as_calls() );
	}

	/**
	 * Existing args-aware actions make recurring and single writes successful no-ops.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedule_methods_skip_existing_args_aware_actions(): void {
		$GLOBALS['a8csp_bgte_test_as_results'] = array(
			'as_has_scheduled_action' => array( true, true ),
		);

		$backend = $this->backend( self::READY_FACTS );

		$recurring = $backend->schedule_recurring( self::HOOK, 300, array( 'a' ), 1_700_000_000, 'reports', 247 );
		$single    = $backend->schedule_single( self::HOOK, 1_700_000_100, array( 'b' ), 'imports', 246 );

		self::assertInstanceOf( Success::class, $recurring );
		self::assertInstanceOf( Success::class, $single );
		self::assertSame(
			array(
				array( self::HOOK, array( 'a' ), 'reports' ),
				array( self::HOOK, array( 'b' ), 'imports' ),
			),
			\array_map(
				static fn ( array $call ): array => $call['args'],
				$this->as_calls( 'as_has_scheduled_action' )
			)
		);
		self::assertSame( array(), $this->as_calls( 'as_schedule_recurring_action' ) );
		self::assertSame( array(), $this->as_calls( 'as_schedule_single_action' ) );
	}

	/**
	 * Scheduling writes preserve every positional group, uniqueness, and priority parameter.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduling_calls_pass_groups_and_priorities_in_the_action_scheduler_shape(): void {
		$GLOBALS['a8csp_bgte_test_as_results'] = array(
			'as_has_scheduled_action' => array( false, false ),
		);

		$backend = $this->backend( self::READY_FACTS );

		$recurring = $backend->schedule_recurring( self::HOOK, 300, array( 'a' ), 1_700_000_000, 'reports', 247 );
		$single    = $backend->schedule_single( self::HOOK, 1_700_000_100, array( 'b' ), 'imports', 246 );
		$async     = $backend->enqueue_async( self::HOOK, array( 'c' ), 'exports', false, 245 );

		self::assertInstanceOf( Success::class, $recurring );
		self::assertInstanceOf( Success::class, $single );
		self::assertInstanceOf( Success::class, $async );
		self::assertSame(
			array( 1_700_000_000, 300, self::HOOK, array( 'a' ), 'reports', false, 247 ),
			$this->as_calls( 'as_schedule_recurring_action' )[0]['args']
		);
		self::assertSame(
			array( 1_700_000_100, self::HOOK, array( 'b' ), 'imports', false, 246 ),
			$this->as_calls( 'as_schedule_single_action' )[0]['args']
		);
		self::assertSame(
			array( self::HOOK, array( 'c' ), 'exports', false, 245 ),
			$this->as_calls( 'as_enqueue_async_action' )[0]['args']
		);
	}

	/**
	 * A null first run schedules a recurring action at the current timestamp.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_recurring_schedule_defaults_the_first_run_to_now(): void {
		$before = \time();
		$result = $this->backend( self::READY_FACTS )->schedule_recurring( self::HOOK, 300 );
		$after  = \time();
		$calls  = $this->as_calls( 'as_schedule_recurring_action' );

		self::assertInstanceOf( Success::class, $result );
		self::assertGreaterThanOrEqual( $before, $calls[0]['args'][0] );
		self::assertLessThanOrEqual( $after, $calls[0]['args'][0] );
	}

	/**
	 * Every Action Scheduler ID-returning write maps an ordinary zero to ScheduleFailed.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_every_id_returning_write_maps_zero_to_schedule_failed(): void {
		$writes = array(
			'as_schedule_recurring_action' => static fn ( ActionSchedulerBackend $backend ): AbstractResult => $backend->schedule_recurring( self::HOOK, 300 ),
			'as_schedule_single_action'    => static fn ( ActionSchedulerBackend $backend ): AbstractResult => $backend->schedule_single( self::HOOK, 1_700_000_000 ),
			'as_enqueue_async_action'      => static fn ( ActionSchedulerBackend $backend ): AbstractResult => $backend->enqueue_async( self::HOOK ),
		);

		foreach ( $writes as $function_name => $write ) {
			$GLOBALS['a8csp_bgte_test_as_calls']   = array();
			$GLOBALS['a8csp_bgte_test_as_results'] = array(
				'as_has_scheduled_action' => array( false ),
				$function_name            => array( 0 ),
			);

			$error = $this->assert_failure_reason(
				$write( $this->backend( self::READY_FACTS ) ),
				SchedulingErrorReason::ScheduleFailed
			);

			self::assertStringContainsString( 'store rejected the action', $error->message );
			self::assertSame( $function_name, $error->context['action_scheduler_function'] );
			self::assertCount( 1, $this->as_calls( $function_name ) );
		}
	}

	/**
	 * Zero-ID diagnostics name the probe state that explains the failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, wp_init_fired: bool} $facts            Diagnostic probe facts.
	 * @param   string                                                                                                $expected_message Cause text.
	 *
	 * @return  void
	 */
	#[DataProvider( 'zero_id_diagnostics_provider' )]
	public function test_zero_id_diagnostics_distinguish_probe_states( array $facts, string $expected_message ): void {
		$GLOBALS['a8csp_bgte_test_as_results'] = array(
			'as_has_scheduled_action'   => array( false ),
			'as_schedule_single_action' => array( 0 ),
		);

		$backend = $this->backend_with_diagnostic_facts( $facts );

		$error = $this->assert_failure_reason(
			$backend->schedule_single( self::HOOK, 1_700_000_000 ),
			SchedulingErrorReason::ScheduleFailed
		);

		self::assertStringContainsString( $expected_message, $error->message );
		self::assertSame( self::HOOK, $error->context['hook'] );
		self::assertSame( $facts['action_scheduler_functions_exist'], $error->context['action_scheduler_functions_exist'] );
		self::assertSame( $facts['action_scheduler_init_fired'], $error->context['action_scheduler_init_fired'] );
		self::assertSame( $facts['wp_init_fired'], $error->context['wp_init_fired'] );
	}

	/**
	 * Diagnostic fact combinations and their corrective cause text.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  iterable<string, array{array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, wp_init_fired: bool}, string}>
	 */
	public static function zero_id_diagnostics_provider(): iterable {
		yield 'functions absent' => array(
			array(
				'action_scheduler_functions_exist' => false,
				'action_scheduler_init_fired'      => false,
				'wp_init_fired'                    => false,
			),
			'function table is unavailable',
		);

		yield 'called before WordPress init' => array(
			array(
				'action_scheduler_functions_exist' => true,
				'action_scheduler_init_fired'      => false,
				'wp_init_fired'                    => false,
			),
			'WordPress init has not fired',
		);

		yield 'Action Scheduler init missing after WordPress init' => array(
			array(
				'action_scheduler_functions_exist' => true,
				'action_scheduler_init_fired'      => false,
				'wp_init_fired'                    => true,
			),
			'action_scheduler_init has not fired',
		);

		yield 'store rejected the action' => array(
			self::READY_FACTS,
			'store rejected the action',
		);
	}

	/**
	 * Async zero-ID diagnostics refresh facts after the enqueue attempt.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_async_zero_id_diagnostics_refresh_the_probe_facts(): void {
		$GLOBALS['a8csp_bgte_test_as_results'] = array(
			'as_enqueue_async_action' => array( 0 ),
		);

		$after_enqueue = array(
			'action_scheduler_functions_exist' => false,
			'action_scheduler_init_fired'      => false,
			'wp_init_fired'                    => true,
		);

		$backend = $this->backend_with_diagnostic_facts( $after_enqueue );

		$error = $this->assert_failure_reason(
			$backend->enqueue_async( self::HOOK ),
			SchedulingErrorReason::ScheduleFailed
		);

		self::assertStringContainsString( 'function table is unavailable', $error->message );
		self::assertFalse( $error->context['action_scheduler_functions_exist'] );
	}

	/**
	 * A unique enqueue passes the flag and accepts a confirmed zero-ID duplicate.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unique_enqueue_passes_the_flag_and_accepts_a_confirmed_duplicate(): void {
		$GLOBALS['a8csp_bgte_test_as_results'] = array(
			'as_enqueue_async_action' => array( 0 ),
			'as_has_scheduled_action' => array( true ),
		);

		$result = $this->backend( self::READY_FACTS )->enqueue_async(
			self::HOOK,
			array( 'a' ),
			'reports',
			true,
			247
		);

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame(
			array( self::HOOK, array( 'a' ), 'reports', true, 247 ),
			$this->as_calls( 'as_enqueue_async_action' )[0]['args']
		);
		self::assertSame(
			array( self::HOOK, array( 'a' ), 'reports' ),
			$this->as_calls( 'as_has_scheduled_action' )[0]['args']
		);
	}

	/**
	 * A unique zero without a matching queued action remains a scheduling failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unique_enqueue_rejects_an_unconfirmed_zero(): void {
		$GLOBALS['a8csp_bgte_test_as_results'] = array(
			'as_enqueue_async_action' => array( 0 ),
			'as_has_scheduled_action' => array( false ),
		);

		$error = $this->assert_failure_reason(
			$this->backend( self::READY_FACTS )->enqueue_async( self::HOOK, unique: true ),
			SchedulingErrorReason::ScheduleFailed
		);

		self::assertStringContainsString( 'store rejected the action', $error->message );
	}

	/**
	 * Unscheduling passes the complete identity and succeeds after a clear verification.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unschedule_passes_the_identity_and_verifies_clearance(): void {
		$GLOBALS['a8csp_bgte_test_as_results'] = array(
			'as_has_scheduled_action' => array( false ),
		);

		$result = $this->backend( self::READY_FACTS )->unschedule( self::HOOK, array( 'a' ), 'reports' );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame(
			array( self::HOOK, array( 'a' ), 'reports' ),
			$this->as_calls( 'as_unschedule_all_actions' )[0]['args']
		);
		self::assertSame(
			array( self::HOOK, array( 'a' ), 'reports' ),
			$this->as_calls( 'as_has_scheduled_action' )[0]['args']
		);
	}

	/**
	 * A remaining pending or running action makes the unschedule postcondition fail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unschedule_reports_a_still_present_action(): void {
		$GLOBALS['a8csp_bgte_test_as_results'] = array(
			'as_has_scheduled_action' => array( true ),
		);

		$error = $this->assert_failure_reason(
			$this->backend( self::READY_FACTS )->unschedule( self::HOOK, array( 'a' ), 'reports' ),
			SchedulingErrorReason::ScheduleFailed
		);

		self::assertStringContainsString( self::HOOK, $error->message );
		self::assertStringContainsString( 'pending or in-progress action', $error->message );
		self::assertSame( array( 'hook' => self::HOOK ), $error->context );
	}

	/**
	 * Reads normalize timestamps, running-state true, and absence without losing group identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_reads_normalize_action_scheduler_next_values(): void {
		$GLOBALS['a8csp_bgte_test_as_results'] = array(
			'as_next_scheduled_action' => array(
				1_700_000_000,
				1_700_000_000,
				true,
				true,
				false,
				false,
			),
		);

		$backend = $this->backend( self::READY_FACTS );

		self::assertTrue( $backend->is_scheduled( self::HOOK, array( 'a' ), 'reports' ) );
		self::assertSame( 1_700_000_000, $backend->get_next_scheduled( self::HOOK, array( 'a' ), 'reports' ) );
		self::assertTrue( $backend->is_scheduled( self::HOOK, array( 'b' ), 'imports' ) );
		self::assertNull( $backend->get_next_scheduled( self::HOOK, array( 'b' ), 'imports' ) );
		self::assertFalse( $backend->is_scheduled( self::HOOK, array( 'c' ), 'exports' ) );
		self::assertNull( $backend->get_next_scheduled( self::HOOK, array( 'c' ), 'exports' ) );

		self::assertSame(
			array( 'reports', 'reports', 'imports', 'imports', 'exports', 'exports' ),
			\array_map(
				static fn ( array $call ): mixed => $call['args'][2],
				$this->as_calls( 'as_next_scheduled_action' )
			)
		);
	}

	/**
	 * Unready reads return empty state without touching the procedural API.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unready_reads_return_empty_state_without_api_calls(): void {
		$backend = $this->backend(
			array(
				'action_scheduler_functions_exist' => true,
				'action_scheduler_init_fired'      => false,
				'wp_init_fired'                    => true,
			)
		);

		self::assertFalse( $backend->is_scheduled( self::HOOK ) );
		self::assertNull( $backend->get_next_scheduled( self::HOOK ) );
		self::assertSame( array(), $this->as_calls() );
	}

	/**
	 * Hook registration remains a no-op because Action Scheduler owns its runners.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_hooks_does_not_touch_the_procedural_api(): void {
		$this->backend( self::READY_FACTS )->register_hooks();

		self::assertSame( array(), $this->as_calls() );
	}

	/**
	 * Builds a backend returning one fixed readiness snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, wp_init_fired: bool} $facts Readiness facts.
	 *
	 * @return  ActionSchedulerBackend
	 */
	private function backend( array $facts ): ActionSchedulerBackend {
		return new ActionSchedulerBackend(
			static fn (): bool => $facts['action_scheduler_functions_exist'] && $facts['action_scheduler_init_fired'],
			static fn ( string $function_name ): bool => $facts['action_scheduler_functions_exist'],
			static fn ( string $hook ): int => match ( $hook ) {
				'action_scheduler_init' => $facts['action_scheduler_init_fired'] ? 1 : 0,
				'init'                  => $facts['wp_init_fired'] ? 1 : 0,
				default                 => 0,
			}
		);
	}

	/**
	 * Builds a ready backend that reports the supplied post-write diagnostic facts.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, wp_init_fired: bool} $facts Diagnostic facts.
	 *
	 * @return  ActionSchedulerBackend
	 */
	private function backend_with_diagnostic_facts( array $facts ): ActionSchedulerBackend {
		return new ActionSchedulerBackend(
			static fn (): bool => true,
			static fn ( string $function_name ): bool => $facts['action_scheduler_functions_exist'],
			static fn ( string $hook ): int => match ( $hook ) {
				'action_scheduler_init' => $facts['action_scheduler_init_fired'] ? 1 : 0,
				'init'                  => $facts['wp_init_fired'] ? 1 : 0,
				default                 => 0,
			}
		);
	}

	/**
	 * Returns a result's scheduling error after checking its reason.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param AbstractResult<true, SchedulingError> $result
	 *
	 * @param   AbstractResult        $result Result to inspect.
	 * @param   SchedulingErrorReason $reason Expected reason.
	 *
	 * @return  SchedulingError
	 */
	private function assert_failure_reason( AbstractResult $result, SchedulingErrorReason $reason ): SchedulingError {
		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( $reason, $result->error->reason );

		return $result->error;
	}

	/**
	 * Returns recorded Action Scheduler calls, optionally filtered by function.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $function_name Function name, or null for every call.
	 *
	 * @return  list<array{function: string, args: list<mixed>}>
	 */
	private function as_calls( ?string $function_name = null ): array {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls = $GLOBALS['a8csp_bgte_test_as_calls'];

		if ( null === $function_name ) {
			return $calls;
		}

		return \array_values(
			\array_filter(
				$calls,
				static fn ( array $call ): bool => $function_name === $call['function']
			)
		);
	}
}
