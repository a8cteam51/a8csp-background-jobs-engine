<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Backends;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\BoundaryErrorMapper;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the WP-Cron procedural boundary that cannot run through EngineRig.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( WPCronBackend::class )]
#[UsesClass( BoundaryErrorMapper::class )]
final class WPCronBackendTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string HOOK = 'a8csp_bgje_test_hook';

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress cron functions before the backend is autoloaded.
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

		require_once \dirname( __DIR__, 2 ) . '/wp-cron-stubs.php';
	}

	/**
	 * Resets all request-local cron state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgje_test_cron_array']                  = array();
		$GLOBALS['a8csp_bgje_test_cron_calls']                  = array();
		$GLOBALS['a8csp_bgje_test_cron_results']                = array();
		$GLOBALS['a8csp_bgje_test_cron_event_sequence']         = 0;
		$GLOBALS['a8csp_bgje_test_cron_before_unschedule']      = null;
		$GLOBALS['a8csp_bgje_test_cron_preserve_on_unschedule'] = false;
		$GLOBALS['a8csp_bgje_test_hooks']                       = array();
		$GLOBALS['a8csp_bgje_test_filter_registrations']        = array();
		$GLOBALS['a8csp_bgje_test_get_option']                  = null;
	}

	// endregion.

	// region TESTS.

	/**
	 * A recurring write installs one queryable interval chain that an exact clear removes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_recurring_chain_round_trips_through_wp_cron(): void {
		$backend = new WPCronBackend();

		$scheduled = $backend->schedule_recurring( self::HOOK, 300, array( 'schedule-17' ), 1_700_000_300, 'ignored-group', 23 );

		self::assertInstanceOf( Success::class, $scheduled );
		self::assertTrue( $backend->is_scheduled( self::HOOK, array( 'schedule-17' ), 'another-ignored-group' ) );
		self::assertSame( 1_700_000_300, $backend->get_next_scheduled( self::HOOK, array( 'schedule-17' ) ) );
		self::assertSame( array( 1_700_000_300, 'a8csp_bgje_every_300s', self::HOOK, array( 'schedule-17' ), true ), $this->calls( 'wp_schedule_event' )[0]['args'] );

		$cleared = $backend->unschedule( self::HOOK, array( 'schedule-17' ), 'ignored-group' );

		self::assertInstanceOf( Success::class, $cleared );
		self::assertFalse( $backend->is_scheduled( self::HOOK, array( 'schedule-17' ) ) );
	}

	/**
	 * Run cancellation removes only matching delivery events from WP-Cron.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unschedule_run_selects_the_identity_and_run_from_cron_arguments(): void {
		$identity       = 'reports:refresh';
		$other_identity = 'reports:export';
		$run_id         = '00000000001700000000-0000000000000000042';
		$sibling_run_id = '00000000001700000000-0000000000000000043';
		$first_args     = array( $identity, $run_id, 1 );
		$second_args    = array( $identity, $run_id, 2 );
		a8csp_bgje_test_store_cron_event( 1_700_000_300, self::HOOK, $first_args, false );
		a8csp_bgje_test_store_cron_event( 1_700_000_600, self::HOOK, array( $identity, $sibling_run_id, 1 ), false );
		a8csp_bgje_test_store_cron_event( 1_700_000_900, self::HOOK, $second_args, false );
		a8csp_bgje_test_store_cron_event( 1_700_001_200, self::HOOK, array( $other_identity, $run_id, 1 ), false );
		a8csp_bgje_test_store_cron_event( 1_700_001_500, 'other-hook', $first_args, false );
		$GLOBALS['a8csp_bgje_test_cron_calls'] = array();

		$result = ( new WPCronBackend() )->unschedule_run( self::HOOK, $identity, $run_id );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				array( 1_700_000_300, self::HOOK, $first_args, true ),
				array( 1_700_000_900, self::HOOK, $second_args, true ),
			),
			\array_column( $this->calls( 'wp_unschedule_event' ), 'args' )
		);
		self::assertFalse( ( new WPCronBackend() )->is_scheduled( self::HOOK, $first_args ) );
		self::assertFalse( ( new WPCronBackend() )->is_scheduled( self::HOOK, $second_args ) );
		self::assertTrue( ( new WPCronBackend() )->is_scheduled( self::HOOK, array( $identity, $sibling_run_id, 1 ) ) );
		self::assertTrue( ( new WPCronBackend() )->is_scheduled( self::HOOK, array( $other_identity, $run_id, 1 ) ) );
		self::assertTrue( ( new WPCronBackend() )->is_scheduled( 'other-hook', $first_args ) );
	}

	/**
	 * Run cancellation preserves a target event rejected by WordPress.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unschedule_run_returns_the_first_wordpress_error_when_the_target_remains(): void {
		$identity = 'reports:refresh';
		$run_id   = '00000000001700000000-0000000000000000042';
		$args     = array( $identity, $run_id, 1 );
		a8csp_bgje_test_store_cron_event( 1_700_000_300, self::HOOK, $args, false );
		$GLOBALS['a8csp_bgje_test_cron_results'] = array(
			'wp_unschedule_event' => array( new \WP_Error( 'unschedule_failed', 'The cron store rejected cancellation.' ) ),
		);

		$result = ( new WPCronBackend() )->unschedule_run( self::HOOK, $identity, $run_id );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::ScheduleFailed, $result->error->reason );
		self::assertSame( 'The cron store rejected cancellation.', $result->error->context['wp_error'] ?? null );
		self::assertTrue( ( new WPCronBackend() )->is_scheduled( self::HOOK, $args ) );
	}

	/**
	 * Run cancellation reports a successful WordPress call that leaves the target event stored.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unschedule_run_fails_when_wordpress_makes_no_progress(): void {
		$identity = 'reports:refresh';
		$run_id   = '00000000001700000000-0000000000000000042';
		$args     = array( $identity, $run_id, 1 );
		a8csp_bgje_test_store_cron_event( 1_700_000_300, self::HOOK, $args, false );
		$GLOBALS['a8csp_bgje_test_cron_preserve_on_unschedule'] = true;

		$result = ( new WPCronBackend() )->unschedule_run( self::HOOK, $identity, $run_id );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::ScheduleFailed, $result->error->reason );
		self::assertArrayNotHasKey( 'wp_error', $result->error->context );
		self::assertTrue( ( new WPCronBackend() )->is_scheduled( self::HOOK, $args ) );
	}

	/**
	 * Matching WP-Cron chains are counted across every stored timestamp.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduled_chains_include_every_matching_timestamp(): void {
		a8csp_bgje_test_store_cron_event( 1_700_000_300, self::HOOK, array( 'schedule-17' ), 'a8csp_bgje_every_300s' );
		a8csp_bgje_test_store_cron_event( 1_700_000_600, self::HOOK, array( 'schedule-17' ), 'a8csp_bgje_every_300s' );
		a8csp_bgje_test_store_cron_event( 1_700_000_900, self::HOOK, array( 'other-schedule' ), 'a8csp_bgje_every_300s' );

		self::assertSame( 2, ( new WPCronBackend() )->scheduled_chains( self::HOOK, array( 'schedule-17' ) )['schedule-17']['count'] );
	}

	/**
	 * Multiple schedule identities are bucketed from one persisted cron-array read.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduled_chains_bucket_multiple_identities_from_one_cron_read(): void {
		a8csp_bgje_test_store_cron_event( 1_700_000_300, self::HOOK, array( 'single' ), 'a8csp_bgje_every_300s', 300 );
		a8csp_bgje_test_store_cron_event( 1_700_000_600, self::HOOK, array( 'many' ), 'a8csp_bgje_every_300s', 300 );
		a8csp_bgje_test_store_cron_event( 1_700_000_600, self::HOOK, array( 'many' ), 'a8csp_bgje_every_300s', 300 );
		a8csp_bgje_test_store_cron_event( 1_700_001_200, self::HOOK, array( 'many', 'extra' ), 'a8csp_bgje_every_300s', 300 );
		a8csp_bgje_test_store_cron_event( 1_700_001_500, 'other-hook', array( 'many' ), 'a8csp_bgje_every_300s', 300 );
		$cron_reads = 0;

		$GLOBALS['a8csp_bgje_test_get_option'] = static function ( string $option, mixed $default_value ) use ( &$cron_reads ): mixed {
			if ( 'cron' !== $option ) {
				return $default_value;
			}

			++$cron_reads;

			return $GLOBALS['a8csp_bgje_test_cron_array'];
		};

		$counts = ( new WPCronBackend() )->scheduled_chains( self::HOOK, array( 'single', 'missing', 'many' ) );

		self::assertSame(
			array(
				'single'  => array(
					'count'    => 1,
					'interval' => 300,
				),
				'missing' => array(
					'count'    => 0,
					'interval' => null,
				),
				'many'    => array(
					'count'    => 2,
					'interval' => null,
				),
			),
			$counts
		);
		self::assertSame( 1, $cron_reads );
	}

	/**
	 * Cancellation keeps scanning past cron entries it cannot read.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The cron array is shared with every other plugin on the site, so an entry the engine cannot parse can
	 *                precede its own delivery. Abandoning the scan at the first such entry would leave the run's delivery
	 *                pending while cancellation reported success.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancellation_scans_past_unreadable_cron_entries(): void {
		$identity = 'reports:refresh';
		$run_id   = '00000000001700000000-0000000000000000042';
		// Both share one timestamp so the unreadable entry precedes the delivery inside the same bucket, which is the
		// ordering that distinguishes skipping an entry from abandoning the bucket.
		a8csp_bgje_test_store_cron_event( 1_700_000_300, self::HOOK, array( 'malformed-single-argument' ), false );
		a8csp_bgje_test_store_cron_event( 1_700_000_300, self::HOOK, array( $identity, $run_id, 1 ), false );

		$backend = new WPCronBackend();

		$result = $backend->unschedule_run( self::HOOK, $identity, $run_id );

		self::assertInstanceOf( Success::class, $result );
		self::assertFalse( $backend->is_scheduled( self::HOOK, array( $identity, $run_id, 1 ) ) );
		// The unreadable neighbour belongs to whoever wrote it and must survive untouched.
		self::assertTrue( $backend->is_scheduled( self::HOOK, array( 'malformed-single-argument' ) ) );
	}

	/**
	 * An event carrying no usable recurrence reports cardinality without a cadence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_an_event_without_a_usable_recurrence_reports_no_cadence(): void {
		// WordPress omits the interval entirely for a one-off event, and a replacement cron store can persist any shape.
		a8csp_bgje_test_store_cron_event( 1_700_000_300, self::HOOK, array( 'one-off' ), false );
		a8csp_bgje_test_store_cron_event( 1_700_000_600, self::HOOK, array( 'zero' ), 'a8csp_bgje_every_300s', 0 );

		$chains = ( new WPCronBackend() )->scheduled_chains( self::HOOK, array( 'one-off', 'zero' ) );

		self::assertSame(
			array(
				'one-off' => array(
					'count'    => 1,
					'interval' => null,
				),
				'zero'    => array(
					'count'    => 1,
					'interval' => null,
				),
			),
			$chains
		);
	}

	/**
	 * Events created during a clear do not expand the finite deletion snapshot.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A producer can continuously add matching cron events while a clear runs; bounding work to the initial snapshot prevents an unbounded request while the postcheck still reports non-convergence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unschedule_does_not_chase_events_created_during_the_clear_snapshot(): void {
		$first_timestamp  = \time() + 1_200;
		$second_timestamp = $first_timestamp + 601;
		$insertions       = 0;
		self::assertTrue( \wp_schedule_single_event( $first_timestamp, self::HOOK, array( 'a' ), true ) );
		self::assertTrue( \wp_schedule_single_event( $second_timestamp, self::HOOK, array( 'a' ), true ) );
		$GLOBALS['a8csp_bgje_test_cron_calls']             = array();
		$GLOBALS['a8csp_bgje_test_cron_before_unschedule'] = static function ( int $timestamp, string $hook, array $args ) use ( &$insertions ): void {
			++$insertions;
			if ( 2 < $insertions ) {
				throw new \LogicException( 'Unscheduling exceeded the initial snapshot length.' );
			}
			if ( ! \array_is_list( $args ) ) {
				throw new \UnexpectedValueException( 'Pass list arguments to the pre-unschedule test hook.' );
			}

			a8csp_bgje_test_store_cron_event( $timestamp + 10_000 + $insertions, $hook, $args, false );
		};

		$result = ( new WPCronBackend() )->unschedule( self::HOOK, array( 'a' ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertSame( 2, $insertions );
		self::assertCount( 2, $this->calls( 'wp_unschedule_event' ) );
	}

	/**
	 * A duplicate event accepts the pending delivery on both single-event write paths.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Both paths precheck the identity across every timestamp, which is broader than the window WordPress
	 *                scans, so a duplicate error means a rival landed the identical event after the precheck. The delivery
	 *                exists either way, and treating that as a failure terminalizes a run whose next delivery will fire.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $verb Backend write accepting a pending duplicate.
	 *
	 * @return  void
	 */
	#[DataProvider( 'single_event_writes' )]
	public function test_a_duplicate_event_accepts_the_pending_delivery( string $verb ): void {
		$GLOBALS['a8csp_bgje_test_cron_results'] = array(
			'wp_schedule_single_event' => array( new \WP_Error( 'duplicate_event', 'A duplicate event already exists.' ) ),
		);
		$backend                                 = new WPCronBackend();

		$result = 'schedule_single' === $verb
			? $backend->schedule_single( self::HOOK, 1_700_000_300 )
			: $backend->enqueue_async( self::HOOK );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
	}

	/**
	 * Backend writes that place one single event.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{string}>
	 */
	public static function single_event_writes(): array {
		return array(
			'timed delivery'        => array( 'schedule_single' ),
			'asynchronous delivery' => array( 'enqueue_async' ),
		);
	}

	/**
	 * Arbitrary WP_Error text is removed before a scheduling failure reaches a consumer.
	 *
	 * @load-bearing security
	 * @pin-rationale WordPress extensions can place credentials or user data in WP_Error messages; the public admission mapper must not expose that external text in BoundaryError context or prose.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_wordpress_error_detail_is_redacted_at_the_public_boundary(): void {
		$secret                                  = 'password=hunter2';
		$GLOBALS['a8csp_bgje_test_cron_results'] = array(
			'wp_schedule_single_event' => array( new \WP_Error( 'single_failed', $secret ) ),
		);

		$internal = ( new WPCronBackend() )->schedule_single( self::HOOK, 1_700_000_300 );
		$public   = BoundaryErrorMapper::map( $internal );

		self::assertInstanceOf( Failure::class, $internal );
		self::assertInstanceOf( SchedulingError::class, $internal->error );
		self::assertSame( $secret, $internal->error->context['wp_error'] ?? null );
		self::assertInstanceOf( Failure::class, $public );
		self::assertInstanceOf( BoundaryError::class, $public->error );
		self::assertSame( ErrorCode::BackendRejected, $public->error->code );
		self::assertArrayNotHasKey( 'wp_error', $public->error->context );
		self::assertStringNotContainsString( $secret, $public->error->message );
	}

	/**
	 * Synthetic interval discovery reads persisted cron once until a new request interval invalidates it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_synthetic_interval_discovery_is_cached_and_invalidated_by_registration(): void {
		$cron_reads = 0;
		$stored     = array(
			1_700_000_000 => array(
				'persisted_hook' => array(
					array(
						'schedule' => 'a8csp_bgje_every_300s',
						'args'     => array(),
					),
				),
			),
		);

		$GLOBALS['a8csp_bgje_test_get_option'] = static function ( string $option, mixed $default_value ) use ( &$cron_reads, $stored ): mixed {
			if ( 'cron' !== $option ) {
				return $default_value;
			}

			++$cron_reads;

			return $stored;
		};

		$backend = new WPCronBackend();
		self::assertArrayHasKey( 'a8csp_bgje_every_300s', $backend->register_synthetic_schedules( array() ) );
		self::assertArrayHasKey( 'a8csp_bgje_every_300s', $backend->register_synthetic_schedules( array() ) );
		self::assertSame( 1, $cron_reads, 'Repeated cron_schedules evaluations must reuse the request snapshot' );

		self::assertInstanceOf( Success::class, $backend->schedule_recurring( self::HOOK, 600, array(), 1_700_000_600 ) );
		$schedules = $backend->register_synthetic_schedules( array() );

		self::assertArrayHasKey( 'a8csp_bgje_every_300s', $schedules );
		self::assertArrayHasKey( 'a8csp_bgje_every_600s', $schedules );
		self::assertSame( 2, $cron_reads, 'Registering a new interval must invalidate and rebuild the request snapshot once' );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns recorded WP-Cron calls, optionally filtered by function.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $function_name Function name, or null for every call.
	 *
	 * @return  list<array{function: string, args: list<mixed>}>
	 */
	private function calls( ?string $function_name = null ): array {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls = $GLOBALS['a8csp_bgje_test_cron_calls'];

		return null === $function_name
			? $calls
			: \array_values( \array_filter( $calls, static fn ( array $call ): bool => $function_name === $call['function'] ) );
	}

	// endregion.
}
