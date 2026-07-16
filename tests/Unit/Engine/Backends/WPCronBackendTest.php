<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Backends;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\AdmissionErrorMapper;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the WP-Cron procedural boundary that cannot run through EngineRig.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( WPCronBackend::class )]
#[UsesClass( AdmissionErrorMapper::class )]
final class WPCronBackendTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const HOOK = 'a8csp_bgte_test_hook';

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

		$GLOBALS['a8csp_bgte_test_cron_array']                  = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']                  = array();
		$GLOBALS['a8csp_bgte_test_cron_results']                = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence']         = 0;
		$GLOBALS['a8csp_bgte_test_cron_before_unschedule']      = null;
		$GLOBALS['a8csp_bgte_test_cron_preserve_on_unschedule'] = false;
		$GLOBALS['a8csp_bgte_test_hooks']                       = array();
		$GLOBALS['a8csp_bgte_test_filter_registrations']        = array();
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
		self::assertSame( array( 1_700_000_300, 'a8csp_bgte_every_300s', self::HOOK, array( 'schedule-17' ), true ), $this->calls( 'wp_schedule_event' )[0]['args'] );

		$cleared = $backend->unschedule( self::HOOK, array( 'schedule-17' ), 'ignored-group' );

		self::assertInstanceOf( Success::class, $cleared );
		self::assertFalse( $backend->is_scheduled( self::HOOK, array( 'schedule-17' ) ) );
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
		$GLOBALS['a8csp_bgte_test_cron_calls']             = array();
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

		$result = ( new WPCronBackend() )->unschedule( self::HOOK, array( 'a' ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertSame( 2, $insertions );
		self::assertCount( 2, $this->calls( 'wp_unschedule_event' ) );
	}

	/**
	 * Arbitrary WP_Error text is removed before a scheduling failure reaches a consumer.
	 *
	 * @load-bearing security
	 * @pin-rationale WordPress extensions can place credentials or user data in WP_Error messages; the public admission mapper must not expose that external text in ApiError context or prose.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_wordpress_error_detail_is_redacted_at_the_public_boundary(): void {
		$secret                                  = 'password=hunter2';
		$GLOBALS['a8csp_bgte_test_cron_results'] = array(
			'wp_schedule_single_event' => array( new \WP_Error( 'single_failed', $secret ) ),
		);

		$internal = ( new WPCronBackend() )->schedule_single( self::HOOK, 1_700_000_300 );
		$public   = AdmissionErrorMapper::map( $internal );

		self::assertInstanceOf( Failure::class, $internal );
		self::assertInstanceOf( SchedulingError::class, $internal->error );
		self::assertSame( $secret, $internal->error->context['wp_error'] ?? null );
		self::assertInstanceOf( Failure::class, $public );
		self::assertInstanceOf( ApiError::class, $public->error );
		self::assertSame( ApiErrorCode::BackendRejected, $public->error->code );
		self::assertArrayNotHasKey( 'wp_error', $public->error->context );
		self::assertStringNotContainsString( $secret, $public->error->message );
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
		$calls = $GLOBALS['a8csp_bgte_test_cron_calls'];

		return null === $function_name
			? $calls
			: \array_values( \array_filter( $calls, static fn ( array $call ): bool => $function_name === $call['function'] ) );
	}

	// endregion.
}
