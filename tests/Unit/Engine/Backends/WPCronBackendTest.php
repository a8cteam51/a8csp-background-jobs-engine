<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Backends;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the WP-Cron backend contract without loading WordPress.
 *
 */
#[CoversClass( WPCronBackend::class )]
#[UsesClass( BackendInterface::class )]
#[UsesClass( Success::class )]
#[UsesClass( Failure::class )]
#[UsesClass( SchedulingError::class )]
#[UsesClass( SchedulingErrorReason::class )]
final class WPCronBackendTest extends TestCase {
	private const HOOK = 'a8csp_bgte_test_hook';

	/**
	 * Loads guarded WordPress cron functions before the backend is autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-cron-stubs.php';
	}

	/**
	 * Resets all request-local cron state.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_cron_array']                  = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']                  = array();
		$GLOBALS['a8csp_bgte_test_cron_results']                = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence']         = 0;
		$GLOBALS['a8csp_bgte_test_cron_before_unschedule']      = null;
		$GLOBALS['a8csp_bgte_test_cron_preserve_on_unschedule'] = false;
		$GLOBALS['a8csp_bgte_test_hooks']                       = array();
		$GLOBALS['a8csp_bgte_test_action_registrations']        = array();
		$GLOBALS['a8csp_bgte_test_filter_registrations']        = array();
	}

	/**
	 * WP-Cron fixed intervals do not provide calendar cron expressions.
	 *
	 * @return  void
	 */
	public function test_cron_expressions_are_not_supported(): void {
		self::assertFalse( ( new WPCronBackend() )->supports_cron_expressions() );
	}

	/**
	 * A recurring write accepts uniqueness while retaining WP-Cron's hook-and-args identity.
	 *
	 * @return  void
	 */
	public function test_schedule_recurring_accepts_uniqueness_with_a_non_empty_group(): void {
		$result = ( new WPCronBackend() )->schedule_recurring(
			self::HOOK,
			300,
			array( 'run-17' ),
			1_700_000_000,
			'reports|run-17',
			true
		);

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame(
			array( 1_700_000_000, 'a8csp_bgte_every_300s', self::HOOK, array( 'run-17' ), true ),
			$this->cron_calls( 'wp_schedule_event' )[0]['args']
		);
	}

	/**
	 * A single write ignores its group and schedules the hook-and-args identity.
	 *
	 * @return  void
	 */
	public function test_schedule_single_ignores_a_non_empty_group(): void {
		$result = ( new WPCronBackend() )->schedule_single(
			self::HOOK,
			1_700_000_000,
			array( 'run-18' ),
			'reports|run-18'
		);

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame(
			array( 1_700_000_000, self::HOOK, array( 'run-18' ), true ),
			$this->cron_calls( 'wp_schedule_single_event' )[0]['args']
		);
	}

	/**
	 * An async write ignores its group and schedules the hook-and-args identity.
	 *
	 * @return  void
	 */
	public function test_enqueue_async_ignores_a_non_empty_group(): void {
		$before = \time();
		$result = ( new WPCronBackend() )->enqueue_async(
			self::HOOK,
			array( 'run-19' ),
			'reports|run-19',
			true
		);
		$after  = \time();

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		$call = $this->cron_calls( 'wp_schedule_single_event' )[0]['args'];
		self::assertGreaterThanOrEqual( $before, $call[0] );
		self::assertLessThanOrEqual( $after, $call[0] );
		self::assertSame( array( self::HOOK, array( 'run-19' ), true ), \array_slice( $call, 1 ) );
	}

	/**
	 * Unscheduling uses WP-Cron's hook-and-args identity regardless of the supplied group.
	 *
	 * @return  void
	 */
	public function test_unschedule_ignores_a_non_empty_group(): void {
		self::assertTrue( \wp_schedule_single_event( 1_700_000_000, self::HOOK, array( 'a' ), true ) );
		$GLOBALS['a8csp_bgte_test_cron_calls'] = array();

		$result = ( new WPCronBackend() )->unschedule( self::HOOK, array( 'a' ), 'reports' );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame( false, \wp_next_scheduled( self::HOOK, array( 'a' ) ) );
		self::assertSame(
			array( 1_700_000_000, self::HOOK, array( 'a' ), true ),
			$this->cron_calls( 'wp_unschedule_event' )[0]['args']
		);
	}

	/**
	 * A group-only clear is a no-op because WP-Cron cannot distinguish grouped run identities.
	 *
	 * @return  void
	 */
	public function test_unschedule_with_an_empty_hook_preserves_every_cron_event(): void {
		self::assertTrue( \wp_schedule_single_event( 1_700_000_000, self::HOOK, array( 'run-22' ), true ) );
		self::assertTrue(
			\wp_schedule_single_event( 1_700_000_100, 'a8csp_bgte_sibling_hook', array( 'run-23' ), true )
		);
		$GLOBALS['a8csp_bgte_test_cron_calls'] = array();

		$result = ( new WPCronBackend() )->unschedule( '', array(), 'reports|run-22' );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame( array(), $this->cron_calls( 'wp_unschedule_event' ) );
		self::assertSame( 1_700_000_000, \wp_next_scheduled( self::HOOK, array( 'run-22' ) ) );
		self::assertSame(
			1_700_000_100,
			\wp_next_scheduled( 'a8csp_bgte_sibling_hook', array( 'run-23' ) )
		);
	}

	/**
	 * Read methods query the ungrouped WP-Cron identity regardless of the supplied group.
	 *
	 * @return  void
	 */
	public function test_reads_ignore_the_group(): void {
		self::assertTrue( \wp_schedule_single_event( 1_700_000_000, self::HOOK, array( 'a' ), true ) );
		$backend = new WPCronBackend();

		self::assertTrue( $backend->is_scheduled( self::HOOK, array( 'a' ), 'reports' ) );
		self::assertSame( 1_700_000_000, $backend->get_next_scheduled( self::HOOK, array( 'a' ), 'reports' ) );
	}

	/**
	 * Read methods report absence when the cron option has no stored value.
	 *
	 * @return  void
	 */
	public function test_reads_report_absence_without_a_cron_store(): void {
		unset( $GLOBALS['a8csp_bgte_test_cron_array'] );
		$backend = new WPCronBackend();

		self::assertFalse( $backend->is_scheduled( self::HOOK, array( 'a' ), 'reports' ) );
		self::assertNull( $backend->get_next_scheduled( self::HOOK, array( 'a' ), 'reports' ) );
		self::assertSame(
			array(
				array( self::HOOK, array( 'a' ) ),
				array( self::HOOK, array( 'a' ) ),
			),
			\array_map(
				static fn ( array $call ): array => $call['args'],
				$this->cron_calls( 'wp_next_scheduled' )
			)
		);
	}

	/**
	 * Zero and negative recurring intervals surface the domain-specific reason.
	 *
	 * @return  void
	 */
	public function test_schedule_recurring_rejects_intervals_below_one_second(): void {
		$backend = new WPCronBackend();

		foreach ( array( 0, -1 ) as $interval ) {
			$error = $this->assert_failure_reason(
				$backend->schedule_recurring( self::HOOK, $interval ),
				SchedulingErrorReason::InvalidInterval
			);

			self::assertStringContainsString( 'greater than zero', $error->message );
		}

		self::assertSame( array(), $this->cron_calls() );
	}

	/**
	 * A recurring write installs and uses the interval's synthetic schedule.
	 *
	 * @return  void
	 */
	public function test_schedule_recurring_registers_the_synthetic_schedule_and_event(): void {
		$backend  = new WPCronBackend();
		$result   = $backend->schedule_recurring( self::HOOK, 300, array( 'a' ), 1_700_000_000, priority: 247 );
		$callback = $this->cron_schedules_callback();

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame( array( $backend, 'register_synthetic_schedules' ), $callback );
		self::assertSame(
			array( 1_700_000_000, 'a8csp_bgte_every_300s', self::HOOK, array( 'a' ), true ),
			$this->cron_calls( 'wp_schedule_event' )[0]['args']
		);
		self::assertSame( 1_700_000_000, \wp_next_scheduled( self::HOOK, array( 'a' ) ) );

		$schedules = $callback( array() );
		self::assertSame( 300, $schedules['a8csp_bgte_every_300s']['interval'] );
	}

	/**
	 * Recurrence filters run before the fake exposes the stored event.
	 *
	 * @return  void
	 */
	public function test_recurring_schedule_resolves_filters_before_storing_the_event(): void {
		$timestamp                = 1_700_000_000;
		$visible_during_filtering = null;

		\add_filter(
			'cron_schedules',
			static function ( array $schedules ) use ( &$visible_during_filtering ): array {
				$visible_during_filtering = false !== \wp_next_scheduled( self::HOOK, array( 'a' ) );

				return $schedules;
			},
			5,
			1
		);

		$result = ( new WPCronBackend() )->schedule_recurring( self::HOOK, 300, array( 'a' ), $timestamp );

		self::assertInstanceOf( Success::class, $result );
		self::assertFalse( $visible_during_filtering );
		self::assertSame( $timestamp, \wp_next_scheduled( self::HOOK, array( 'a' ) ) );
	}

	/**
	 * An unavailable recurrence is rejected before the fake stores an event.
	 *
	 * @return  void
	 */
	public function test_recurring_schedule_requires_a_registered_recurrence_before_storage(): void {
		$result = \wp_schedule_event( 1_700_000_000, 'missing_recurrence', self::HOOK, array(), true );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'invalid_schedule', $result->get_error_code() );
		self::assertSame( false, \wp_next_scheduled( self::HOOK ) );
	}

	/**
	 * A null first-run timestamp schedules the recurring event at the current time.
	 *
	 * @return  void
	 */
	public function test_schedule_recurring_defaults_the_first_run_to_now(): void {
		$before = \time();
		$result = ( new WPCronBackend() )->schedule_recurring( self::HOOK, 300 );
		$after  = \time();
		$calls  = $this->cron_calls( 'wp_schedule_event' );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertGreaterThanOrEqual( $before, $calls[0]['args'][0] );
		self::assertLessThanOrEqual( $after, $calls[0]['args'][0] );
	}

	/**
	 * A single write schedules the exact event and carries true on success.
	 *
	 * @return  void
	 */
	public function test_schedule_single_schedules_the_event_and_returns_true(): void {
		$result = ( new WPCronBackend() )->schedule_single( self::HOOK, 1_700_000_000, array( 'a' ), priority: 247 );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame(
			array( 1_700_000_000, self::HOOK, array( 'a' ), true ),
			$this->cron_calls( 'wp_schedule_single_event' )[0]['args']
		);
		self::assertSame( 1_700_000_000, \wp_next_scheduled( self::HOOK, array( 'a' ) ) );
	}

	/**
	 * A fresh callback reconstructs every distinct synthetic interval from stored events.
	 *
	 * @return  void
	 */
	public function test_filter_rebuilds_distinct_intervals_from_the_current_cron_array(): void {
		$fresh = new WPCronBackend();
		$fresh->register_hooks();
		$callback = $this->cron_schedules_callback();

		self::assertSame( array(), $callback( array() ) );

		$backend = new WPCronBackend();
		self::assertInstanceOf( Success::class, $backend->schedule_recurring( self::HOOK, 300, array( 'a' ), 1_700_000_000 ) );
		self::assertInstanceOf( Success::class, $backend->schedule_recurring( self::HOOK, 600, array( 'b' ), 1_700_000_100 ) );
		self::assertInstanceOf( Success::class, $backend->schedule_recurring( self::HOOK, 300, array( 'c' ), 1_700_000_200 ) );

		$schedules = $callback(
			array(
				'hourly' => array(
					'interval' => 3600,
					'display'  => 'Hourly',
				),
			)
		);

		self::assertSame( array( 'hourly', 'a8csp_bgte_every_300s', 'a8csp_bgte_every_600s' ), \array_keys( $schedules ) );
		self::assertSame( 300, $schedules['a8csp_bgte_every_300s']['interval'] );
		self::assertSame( 600, $schedules['a8csp_bgte_every_600s']['interval'] );
	}

	/**
	 * Raw option corruption is ignored without hiding valid synthetic schedules.
	 *
	 * @return  void
	 */
	public function test_filter_tolerates_raw_cron_option_shapes(): void {
		$backend = new WPCronBackend();
		$backend->register_hooks();
		$callback = $this->cron_schedules_callback();

		$GLOBALS['a8csp_bgte_test_cron_array'] = 'not an array';
		self::assertSame( 'not an array', \get_option( 'cron', array() ) );
		self::assertSame( array(), $callback( array() ) );

		$GLOBALS['a8csp_bgte_test_cron_array'] = array(
			'version'     => 2,
			1_700_000_000 => 'not a hook map',
			1_700_000_100 => array( self::HOOK => 'not an event map' ),
			1_700_000_200 => array( self::HOOK => array( 'not an event' ) ),
			1_700_000_300 => array(
				self::HOOK => array(
					array( 'args' => array() ),
					array( 'schedule' => array() ),
					array(
						'schedule' => 'a8csp_bgte_every_300s',
						'args'     => array(),
					),
				),
			),
		);

		$schedules = $callback( array() );

		self::assertSame(
			array(
				'a8csp_bgte_every_300s' => array(
					'interval' => 300,
					'display'  => 'Every 300 seconds',
				),
			),
			$schedules
		);
	}

	/**
	 * Per-request hook registration remains idempotent without a scheduling call.
	 *
	 * @return  void
	 */
	public function test_register_hooks_wires_the_cron_schedules_filter_once(): void {
		$backend = new WPCronBackend();

		$backend->register_hooks();
		$backend->register_hooks();

		self::assertSame( array( 'cron_schedules' ), $GLOBALS['a8csp_bgte_test_hooks'] );
		self::assertSame( array( $backend, 'register_synthetic_schedules' ), $this->cron_schedules_callback() );
		self::assertSame( 1, $this->cron_schedules_registration()['accepted_args'] );
	}

	/**
	 * Existing hook-and-args identities make recurring and single scheduling successful no-ops.
	 *
	 * @return  void
	 */
	public function test_schedule_methods_skip_existing_args_aware_events(): void {
		$backend          = new WPCronBackend();
		$first_single_run = \time() + 1_200;
		$next_single_run  = $first_single_run + 601;

		self::assertInstanceOf( Success::class, $backend->schedule_recurring( self::HOOK, 300, array( 'a' ), 1_700_000_000 ) );
		self::assertInstanceOf( Success::class, $backend->schedule_single( self::HOOK, $first_single_run, array( 'b' ) ) );
		$GLOBALS['a8csp_bgte_test_cron_calls'] = array();

		$recurring = $backend->schedule_recurring( self::HOOK, 300, array( 'a' ), 1_700_000_100 );
		$single    = $backend->schedule_single( self::HOOK, $next_single_run, array( 'b' ) );

		self::assertInstanceOf( Success::class, $recurring );
		self::assertTrue( $recurring->value );
		self::assertInstanceOf( Success::class, $single );
		self::assertTrue( $single->value );
		self::assertSame(
			array(
				array( self::HOOK, array( 'a' ) ),
				array( self::HOOK, array( 'b' ) ),
			),
			\array_map(
				static fn ( array $call ): array => $call['args'],
				$this->cron_calls( 'wp_next_scheduled' )
			)
		);
		self::assertSame( array(), $this->cron_calls( 'wp_schedule_event' ) );
		self::assertSame( array(), $this->cron_calls( 'wp_schedule_single_event' ) );
	}

	/**
	 * The cron fake rejects timestamps that WordPress Core does not accept.
	 *
	 * @return  void
	 */
	public function test_cron_fake_rejects_non_positive_timestamps(): void {
		foreach ( array( 0, -1 ) as $timestamp ) {
			$recurring = \wp_schedule_event( $timestamp, 'missing_recurrence', self::HOOK, array( 'recurring', $timestamp ), true );
			$single    = \wp_schedule_single_event( $timestamp, self::HOOK, array( 'single', $timestamp ), true );

			self::assertInstanceOf( \WP_Error::class, $recurring );
			self::assertSame( 'invalid_timestamp', $recurring->get_error_code() );
			self::assertInstanceOf( \WP_Error::class, $single );
			self::assertSame( 'invalid_timestamp', $single->get_error_code() );
		}

		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_cron_array'] );
	}

	/**
	 * Core's duplicate window includes both ten-minute boundaries and serialized arguments.
	 *
	 * @return  void
	 */
	public function test_single_event_duplicate_window_is_inclusive_for_serialized_arguments(): void {
		$timestamp          = \time() + 1_800;
		$first_arg          = new \stdClass();
		$next_arg           = new \stdClass();
		$first_arg->task_id = 7;
		$next_arg->task_id  = 7;

		self::assertTrue( \wp_schedule_single_event( $timestamp - 600, self::HOOK, array( $first_arg ), true ) );
		$result = \wp_schedule_single_event( $timestamp, self::HOOK, array( $next_arg ), true );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'duplicate_event', $result->get_error_code() );

		$GLOBALS['a8csp_bgte_test_cron_array']          = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence'] = 0;

		self::assertTrue( \wp_schedule_single_event( $timestamp + 600, self::HOOK, array( 'a' ), true ) );
		$result = \wp_schedule_single_event( $timestamp, self::HOOK, array( 'a' ), true );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'duplicate_event', $result->get_error_code() );
	}

	/**
	 * A non-future request conflicts with every identical historical event.
	 *
	 * @return  void
	 */
	public function test_single_event_duplicate_scan_includes_all_past_events(): void {
		self::assertTrue( \wp_schedule_single_event( \time() - 7_200, self::HOOK, array( 'a' ), true ) );

		$result = \wp_schedule_single_event( \time() - 3_600, self::HOOK, array( 'a' ), true );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'duplicate_event', $result->get_error_code() );
	}

	/**
	 * Different hooks and differently serialized arguments remain distinct identities.
	 *
	 * @return  void
	 */
	public function test_single_event_duplicate_identity_uses_the_hook_and_serialized_arguments(): void {
		$timestamp = \time() + 1_200;

		self::assertTrue( \wp_schedule_single_event( $timestamp, self::HOOK, array( 1 ), true ) );
		self::assertTrue( \wp_schedule_single_event( $timestamp, self::HOOK . '_other', array( 1 ), true ) );
		self::assertTrue( \wp_schedule_single_event( $timestamp, self::HOOK, array( '1' ), true ) );
	}

	/**
	 * Events beyond either ten-minute boundary do not block a future request.
	 *
	 * @return  void
	 */
	public function test_single_event_duplicate_window_excludes_both_601_second_boundaries(): void {
		$timestamp = \time() + 1_800;

		self::assertTrue( \wp_schedule_single_event( $timestamp - 601, self::HOOK, array( 'a' ), true ) );
		self::assertTrue( \wp_schedule_single_event( $timestamp, self::HOOK, array( 'a' ), true ) );

		$GLOBALS['a8csp_bgte_test_cron_array']          = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence'] = 0;

		self::assertTrue( \wp_schedule_single_event( $timestamp + 601, self::HOOK, array( 'a' ), true ) );
		self::assertTrue( \wp_schedule_single_event( $timestamp, self::HOOK, array( 'a' ), true ) );
	}

	/**
	 * Near-future and past requests use Core's asymmetric now-based duplicate bounds.
	 *
	 * @return  void
	 */
	public function test_single_event_duplicate_window_uses_core_asymmetric_bounds(): void {
		$now = \time();

		self::assertTrue( \wp_schedule_single_event( $now - 7_200, self::HOOK, array( 'a' ), true ) );
		$near_future_result = \wp_schedule_single_event( $now + 300, self::HOOK, array( 'a' ), true );

		self::assertInstanceOf( \WP_Error::class, $near_future_result );
		self::assertSame( 'duplicate_event', $near_future_result->get_error_code() );

		$GLOBALS['a8csp_bgte_test_cron_array']          = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence'] = 0;

		self::assertTrue( \wp_schedule_single_event( $now + 300, self::HOOK, array( 'a' ), true ) );
		$past_result = \wp_schedule_single_event( $now - 7_200, self::HOOK, array( 'a' ), true );

		self::assertInstanceOf( \WP_Error::class, $past_result );
		self::assertSame( 'duplicate_event', $past_result->get_error_code() );
	}

	/**
	 * Unique async enqueue retains an existing event with identical hook arguments.
	 *
	 * @return  void
	 */
	public function test_unique_async_enqueue_skips_an_identical_event(): void {
		self::assertTrue( \wp_schedule_single_event( 1_700_000_000, self::HOOK, array( 'a' ), true ) );
		$GLOBALS['a8csp_bgte_test_cron_calls'] = array();

		$result = ( new WPCronBackend() )->enqueue_async( self::HOOK, array( 'a' ), unique: true );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame( array(), $this->cron_calls( 'wp_schedule_single_event' ) );
	}

	/**
	 * Unique async enqueue schedules when the existing event has different arguments.
	 *
	 * @return  void
	 */
	public function test_unique_async_enqueue_schedules_when_arguments_differ(): void {
		self::assertTrue( \wp_schedule_single_event( 1_700_000_000, self::HOOK, array( 'a' ), true ) );
		$GLOBALS['a8csp_bgte_test_cron_calls'] = array();

		$before = \time();

		$result = ( new WPCronBackend() )->enqueue_async( self::HOOK, array( 'b' ), unique: true );
		$after  = \time();
		$calls  = $this->cron_calls( 'wp_schedule_single_event' );

		self::assertInstanceOf( Success::class, $result );
		self::assertCount( 1, $calls );
		self::assertGreaterThanOrEqual( $before, $calls[0]['args'][0] );
		self::assertLessThanOrEqual( $after, $calls[0]['args'][0] );
		self::assertSame( array( 'b' ), $calls[0]['args'][2] );
	}

	/**
	 * Non-unique async enqueue delegates even when an identical event already exists.
	 *
	 * @return  void
	 */
	public function test_non_unique_async_enqueue_does_not_preemptively_deduplicate(): void {
		self::assertTrue( \wp_schedule_single_event( \time() + 1_200, self::HOOK, array( 'a' ), true ) );
		$GLOBALS['a8csp_bgte_test_cron_calls'] = array();

		$result = ( new WPCronBackend() )->enqueue_async( self::HOOK, array( 'a' ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertCount( 1, $this->cron_calls( 'wp_schedule_single_event' ) );
	}

	/**
	 * A scripted Core duplicate accepts the identical pending event for a non-unique request.
	 *
	 * @return  void
	 */
	public function test_non_unique_async_enqueue_accepts_a_scripted_duplicate_event(): void {
		$GLOBALS['a8csp_bgte_test_cron_results'] = array(
			'wp_schedule_single_event' => array(
				new \WP_Error( 'duplicate_event', 'A duplicate event already exists.' ),
			),
		);

		$result = ( new WPCronBackend() )->enqueue_async( self::HOOK, array( 'a' ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertCount( 1, $this->cron_calls( 'wp_schedule_single_event' ) );
	}

	/**
	 * Unscheduling clears every stacked occurrence with exact arguments and verifies absence.
	 *
	 * @return  void
	 */
	public function test_unschedule_clears_all_exact_occurrences_and_preserves_other_arguments(): void {
		$first_timestamp  = \time() + 1_200;
		$other_timestamp  = $first_timestamp + 300;
		$second_timestamp = $first_timestamp + 601;

		self::assertTrue( \wp_schedule_single_event( $first_timestamp, self::HOOK, array( 'a' ), true ) );
		self::assertTrue( \wp_schedule_single_event( $other_timestamp, self::HOOK, array( 'b' ), true ) );
		self::assertTrue( \wp_schedule_single_event( $second_timestamp, self::HOOK, array( 'a' ), true ) );
		$GLOBALS['a8csp_bgte_test_cron_calls'] = array();

		$result = ( new WPCronBackend() )->unschedule( self::HOOK, array( 'a' ) );
		$calls  = $this->cron_calls( 'wp_unschedule_event' );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame( false, \wp_next_scheduled( self::HOOK, array( 'a' ) ) );
		self::assertSame( $other_timestamp, \wp_next_scheduled( self::HOOK, array( 'b' ) ) );
		self::assertSame(
			array(
				array( $first_timestamp, self::HOOK, array( 'a' ), true ),
				array( $second_timestamp, self::HOOK, array( 'a' ), true ),
			),
			\array_map( static fn ( array $call ): array => $call['args'], $calls )
		);
	}

	/**
	 * Hook-wide clearance counts all arguments while preserving unrelated cron hooks.
	 *
	 * @return  void
	 */
	public function test_unschedule_hooks_counts_and_clears_every_pending_event(): void {
		self::assertTrue( \wp_schedule_single_event( 1_700_000_000, self::HOOK, array( 'a' ), true ) );
		self::assertTrue( \wp_schedule_single_event( 1_700_000_601, self::HOOK, array( 'b' ), true ) );
		self::assertTrue(
			\wp_schedule_single_event( 1_700_000_100, 'a8csp_bgte_sibling_hook', array( 'c' ), true )
		);
		self::assertTrue(
			\wp_schedule_single_event( 1_700_000_200, 'a8csp_bgte_unrelated_hook', array( 'd' ), true )
		);
		$GLOBALS['a8csp_bgte_test_cron_calls'] = array();

		$result = ( new WPCronBackend() )->unschedule_hooks(
			array( self::HOOK, 'a8csp_bgte_sibling_hook' )
		);

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( 3, $result->value );
		self::assertSame(
			array(
				array( self::HOOK, true ),
				array( 'a8csp_bgte_sibling_hook', true ),
			),
			\array_map(
				static fn ( array $call ): array => $call['args'],
				$this->cron_calls( 'wp_unschedule_hook' )
			)
		);
		self::assertSame(
			1_700_000_200,
			\wp_next_scheduled( 'a8csp_bgte_unrelated_hook', array( 'd' ) )
		);
	}

	/**
	 * A successful-looking clear that makes no progress is reported instead of looping forever.
	 *
	 * @return  void
	 */
	public function test_unschedule_fails_when_the_event_remains_scheduled(): void {
		self::assertTrue( \wp_schedule_single_event( 1_700_000_000, self::HOOK, array( 'a' ), true ) );
		$GLOBALS['a8csp_bgte_test_cron_calls']                  = array();
		$GLOBALS['a8csp_bgte_test_cron_preserve_on_unschedule'] = true;

		$error = $this->assert_failure_reason(
			( new WPCronBackend() )->unschedule( self::HOOK, array( 'a' ) ),
			SchedulingErrorReason::ScheduleFailed
		);

		self::assertStringContainsString( self::HOOK, $error->message );
		self::assertCount( 1, $this->cron_calls( 'wp_unschedule_event' ) );
	}

	/**
	 * A mid-loop clear error is retained only when a fresh snapshot still finds the event.
	 *
	 * @return  void
	 */
	public function test_unschedule_continues_after_a_wordpress_error_and_folds_it_into_failure(): void {
		$first_timestamp  = \time() + 1_200;
		$second_timestamp = $first_timestamp + 601;

		self::assertTrue( \wp_schedule_single_event( $first_timestamp, self::HOOK, array( 'a' ), true ) );
		self::assertTrue( \wp_schedule_single_event( $second_timestamp, self::HOOK, array( 'a' ), true ) );
		$GLOBALS['a8csp_bgte_test_cron_results'] = array(
			'wp_unschedule_event' => array(
				new \WP_Error( 'clear_failed', 'Cron storage refused the clear.' ),
				true,
			),
		);

		$error = $this->assert_failure_reason(
			( new WPCronBackend() )->unschedule( self::HOOK, array( 'a' ) ),
			SchedulingErrorReason::ScheduleFailed
		);

		self::assertSame(
			'WP-Cron could not unschedule hook "a8csp_bgte_test_hook"; inspect the WordPress cron error, correct the rejected event, and retry.',
			$error->message
		);
		self::assertSame(
			array(
				'hook'     => self::HOOK,
				'wp_error' => 'Cron storage refused the clear.',
			),
			$error->context
		);
		self::assertSame(
			array(
				array( $first_timestamp, self::HOOK, array( 'a' ), true ),
				array( $second_timestamp, self::HOOK, array( 'a' ), true ),
			),
			\array_map(
				static fn ( array $call ): array => $call['args'],
				$this->cron_calls( 'wp_unschedule_event' )
			)
		);
	}

	/**
	 * A raced clear error succeeds when the event vanished before Core handled it.
	 *
	 * @return  void
	 */
	public function test_unschedule_succeeds_when_a_snapshot_event_vanishes_before_clear(): void {
		$timestamp = \time() + 1_200;
		self::assertTrue( \wp_schedule_single_event( $timestamp, self::HOOK, array( 'a' ), true ) );

		$GLOBALS['a8csp_bgte_test_cron_before_unschedule'] = static function ( int $event_timestamp, string $hook, array $args ): void {
			$GLOBALS['a8csp_bgte_test_cron_array'] = array();
		};
		$GLOBALS['a8csp_bgte_test_cron_results']           = array(
			'wp_unschedule_event' => array( new \WP_Error( 'clear_failed', 'The event already vanished.' ) ),
		);

		$result = ( new WPCronBackend() )->unschedule( self::HOOK, array( 'a' ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertCount( 1, $this->cron_calls( 'wp_unschedule_event' ) );
	}

	/**
	 * New matching events do not expand the finite deletion snapshot.
	 *
	 * @return  void
	 */
	public function test_unschedule_does_not_chase_events_created_during_the_clear_loop(): void {
		$first_timestamp  = \time() + 1_200;
		$second_timestamp = $first_timestamp + 601;
		$insertions       = 0;

		self::assertTrue( \wp_schedule_single_event( $first_timestamp, self::HOOK, array( 'a' ), true ) );
		self::assertTrue( \wp_schedule_single_event( $second_timestamp, self::HOOK, array( 'a' ), true ) );

		$GLOBALS['a8csp_bgte_test_cron_before_unschedule'] = static function ( int $timestamp, string $hook, array $args ) use ( &$insertions ): void {
			++$insertions;
			if ( 2 < $insertions ) {
				throw new \LogicException( 'Unscheduling exceeded the initial snapshot length.' );
			}
			if ( ! \array_is_list( $args ) ) {
				throw new \UnexpectedValueException( 'Pass list arguments to the pre-unschedule test hook.' );
			}

			a8csp_bgte_test_store_cron_event( $timestamp + 10_000 + $insertions, $hook, $args, false );
		};

		$error = $this->assert_failure_reason(
			( new WPCronBackend() )->unschedule( self::HOOK, array( 'a' ) ),
			SchedulingErrorReason::ScheduleFailed
		);

		self::assertStringContainsString( self::HOOK, $error->message );
		self::assertSame( 2, $insertions );
		self::assertCount( 2, $this->cron_calls( 'wp_unschedule_event' ) );
	}

	/**
	 * A WordPress scheduling error becomes an actionable scheduling failure.
	 *
	 * @return  void
	 */
	public function test_schedule_maps_a_wordpress_error_to_schedule_failed(): void {
		$GLOBALS['a8csp_bgte_test_cron_results'] = array(
			'wp_schedule_event' => array( new \WP_Error( 'invalid_schedule', 'The recurrence is unavailable.' ) ),
		);

		$error = $this->assert_failure_reason(
			( new WPCronBackend() )->schedule_recurring( self::HOOK, 300 ),
			SchedulingErrorReason::ScheduleFailed
		);

		self::assertSame(
			'WP-Cron could not schedule hook "a8csp_bgte_test_hook": the recurrence is not registered; ensure register_hooks() ran on this request.',
			$error->message
		);
	}

	/**
	 * A single-event WordPress error keeps external detail out of its engine-authored message.
	 *
	 * @return  void
	 */
	public function test_schedule_single_maps_a_wordpress_error_to_schedule_failed(): void {
		$GLOBALS['a8csp_bgte_test_cron_results'] = array(
			'wp_schedule_single_event' => array( new \WP_Error( 'single_failed', 'The single event was rejected.' ) ),
		);

		$error = $this->assert_failure_reason(
			( new WPCronBackend() )->schedule_single( self::HOOK, 1_700_000_000 ),
			SchedulingErrorReason::ScheduleFailed
		);

		self::assertSame(
			'WP-Cron could not schedule hook "a8csp_bgte_test_hook"; inspect the WordPress cron error, correct the rejected event, and retry.',
			$error->message
		);
		self::assertSame(
			array(
				'hook'     => self::HOOK,
				'wp_error' => 'The single event was rejected.',
			),
			$error->context
		);
		self::assertSame(
			array( 1_700_000_000, self::HOOK, array(), true ),
			$this->cron_calls( 'wp_schedule_single_event' )[0]['args']
		);
	}

	/**
	 * Advisory priorities accept arbitrary values without reaching WP-Cron calls.
	 *
	 * @return  void
	 */
	public function test_priority_values_are_accepted_and_ignored(): void {
		$backend = new WPCronBackend();
		$before  = \time();

		self::assertInstanceOf( Success::class, $backend->schedule_recurring( self::HOOK, 300, array( 'a' ), 1_700_000_000, priority: -100 ) );
		self::assertInstanceOf( Success::class, $backend->schedule_single( self::HOOK, 1_700_001_000, array( 'b' ), priority: 999 ) );
		self::assertInstanceOf( Success::class, $backend->enqueue_async( self::HOOK, array( 'c' ), priority: \PHP_INT_MAX ) );
		$after = \time();

		self::assertSame(
			array( 1_700_000_000, 'a8csp_bgte_every_300s', self::HOOK, array( 'a' ), true ),
			$this->cron_calls( 'wp_schedule_event' )[0]['args']
		);
		self::assertSame(
			array( 1_700_001_000, self::HOOK, array( 'b' ), true ),
			$this->cron_calls( 'wp_schedule_single_event' )[0]['args']
		);
		$async_call = $this->cron_calls( 'wp_schedule_single_event' )[1]['args'];
		self::assertGreaterThanOrEqual( $before, $async_call[0] );
		self::assertLessThanOrEqual( $after, $async_call[0] );
		self::assertSame( array( self::HOOK, array( 'c' ), true ), \array_slice( $async_call, 1 ) );
	}

	/**
	 * Core availability makes WP-Cron consultable regardless of runner configuration.
	 *
	 * @return  void
	 */
	public function test_is_ready_is_always_true(): void {
		$backend = new WPCronBackend();

		self::assertTrue( $backend->is_ready() );
		self::assertFalse( $backend->is_absent() );
	}

	/**
	 * Returns a result's scheduling error after checking its reason.
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
	 * Returns the callback recorded for the WP-Cron schedules filter.
	 *
	 * @phpstan-return callable(array<string, array{interval: int, display: string}>): array<string, array{interval: int, display: string}>
	 *
	 * @return  callable
	 */
	private function cron_schedules_callback(): callable {
		$callback = $this->cron_schedules_registration()['callback'];
		self::assertIsCallable( $callback );

		return $callback;
	}

	/**
	 * Returns the registration recorded for the WP-Cron schedules filter.
	 *
	 * @return  array{hook_name: string, callback: mixed, priority: int, accepted_args: int}
	 */
	private function cron_schedules_registration(): array {
		/** @var list<array{hook_name: string, callback: mixed, priority: int, accepted_args: int}> $registrations */
		$registrations = $GLOBALS['a8csp_bgte_test_filter_registrations'];

		foreach ( $registrations as $registration ) {
			if ( 'cron_schedules' !== $registration['hook_name'] ) {
				continue;
			}

			return $registration;
		}

		self::fail( 'The cron_schedules filter callback was not registered.' );
	}

	/**
	 * Returns recorded cron calls, optionally filtered by function.
	 *
	 * @param   string|null $function_name Function name, or null for every call.
	 *
	 * @return  list<array{function: string, args: list<mixed>}>
	 */
	private function cron_calls( ?string $function_name = null ): array {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls = $GLOBALS['a8csp_bgte_test_cron_calls'];

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
