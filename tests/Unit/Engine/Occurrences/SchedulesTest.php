<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Occurrences;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\TerminalTransitions;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\WorkRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;
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
#[UsesClass( Recurrence::class )]
#[UsesClass( Schedule::class )]
#[UsesClass( ScheduleRegistry::class )]
#[UsesClass( OccurrenceDelivery::class )]
#[UsesClass( Success::class )]
#[UsesClass( Failure::class )]
#[UsesClass( SchedulingError::class )]
#[UsesClass( SchedulingErrorReason::class )]
#[UsesClass( WorkIdentity::class )]
final class SchedulesTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const NOW = 1_700_000_000;

	private WpdbLockSpy $wpdb;

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

		require_once \dirname( __DIR__, 2 ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-lock-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-time-constant-stubs.php';
		require_once \dirname( __DIR__ ) . '/Backends/wp-json-encode-stub.php';
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
		$this->wpdb                                       = new WpdbLockSpy();
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
		$initial = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );

		$added = $api->sync( 'owner-a', self::declarations( 'owner-a', $initial ) );

		self::assertInstanceOf( Success::class, $added );
		self::assertTrue( $added->value );
		self::assertSame(
			array(
				array(
					'verb' => 'is_scheduled',
					'args' => array(
						'hook'  => 'a8csp_background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
				array(
					'verb' => 'schedule_recurring',
					'args' => array(
						'hook'                => 'a8csp_background_tasks/schedule_due',
						'interval'            => 300,
						'args'                => array( 'owner-a:nightly' ),
						'first_run_timestamp' => self::NOW + 300,
						'group'               => 'owner-a:nightly',
						'priority'            => 10,
					),
				),
			),
			$backend->calls
		);
		self::assertSame(
			array(
				'owner-a' => array(
					'owner-a:nightly' => array(
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

		$unchanged = $api->sync( 'owner-a', self::declarations( 'owner-a', $initial ) );

		self::assertInstanceOf( Success::class, $unchanged );
		self::assertSame(
			array(
				array(
					'verb' => 'is_scheduled',
					'args' => array(
						'hook'  => 'a8csp_background_tasks/schedule_due',
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
			Recurrence::every( 600 ),
			'refresh-index',
			priority: 20
		);
		$this->clear_backend_calls( $backend );
		$changed = $api->sync( 'owner-a', self::declarations( 'owner-a', $changed_schedule ) );

		self::assertInstanceOf( Success::class, $changed );
		$calls = $this->backend_calls( $backend );
		self::assertCount( 2, $calls );
		self::assertSame(
			array( 'unschedule', 'schedule_recurring' ),
			\array_column( $calls, 'verb' )
		);
		self::assertSame(
			array(
				'hook'  => 'a8csp_background_tasks/schedule_due',
				'args'  => array( 'owner-a:nightly' ),
				'group' => 'owner-a:nightly',
			),
			$calls[0]['args']
		);
		self::assertSame( self::NOW + 600, $calls[1]['args']['first_run_timestamp'] );
		self::assertArrayNotHasKey( 'unique', $calls[1]['args'] );
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
						'hook'  => 'a8csp_background_tasks/schedule_due',
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
		$schedule  = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$persisted = array(
			'a8csp_bgte_schedules' => array(
				'owner-a' => array(
					'owner-a:nightly' => array(
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

		$result = $api->sync( 'owner-a', self::declarations( 'owner-a', $schedule ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame(
			array(
				array(
					'verb' => 'is_scheduled',
					'args' => array(
						'hook'  => 'a8csp_background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
				array(
					'verb' => 'schedule_recurring',
					'args' => array(
						'hook'                => 'a8csp_background_tasks/schedule_due',
						'interval'            => 300,
						'args'                => array( 'owner-a:nightly' ),
						'first_run_timestamp' => self::NOW - 60,
						'group'               => 'owner-a:nightly',
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
	 * An unregistered backend occurrence is cleared before the declared recurrence is persisted.
	 *
	 * @return  void
	 */
	public function test_backend_without_persisted_registration_is_cleared_and_recreated(): void {
		$backend            = new RecordingBackend();
		$backend->scheduled = true;
		$schedule           = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$api                = $this->new_schedules(
			$this->new_registry(),
			$backend,
			new FixedClock( self::NOW )
		);

		$result = $api->sync( 'owner-a', self::declarations( 'owner-a', $schedule ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertTrue( $result->value );
		self::assertSame(
			array(
				array(
					'verb' => 'is_scheduled',
					'args' => array(
						'hook'  => 'a8csp_background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
				array(
					'verb' => 'unschedule',
					'args' => array(
						'hook'  => 'a8csp_background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
				array(
					'verb' => 'schedule_recurring',
					'args' => array(
						'hook'                => 'a8csp_background_tasks/schedule_due',
						'interval'            => 300,
						'args'                => array( 'owner-a:nightly' ),
						'first_run_timestamp' => self::NOW + 300,
						'group'               => 'owner-a:nightly',
						'priority'            => 10,
					),
				),
			),
			$backend->calls
		);
		self::assertSame(
			array(
				'owner-a' => array(
					'owner-a:nightly' => array(
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
	 * A registry write failure stops synchronization before scheduling the declared recurrence.
	 *
	 * @return  void
	 */
	public function test_registry_write_failure_stops_before_backend_mutation(): void {
		$persisted = array(
			'a8csp_bgte_schedules' => array(
				'owner-b' => array(
					'owner-b:hourly' => array(
						'fingerprint' => 'retained-fingerprint',
						'next_due'    => self::NOW + 3_600,
						'last_fired'  => null,
						'misfires'    => 0,
						'skips'       => 0,
					),
				),
			),
		);

		$GLOBALS['a8csp_bgte_test_options'] = $persisted;
		$this->wpdb->script_result( 'update', false );

		$backend = new RecordingBackend();
		$result  = ( $this->new_schedules(
			$this->new_registry(),
			$backend,
			new FixedClock( self::NOW )
		) )->sync(
			'owner-a',
			self::declarations( 'owner-a', new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' ) )
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::StorageFailure, $result->error->reason );
		self::assertSame(
			'Schedule registry state for owner "owner-a" could not be persisted; repair WordPress option writes and retry synchronization.',
			$result->error->message
		);
		self::assertSame( array( 'owner' => 'owner-a' ), $result->error->context );
		self::assertSame( array( 'is_scheduled' ), \array_column( $backend->calls, 'verb' ) );
		self::assertSame( $persisted, $this->options() );
		$option_calls = $GLOBALS['a8csp_bgte_test_option_calls'] ?? null;
		self::assertIsArray( $option_calls );
		self::assertSame( array(), $option_calls );
		$updates = \array_values(
			\array_filter(
				$this->wpdb->recorded_queries,
				static fn ( string $query ): bool => \str_starts_with( $query, 'UPDATE ' )
			)
		);
		self::assertCount( 1, $updates );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $updates[0] );
	}

	/** A failed registry read stops synchronization before backend cleanup or persistence. */
	public function test_registry_read_failure_stops_before_backend_or_registry_mutation(): void {
		$persisted                          = array(
			'a8csp_bgte_schedules' => array(
				'owner-a' => array(
					'owner-a:retained' => array(
						'fingerprint' => 'retained-fingerprint',
						'next_due'    => self::NOW + 300,
						'last_fired'  => null,
						'misfires'    => 0,
						'skips'       => 0,
					),
				),
			),
		);
		$GLOBALS['a8csp_bgte_test_options'] = $persisted;
		$wpdb                               = new WpdbLockSpy();
		$wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $database ): void {
				$database->last_error = 'scripted registry read failure';
			}
		);
		$backend = new RecordingBackend();

		$result = ( $this->new_schedules(
			new ScheduleRegistry( new OptionRows( $wpdb ) ),
			$backend,
			new FixedClock( self::NOW )
		) )->sync( 'owner-a', array() );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::StorageFailure, $result->error->reason );
		self::assertSame(
			'Schedule registry state for owner "owner-a" could not be read; repair WordPress option reads and retry.',
			$result->error->message
		);
		self::assertSame( array(), $backend->calls );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame( $persisted, $this->options() );
	}

	/**
	 * A replacement stops before persistence when the previous identity remains observable.
	 *
	 * @return  void
	 */
	public function test_change_path_fails_without_persisting_when_previous_occurrence_remains(): void {
		$backend            = new RecordingBackend();
		$backend->scheduled = true;

		$initial   = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$changed   = new Schedule( 'nightly', Recurrence::every( 600 ), 'refresh-index' );
		$persisted = array(
			'a8csp_bgte_schedules' => array(
				'owner-a' => array(
					'owner-a:nightly' => array(
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

		$result = $api->sync( 'owner-a', self::declarations( 'owner-a', $changed ) );

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
						'hook'  => 'a8csp_background_tasks/schedule_due',
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
	 * Removing owner A cannot alter another consumer or the engine-reserved owner.
	 *
	 * @return  void
	 */
	public function test_sync_orphan_removal_is_strictly_owner_scoped(): void {
		$backend     = new RecordingBackend();
		$api         = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );
		$owner_a     = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$owner_b     = new Schedule( 'hourly', Recurrence::every( 3_600 ), 'refresh-index' );
		$maintenance = new Schedule( 'maintenance', Recurrence::every( 3_600 ), 'maintenance' );

		$result_a = $api->sync( 'owner-a', self::declarations( 'owner-a', $owner_a ) );
		$result_b = $api->sync( 'owner-b', self::declarations( 'owner-b', $owner_b ) );
		$engine   = $api->sync_owner(
			'a8csp-bgte',
			self::declarations( 'a8csp-bgte', $maintenance )
		);
		self::assertInstanceOf( Success::class, $result_a );
		self::assertInstanceOf( Success::class, $result_b );
		self::assertInstanceOf( Success::class, $engine );

		$before        = $this->schedule_registry();
		$owner_b_bytes = \maybe_serialize( $before['owner-b'] ?? null );
		$engine_bytes  = \maybe_serialize( $before['a8csp-bgte'] ?? null );
		self::assertIsString( $owner_b_bytes );
		self::assertIsString( $engine_bytes );

		$this->clear_backend_calls( $backend );
		$removed = $api->sync( 'owner-a', array() );

		self::assertInstanceOf( Success::class, $removed );
		self::assertSame(
			array(
				array(
					'verb' => 'unschedule',
					'args' => array(
						'hook'  => 'a8csp_background_tasks/schedule_due',
						'args'  => array( 'owner-a:nightly' ),
						'group' => 'owner-a:nightly',
					),
				),
			),
			$backend->calls
		);
		$persisted = $this->schedule_registry();
		self::assertSame(
			array(
				'owner-b'    => array(
					'owner-b:hourly' => array(
						'fingerprint' => $owner_b->fingerprint(),
						'next_due'    => self::NOW + 3_600,
						'last_fired'  => null,
						'misfires'    => 0,
						'skips'       => 0,
					),
				),
				'a8csp-bgte' => array(
					'a8csp-bgte:maintenance' => array(
						'fingerprint' => $maintenance->fingerprint(),
						'next_due'    => self::NOW + 3_600,
						'last_fired'  => null,
						'misfires'    => 0,
						'skips'       => 0,
					),
				),
			),
			$persisted
		);
		self::assertSame( $owner_b_bytes, \maybe_serialize( $persisted['owner-b'] ) );
		self::assertSame( $engine_bytes, \maybe_serialize( $persisted['a8csp-bgte'] ) );
	}

	/**
	 * Numeric owner and schedule names remain removable across request-local registries.
	 *
	 * @return  void
	 */
	public function test_numeric_identifiers_round_trip_without_orphaning_backend_state(): void {
		$backend  = new RecordingBackend();
		$schedule = new Schedule( '456', Recurrence::every( 300 ), 'refresh-index' );
		$created  = ( $this->new_schedules(
			$this->new_registry(),
			$backend,
			new FixedClock( self::NOW )
		) )->sync( '123', self::declarations( '123', $schedule ) );
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
	public function test_cron_recurrence_fails_as_data_without_mutating_backend_or_registry(): void {
		$backend = new RecordingBackend();
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );
		$fixed   = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );

		$seeded = $api->sync( 'owner-a', self::declarations( 'owner-a', $fixed ) );
		self::assertInstanceOf( Success::class, $seeded );
		$stored_before = $this->options();
		$this->clear_backend_calls( $backend );

		$result = $api->sync(
			'owner-a',
			self::declarations( 'owner-a', new Schedule( 'nightly', Recurrence::cron( '0 3 * * *' ), 'refresh-index' ) )
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::UnsupportedRecurrence, $result->error->reason );
		self::assertSame(
			'Schedule "nightly" uses a cron expression unsupported by every ready backend; use Recurrence::every() or configure a backend that supports cron expressions.',
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
		self::assertSame( $stored_before, $this->options() );
	}

	/**
	 * Persisted fingerprint equality cannot bypass cron capability validation.
	 *
	 * @return  void
	 */
	public function test_unchanged_cron_recurrence_still_fails_as_data(): void {
		$backend  = new RecordingBackend();
		$schedule = new Schedule( 'nightly', Recurrence::cron( '0 3 * * *' ), 'refresh-index' );

		$GLOBALS['a8csp_bgte_test_options'] = array(
			'a8csp_bgte_schedules' => array(
				'owner-a' => array(
					'owner-a:nightly' => array(
						'fingerprint' => $schedule->fingerprint(),
						'next_due'    => self::NOW,
						'last_fired'  => null,
					),
				),
			),
		);

		$stored_before = $this->options();
		$api           = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );

		$result = $api->sync( 'owner-a', self::declarations( 'owner-a', $schedule ) );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::UnsupportedRecurrence, $result->error->reason );
		self::assertSame(
			array(
				array(
					'verb' => 'supports_cron_expressions',
					'args' => array(),
				),
			),
			$backend->calls
		);
		self::assertSame( $stored_before, $this->options() );
	}

	/**
	 * First-due overflow fails before replacing the live occurrence.
	 *
	 * @return  void
	 */
	public function test_first_due_overflow_fails_before_backend_mutation(): void {
		$backend = new RecordingBackend();
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );
		$initial = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$seeded  = $api->sync( 'owner-a', self::declarations( 'owner-a', $initial ) );
		self::assertInstanceOf( Success::class, $seeded );
		$stored_before = $this->options();
		$this->clear_backend_calls( $backend );

		$result = $api->sync(
			'owner-a',
			self::declarations( 'owner-a', new Schedule( 'nightly', Recurrence::every( \PHP_INT_MAX ), 'refresh-index' ) )
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::InvalidTimeInput, $result->error->reason );
		self::assertSame( array(), $backend->calls );
		self::assertSame( $stored_before, $this->options() );
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
		$initial = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$seeded  = $api->sync( 'owner-a', self::declarations( 'owner-a', $initial ) );
		self::assertInstanceOf( Success::class, $seeded );
		$stored_before = $this->options();
		$this->clear_backend_calls( $backend );
		$clock->timestamp = -1_000;

		$result = $api->sync(
			'owner-a',
			self::declarations( 'owner-a', new Schedule( 'nightly', Recurrence::every( 600 ), 'refresh-index' ) )
		);

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::InvalidTimeInput, $result->error->reason );
		self::assertSame( array(), $backend->calls );
		self::assertSame( $stored_before, $this->options() );
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
			self::declarations( 'owner-a', new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' ) )
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
	 * The longest owner and local-name components compose one 97-byte identity.
	 *
	 * @return  void
	 */
	public function test_sync_accepts_a_97_byte_registration_identity(): void {
		$backend  = new RecordingBackend();
		$owner    = \str_repeat( 'o', 32 );
		$name     = \str_repeat( 'n', 64 );
		$key      = WorkIdentity::compose( $owner, $name );
		$schedule = new Schedule( $name, Recurrence::every( 300 ), 'refresh-index' );

		$result = ( $this->new_schedules(
			$this->new_registry(),
			$backend,
			new FixedClock( self::NOW )
		) )->sync( $owner, self::declarations( $owner, $schedule ) );

		self::assertSame( 97, \strlen( $key ) );
		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $key, $backend->calls[0]['args']['group'] );
		self::assertSame( $key, $backend->calls[1]['args']['group'] );
		self::assertSame( $schedule->fingerprint(), $this->registration( $owner, $name )['fingerprint'] );
	}

	/**
	 * A noncanonical declaration identity fails before backend or registry mutation.
	 *
	 * @return  void
	 */
	public function test_sync_rejects_a_98_byte_registration_identity_before_mutation(): void {
		$backend  = new RecordingBackend();
		$owner    = \str_repeat( 'o', 32 );
		$name     = \str_repeat( 'n', 64 );
		$schedule = new Schedule( $name, Recurrence::every( 300 ), 'refresh-index' );
		$key      = WorkIdentity::compose( $owner, $name ) . 'n';
		$api      = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );

		try {
			(void) $api->sync(
				$owner,
				array(
					$key => array(
						'schedule' => $schedule,
						'task'     => WorkIdentity::compose( $owner, 'refresh-index' ),
					),
				)
			);
			self::fail( 'Noncanonical registration identity did not throw.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( 98, \strlen( $key ) );
			self::assertSame(
				'Schedule sync declaration identities must be canonical and belong to the bound owner.',
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
				'Background-work owner is invalid; pass 1 to 32 bytes matching [a-z0-9][a-z0-9-]*.',
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
				'Background-work owner uses the engine-reserved "a8csp-bgte" prefix; use the consumer plugin slug.',
				$exception->getMessage()
			);
		}

		self::assertSame( array(), $backend->calls );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame( array(), $this->options() );
	}

	/**
	 * A declaration key must name the enclosed schedule value object.
	 *
	 * @return  void
	 */
	public function test_sync_rejects_a_declaration_identity_that_disagrees_with_the_schedule(): void {
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$backend  = new RecordingBackend();
		$api      = $this->new_schedules(
			$this->new_registry(),
			$backend,
			new FixedClock( self::NOW )
		);

		try {
			(void) $api->sync(
				'owner-a',
				array(
					'owner-a:hourly' => array(
						'schedule' => $schedule,
						'task'     => 'owner-a:refresh-index',
					),
				)
			);
			self::fail( 'Mismatched schedule identity did not throw.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame(
				'Schedule sync declaration identities must match their Schedule value-object names.',
				$exception->getMessage()
			);
		}

		self::assertSame( array(), $backend->calls );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame( array(), $this->options() );
	}

	/**
	 * A declaration target must be the bound owner's complete task identity.
	 *
	 * @return  void
	 */
	public function test_sync_rejects_a_target_identity_that_disagrees_with_the_schedule(): void {
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$backend  = new RecordingBackend();
		$api      = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );

		try {
			(void) $api->sync(
				'owner-a',
				array(
					'owner-a:nightly' => array(
						'schedule' => $schedule,
						'task'     => 'owner-b:refresh-index',
					),
				)
			);
			self::fail( 'Mismatched task identity did not throw.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame(
				'Schedule sync target identities must be canonical, belong to the bound owner, and match their Schedule value-object task names.',
				$exception->getMessage()
			);
		}

		self::assertSame( array(), $backend->calls );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertSame( array(), $this->options() );
	}

	/**
	 * A failed replacement retains the changed registration so another declaration repairs through the change path.
	 *
	 * @return  void
	 */
	public function test_failed_replacement_persists_the_changed_registration_and_the_old_declaration_replaces_it(): void {
		$backend = new RecordingBackend();
		$api     = $this->new_schedules( $this->new_registry(), $backend, new FixedClock( self::NOW ) );
		$initial = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$changed = new Schedule( 'nightly', Recurrence::every( 600 ), 'refresh-index' );
		$seeded  = $api->sync( 'owner-a', self::declarations( 'owner-a', $initial ) );
		self::assertInstanceOf( Success::class, $seeded );
		$this->clear_backend_calls( $backend );

		$failure = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Repair the scheduler store before retrying schedule sync.'
			)
		);

		$backend->results['schedule_recurring'] = $failure;

		$result = $api->sync( 'owner-a', self::declarations( 'owner-a', $changed ) );

		self::assertSame( $failure, $result );
		self::assertSame(
			array(
				'fingerprint' => $changed->fingerprint(),
				'next_due'    => self::NOW + 600,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			),
			$this->registration( 'owner-a', 'nightly' )
		);
		self::assertSame(
			array( 'unschedule', 'schedule_recurring' ),
			\array_column( $backend->calls, 'verb' )
		);

		unset( $backend->results['schedule_recurring'] );
		$this->clear_backend_calls( $backend );
		$repaired = $api->sync( 'owner-a', self::declarations( 'owner-a', $initial ) );

		self::assertInstanceOf( Success::class, $repaired );
		self::assertSame( array( 'unschedule', 'schedule_recurring' ), \array_column( $backend->calls, 'verb' ) );
		self::assertSame( 300, $backend->calls[1]['args']['interval'] );
		self::assertSame( $initial->fingerprint(), $this->registration( 'owner-a', 'nightly' )['fingerprint'] );
	}

	/**
	 * A schedule failure leaves its registration for the fast path to recreate the missing occurrence.
	 *
	 * @return  void
	 */
	public function test_schedule_failure_persists_the_registration_and_the_next_sync_recreates_the_occurrence(): void {
		$backend  = new RecordingBackend();
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$failure  = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Repair the scheduler store before retrying schedule sync.'
			)
		);

		$backend->results['schedule_recurring'] = $failure;
		$api                                    = $this->new_schedules(
			$this->new_registry(),
			$backend,
			new FixedClock( self::NOW )
		);

		$result = $api->sync( 'owner-a', self::declarations( 'owner-a', $schedule ) );

		$expected_registration = array(
			'fingerprint' => $schedule->fingerprint(),
			'next_due'    => self::NOW + 300,
			'last_fired'  => null,
			'misfires'    => 0,
			'skips'       => 0,
		);
		self::assertSame( $failure, $result );
		self::assertSame( $expected_registration, $this->registration( 'owner-a', 'nightly' ) );
		self::assertSame(
			array( 'is_scheduled', 'schedule_recurring' ),
			\array_column( $backend->calls, 'verb' )
		);

		$persisted = $this->options();
		unset( $backend->results['schedule_recurring'] );
		$this->clear_backend_calls( $backend );
		$GLOBALS['a8csp_bgte_test_option_calls'] = array();

		$repaired = $api->sync( 'owner-a', self::declarations( 'owner-a', $schedule ) );

		self::assertInstanceOf( Success::class, $repaired );
		self::assertSame(
			array( 'is_scheduled', 'schedule_recurring' ),
			\array_column( $backend->calls, 'verb' )
		);
		self::assertSame( 300, $backend->calls[1]['args']['interval'] );
		self::assertSame( self::NOW + 300, $backend->calls[1]['args']['first_run_timestamp'] );
		self::assertSame( $persisted, $this->options() );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
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
			self::declarations( 'owner-a', new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' ) )
		);
		self::assertInstanceOf( Success::class, $seeded );

		$stored_before = $this->options();

		$this->wpdb->script_result( 'delete', false );
		$this->clear_backend_calls( $backend );

		$result = $api->sync( 'owner-a', array() );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::StorageFailure, $result->error->reason );
		$expected_clear = array(
			array(
				'verb' => 'unschedule',
				'args' => array(
					'hook'  => 'a8csp_background_tasks/schedule_due',
					'args'  => array( 'owner-a:nightly' ),
					'group' => 'owner-a:nightly',
				),
			),
		);
		self::assertSame( $expected_clear, $backend->calls );
		self::assertSame( $stored_before, $this->options() );

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
		return new ScheduleRegistry( new OptionRows( $this->wpdb ) );
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
		$logger               = new RecordingLogger();
		$wpdb                 = new WpdbLockSpy();
		$guard                = new OverlapGuard( $clock, $logger, new OptionRows( $wpdb ) );
		$stores               = new StoreFactory( $clock, new OptionRows( $wpdb ) );
		$randomizer           = new RecordingRandomizer( 42 );
		$work                 = new WorkRegistry();
		$tasks                = new TaskRegistry( $work );
		$batches              = new BatchRegistry( $work );
		$lock_windows         = new LockWindows( $clock );
		$terminal_transitions = new TerminalTransitions( $guard, $stores, $clock, $lock_windows, $logger );
		$dispatcher           = new Dispatcher(
			$tasks,
			$batches,
			$backend,
			$guard,
			$stores,
			$clock,
			$randomizer,
			$logger,
			$lock_windows,
			$terminal_transitions,
		);

		$delivery = new OccurrenceDelivery(
			$registry,
			$dispatcher,
			new OccurrenceLease( new OptionRows( $wpdb ), $clock, new RecordingRandomizer( 42 ) ),
			new SchedulerFacade( array( $backend ) ),
			new OptionRows( $wpdb ),
			$clock,
			$logger
		);

		return new Schedules( $registry, $backend, $clock, $delivery );
	}

	/**
	 * Returns declarations keyed by complete schedule and task identities.
	 *
	 * @param   string   $owner     Owner identifier.
	 * @param   Schedule ...$schedules Schedule value objects.
	 *
	 * @return  array<string, array{schedule: Schedule, task: string}>
	 */
	private static function declarations( string $owner, Schedule ...$schedules ): array {
		$declarations = array();
		foreach ( $schedules as $schedule ) {
			$identity = WorkIdentity::compose( $owner, $schedule->name, true );

			$declarations[ $identity ] = array(
				'schedule' => $schedule,
				'task'     => WorkIdentity::compose( $owner, $schedule->task, true ),
			);
		}

		return $declarations;
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
		$raw = $this->wpdb->rows['a8csp_bgte_schedules'] ?? null;
		if ( \is_string( $raw ) ) {
			$registry = \maybe_unserialize( $raw );
			self::assertIsArray( $registry );
			$options['a8csp_bgte_schedules'] = $registry;
		}

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

		$registration = $registrations[ WorkIdentity::compose( $owner, $name, true ) ] ?? null;
		self::assertIsArray( $registration );

		return $registration;
	}

	// endregion.
}
