<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\LockRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Orchestrator;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\Cadence;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins schedule delivery, misfire, overlap, and immediate-run behavior.
 *
 */
#[CoversClass( Schedules::class )]
#[UsesClass( Cadence::class )]
#[UsesClass( Schedule::class )]
#[UsesClass( ScheduleRegistry::class )]
#[UsesClass( OccurrenceLease::class )]
#[UsesClass( Orchestrator::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( LockRows::class )]
#[UsesClass( StoreFactory::class )]
final class ScheduleExecutionTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const ARGS             = array( 'site_id' => 7 );
	private const ARGS_HASH        = 'd3e2a7f3f4041a96ec4e9d3de1622dea7c050a65d9ee0b77a49a76848fdd9737';
	private const INTERVAL         = 300;
	private const NAME             = 'nightly';
	private const NOW              = 1_700_000_000;
	private const OWNER            = 'owner-a';
	private const REGISTRATION_KEY = 'owner-a:nightly';
	private const RUN_ID           = '00000000001700000300-0000000000000000042';
	private const TASK             = 'refresh-index';

	private Schedules $api;
	private RecordingBackend $backend;
	private FixedClock $clock;
	private RecordingLogger $logger;
	private ScheduleRegistry $registry;
	private WpdbLockSpy $wpdb;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads the guarded WordPress seams required by the execution graph.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-options-stubs.php';
		require_once __DIR__ . '/wp-hook-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-time-constant-stubs.php';
		require_once __DIR__ . '/Scheduling/wp-json-encode-stub.php';
	}

	/**
	 * Constructs one registered target task and schedule API.
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
		$GLOBALS['a8csp_bgte_test_filter_values']         = array();
		$GLOBALS['a8csp_bgte_test_fired_actions']         = array();
		$GLOBALS['a8csp_bgte_test_action_callbacks']      = array();
		$GLOBALS['a8csp_bgte_test_action_throwables']     = array();
		$GLOBALS['a8csp_bgte_test_hooks']                 = array();
		$GLOBALS['a8csp_bgte_test_action_registrations']  = array();
		$GLOBALS['a8csp_bgte_test_blog_id']               = 1;
		$GLOBALS['a8csp_bgte_test_cache']                 = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']           = array();
		unset( $GLOBALS['a8csp_bgte_test_before_add_option'] );

		$this->backend  = new RecordingBackend();
		$this->clock    = new FixedClock( self::NOW );
		$this->logger   = new RecordingLogger();
		$this->wpdb     = new WpdbLockSpy();
		$this->registry = new ScheduleRegistry( new OptionRows( $this->wpdb ) );
		$tasks          = new TaskRegistry();
		$tasks->register( new RecordingTask( self::TASK ) );
		$orchestrator = new Orchestrator(
			$tasks,
			new BatchRegistry(),
			$this->backend,
			new OverlapGuard( $this->clock, $this->logger, new LockRows( $this->wpdb ) ),
			new StoreFactory( $this->clock, new OptionRows( $this->wpdb ) ),
			$this->logger,
			$this->clock,
			new RecordingRandomizer( 42 ),
		);
		$this->api    = new Schedules(
			$this->registry,
			$this->backend,
			$this->clock,
			$orchestrator,
			new OccurrenceLease( new LockRows( $this->wpdb ), $this->clock, new RecordingRandomizer( 42 ) ),
			$this->logger
		);
	}

	// endregion.

	// region TESTS.

	/**
	 * An on-time occurrence dispatches once and advances the persisted cadence token.
	 *
	 * @return  void
	 */
	public function test_on_time_occurrence_enqueues_and_advances_next_due(): void {
		$this->sync_schedule( $this->schedule() );
		$this->clock->timestamp = self::NOW + self::INTERVAL;

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array( 'enqueue_async' ), \array_column( $this->backend->calls, 'verb' ) );
		$scheduled_args = $this->backend->calls[0]['args']['args'] ?? null;
		self::assertIsArray( $scheduled_args );
		self::assertSame( self::RUN_ID, $scheduled_args[1] ?? null );
		self::assertSame(
			array(
				'fingerprint' => $this->schedule()->fingerprint(),
				'next_due'    => self::NOW + 2 * self::INTERVAL,
				'last_fired'  => self::NOW + self::INTERVAL,
				'misfires'    => 0,
				'skips'       => 0,
			),
			$this->registration()
		);
	}

	/**
	 * Accepted state is persisted and unlocked before an unbounded started listener can redeliver it.
	 *
	 * @return  void
	 */
	public function test_slow_started_listener_cannot_redispatch_the_accepted_occurrence(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Allow ) );
		$this->clock->timestamp = self::NOW + self::INTERVAL;

		$GLOBALS['a8csp_bgte_test_action_callbacks'] = array(
			'a8csp/background_tasks/started/' . self::TASK => function (): void {
				$this->clock->timestamp += 61;
				$this->api->handle_schedule_due( self::REGISTRATION_KEY );
			},
		);

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array( 'enqueue_async' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertSame( self::NOW + 2 * self::INTERVAL, $this->registration()['next_due'] ?? null );
		self::assertSame( self::NOW + self::INTERVAL, $this->registration()['last_fired'] ?? null );
		self::assertSame(
			'Stale schedule occurrence redelivery dropped after its next-due token advanced.',
			$this->logger->records[0]['message'] ?? null
		);
	}

	/**
	 * Accepted state is persisted before the started-history size filter can delay dispatch completion.
	 *
	 * @return  void
	 */
	public function test_slow_history_filter_cannot_redispatch_the_accepted_occurrence(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Allow ) );
		$this->clock->timestamp = self::NOW + self::INTERVAL;

		$GLOBALS['a8csp_bgte_test_filter_values'] = array(
			'a8csp/background_tasks/history_size' => function ( int $size ): int {
				$this->clock->timestamp += 61;
				$this->api->handle_schedule_due( self::REGISTRATION_KEY );

				return $size;
			},
		);

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array( 'enqueue_async' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertSame( self::NOW + 2 * self::INTERVAL, $this->registration()['next_due'] ?? null );
		self::assertSame( self::NOW + self::INTERVAL, $this->registration()['last_fired'] ?? null );
	}

	/**
	 * A started-listener failure does not reopen an occurrence whose backend action was accepted.
	 *
	 * @return  void
	 */
	public function test_started_listener_failure_keeps_the_accepted_occurrence_advanced(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Allow ) );
		$this->clock->timestamp = self::NOW + self::INTERVAL;

		$GLOBALS['a8csp_bgte_test_action_throwables'] = array(
			'a8csp/background_tasks/started/' . self::TASK => new \RuntimeException( 'listener failed' ),
		);

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array( 'enqueue_async' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertSame( self::NOW + 2 * self::INTERVAL, $this->registration()['next_due'] ?? null );
		self::assertSame( self::NOW + self::INTERVAL, $this->registration()['last_fired'] ?? null );
		self::assertSame(
			'Schedule occurrence could not enqueue its target task: {error}',
			$this->logger->records[0]['message'] ?? null
		);
	}

	/**
	 * A delivery inside the grace window remains a normal occurrence.
	 *
	 * @return  void
	 */
	public function test_within_grace_occurrence_enqueues_normally(): void {
		$this->sync_schedule( $this->schedule( catch_up: CatchUpPolicy::Skip ) );
		$this->clock->timestamp = self::NOW + 2 * self::INTERVAL - 1;

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array( 'enqueue_async' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertSame( self::NOW + 2 * self::INTERVAL, $this->registration()['next_due'] ?? null );
		self::assertSame( 0, $this->registration()['misfires'] ?? null );
	}

	/**
	 * A Skip occurrence exactly at next-due plus grace remains a normal run.
	 *
	 * @return  void
	 */
	public function test_skip_at_exact_grace_enqueues_without_recording_a_misfire(): void {
		$this->sync_schedule( $this->schedule( catch_up: CatchUpPolicy::Skip ) );
		$this->clock->timestamp = self::NOW + 2 * self::INTERVAL;

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array( 'enqueue_async' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertSame( 0, $this->registration()['misfires'] ?? null );
		self::assertSame( array(), $this->misfired_actions() );
	}

	/**
	 * The per-name misfire-grace filter receives the default, owner, and schedule name.
	 *
	 * @return  void
	 */
	public function test_misfire_grace_filter_receives_its_complete_payload(): void {
		$this->sync_schedule( $this->schedule() );
		$filter_args                              = null;
		$GLOBALS['a8csp_bgte_test_filter_values'] = array(
			'a8csp/background_tasks/misfire_grace/' . self::NAME =>
			static function ( int $grace, string $owner, string $name ) use ( &$filter_args ): int {
				$filter_args = array(
					'arity' => \func_num_args(),
					'args'  => array( $grace, $owner, $name ),
				);

				return $grace;
			},
		);
		$this->clock->timestamp                   = self::NOW + self::INTERVAL;

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame(
			array(
				'arity' => 3,
				'args'  => array( self::INTERVAL, self::OWNER, self::NAME ),
			),
			$filter_args
		);
	}

	/**
	 * RunOnce performs one make-up run and advances by whole intervals into the future.
	 *
	 * @return  void
	 */
	public function test_beyond_grace_run_once_enqueues_once_and_realigns_without_stacking(): void {
		$this->sync_schedule( $this->schedule( catch_up: CatchUpPolicy::RunOnce ) );
		$this->clock->timestamp = self::NOW + self::INTERVAL + 901;

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array( 'enqueue_async' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertSame( self::NOW + 5 * self::INTERVAL, $this->registration()['next_due'] ?? null );
		self::assertSame( self::NOW + self::INTERVAL + 901, $this->registration()['last_fired'] ?? null );
		self::assertSame( array(), $this->misfired_actions() );
	}

	/**
	 * Skip drops one late occurrence, fires both hooks, increments the counter, and realigns.
	 *
	 * @return  void
	 */
	public function test_beyond_grace_skip_records_the_misfire_without_enqueueing(): void {
		$this->sync_schedule( $this->schedule( catch_up: CatchUpPolicy::Skip ) );
		$fired_at               = self::NOW + self::INTERVAL + 901;
		$this->clock->timestamp = $fired_at;

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array(), $this->backend->calls );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/misfired/' . self::NAME,
					'args'      => array( self::OWNER, self::NOW + self::INTERVAL, $fired_at ),
				),
				array(
					'hook_name' => 'a8csp/background_tasks/misfired',
					'args'      => array( self::NAME, self::OWNER, self::NOW + self::INTERVAL, $fired_at ),
				),
			),
			$this->fired_actions()
		);
		self::assertSame( self::NOW + 5 * self::INTERVAL, $this->registration()['next_due'] ?? null );
		self::assertNull( $this->registration()['last_fired'] ?? null );
		self::assertSame( 1, $this->registration()['misfires'] ?? null );
		self::assertSame( 0, $this->registration()['skips'] ?? null );
	}

	/**
	 * A throwing misfire listener cannot prevent cadence realignment and counter persistence.
	 *
	 * @return  void
	 */
	public function test_misfire_listener_failure_is_logged_after_state_persists(): void {
		$this->sync_schedule( $this->schedule( catch_up: CatchUpPolicy::Skip ) );
		$GLOBALS['a8csp_bgte_test_action_throwables'] = array(
			'a8csp/background_tasks/misfired/' . self::NAME => new \RuntimeException( 'listener failed' ),
		);
		$this->clock->timestamp                       = self::NOW + self::INTERVAL + 901;

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( self::NOW + 5 * self::INTERVAL, $this->registration()['next_due'] ?? null );
		self::assertSame( 1, $this->registration()['misfires'] ?? null );
		self::assertSame(
			array(
				'a8csp/background_tasks/misfired/' . self::NAME,
				'a8csp/background_tasks/misfired',
			),
			\array_column( $this->fired_actions(), 'hook_name' )
		);
		self::assertSame( 'error', $this->logger->records[0]['level'] ?? null );
	}

	/**
	 * A missing registry row requests fast clearing and schedules a distinct cleanup delivery.
	 *
	 * @return  void
	 */
	public function test_unknown_registration_schedules_a_distinct_cleanup_delivery(): void {
		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array( 'unschedule', 'schedule_single' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertSame(
			array( self::REGISTRATION_KEY, 'cleanup' ),
			$this->backend->calls[1]['args']['args'] ?? null
		);
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame(
			'Unknown schedule registration "owner-a:nightly" was delivered; re-declare the schedule or remove the leftover occurrence.',
			$this->logger->records[0]['message'] ?? null
		);
	}

	/**
	 * A cleanup single clears only the recurring chain identity and never schedules a successor.
	 *
	 * @return  void
	 */
	public function test_cleanup_delivery_clears_the_recurring_identity_without_rescheduling(): void {
		$this->api->handle_schedule_due( self::REGISTRATION_KEY, 'cleanup' );

		self::assertSame( array( 'unschedule' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertSame( array( self::REGISTRATION_KEY ), $this->backend->calls[0]['args']['args'] ?? null );
		self::assertSame( array(), $this->logger->records );
	}

	/**
	 * A cleanup single warns once when the recurring chain still cannot be cleared.
	 *
	 * @return  void
	 */
	public function test_cleanup_delivery_rewarns_only_when_verified_clear_fails(): void {
		$this->backend->results['unschedule'] = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Repair the backend before retrying cleanup.'
			)
		);

		$this->api->handle_schedule_due( self::REGISTRATION_KEY, 'cleanup' );

		self::assertSame( array( 'unschedule' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
	}

	/**
	 * A concurrent redelivery observes the held occurrence lease and cannot enqueue a second run.
	 *
	 * @return  void
	 */
	public function test_concurrent_delivery_quietly_skips_while_occurrence_lease_is_held(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Allow ) );
		$this->clock->timestamp = self::NOW + self::INTERVAL;
		$this->wpdb->before_next(
			'select',
			function (): void {
				$this->api->handle_schedule_due( self::REGISTRATION_KEY );
			}
		);

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array( 'enqueue_async' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertSame( self::NOW + 2 * self::INTERVAL, $this->registration()['next_due'] ?? null );
		self::assertSame( 'debug', $this->logger->records[0]['level'] ?? null );
		self::assertSame(
			'Schedule occurrence skipped because its decision lease is held by a concurrent delivery.',
			$this->logger->records[0]['message'] ?? null
		);
	}

	/**
	 * A persisted owner absent from this request remains registered and is skipped quietly.
	 *
	 * @return  void
	 */
	public function test_inactive_owner_skips_without_unscheduling(): void {
		$this->sync_schedule( $this->schedule() );
		$this->api             = $this->new_api( new ScheduleRegistry( new OptionRows( $this->wpdb ) ) );
		$this->backend->calls  = array();
		$this->logger->records = array();

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array(), $this->backend->calls );
		self::assertSame( 'debug', $this->logger->records[0]['level'] ?? null );
	}

	/**
	 * The advanced next-due token drops a stale at-least-once redelivery.
	 *
	 * @return  void
	 */
	public function test_advanced_next_due_drops_a_stale_redelivery(): void {
		$this->sync_schedule( $this->schedule() );

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array(), $this->backend->calls );
		self::assertSame( 'debug', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::NOW + self::INTERVAL, $this->registration()['next_due'] ?? null );
	}

	/**
	 * A request-local declaration cannot dispatch after another request replaces its fingerprint.
	 *
	 * @return  void
	 */
	public function test_stale_request_declaration_does_not_dispatch_a_replaced_registration(): void {
		$this->sync_schedule( $this->schedule() );
		$current_api = $this->new_api( new ScheduleRegistry( new OptionRows( $this->wpdb ) ) );
		$current     = new Schedule(
			self::NAME,
			Cadence::every( 600 ),
			self::TASK,
			self::ARGS,
			OverlapPolicy::Allow,
			CatchUpPolicy::RunOnce,
			23
		);
		$result      = $current_api->sync( self::OWNER, array( $current ) );
		self::assertInstanceOf( Success::class, $result );
		$this->backend->calls   = array();
		$this->logger->records  = array();
		$this->clock->timestamp = self::NOW + 600;

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array(), $this->backend->calls );
		self::assertSame( 'debug', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $current->fingerprint(), $this->registration()['fingerprint'] ?? null );
	}

	/**
	 * A held Skip occurrence advances cadence and records a benign overlap skip.
	 *
	 * @return  void
	 */
	public function test_held_skip_occurrence_advances_and_increments_skips(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Skip ) );
		$this->seed_held_lock();
		$this->clock->timestamp = self::NOW + self::INTERVAL;

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array(), $this->backend->calls );
		self::assertSame( 1, $this->registration()['skips'] ?? null );
		self::assertSame( self::NOW + 2 * self::INTERVAL, $this->registration()['next_due'] ?? null );
		self::assertNull( $this->registration()['last_fired'] ?? null );
		self::assertSame( 'info', $this->logger->records[0]['level'] ?? null );
	}

	/**
	 * A failed post-enqueue registry write is logged so the next delivery can retry the token advance.
	 *
	 * @return  void
	 */
	public function test_successful_enqueue_logs_a_failed_registry_write(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Allow ) );
		$this->wpdb->script_result( 'update', false );
		$this->clock->timestamp = self::NOW + self::INTERVAL;

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array( 'enqueue_async' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'error', $this->logger->records[0]['level'] ?? null );
		self::assertSame(
			'Schedule occurrence state could not be persisted: {error}',
			$this->logger->records[0]['message'] ?? null
		);
	}

	/**
	 * A concurrently pruned registration discards delivery state without a repair-write error.
	 *
	 * @return  void
	 */
	public function test_concurrently_pruned_registration_logs_delivery_state_discard(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Allow ) );
		$this->wpdb->before_next(
			'update',
			static function (): void {
				$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
				self::assertIsArray( $options );

				$registry = $options['a8csp_bgte_schedules'] ?? null;
				self::assertIsArray( $registry );

				$owner = $registry[ self::OWNER ] ?? null;
				self::assertIsArray( $owner );
				unset( $owner[ self::NAME ] );

				$registry[ self::OWNER ]         = $owner;
				$options['a8csp_bgte_schedules'] = $registry;

				$GLOBALS['a8csp_bgte_test_options'] = $options;
			}
		);
		$this->clock->timestamp = self::NOW + self::INTERVAL;

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array( 'enqueue_async' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'debug', $this->logger->records[0]['level'] ?? null );
		self::assertSame(
			'Schedule registration pruned concurrently; delivery state discarded.',
			$this->logger->records[0]['message'] ?? null
		);
	}

	/**
	 * A dispatch failure leaves cadence timing unchanged for a later occurrence retry.
	 *
	 * @return  void
	 */
	public function test_dispatch_failure_preserves_occurrence_timing(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Allow ) );
		$before                                  = $this->registration();
		$this->backend->results['enqueue_async'] = new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				'Restore the scheduler before retrying.'
			)
		);
		$this->clock->timestamp                  = self::NOW + self::INTERVAL;

		$this->api->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( $before, $this->registration() );
		self::assertSame( 'error', $this->logger->records[0]['level'] ?? null );
	}

	/**
	 * Run-now dispatches immediately, records last-fired, and preserves cadence.
	 *
	 * @return  void
	 */
	public function test_run_now_updates_last_fired_without_touching_next_due(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Allow ) );
		$this->seed_held_lock();
		$next_due               = $this->registration()['next_due'] ?? null;
		$this->clock->timestamp = self::NOW + 10;

		$result = $this->api->run_now( self::OWNER, self::NAME );

		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );
		self::assertSame( $next_due, $this->registration()['next_due'] ?? null );
		self::assertSame( self::NOW + 10, $this->registration()['last_fired'] ?? null );
		self::assertSame( 'run-incumbent', $this->lock_owner( self::ARGS_HASH ) );
	}

	/**
	 * Run-now commits its accepted metadata and releases decision ownership before started listeners.
	 *
	 * @return  void
	 */
	public function test_run_now_persists_and_releases_before_started_hooks(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Allow ) );
		$this->clock->timestamp = self::NOW + 10;

		$observed = null;

		$GLOBALS['a8csp_bgte_test_action_callbacks'] = array(
			'a8csp/background_tasks/started/' . self::TASK => function () use ( &$observed ): void {
				$observed = array(
					'last_fired' => $this->registration()['last_fired'] ?? null,
					'lease_held' => \array_key_exists(
						'a8csp_bgte_lease_' . \hash( 'sha256', self::REGISTRATION_KEY ),
						$this->wpdb->rows
					),
				);
			},
		);

		$result = $this->api->run_now( self::OWNER, self::NAME );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				'last_fired' => self::NOW + 10,
				'lease_held' => false,
			),
			$observed
		);
	}

	/**
	 * A post-dispatch registry failure logs metadata loss without hiding the accepted run identifier.
	 *
	 * @return  void
	 */
	public function test_run_now_returns_success_after_a_failed_last_fired_write(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Allow ) );
		$this->wpdb->script_result( 'update', false );

		$result = $this->api->run_now( self::OWNER, self::NAME );

		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );
		self::assertSame( array( 'enqueue_async' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertSame( 'error', $this->logger->records[0]['level'] ?? null );
	}

	/**
	 * Run-now returns the held failure for Skip without changing registry timing.
	 *
	 * @return  void
	 */
	public function test_run_now_surfaces_skip_contention_without_changing_registry(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Skip ) );
		$this->seed_held_lock();
		$before = $this->registration();

		$result = $this->api->run_now( self::OWNER, self::NAME );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( EngineError::class, $result->error );
		self::assertStringContainsString( 'before dispatching the same arguments', $result->error->message );
		self::assertSame( $before, $this->registration() );
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * Run-now applies Replace ownership transfer to a held target lock.
	 *
	 * @return  void
	 */
	public function test_run_now_replace_takes_the_target_lock(): void {
		$this->sync_schedule( $this->schedule( overlap: OverlapPolicy::Replace ) );
		$this->seed_held_lock();

		$result = $this->api->run_now( self::OWNER, self::NAME );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( $result->value, $this->lock_owner( self::ARGS_HASH ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns one schedule declaration for the requested policies.
	 *
	 * @param   OverlapPolicy $overlap  Overlap policy.
	 * @param   CatchUpPolicy $catch_up Catch-up policy.
	 *
	 * @return  Schedule
	 */
	private function schedule(
		OverlapPolicy $overlap = OverlapPolicy::Skip,
		CatchUpPolicy $catch_up = CatchUpPolicy::RunOnce
	): Schedule {
		return new Schedule(
			self::NAME,
			Cadence::every( self::INTERVAL ),
			self::TASK,
			self::ARGS,
			$overlap,
			$catch_up,
			23
		);
	}

	/**
	 * Synchronizes one declaration and clears setup observations.
	 *
	 * @param   Schedule $schedule Schedule declaration.
	 *
	 * @return  void
	 */
	private function sync_schedule( Schedule $schedule ): void {
		$result = $this->api->sync( self::OWNER, array( $schedule ) );
		self::assertInstanceOf( Success::class, $result );

		$this->backend->calls                     = array();
		$this->logger->records                    = array();
		$GLOBALS['a8csp_bgte_test_fired_actions'] = array();
		$GLOBALS['a8csp_bgte_test_option_calls']  = array();
	}

	/**
	 * Returns another API over the same runtime seams.
	 *
	 * @param   ScheduleRegistry $registry Request-local schedule registry.
	 *
	 * @return  Schedules
	 */
	private function new_api( ScheduleRegistry $registry ): Schedules {
		$tasks = new TaskRegistry();
		$tasks->register( new RecordingTask( self::TASK ) );
		$orchestrator = new Orchestrator(
			$tasks,
			new BatchRegistry(),
			$this->backend,
			new OverlapGuard( $this->clock, $this->logger, new LockRows( $this->wpdb ) ),
			new StoreFactory( $this->clock, new OptionRows( $this->wpdb ) ),
			$this->logger,
			$this->clock,
			new RecordingRandomizer( 42 ),
		);

		return new Schedules(
			$registry,
			$this->backend,
			$this->clock,
			$orchestrator,
			new OccurrenceLease( new LockRows( $this->wpdb ), $this->clock, new RecordingRandomizer( 42 ) ),
			$this->logger
		);
	}

	/**
	 * Stores one fresh incumbent target lock and discovery pointer.
	 *
	 * @return  void
	 */
	private function seed_held_lock(): void {
		$raw = \maybe_serialize(
			array(
				'run_id'       => 'run-incumbent',
				'claimed_at'   => self::NOW,
				'heartbeat_at' => self::NOW,
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( 'a8csp_bgte_lock_' . self::TASK . '_' . self::ARGS_HASH, $raw );
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		$options[ 'a8csp_bgte_latest_' . self::TASK ] = array(
			'all'     => 'run-incumbent',
			'by_hash' => array( self::ARGS_HASH => 'run-incumbent' ),
		);
		$GLOBALS['a8csp_bgte_test_options']           = $options;
	}

	/**
	 * Returns the owner of one target lock.
	 *
	 * @param   string $args_hash Argument identity.
	 *
	 * @return  string|null
	 */
	private function lock_owner( string $args_hash ): ?string {
		$raw = $this->wpdb->rows[ 'a8csp_bgte_lock_' . self::TASK . '_' . $args_hash ] ?? null;
		if ( ! \is_string( $raw ) ) {
			return null;
		}

		$lock = \maybe_unserialize( $raw );

		return \is_array( $lock ) && \is_string( $lock['run_id'] ?? null )
			? $lock['run_id']
			: null;
	}

	/**
	 * Returns the persisted registration row.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function registration(): array {
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;
		self::assertIsArray( $options );
		$owners = $options['a8csp_bgte_schedules'] ?? null;
		self::assertIsArray( $owners );
		$schedules = $owners[ self::OWNER ] ?? null;
		self::assertIsArray( $schedules );
		$registration = $schedules[ self::NAME ] ?? null;
		self::assertIsArray( $registration );

		return $registration;
	}

	/**
	 * Returns fired consumer actions in order.
	 *
	 * @return  list<array{hook_name: string, args: list<mixed>}>
	 */
	private function fired_actions(): array {
		$actions = $GLOBALS['a8csp_bgte_test_fired_actions'] ?? null;
		self::assertIsArray( $actions );
		$typed = array();
		foreach ( $actions as $action ) {
			self::assertIsArray( $action );
			$hook_name = $action['hook_name'] ?? null;
			$args      = $action['args'] ?? null;
			self::assertIsString( $hook_name );
			self::assertIsArray( $args );
			$typed[] = array(
				'hook_name' => $hook_name,
				'args'      => \array_values( $args ),
			);
		}

		return $typed;
	}

	/**
	 * Returns only misfire lifecycle actions in delivery order.
	 *
	 * @return  list<array{hook_name: string, args: list<mixed>}>
	 */
	private function misfired_actions(): array {
		return \array_values(
			\array_filter(
				$this->fired_actions(),
				static fn ( array $action ): bool => \str_starts_with(
					$action['hook_name'],
					'a8csp/background_tasks/misfired'
				)
			)
		);
	}

	// endregion.
}
