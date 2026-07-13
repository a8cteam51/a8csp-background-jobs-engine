<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Orchestrator;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\Cadence;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins owner-scoped declarative schedule synchronization.
 *
 */
#[CoversClass( Schedules::class )]
#[UsesClass( Cadence::class )]
#[UsesClass( Schedule::class )]
#[UsesClass( ScheduleRegistry::class )]
#[UsesClass( Success::class )]
#[UsesClass( Failure::class )]
#[UsesClass( SchedulingError::class )]
#[UsesClass( SchedulingErrorReason::class )]
final class SchedulesTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const NOW = 1_700_000_000;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads WordPress option and JSON seams before schedule classes are first autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/Scheduling/wp-json-encode-stub.php';
	}

	/**
	 * Resets the in-memory option store and call ledger.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']               = array();
		$GLOBALS['a8csp_bgte_test_option_calls']          = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']       = array();
		$GLOBALS['a8csp_bgte_test_update_option_results'] = array();
		$GLOBALS['a8csp_bgte_test_update_option_values']  = array();
		$GLOBALS['a8csp_bgte_test_delete_option_results'] = array();
	}

	/**
	 * Removes option-write scripts before another test class uses the shared stubs.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function tearDown(): void {
		unset(
			$GLOBALS['a8csp_bgte_test_update_option_results'],
			$GLOBALS['a8csp_bgte_test_update_option_values'],
			$GLOBALS['a8csp_bgte_test_delete_option_results']
		);

		parent::tearDown();
	}

	// endregion.

	// region TESTS.

	/**
	 * Add, no-op, change, and removal apply the exact backend diff and registry state.
	 *
	 * @return  void
	 */
	public function test_sync_applies_the_add_change_remove_and_no_op_matrix(): void {
		$backend = new RecordingBackend();
		$clock   = new FixedClock( self::NOW );
		$api     = $this->new_schedules( $this->new_registry(), $backend, $clock );
		$initial = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );

		$added = $api->sync( 'owner-a', array( $initial ) );

		self::assertInstanceOf( Success::class, $added );
		self::assertTrue( $added->value );
		self::assertSame(
			array(
				array(
					'verb' => 'is_scheduled',
					'args' => array(
						'hook'  => 'a8csp/background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
				array(
					'verb' => 'schedule_recurring',
					'args' => array(
						'hook'                => 'a8csp/background_tasks/schedule_due',
						'interval'            => 300,
						'args'                => array( 'owner-a:nightly' ),
						'first_run_timestamp' => self::NOW + 300,
						'group'               => 'owner-a:nightly',
						'unique'              => true,
						'priority'            => 10,
					),
				),
			),
			$backend->calls
		);
		self::assertSame(
			array(
				'owner-a' => array(
					'nightly' => array(
						'fingerprint' => $initial->fingerprint(),
						'next_due'    => self::NOW + 300,
						'last_fired'  => null,
						'misfires'    => 0,
						'skips'       => 0,
					),
				),
			),
			$this->schedule_registry()
		);

		$this->clear_backend_calls( $backend );
		$GLOBALS['a8csp_bgte_test_option_calls'] = array();

		$backend->scheduled = true;

		$unchanged = $api->sync( 'owner-a', array( $initial ) );

		self::assertInstanceOf( Success::class, $unchanged );
		self::assertSame(
			array(
				array(
					'verb' => 'is_scheduled',
					'args' => array(
						'hook'  => 'a8csp/background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
			),
			$backend->calls
		);
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame(
			array(
				'fingerprint' => $initial->fingerprint(),
				'next_due'    => self::NOW + 300,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			),
			$this->registration( 'owner-a', 'nightly' )
		);

		$changed_schedule = new Schedule(
			'nightly',
			Cadence::every( 600 ),
			'refresh-index',
			priority: 20
		);
		$this->clear_backend_calls( $backend );
		$changed = $api->sync( 'owner-a', array( $changed_schedule ) );

		self::assertInstanceOf( Success::class, $changed );
		$calls = $this->backend_calls( $backend );
		self::assertCount( 2, $calls );
		self::assertSame(
			array( 'unschedule', 'schedule_recurring' ),
			\array_column( $calls, 'verb' )
		);
		self::assertSame(
			array(
				'hook'  => 'a8csp/background_tasks/schedule_due',
				'args'  => array( 'owner-a:nightly' ),
				'group' => 'owner-a:nightly',
			),
			$calls[0]['args']
		);
		self::assertSame( self::NOW + 600, $calls[1]['args']['first_run_timestamp'] );
		self::assertTrue( $calls[1]['args']['unique'] );
		self::assertSame( 20, $calls[1]['args']['priority'] );
		self::assertSame(
			array(
				'fingerprint' => $changed_schedule->fingerprint(),
				'next_due'    => self::NOW + 600,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			),
			$this->registration( 'owner-a', 'nightly' )
		);

		$this->clear_backend_calls( $backend );
		$removed = $api->sync( 'owner-a', array() );

		self::assertInstanceOf( Success::class, $removed );
		self::assertSame(
			array(
				array(
					'verb' => 'unschedule',
					'args' => array(
						'hook'  => 'a8csp/background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
			),
			$backend->calls
		);
		self::assertArrayNotHasKey( 'a8csp_bgte_schedules', $this->options() );
	}

	/**
	 * A matching persisted registration recreates a missing occurrence at its retained due time.
	 *
	 * @return  void
	 */
	public function test_persisted_registration_without_backend_is_recreated_at_the_persisted_next_due(): void {
		$backend   = new RecordingBackend();
		$schedule  = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );
		$persisted = array(
			'a8csp_bgte_schedules' => array(
				'owner-a' => array(
					'nightly' => array(
						'fingerprint' => $schedule->fingerprint(),
						'next_due'    => self::NOW - 60,
						'last_fired'  => self::NOW - 360,
						'misfires'    => 0,
						'skips'       => 0,
					),
				),
			),
		);

		$GLOBALS['a8csp_bgte_test_options'] = $persisted;

		$api = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );

		$result = $api->sync( 'owner-a', array( $schedule ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame(
			array(
				array(
					'verb' => 'is_scheduled',
					'args' => array(
						'hook'  => 'a8csp/background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
				array(
					'verb' => 'schedule_recurring',
					'args' => array(
						'hook'                => 'a8csp/background_tasks/schedule_due',
						'interval'            => 300,
						'args'                => array( 'owner-a:nightly' ),
						'first_run_timestamp' => self::NOW - 60,
						'group'               => 'owner-a:nightly',
						'unique'              => true,
						'priority'            => 10,
					),
				),
			),
			$backend->calls
		);
		self::assertSame( $persisted, $this->options() );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
	}

	/**
	 * An unregistered backend occurrence is cleared before the declared cadence is persisted.
	 *
	 * @return  void
	 */
	public function test_backend_without_persisted_registration_is_cleared_and_recreated(): void {
		$backend            = new RecordingBackend();
		$backend->scheduled = true;
		$schedule           = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );
		$api                = $this->new_schedules(
			$this->new_registry(),
			$backend,
			new FixedClock( self::NOW )
		);

		$result = $api->sync( 'owner-a', array( $schedule ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame(
			array(
				array(
					'verb' => 'is_scheduled',
					'args' => array(
						'hook'  => 'a8csp/background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
				array(
					'verb' => 'unschedule',
					'args' => array(
						'hook'  => 'a8csp/background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
				array(
					'verb' => 'schedule_recurring',
					'args' => array(
						'hook'                => 'a8csp/background_tasks/schedule_due',
						'interval'            => 300,
						'args'                => array( 'owner-a:nightly' ),
						'first_run_timestamp' => self::NOW + 300,
						'group'               => 'owner-a:nightly',
						'unique'              => true,
						'priority'            => 10,
					),
				),
			),
			$backend->calls
		);
		self::assertSame(
			array(
				'owner-a' => array(
					'nightly' => array(
						'fingerprint' => $schedule->fingerprint(),
						'next_due'    => self::NOW + 300,
						'last_fired'  => null,
						'misfires'    => 0,
						'skips'       => 0,
					),
				),
			),
			$this->schedule_registry()
		);
	}

	/**
	 * A replacement stops before persistence when the previous identity remains observable.
	 *
	 * @return  void
	 */
	public function test_change_path_fails_without_persisting_when_previous_occurrence_remains(): void {
		$backend            = new RecordingBackend();
		$backend->scheduled = true;

		$initial   = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );
		$changed   = new Schedule( 'nightly', Cadence::every( 600 ), 'refresh-index' );
		$persisted = array(
			'a8csp_bgte_schedules' => array(
				'owner-a' => array(
					'nightly' => array(
						'fingerprint' => $initial->fingerprint(),
						'next_due'    => self::NOW + 300,
						'last_fired'  => null,
					),
				),
			),
		);

		$GLOBALS['a8csp_bgte_test_options'] = $persisted;

		$backend->results['unschedule'] = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'The backend could not confirm clearance.'
			)
		);

		$api = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );

		$result = $api->sync( 'owner-a', array( $changed ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::ScheduleFailed, $result->error->reason );
		self::assertSame(
			'Schedule "nightly" cannot be replaced; retry the sync; the previous occurrence could not be confirmed removed.',
			$result->error->message
		);
		self::assertSame(
			array(
				array(
					'verb' => 'unschedule',
					'args' => array(
						'hook'  => 'a8csp/background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
			),
			$backend->calls
		);
		self::assertSame( $persisted, $this->options() );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
	}

	/**
	 * Removing owner A leaves owner B untouched in both persistence and backend calls.
	 *
	 * @return  void
	 */
	public function test_sync_orphan_removal_is_strictly_owner_scoped(): void {
		$backend = new RecordingBackend();
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );
		$owner_a = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );
		$owner_b = new Schedule( 'hourly', Cadence::every( 3_600 ), 'refresh-index' );

		$result_a = $api->sync( 'owner-a', array( $owner_a ) );
		$result_b = $api->sync( 'owner-b', array( $owner_b ) );
		self::assertInstanceOf( Success::class, $result_a );
		self::assertInstanceOf( Success::class, $result_b );

		$this->clear_backend_calls( $backend );
		$removed = $api->sync( 'owner-a', array() );

		self::assertInstanceOf( Success::class, $removed );
		self::assertSame(
			array(
				array(
					'verb' => 'unschedule',
					'args' => array(
						'hook'  => 'a8csp/background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
			),
			$backend->calls
		);
		self::assertSame(
			array(
				'owner-b' => array(
					'hourly' => array(
						'fingerprint' => $owner_b->fingerprint(),
						'next_due'    => self::NOW + 3_600,
						'last_fired'  => null,
						'misfires'    => 0,
						'skips'       => 0,
					),
				),
			),
			$this->schedule_registry()
		);
	}

	/**
	 * Numeric owner and schedule names remain removable across request-local registries.
	 *
	 * @return  void
	 */
	public function test_numeric_identifiers_round_trip_without_orphaning_backend_state(): void {
		$backend  = new RecordingBackend();
		$schedule = new Schedule( '456', Cadence::every( 300 ), 'refresh-index' );
		$created  = ( $this->new_schedules(
			$this->new_registry(),
			$backend,
			new FixedClock( self::NOW )
		) )->sync( '123', array( $schedule ) );
		self::assertInstanceOf( Success::class, $created );

		$this->clear_backend_calls( $backend );
		$removed = ( $this->new_schedules(
			$this->new_registry(),
			$backend,
			new FixedClock( self::NOW )
		) )->sync( '123', array() );

		self::assertInstanceOf( Success::class, $removed );
		self::assertSame( array( 'unschedule' ), \array_column( $backend->calls, 'verb' ) );
		self::assertSame( array( '123:456' ), $backend->calls[0]['args']['args'] );
		self::assertArrayNotHasKey( 'a8csp_bgte_schedules', $this->options() );
	}

	/**
	 * Unsupported cron data fails before backend mutation or registry replacement.
	 *
	 * @return  void
	 */
	public function test_cron_cadence_fails_as_data_without_mutating_backend_or_registry(): void {
		$backend = new RecordingBackend();
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );
		$fixed   = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );

		$seeded = $api->sync( 'owner-a', array( $fixed ) );
		self::assertInstanceOf( Success::class, $seeded );
		$stored_before = $GLOBALS['a8csp_bgte_test_options'];
		$this->clear_backend_calls( $backend );

		$result = $api->sync(
			'owner-a',
			array( new Schedule( 'nightly', Cadence::cron( '0 3 * * *' ), 'refresh-index' ) )
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::UnsupportedCadence, $result->error->reason );
		self::assertSame(
			'Schedule "nightly" uses a cron expression unsupported by every ready backend; use Cadence::every() or configure a backend that supports cron expressions.',
			$result->error->message
		);
		self::assertSame(
			array(
				array(
					'verb' => 'supports_cron_expressions',
					'args' => array(),
				),
			),
			$backend->calls
		);
		self::assertSame( $stored_before, $GLOBALS['a8csp_bgte_test_options'] );
	}

	/**
	 * Persisted fingerprint equality cannot bypass cron capability validation.
	 *
	 * @return  void
	 */
	public function test_unchanged_cron_cadence_still_fails_as_data(): void {
		$backend  = new RecordingBackend();
		$schedule = new Schedule( 'nightly', Cadence::cron( '0 3 * * *' ), 'refresh-index' );

		$GLOBALS['a8csp_bgte_test_options'] = array(
			'a8csp_bgte_schedules' => array(
				'owner-a' => array(
					'nightly' => array(
						'fingerprint' => $schedule->fingerprint(),
						'next_due'    => self::NOW,
						'last_fired'  => null,
					),
				),
			),
		);

		$stored_before = $GLOBALS['a8csp_bgte_test_options'];
		$api           = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );

		$result = $api->sync( 'owner-a', array( $schedule ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::UnsupportedCadence, $result->error->reason );
		self::assertSame(
			array(
				array(
					'verb' => 'supports_cron_expressions',
					'args' => array(),
				),
			),
			$backend->calls
		);
		self::assertSame( $stored_before, $GLOBALS['a8csp_bgte_test_options'] );
	}

	/**
	 * First-due overflow fails before replacing the live occurrence.
	 *
	 * @return  void
	 */
	public function test_first_due_overflow_fails_before_backend_mutation(): void {
		$backend = new RecordingBackend();
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );
		$initial = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );
		$seeded  = $api->sync( 'owner-a', array( $initial ) );
		self::assertInstanceOf( Success::class, $seeded );
		$stored_before = $GLOBALS['a8csp_bgte_test_options'];
		$this->clear_backend_calls( $backend );

		$result = $api->sync(
			'owner-a',
			array( new Schedule( 'nightly', Cadence::every( \PHP_INT_MAX ), 'refresh-index' ) )
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::InvalidInterval, $result->error->reason );
		self::assertSame( array(), $backend->calls );
		self::assertSame( $stored_before, $GLOBALS['a8csp_bgte_test_options'] );
	}

	/**
	 * A non-positive clock fails before replacing the live occurrence.
	 *
	 * @return  void
	 */
	public function test_non_positive_clock_fails_before_backend_mutation(): void {
		$backend = new RecordingBackend();
		$clock   = new FixedClock( self::NOW );
		$api     = $this->new_schedules( $this->new_registry(), $backend, $clock );
		$initial = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );
		$seeded  = $api->sync( 'owner-a', array( $initial ) );
		self::assertInstanceOf( Success::class, $seeded );
		$stored_before = $GLOBALS['a8csp_bgte_test_options'];
		$this->clear_backend_calls( $backend );
		$clock->timestamp = -1_000;

		$result = $api->sync(
			'owner-a',
			array( new Schedule( 'nightly', Cadence::every( 600 ), 'refresh-index' ) )
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::InvalidInterval, $result->error->reason );
		self::assertSame( array(), $backend->calls );
		self::assertSame( $stored_before, $GLOBALS['a8csp_bgte_test_options'] );
	}

	/**
	 * A clock before the epoch remains usable when the computed occurrence is positive.
	 *
	 * @return  void
	 */
	public function test_negative_clock_schedules_a_positive_first_occurrence(): void {
		$backend = new RecordingBackend();
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( -100 ) );

		$result = $api->sync(
			'owner-a',
			array( new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' ) )
		);

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( array( 'is_scheduled', 'schedule_recurring' ), \array_column( $backend->calls, 'verb' ) );
		self::assertSame( 200, $backend->calls[1]['args']['first_run_timestamp'] );
		self::assertSame(
			200,
			$this->registration( 'owner-a', 'nightly' )['next_due']
		);
	}

	/**
	 * A combined owner and schedule identity at the backend storage limit remains valid.
	 *
	 * @return  void
	 */
	public function test_sync_accepts_a_255_byte_registration_key(): void {
		$backend  = new RecordingBackend();
		$name     = \str_repeat( 'n', 253 );
		$key      = 'o:' . $name;
		$schedule = new Schedule( $name, Cadence::every( 300 ), 'refresh-index' );

		$result = ( $this->new_schedules(
			$this->new_registry(),
			$backend,
			new FixedClock( self::NOW )
		) )->sync( 'o', array( $schedule ) );

		self::assertSame( 255, \strlen( $key ) );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $key, $backend->calls[0]['args']['group'] );
		self::assertSame( $key, $backend->calls[1]['args']['group'] );
		self::assertSame( $schedule->fingerprint(), $this->registration( 'o', $name )['fingerprint'] );
	}

	/**
	 * An oversized combined identity fails before any earlier declaration can mutate state.
	 *
	 * @return  void
	 */
	public function test_sync_rejects_a_256_byte_registration_key_before_mutation(): void {
		$backend = new RecordingBackend();
		$name    = \str_repeat( 'n', 254 );
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );

		try {
			(void) $api->sync(
				'o',
				array(
					new Schedule( 'valid', Cadence::every( 300 ), 'refresh-index' ),
					new Schedule( $name, Cadence::every( 300 ), 'refresh-index' ),
				)
			);
			self::fail( 'Oversized registration key did not throw.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( 256, \strlen( 'o:' . $name ) );
			self::assertSame(
				'Schedule registration key is 256 bytes; shorten the owner or schedule name so the combined "{owner}:{name}" identity is at most 255 bytes.',
				$exception->getMessage()
			);
		}

		self::assertSame( array(), $backend->calls );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame( array(), $this->options() );
	}

	/**
	 * Invalid owner spelling fails before any backend or option mutation.
	 *
	 * @return  void
	 */
	public function test_sync_rejects_an_invalid_owner_with_the_fix(): void {
		$backend = new RecordingBackend();
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );

		try {
			(void) $api->sync( 'Owner A', array() );
			self::fail( 'Invalid owner sync did not throw.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame(
				'Schedule owner is invalid; pass a non-empty identifier containing only lowercase letters, digits, underscores, and hyphens.',
				$exception->getMessage()
			);
		}

		self::assertSame( array(), $backend->calls );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame( array(), $this->options() );
	}

	/**
	 * The engine owner cannot be replaced through the consumer synchronization API.
	 *
	 * @return  void
	 */
	public function test_sync_rejects_the_engine_reserved_owner_with_the_fix(): void {
		$backend = new RecordingBackend();
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );

		try {
			(void) $api->sync( 'a8csp-bgte', array() );
			self::fail( 'Reserved owner sync did not throw.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame(
				'Schedule owner "a8csp-bgte" is reserved for engine maintenance; choose a consumer-specific owner identifier.',
				$exception->getMessage()
			);
		}

		self::assertSame( array(), $backend->calls );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame( array(), $this->options() );
	}

	/**
	 * Duplicate declarations fail before any backend or option mutation.
	 *
	 * @return  void
	 */
	public function test_sync_rejects_duplicate_names_with_the_fix(): void {
		$schedule = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );
		$backend  = new RecordingBackend();
		$api      = $this->new_schedules(
			$this->new_registry(),
			$backend,
			new FixedClock( self::NOW )
		);

		try {
			(void) $api->sync( 'owner-a', array( $schedule, $schedule ) );
			self::fail( 'Duplicate schedule sync did not throw.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame(
				'Schedule "nightly" is declared more than once; pass each schedule name exactly once per owner.',
				$exception->getMessage()
			);
		}

		self::assertSame( array(), $backend->calls );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame( array(), $this->options() );
	}

	/**
	 * A failed replacement removes stale state so either declaration can repair the occurrence.
	 *
	 * @return  void
	 */
	public function test_failed_replacement_leaves_no_stale_fingerprint_and_the_old_declaration_repairs(): void {
		$backend = new RecordingBackend();
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );
		$initial = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );
		$seeded  = $api->sync( 'owner-a', array( $initial ) );
		self::assertInstanceOf( Success::class, $seeded );
		$this->clear_backend_calls( $backend );

		$failure = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Repair the scheduler store before retrying schedule sync.'
			)
		);

		$backend->results['schedule_recurring'] = $failure;

		$result = $api->sync(
			'owner-a',
			array( new Schedule( 'nightly', Cadence::every( 600 ), 'refresh-index' ) )
		);

		self::assertSame( $failure, $result );
		self::assertArrayNotHasKey( 'a8csp_bgte_schedules', $this->options() );
		self::assertSame(
			array( 'unschedule', 'schedule_recurring' ),
			\array_column( $backend->calls, 'verb' )
		);

		unset( $backend->results['schedule_recurring'] );
		$this->clear_backend_calls( $backend );
		$repaired = $api->sync( 'owner-a', array( $initial ) );

		self::assertInstanceOf( Success::class, $repaired );
		self::assertSame( array( 'is_scheduled', 'schedule_recurring' ), \array_column( $backend->calls, 'verb' ) );
		self::assertSame( 300, $backend->calls[1]['args']['interval'] );
	}

	/**
	 * A failed registry write rolls back a newly scheduled unresolvable occurrence.
	 *
	 * @return  void
	 */
	public function test_registry_update_failure_returns_failure_and_rolls_back_the_backend_add(): void {
		$GLOBALS['a8csp_bgte_test_update_option_results'] = array(
			'a8csp_bgte_schedules' => false,
		);
		$backend = new RecordingBackend();
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );

		$result = $api->sync(
			'owner-a',
			array( new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' ) )
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::ScheduleFailed, $result->error->reason );
		self::assertSame(
			'Schedule registry for owner "owner-a" could not be persisted; repair WordPress option writes and retry synchronization.',
			$result->error->message
		);
		self::assertSame(
			array( 'is_scheduled', 'schedule_recurring', 'unschedule' ),
			\array_column( $backend->calls, 'verb' )
		);
		self::assertArrayNotHasKey( 'a8csp_bgte_schedules', $this->options() );
	}

	/**
	 * A clear followed by a failed registry delete converges on the next synchronization.
	 *
	 * @return  void
	 */
	public function test_removal_crash_window_converges_on_the_next_sync(): void {
		$backend = new RecordingBackend();
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );
		$seeded  = $api->sync(
			'owner-a',
			array( new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' ) )
		);
		self::assertInstanceOf( Success::class, $seeded );

		$stored_before = $GLOBALS['a8csp_bgte_test_options'];

		$GLOBALS['a8csp_bgte_test_delete_option_results'] = array(
			'a8csp_bgte_schedules' => false,
		);
		$this->clear_backend_calls( $backend );

		$result = $api->sync( 'owner-a', array() );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::ScheduleFailed, $result->error->reason );
		$expected_clear = array(
			array(
				'verb' => 'unschedule',
				'args' => array(
					'hook'  => 'a8csp/background_tasks/schedule_due',
					'args'  => array( 'owner-a:nightly' ),
					'group' => 'owner-a:nightly',
				),
			),
		);
		self::assertSame( $expected_clear, $backend->calls );
		self::assertSame( $stored_before, $GLOBALS['a8csp_bgte_test_options'] );

		$GLOBALS['a8csp_bgte_test_delete_option_results'] = array();
		$this->clear_backend_calls( $backend );

		$retried = $api->sync( 'owner-a', array() );

		self::assertInstanceOf( Success::class, $retried );
		self::assertTrue( $retried->value );
		self::assertSame( $expected_clear, $backend->calls );
		self::assertArrayNotHasKey( 'a8csp_bgte_schedules', $this->options() );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a schedule registry with authoritative raw-row support.
	 *
	 * @return  ScheduleRegistry
	 */
	private function new_registry(): ScheduleRegistry {
		return new ScheduleRegistry( new OptionRows( new WpdbLockSpy() ) );
	}

	/**
	 * Constructs the complete schedule API graph used by synchronization tests.
	 *
	 * @param   ScheduleRegistry $registry Schedule registry.
	 * @param   RecordingBackend $backend  Scheduling seam.
	 * @param   FixedClock       $clock    Timestamp source.
	 *
	 * @return  Schedules
	 */
	private function new_schedules(
		ScheduleRegistry $registry,
		RecordingBackend $backend,
		FixedClock $clock
	): Schedules {
		$logger       = new RecordingLogger();
		$wpdb         = new WpdbLockSpy();
		$orchestrator = new Orchestrator(
			new TaskRegistry(),
			new BatchRegistry(),
			$backend,
			new OverlapGuard( $clock, $logger, new LockRows( $wpdb ) ),
			new StoreFactory( $clock, new OptionRows( $wpdb ) ),
			$logger,
			$clock,
			new RecordingRandomizer( 42 ),
		);

		return new Schedules(
			$registry,
			$backend,
			$clock,
			$orchestrator,
			new OccurrenceLease( new LockRows( $wpdb ), $clock, new RecordingRandomizer( 42 ) ),
			$logger
		);
	}

	/**
	 * Clears the recording backend ledger without narrowing its declared element type.
	 *
	 * @param   RecordingBackend $backend Backend call recorder.
	 *
	 * @return  void
	 */
	private function clear_backend_calls( RecordingBackend $backend ): void {
		$backend->calls = array();
	}

	/**
	 * Returns the backend ledger through its declared call shape.
	 *
	 * @param   RecordingBackend $backend Backend call recorder.
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function backend_calls( RecordingBackend $backend ): array {
		return $backend->calls;
	}

	/**
	 * Returns the option store after verifying its runtime representation.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function options(): array {
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );

		return $options;
	}

	/**
	 * Returns the persisted schedule registry after verifying its runtime representation.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function schedule_registry(): array {
		$registry = $this->options()['a8csp_bgte_schedules'] ?? null;
		self::assertIsArray( $registry );

		return $registry;
	}

	/**
	 * Returns one persisted registration after verifying each registry level.
	 *
	 * @param   string $owner Owner identifier.
	 * @param   string $name  Schedule name.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function registration( string $owner, string $name ): array {
		$registrations = $this->schedule_registry()[ $owner ] ?? null;
		self::assertIsArray( $registrations );

		$registration = $registrations[ $name ] ?? null;
		self::assertIsArray( $registration );

		return $registration;
	}

	// endregion.
}
