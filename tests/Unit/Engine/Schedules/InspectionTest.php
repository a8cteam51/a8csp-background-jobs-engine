<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Schedules;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Inspection;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Tasks\TaskRegistry;
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

		require_once \dirname( __DIR__, 2 ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-lock-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-time-constant-stubs.php';
		require_once \dirname( __DIR__ ) . '/Scheduling/wp-json-encode-stub.php';
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
		$this->tasks      = new TaskRegistry();
		$this->batches    = new BatchRegistry();
		$this->backend    = new RecordingBackend();
		$this->wpdb       = new WpdbLockSpy();
		$rows             = new OptionRows( $this->wpdb );
		$this->schedules  = new ScheduleRegistry( $rows );
		$this->stores     = new StoreFactory( $this->clock, $rows );
		$scheduler        = new SchedulerFacade( array( $this->backend ) );
		$guard            = new OverlapGuard( $this->clock, new RecordingLogger(), new OptionRows( $this->wpdb ) );
		$lock_windows     = new LockWindows( $this->clock );
		$this->inspection = new Inspection(
			$this->schedules,
			$this->tasks,
			$this->batches,
			$scheduler,
			$guard,
			$this->stores,
			$rows,
			$lock_windows,
			$this->clock
		);
	}

	/**
	 * Schedule rows are owner-sorted and join declaration, union, and exact lock state.
	 *
	 * @return  void
	 */
	public function test_schedules_join_live_declarations_and_preserve_orphan_honesty(): void {
		$schedule = new Schedule(
			'nightly',
			Recurrence::every( 300 ),
			'refresh-index',
			array( 'scope' => 'all' )
		);
		$this->tasks->register( new RecordingTask( 'refresh-index' ) );
		self::assertTrue(
			$this->schedules->replace_owner(
				'owner-b',
				array(),
				array(
					'orphaned' => array(
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
				array( 'nightly' => $schedule ),
				array(
					'nightly' => array(
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
		$this->put_lock( 'refresh-index', $args_hash, 'run-lock', self::NOW );

		self::assertSame(
			array(
				'observed_at'       => self::NOW,
				'dormant_candidate' => false,
				'entries'           => array(
					array(
						'owner'      => 'owner-a',
						'name'       => 'nightly',
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
						'name'       => 'orphaned',
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
		self::assertSame(
			array( 'owner-b' ),
			\array_column( $this->inspection->schedules( 'owner-b' )['entries'], 'owner' )
		);
	}

	/**
	 * Present-but-unready backends mark an otherwise observable schedule snapshot as incomplete.
	 *
	 * @return  void
	 */
	public function test_schedule_snapshot_carries_the_dormant_candidate_branch(): void {
		$this->backend->ready = false;

		$snapshot = $this->inspection->schedules();

		self::assertTrue( $snapshot['dormant_candidate'] );
		self::assertSame( array(), $snapshot['entries'] );
	}

	/**
	 * Schedule locks distinguish undeclared, overlap-allowed, failed, absent, and malformed reads.
	 *
	 * @return  void
	 */
	public function test_schedule_locks_preserve_discriminated_honesty_states(): void {
		$schedules     = array(
			'allow'   => new Schedule(
				'allow',
				Recurrence::every( 300 ),
				'allow-task',
				array( 'case' => 'allow' ),
				OverlapPolicy::Allow
			),
			'failed'  => new Schedule(
				'failed',
				Recurrence::every( 300 ),
				'failed-task',
				array( 'case' => 'failed' )
			),
			'free'    => new Schedule(
				'free',
				Recurrence::every( 300 ),
				'free-task',
				array( 'case' => 'free' )
			),
			'invalid' => new Schedule(
				'invalid',
				Recurrence::every( 300 ),
				'invalid-task',
				array( 'case' => 'invalid' )
			),
		);
		$registrations = array();
		foreach ( $schedules as $name => $schedule ) {
			$registrations[ $name ] = array(
				'fingerprint' => $schedule->fingerprint(),
				'next_due'    => self::NOW + 300,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			);
		}
		self::assertTrue( $this->schedules->replace_owner( 'owner', $schedules, $registrations ) );
		$this->wpdb->put(
			'a8csp_bgte_lock_invalid-task_' . self::args_hash( array( 'case' => 'invalid' ) ),
			'not-a-lock-row'
		);
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted lock read failure';
			}
		);

		$locks = \array_column( $this->inspection->schedules()['entries'], 'lock', 'name' );

		self::assertSame( array( 'state' => 'overlap_allowed' ), $locks['allow'] );
		self::assertSame( array( 'state' => 'read_failed' ), $locks['failed'] );
		self::assertSame( array( 'state' => 'free' ), $locks['free'] );
		self::assertSame( array( 'state' => 'invalid' ), $locks['invalid'] );
	}

	/**
	 * Live rows preserve execution markers and the strict effective staleness boundary.
	 *
	 * @return  void
	 */
	public function test_runs_expose_phase_queue_kind_and_strict_staleness(): void {
		$this->tasks->register( new RecordingTask( 'email-digest' ) );
		$fresh_id    = self::run_id( 1 );
		$stale_id    = self::run_id( 2 );
		$store       = $this->stores->run_store( 'email-digest' );
		$fresh_state = $store->create( $fresh_id, array(), 'hash-fresh', array( array() ) );
		$stale_state = $store->create( $stale_id, array(), 'hash-stale', array( array() ) );
		self::assertNotNull( $fresh_state );
		self::assertNotNull( $stale_state );
		self::assertIsString(
			$store->transition_state(
				$fresh_id,
				$fresh_state,
				$fresh_state
					->with_heartbeat_at( self::NOW - 15 * \MINUTE_IN_SECONDS )
					->with_executing( true )
			)
		);
		self::assertIsString(
			$store->transition_state(
				$stale_id,
				$stale_state,
				$stale_state->with_heartbeat_at( self::NOW - 15 * \MINUTE_IN_SECONDS - 1 )
			)
		);

		$snapshot = $this->inspection->runs( 'email-digest' );

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
		$this->batches->register( new RecordingBatch( 'catalog-sync' ) );
		$live_id = self::run_id( 1 );
		$store   = $this->stores->run_store( 'catalog-sync' );
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
		$options[ 'a8csp_bgte_run_catalog-sync_' . self::run_id( 99 ) ] = 'not-a-run-row';

		$GLOBALS['a8csp_bgte_test_options'] = $options;

		$history = $this->stores->run_history( 'catalog-sync' );
		$history->record_started( 'run-completed', 'hash-completed' );
		$history->record_started( 'run-failed', 'hash-failed' );
		$history->record_started( $live_id, 'hash-live' );
		$history->record_terminal( 'run-completed', 'hash-completed', RunStatus::Completed );
		$history->record_terminal( 'run-failed', 'hash-failed', RunStatus::Failed );
		$this->stores->failed_run_store( 'catalog-sync' )->record(
			'run-failed',
			self::NOW - 1,
			array(),
			2,
			new EngineError( 'Retained failure.' )
		);

		$snapshot = $this->inspection->runs( 'catalog-sync' );

		self::assertCount( 1, $snapshot['live'] );
		self::assertSame( 'batch', $snapshot['live'][0]['kind'] );
		self::assertSame( 2, $snapshot['live'][0]['queue_depth'] );
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
	 * Canonical suffix parsing keeps prefix-colliding background-work names isolated.
	 *
	 * @return  void
	 */
	public function test_run_enumeration_requires_the_exact_parsed_name(): void {
		$requested_id = self::run_id( 1 );
		$foreign_id   = self::run_id( 2 );
		$this->tasks->register( new RecordingTask( 'foo' ) );
		$this->tasks->register( new RecordingTask( 'foo_bar' ) );
		self::assertNotNull(
			$this->stores->run_store( 'foo' )->create( $requested_id, array(), 'foo-hash', array( array() ) )
		);
		self::assertNotNull(
			$this->stores->run_store( 'foo_bar' )->create( $foreign_id, array(), 'foo-bar-hash', array( array() ) )
		);
		self::assertSame(
			array(
				'name'   => 'foo_bar',
				'run_id' => $foreign_id,
			),
			Inspection::run_identity_from_option_name( 'a8csp_bgte_run_foo_bar_' . $foreign_id )
		);

		$snapshot = $this->inspection->runs( 'foo' );

		self::assertSame( array( $requested_id ), \array_column( $snapshot['live'], 'run_id' ) );
		self::assertSame( 1, $snapshot['live_scanned'] );
		self::assertSame( 0, $snapshot['live_uninspected'] );
	}

	/**
	 * Live-run inspection caps authoritative row reads and reports the exact uninspected count.
	 *
	 * @return  void
	 */
	public function test_run_enumeration_is_bounded_with_explicit_truncation_counts(): void {
		$this->tasks->register( new RecordingTask( 'many-runs' ) );
		$store = $this->stores->run_store( 'many-runs' );
		for ( $sequence = 1; $sequence <= 24; ++$sequence ) {
			self::assertNotNull(
				$store->create( self::run_id( $sequence ), array(), 'hash-' . $sequence, array( array() ) )
			);
		}

		$snapshot = $this->inspection->runs( 'many-runs' );

		self::assertNull( $snapshot['live_error'] );
		self::assertSame( 20, $snapshot['live_scanned'] );
		self::assertSame( 4, $snapshot['live_uninspected'] );
		self::assertSame(
			\array_map( static fn ( int $sequence ): string => self::run_id( $sequence ), \range( 1, 20 ) ),
			\array_column( $snapshot['live'], 'run_id' )
		);
	}

	/**
	 * Enumeration and per-row database failures remain corrective unknown states.
	 *
	 * @return  void
	 */
	public function test_run_read_failures_do_not_collapse_into_absence(): void {
		$this->wpdb->before_next(
			'count',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted enumeration failure';
			}
		);

		$enumeration_failure = $this->inspection->runs( 'failed-enumeration' );

		self::assertSame( 'enumeration_failed', $enumeration_failure['live_error'] );
		self::assertSame( array(), $enumeration_failure['live'] );
		self::assertSame( array(), $enumeration_failure['history'] );

		$this->wpdb->before_next(
			'scan',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted bounded scan failure';
			}
		);

		$scan_failure = $this->inspection->runs( 'failed-scan' );

		self::assertSame( 'enumeration_failed', $scan_failure['live_error'] );
		self::assertSame( array(), $scan_failure['live'] );
		self::assertSame( array(), $scan_failure['history'] );

		$run_id = self::run_id( 1 );
		self::assertNotNull(
			$this->stores->run_store( 'failed-row' )->create( $run_id, array(), 'hash', array( array() ) )
		);
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted run read failure';
			}
		);

		$row_failure = $this->inspection->runs( 'failed-row' );

		self::assertSame( 'read_failed', $row_failure['live_error'] );
		self::assertSame( array(), $row_failure['live'] );
		self::assertSame( array(), $row_failure['history'] );
	}

	/**
	 * Undeclared and ambiguously declared live work retains unknown kind and observable queue depth.
	 *
	 * @return  void
	 */
	public function test_unknown_work_kind_preserves_queue_depth(): void {
		$orphan_id = self::run_id( 1 );
		self::assertNotNull(
			$this->stores->run_store( 'orphaned' )->create(
				$orphan_id,
				array(),
				'orphaned-hash',
				array( array( 'page' => 1 ), array( 'page' => 2 ) )
			)
		);

		$ambiguous_id = self::run_id( 2 );
		$this->tasks->register( new RecordingTask( 'ambiguous' ) );
		$this->batches->register( new RecordingBatch( 'ambiguous' ) );
		self::assertNotNull(
			$this->stores->run_store( 'ambiguous' )->create(
				$ambiguous_id,
				array(),
				'ambiguous-hash',
				array( array( 'page' => 1 ) )
			)
		);

		$orphaned  = $this->inspection->runs( 'orphaned' )['live'][0];
		$ambiguous = $this->inspection->runs( 'ambiguous' )['live'][0];

		self::assertSame( 'unknown', $orphaned['kind'] );
		self::assertSame( 2, $orphaned['queue_depth'] );
		self::assertSame( 'unknown', $ambiguous['kind'] );
		self::assertSame( 1, $ambiguous['queue_depth'] );
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
	 * Returns the engine's exact scalar-tree argument identity.
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
	 * Persists one exact complete lock row.
	 *
	 * @param   string $name         Stable background-work name.
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
