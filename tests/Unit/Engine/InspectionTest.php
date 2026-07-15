<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Inspection;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\WorkRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the read-only joins over live schedule, lock, run, and history collaborators.
 */
#[CoversClass( Inspection::class )]
#[UsesClass( ScheduleRegistry::class )]
#[UsesClass( SchedulerFacade::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( LockWindows::class )]
#[UsesClass( StoreFactory::class )]
final class InspectionTest extends TestCase {
	private const NOW = 1_700_000_000;

	private RecordingBackend $backend;
	private BatchRegistry $batches;
	private FixedClock $clock;
	private Inspection $inspection;
	private ScheduleRegistry $schedules;
	private StoreFactory $stores;
	private TaskRegistry $tasks;
	private WpdbLockSpy $wpdb;

	/**
	 * Loads the guarded WordPress seams required by the live collaborators.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-lock-stubs.php';
		require_once \dirname( __DIR__ ) . '/wp-time-constant-stubs.php';
		require_once __DIR__ . '/Backends/wp-json-encode-stub.php';
	}

	/**
	 * Constructs one deterministic live inspection graph.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']         = array();
		$GLOBALS['a8csp_bgte_test_option_calls']    = array();
		$GLOBALS['a8csp_bgte_test_option_autoload'] = array();
		$GLOBALS['a8csp_bgte_test_filter_values']   = array();
		$GLOBALS['a8csp_bgte_test_blog_id']         = 1;
		$GLOBALS['a8csp_bgte_test_cache']           = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']     = array();

		$this->clock      = new FixedClock( self::NOW );
		$work             = new WorkRegistry();
		$this->tasks      = new TaskRegistry( $work );
		$this->batches    = new BatchRegistry( $work );
		$this->backend    = new RecordingBackend();
		$this->wpdb       = new WpdbLockSpy();
		$rows             = new OptionRows( $this->wpdb );
		$this->schedules  = new ScheduleRegistry( $rows );
		$this->stores     = new StoreFactory( $this->clock, $rows );
		$scheduler        = new SchedulerFacade( array( $this->backend ) );
		$guard            = new OverlapGuard( $this->clock, new RecordingLogger(), new OptionRows( $this->wpdb ) );
		$lock_windows     = new LockWindows( $this->clock );
		$this->inspection = new Inspection( $this->schedules, $this->tasks, $this->batches, $scheduler, $guard, $this->stores, $rows, $lock_windows, $this->clock );
	}

	/**
	 * Schedule rows are owner-sorted and join declaration, union, and exact lock state.
	 *
	 * @return  void
	 */
	public function test_schedules_join_live_declarations_and_preserve_orphan_honesty(): void {
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', array( 'scope' => 'all' ) );
		$this->tasks->register( 'owner-a:refresh-index', new RecordingTask( 'refresh-index' ) );
		self::assertTrue(
			$this->schedules->replace_owner(
				'owner-b',
				array(),
				array(
					'owner-b:orphaned' => array(
						'fingerprint' => 'orphaned-fingerprint',
						'next_due'    => self::NOW + 600,
						'last_fired'  => null,
						'misfires'    => 4,
						'skips'       => 5,
					),
				)
			)
		);
		self::assertTrue(
			$this->schedules->replace_owner(
				'owner-a',
				self::declarations( 'owner-a', $schedule ),
				array(
					'owner-a:nightly' => array(
						'fingerprint' => $schedule->fingerprint(),
						'next_due'    => self::NOW + 300,
						'last_fired'  => self::NOW - 60,
						'misfires'    => 1,
						'skips'       => 2,
					),
				)
			)
		);
		$this->backend->scheduled = true;
		$args_hash                = self::args_hash( $schedule->args );
		$this->put_lock( 'owner-a:refresh-index', $args_hash, 'run-lock', self::NOW );

		self::assertSame(
			array(
				'observed_at'       => self::NOW,
				'dormant_candidate' => false,
				'entries'           => array(
					array(
						'owner'      => 'owner-a',
						'name'       => 'owner-a:nightly',
						'recurrence' => 300,
						'next_due'   => self::NOW + 300,
						'last_fired' => self::NOW - 60,
						'misfires'   => 1,
						'skips'      => 2,
						'scheduled'  => true,
						'lock'       => array(
							'state'  => 'held',
							'run_id' => 'run-lock',
							'stale'  => false,
						),
					),
					array(
						'owner'      => 'owner-b',
						'name'       => 'owner-b:orphaned',
						'recurrence' => null,
						'next_due'   => self::NOW + 600,
						'last_fired' => null,
						'misfires'   => 4,
						'skips'      => 5,
						'scheduled'  => true,
						'lock'       => array( 'state' => 'not_declared' ),
					),
				),
			),
			$this->inspection->schedules()
		);
		$owner_snapshot = $this->inspection->schedules( 'owner-b' );
		self::assertNotNull( $owner_snapshot );
		self::assertSame( array( 'owner-b' ), \array_column( $owner_snapshot['entries'], 'owner' ) );
	}

	/**
	 * Present-but-unready backends mark an otherwise observable schedule snapshot as incomplete.
	 *
	 * @return  void
	 */
	public function test_schedule_snapshot_carries_the_dormant_candidate_branch(): void {
		$this->backend->ready = false;

		$snapshot = $this->inspection->schedules();

		self::assertNotNull( $snapshot );
		self::assertTrue( $snapshot['dormant_candidate'] );
		self::assertSame( array(), $snapshot['entries'] );
	}

	/**
	 * An unreadable registry reports schedule inspection as unavailable instead of empty.
	 *
	 * @return  void
	 */
	public function test_schedules_report_an_authoritative_registry_read_failure(): void {
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted schedule inspection read failure';
			}
		);

		self::assertNull( $this->inspection->schedules() );
	}

	/**
	 * Schedule locks distinguish undeclared, overlap-allowed, failed, absent, and malformed reads.
	 *
	 * @return  void
	 */
	public function test_schedule_locks_preserve_discriminated_honesty_states(): void {
		$schedules     = array(
			'allow'   => new Schedule( 'allow', Recurrence::every( 300 ), 'allow-task', array( 'case' => 'allow' ), OverlapPolicy::Allow ),
			'failed'  => new Schedule( 'failed', Recurrence::every( 300 ), 'failed-task', array( 'case' => 'failed' ) ),
			'free'    => new Schedule( 'free', Recurrence::every( 300 ), 'free-task', array( 'case' => 'free' ) ),
			'invalid' => new Schedule( 'invalid', Recurrence::every( 300 ), 'invalid-task', array( 'case' => 'invalid' ) ),
		);
		$registrations = array();
		foreach ( $schedules as $name => $schedule ) {
			$registrations[ 'owner:' . $name ] = array(
				'fingerprint' => $schedule->fingerprint(),
				'next_due'    => self::NOW + 300,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			);
		}
		self::assertTrue( $this->schedules->replace_owner( 'owner', self::declarations( 'owner', ...\array_values( $schedules ) ), $registrations ) );
		$this->wpdb->put( 'a8csp_bgte_lock_owner:invalid-task_' . self::args_hash( array( 'case' => 'invalid' ) ), 'not-a-lock-row' );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted lock read failure';
			}
		);

		$snapshot = $this->inspection->schedules();
		self::assertNotNull( $snapshot );
		$locks = \array_column( $snapshot['entries'], 'lock', 'name' );

		self::assertSame( array( 'state' => 'overlap_allowed' ), $locks['owner:allow'] );
		self::assertSame( array( 'state' => 'read_failed' ), $locks['owner:failed'] );
		self::assertSame( array( 'state' => 'free' ), $locks['owner:free'] );
		self::assertSame( array( 'state' => 'invalid' ), $locks['owner:invalid'] );
	}

	/**
	 * Live rows preserve execution markers and the strict effective staleness boundary.
	 *
	 * @return  void
	 */
	public function test_runs_expose_phase_queue_kind_and_strict_staleness(): void {
		$identity = 'owner:email-digest';
		$this->tasks->register( $identity, new RecordingTask( 'email-digest' ) );
		$fresh_id    = self::run_id( 1 );
		$stale_id    = self::run_id( 2 );
		$store       = $this->stores->run_store( $identity );
		$fresh_state = $store->create( $fresh_id, array(), 'hash-fresh', array( array() ) );
		$stale_state = $store->create( $stale_id, array(), 'hash-stale', array( array() ) );
		self::assertNotNull( $fresh_state );
		self::assertNotNull( $stale_state );
		self::assertIsString( $store->transition_state( $fresh_id, $fresh_state, $fresh_state->with_heartbeat_at( self::NOW - 15 * \MINUTE_IN_SECONDS )->with_executing( true ) ) );
		self::assertIsString( $store->transition_state( $stale_id, $stale_state, $stale_state->with_heartbeat_at( self::NOW - 15 * \MINUTE_IN_SECONDS - 1 ) ) );

		$snapshot = $this->inspection->runs( $identity );

		self::assertSame( self::NOW, $snapshot['observed_at'] );
		self::assertNull( $snapshot['live_error'] );
		self::assertSame( 2, $snapshot['live_scanned'] );
		self::assertSame( 0, $snapshot['live_uninspected'] );
		self::assertSame(
			array(
				array(
					'run_id'       => $fresh_id,
					'kind'         => 'task',
					'status'       => 'running',
					'executing'    => true,
					'attempts'     => 0,
					'queue_depth'  => null,
					'heartbeat_at' => self::NOW - 15 * \MINUTE_IN_SECONDS,
					'stale'        => false,
				),
				array(
					'run_id'       => $stale_id,
					'kind'         => 'task',
					'status'       => 'running',
					'executing'    => false,
					'attempts'     => 0,
					'queue_depth'  => null,
					'heartbeat_at' => self::NOW - 15 * \MINUTE_IN_SECONDS - 1,
					'stale'        => true,
				),
			),
			$snapshot['live']
		);
	}

	/**
	 * Batch depth, corrupt-row skipping, terminal recency, and failed retention share one read.
	 *
	 * @return  void
	 */
	public function test_runs_merge_valid_live_rows_and_bounded_history(): void {
		$identity = 'owner:catalog-sync';
		$this->batches->register( $identity, new RecordingBatch( 'catalog-sync' ) );
		$live_id = self::run_id( 1 );
		$store   = $this->stores->run_store( $identity );
		$state   = $store->create(
			$live_id,
			array(),
			'hash-live',
			array(
				array( 'page' => 1 ),
				array( 'page' => 2 ),
			)
		);
		self::assertNotNull( $state );
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		$options[ 'a8csp_bgte_run_' . $identity . '_' . self::run_id( 99 ) ] = 'not-a-run-row';

		$GLOBALS['a8csp_bgte_test_options'] = $options;

		$history = $this->stores->run_history( $identity );
		self::assertTrue( $history->record_started( 'run-completed', 'hash-completed' ) );
		self::assertTrue( $history->record_started( 'run-failed', 'hash-failed' ) );
		self::assertTrue( $history->record_started( $live_id, 'hash-live' ) );
		self::assertTrue( $history->record_terminal( 'run-completed', 'hash-completed', RunStatus::Completed ) );
		self::assertTrue( $history->record_terminal( 'run-failed', 'hash-failed', RunStatus::Failed ) );
		self::assertTrue( $this->stores->failed_run_store( $identity )->record( 'run-failed', self::NOW - 1, array(), 2, new EngineError( 'Retained failure.' ), new RunFailure( name: $identity, run_id: 'run-failed', attempts: 2, stage: 'execution', code: ApiErrorCode::ExecutionFailed, summary: 'Retained failure.', failed_chunk: null, ) ) );

		$snapshot = $this->inspection->runs( $identity );

		self::assertCount( 1, $snapshot['live'] );
		self::assertSame( 'batch', $snapshot['live'][0]['kind'] );
		self::assertSame( 2, $snapshot['live'][0]['queue_depth'] );
		self::assertNotNull( $snapshot['history'] );
		self::assertSame(
			array(
				array(
					'run_id'   => 'run-failed',
					'outcome'  => 'failed',
					'retained' => true,
				),
				array(
					'run_id'   => 'run-completed',
					'outcome'  => 'completed',
					'retained' => false,
				),
				array(
					'run_id'   => $live_id,
					'outcome'  => 'started',
					'retained' => false,
				),
			),
			$snapshot['history']
		);
	}

	/**
	 * Last-completed inspection follows recording order and skips every other terminal outcome.
	 *
	 * @return  void
	 */
	public function test_last_completed_run_uses_terminal_recording_order(): void {
		$identity          = 'owner:recording-order';
		$recorded_first    = self::run_id( 99 );
		$recorded_last     = self::run_id( 1 );
		$terminal_outcomes = array(
			array( $recorded_first, RunStatus::Completed ),
			array( $recorded_last, RunStatus::Completed ),
			array( self::run_id( 100 ), RunStatus::Failed ),
			array( self::run_id( 101 ), RunStatus::Cancelled ),
			array( self::run_id( 102 ), RunStatus::Superseded ),
		);
		$history           = $this->stores->run_history( $identity );

		foreach ( $terminal_outcomes as [ $run_id, $status ] ) {
			self::assertTrue( $history->record_terminal( $run_id, 'shared-hash', $status ) );
		}

		$result = $this->inspection->last_completed_run( $identity );
		if ( $result->is_failure() ) {
			self::fail( 'The recording-order inspection returned an unexpected failure.' );
		}

		self::assertSame( $recorded_last, $result->value );
	}

	/**
	 * An unreadable failed-run store marks history unavailable instead of reporting no history.
	 *
	 * @return  void
	 */
	public function test_runs_report_failed_store_history_as_unavailable(): void {
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted failed-run inspection failure';
			}
		);

		$snapshot = $this->inspection->runs( 'owner:unavailable-history' );

		self::assertNull( $snapshot['live_error'] );
		self::assertSame( array(), $snapshot['live'] );
		self::assertNull( $snapshot['history'] );
	}

	/**
	 * Canonical suffix parsing keeps prefix-colliding background-work names isolated.
	 *
	 * @return  void
	 */
	public function test_run_enumeration_requires_the_exact_parsed_name(): void {
		$requested_id = self::run_id( 1 );
		$foreign_id   = self::run_id( 2 );
		$this->tasks->register( 'owner:foo', new RecordingTask( 'foo' ) );
		$this->tasks->register( 'owner:foo_bar', new RecordingTask( 'foo_bar' ) );
		self::assertNotNull( $this->stores->run_store( 'owner:foo' )->create( $requested_id, array(), 'foo-hash', array( array() ) ) );
		self::assertNotNull( $this->stores->run_store( 'owner:foo_bar' )->create( $foreign_id, array(), 'foo-bar-hash', array( array() ) ) );
		self::assertSame(
			array(
				'name'   => 'owner:foo_bar',
				'run_id' => $foreign_id,
			),
			RunIdentity::from_option_name( 'a8csp_bgte_run_owner:foo_bar_' . $foreign_id )
		);

		$snapshot = $this->inspection->runs( 'owner:foo' );

		self::assertSame( array( $requested_id ), \array_column( $snapshot['live'], 'run_id' ) );
		self::assertSame( 1, $snapshot['live_scanned'] );
		self::assertSame( 0, $snapshot['live_uninspected'] );
	}

	/**
	 * Malformed fixed-width candidates cannot consume the valid live-run inspection cap.
	 *
	 * @return  void
	 */
	public function test_run_enumeration_skips_malformed_candidates_before_valid_rows(): void {
		$identity = 'owner:malformed-leading';
		$run_id   = self::run_id( 1 );
		self::assertNotNull( $this->stores->run_store( $identity )->create( $run_id, array(), 'valid-hash', array( array() ) ) );

		$prefix = 'a8csp_bgte_run_' . $identity . '_';
		for ( $sequence = 1; $sequence <= 20; ++$sequence ) {
			$this->wpdb->put( $prefix . \sprintf( '!%039d', $sequence ), 'malformed-run-row' );
		}

		$snapshot = $this->inspection->runs( $identity );

		self::assertSame( array( $run_id ), \array_column( $snapshot['live'], 'run_id' ) );
		self::assertSame( 1, $snapshot['live_scanned'] );
		self::assertSame( 0, $snapshot['live_uninspected'] );
	}

	/**
	 * Live-run inspection caps authoritative row reads and reports the exact uninspected count.
	 *
	 * @return  void
	 */
	public function test_run_enumeration_is_bounded_with_explicit_truncation_counts(): void {
		$this->tasks->register( 'owner:many-runs', new RecordingTask( 'many-runs' ) );
		$store = $this->stores->run_store( 'owner:many-runs' );
		for ( $sequence = 1; $sequence <= 24; ++$sequence ) {
			self::assertNotNull( $store->create( self::run_id( $sequence ), array(), 'hash-' . $sequence, array( array() ) ) );
		}

		$snapshot = $this->inspection->runs( 'owner:many-runs' );

		self::assertNull( $snapshot['live_error'] );
		self::assertSame( 20, $snapshot['live_scanned'] );
		self::assertSame( 4, $snapshot['live_uninspected'] );
		self::assertSame( \array_map( static fn ( int $sequence ): string => self::run_id( $sequence ), \range( 1, 20 ) ), \array_column( $snapshot['live'], 'run_id' ) );
	}

	/**
	 * Enumeration and per-row database failures remain corrective unknown states.
	 *
	 * @return  void
	 */
	public function test_run_read_failures_do_not_collapse_into_absence(): void {
		$this->wpdb->before_next(
			'scan',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted enumeration failure';
			}
		);

		$enumeration_failure = $this->inspection->runs( 'owner:failed-enumeration' );

		self::assertSame( 'enumeration_failed', $enumeration_failure['live_error'] );
		self::assertSame( array(), $enumeration_failure['live'] );
		self::assertSame( array(), $enumeration_failure['history'] );

		$scan_prefix = 'a8csp_bgte_run_owner:failed-scan_';
		for ( $sequence = 1; $sequence <= 20; ++$sequence ) {
			$this->wpdb->put( $scan_prefix . \sprintf( '!%039d', $sequence ), 'malformed-run-row' );
		}
		$this->wpdb->before_next( 'scan', static function (): void {} );
		$this->wpdb->before_next(
			'scan',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted bounded scan failure';
			}
		);

		$scan_failure = $this->inspection->runs( 'owner:failed-scan' );

		self::assertSame( 'enumeration_failed', $scan_failure['live_error'] );
		self::assertSame( array(), $scan_failure['live'] );
		self::assertSame( array(), $scan_failure['history'] );

		$run_id = self::run_id( 1 );
		self::assertNotNull( $this->stores->run_store( 'owner:failed-row' )->create( $run_id, array(), 'hash', array( array() ) ) );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted run read failure';
			}
		);

		$row_failure = $this->inspection->runs( 'owner:failed-row' );

		self::assertSame( 'read_failed', $row_failure['live_error'] );
		self::assertSame( array(), $row_failure['live'] );
		self::assertSame( array(), $row_failure['history'] );
	}

	/**
	 * Complete identities keep work kinds owner-qualified while undeclared work remains unknown.
	 *
	 * @return  void
	 */
	public function test_work_kind_is_owner_qualified_and_unknown_preserves_queue_depth(): void {
		$orphan_id = self::run_id( 1 );
		self::assertNotNull( $this->stores->run_store( 'owner:orphaned' )->create( $orphan_id, array(), 'orphaned-hash', array( array( 'page' => 1 ), array( 'page' => 2 ) ) ) );

		$task_id = self::run_id( 2 );
		$this->tasks->register( 'owner-a:shared', new RecordingTask( 'shared' ) );
		self::assertNotNull( $this->stores->run_store( 'owner-a:shared' )->create( $task_id, array(), 'task-hash', array( array( 'page' => 1 ) ) ) );
		$batch_id = self::run_id( 3 );
		$this->batches->register( 'owner-b:shared', new RecordingBatch( 'shared' ) );
		self::assertNotNull( $this->stores->run_store( 'owner-b:shared' )->create( $batch_id, array(), 'batch-hash', array( array( 'page' => 1 ) ) ) );

		$orphaned = $this->inspection->runs( 'owner:orphaned' )['live'][0];
		$task     = $this->inspection->runs( 'owner-a:shared' )['live'][0];
		$batch    = $this->inspection->runs( 'owner-b:shared' )['live'][0];

		self::assertSame( 'unknown', $orphaned['kind'] );
		self::assertSame( 2, $orphaned['queue_depth'] );
		self::assertSame( 'task', $task['kind'] );
		self::assertNull( $task['queue_depth'] );
		self::assertSame( 'batch', $batch['kind'] );
		self::assertSame( 1, $batch['queue_depth'] );
	}

	/**
	 * Returns one canonical fixed-width run identifier.
	 *
	 * @param   int $sequence Deterministic random-suffix stand-in.
	 *
	 * @return  string
	 */
	private static function run_id( int $sequence ): string {
		return \sprintf( '%020d-%019d', self::NOW, $sequence );
	}

	/**
	 * Returns the engine's exact identity for portable arguments.
	 *
	 * @param   array<array-key, mixed> $args Start arguments.
	 *
	 * @return  string
	 */
	private static function args_hash( array $args ): string {
		$encoded = \wp_json_encode( $args, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION );
		self::assertIsString( $encoded );

		return \hash( 'sha256', $encoded );
	}

	/**
	 * Returns request-local declarations keyed by complete schedule identity.
	 *
	 * @param   string   $owner     Owner identifier.
	 * @param   Schedule ...$schedules Schedule value objects.
	 *
	 * @return  array<string, array{schedule: Schedule, task: string}>
	 */
	private static function declarations( string $owner, Schedule ...$schedules ): array {
		$declarations = array();
		foreach ( $schedules as $schedule ) {
			$declarations[ $owner . ':' . $schedule->name ] = array(
				'schedule' => $schedule,
				'task'     => $owner . ':' . $schedule->task,
			);
		}

		return $declarations;
	}

	/**
	 * Persists one exact complete lock row.
	 *
	 * @param   string $name         Complete background-work identity.
	 * @param   string $args_hash    Stable argument identity.
	 * @param   string $run_id       Owning run identifier.
	 * @param   int    $heartbeat_at Latest heartbeat timestamp.
	 *
	 * @return  void
	 */
	private function put_lock( string $name, string $args_hash, string $run_id, int $heartbeat_at ): void {
		$this->wpdb->put(
			'a8csp_bgte_lock_' . $name . '_' . $args_hash,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The fixture matches the serialized lock-row storage contract.
			\serialize(
				array(
					'run_id'       => $run_id,
					'claimed_at'   => $heartbeat_at,
					'heartbeat_at' => $heartbeat_at,
				)
			)
		);
	}
}
