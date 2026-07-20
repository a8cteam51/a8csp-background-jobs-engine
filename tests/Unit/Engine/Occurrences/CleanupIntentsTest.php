<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Engine\Occurrences;

use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\Schedules;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingRandomizer;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins durable cleanup-intent storage and unknown-chain convergence.
 *
 */
#[CoversClass( CleanupIntents::class )]
#[UsesClass( Schedules::class )]
#[UsesClass( OccurrenceDelivery::class )]
#[UsesClass( Recurrence::class )]
#[UsesClass( Schedule::class )]
#[UsesClass( ScheduleRegistry::class )]
#[UsesClass( OccurrenceLease::class )]
#[UsesClass( Dispatcher::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( StoreFactory::class )]
#[UsesClass( LifecycleEffects::class )]
final class CleanupIntentsTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS              = array( 'site_id' => 7 );
	private const int INTERVAL            = 300;
	private const string NAME             = 'nightly';
	private const int NOW                 = 1_700_000_000;
	private const string OWNER            = 'owner-a';
	private const string REGISTRATION_KEY = 'owner-a:nightly';
	private const string JOB              = 'refresh-index';
	private const string JOB_IDENTITY     = 'owner-a:refresh-index';

	private Schedules $api;
	private RecordingBackend $backend;
	private FixedClock $clock;
	private CleanupIntents $cleanup_intents;
	private OccurrenceDelivery $delivery;
	private RecordingLogger $logger;
	private ScheduleRegistry $registry;
	private WpdbLockSpy $wpdb;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads the guarded WordPress seams required by the cleanup graph.
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
		require_once \dirname( __DIR__ ) . '/Backends/wp-json-encode-stub.php';
	}

	/**
	 * Constructs one cleanup-intent boundary and its delivery producer.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgje_test_options']               = array();
		$GLOBALS['a8csp_bgje_test_option_calls']          = array();
		$GLOBALS['a8csp_bgje_test_option_autoload']       = array();
		$GLOBALS['a8csp_bgje_test_update_option_results'] = array();
		$GLOBALS['a8csp_bgje_test_update_option_values']  = array();
		$GLOBALS['a8csp_bgje_test_delete_option_results'] = array();
		$GLOBALS['a8csp_bgje_test_filter_values']         = array();
		$GLOBALS['a8csp_bgje_test_fired_actions']         = array();
		$GLOBALS['a8csp_bgje_test_action_callbacks']      = array();
		$GLOBALS['a8csp_bgje_test_action_throwables']     = array();
		$GLOBALS['a8csp_bgje_test_hooks']                 = array();
		$GLOBALS['a8csp_bgje_test_action_registrations']  = array();
		$GLOBALS['a8csp_bgje_test_blog_id']               = 1;
		$GLOBALS['a8csp_bgje_test_cache']                 = array();
		$GLOBALS['a8csp_bgje_test_cache_calls']           = array();
		unset( $GLOBALS['a8csp_bgje_test_before_add_option'] );

		$this->backend  = new RecordingBackend();
		$this->clock    = new FixedClock( self::NOW );
		$this->logger   = new RecordingLogger();
		$this->wpdb     = new WpdbLockSpy();
		$this->registry = new ScheduleRegistry( new OptionRows( $this->wpdb ), $this->logger );
		$this->delivery = $this->new_delivery( $this->registry );
		$this->api      = new Schedules( $this->registry, $this->backend, $this->clock, $this->delivery );
	}

	// endregion.

	// region TESTS.

	/**
	 * A missing registry row records durable intent before attempting inline convergence.
	 *
	 * @return  void
	 */
	public function test_unknown_registration_records_intent_and_attempts_inline_convergence(): void {
		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array( 'is_ready', 'unschedule' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertSame( array( self::REGISTRATION_KEY ), $this->backend->calls[1]['args']['args'] ?? null );
		self::assertCount( 1, \array_filter( $this->wpdb->recorded_queries, fn ( string $query ): bool => \str_starts_with( $query, 'INSERT IGNORE ' ) && \str_contains( $query, $this->intent_option_name() ) ) );
		self::assertArrayNotHasKey( $this->intent_option_name(), $this->wpdb->rows );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->logger->records[0]['context']['registration_key'] ?? null );
		self::assertTrue( $this->logger->records[0]['context']['converged'] ?? null );
	}

	/**
	 * An unreadable registry row preserves its recurring chain and cannot create a cleanup intent.
	 *
	 * @return  void
	 */
	public function test_corrupt_registration_row_is_not_delivered_as_an_unknown_schedule(): void {
		$option_name = ScheduleRegistry::option_name( self::OWNER );
		$this->wpdb->put( $option_name, 'poison-registry-row' );

		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array(), $this->backend->calls );
		self::assertArrayNotHasKey( $this->intent_option_name(), $this->wpdb->rows );
		self::assertCount( 2, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $option_name, $this->logger->records[0]['context']['option_name'] ?? null );
		self::assertSame( 'warning', $this->logger->records[1]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->logger->records[1]['context']['registration_key'] ?? null );
		$propagated_error = $this->logger->records[1]['context']['error'] ?? null;
		self::assertIsString( $propagated_error );
		self::assertStringContainsString(
			'a8csp_bgje_schedule_registrations_owner-a',
			$propagated_error,
			'The propagated corrupt-registry error must name the exact option row so an operator can act on it.'
		);
	}

	/**
	 * A later readiness transition cannot grant authority to an earlier partial clear.
	 *
	 * @return  void
	 */
	public function test_inline_convergence_uses_authority_from_the_clearing_snapshot(): void {
		$dormant                    = new RecordingBackend();
		$dormant->readiness_results = array( false, true );
		$this->delivery             = $this->new_delivery( $this->registry, new SchedulerFacade( array( $dormant, $this->backend ) ) );

		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertArrayHasKey( $this->intent_option_name(), $this->wpdb->rows );
		self::assertSame( array( 'is_ready', 'is_absent' ), \array_column( $dormant->calls, 'verb' ) );
		self::assertSame( array( 'is_ready', 'unschedule' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertSame( 'debug', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->logger->records[0]['context']['registration_key'] ?? null );
		self::assertFalse( $this->logger->records[1]['context']['converged'] ?? null );
		self::assertTrue( $dormant->is_ready() );

		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertArrayNotHasKey( $this->intent_option_name(), $this->wpdb->rows );
		self::assertTrue( $this->logger->records[2]['context']['converged'] ?? null );
	}

	/**
	 * A failed intent CAS and verification read cannot report inline convergence.
	 *
	 * @return  void
	 */
	public function test_inline_convergence_rejects_a_failed_post_cas_read(): void {
		$verification_reads = 0;
		$this->wpdb->script_result( 'delete', 0 );
		$this->wpdb->before_next(
			'delete',
			static function ( WpdbLockSpy $wpdb ) use ( &$verification_reads ): void {
				$wpdb->before_next(
					'select',
					static function ( WpdbLockSpy $wpdb ) use ( &$verification_reads ): void {
						++$verification_reads;
						$wpdb->last_error = 'scripted intent verification failure';
					}
				);
			}
		);

		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertArrayHasKey( $this->intent_option_name(), $this->wpdb->rows );
		self::assertSame( 1, $verification_reads );
		self::assertFalse( $this->logger->records[0]['context']['converged'] ?? null );
	}

	/**
	 * A failed maintenance convergence retains its intent and emits a warning diagnostic.
	 *
	 * @return  void
	 */
	public function test_pending_intent_sweep_logs_and_retains_a_failed_clear(): void {
		$this->backend->results['unschedule'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Repair the backend before retrying convergence.' ) );
		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );
		$this->backend->calls  = array();
		$this->logger->records = array();

		$this->cleanup_intents->converge_pending_intents();

		self::assertSame( array( 'is_ready', 'unschedule' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertArrayHasKey( $this->intent_option_name(), $this->wpdb->rows );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->logger->records[0]['context']['registration_key'] ?? null );
		self::assertSame( 'Repair the backend before retrying convergence.', $this->logger->records[0]['context']['error'] ?? null );
	}

	/**
	 * A failed authoritative intent-name scan skips the sweep without scheduler or row mutation.
	 *
	 * @return  void
	 */
	public function test_pending_intent_sweep_skips_a_failed_name_scan(): void {
		$this->backend->results['unschedule'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Keep the intent pending until the maintenance sweep.' ) );
		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );
		unset( $this->backend->results['unschedule'] );
		$this->backend->calls         = array();
		$this->wpdb->recorded_queries = array();
		$scan_failures                = 0;
		$this->wpdb->before_next(
			'scan',
			static function ( WpdbLockSpy $wpdb ) use ( &$scan_failures ): void {
				++$scan_failures;
				$wpdb->last_error = 'scripted intent-name scan failure';
			}
		);

		$this->cleanup_intents->converge_pending_intents();

		self::assertArrayHasKey( $this->intent_option_name(), $this->wpdb->rows );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( 1, $scan_failures );
		self::assertCount( 1, $this->wpdb->recorded_queries );
	}

	/**
	 * A throwable during intent enumeration is retained as structured log context.
	 *
	 * @return  void
	 */
	public function test_pending_intent_sweep_logs_an_enumeration_throwable_as_exception_context(): void {
		$throwable = new \RuntimeException( 'Intent enumeration secret.' );
		$this->wpdb->before_next(
			'scan',
			static function () use ( $throwable ): void {
				throw $throwable;
			}
		);

		$this->cleanup_intents->converge_pending_intents();

		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $throwable, $this->logger->records[0]['context']['exception'] ?? null );
	}

	/**
	 * A throwable during one intent convergence is retained with its schedule identity.
	 *
	 * @fixture StoreFixtureBuilder
	 *
	 * @return  void
	 */
	public function test_pending_intent_sweep_logs_a_convergence_throwable_as_exception_context(): void {
		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( self::REGISTRATION_KEY )->cleanup_intent( self::NOW );
		self::assertSame( $this->intent_option_name(), $option_name );
		$this->wpdb->put( $option_name, $raw );
		$throwable = new \RuntimeException( 'Intent convergence secret.' );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function () use ( $throwable ): void {
				throw $throwable;
			}
		);

		$this->cleanup_intents->converge_pending_intents();

		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->logger->records[0]['context']['registration_key'] ?? null );
		self::assertSame( $throwable, $this->logger->records[0]['context']['exception'] ?? null );
	}

	/**
	 * An unreadable intent row is retained and skipped without consulting the scheduler.
	 *
	 * @fixture StoreFixtureBuilder
	 *
	 * @return  void
	 */
	public function test_pending_intent_sweep_skips_a_failed_row_read(): void {
		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( self::REGISTRATION_KEY )->cleanup_intent( self::NOW );
		self::assertSame( $this->intent_option_name(), $option_name );
		$this->wpdb->put( $option_name, $raw );
		$row_read_failures = 0;
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( &$row_read_failures ): void {
				++$row_read_failures;
				$wpdb->last_error = 'scripted intent-row read failure';
			}
		);

		$this->cleanup_intents->converge_pending_intents();

		self::assertSame( $raw, $this->wpdb->rows[ $this->intent_option_name() ] ?? null );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( 1, $row_read_failures );
	}

	/**
	 * The inline scheduler clear cannot begin before durable intent is visible.
	 *
	 * @return  void
	 */
	public function test_unknown_delivery_records_intent_before_inline_convergence(): void {
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			function (): void {
				self::assertArrayHasKey( $this->intent_option_name(), $this->wpdb->rows );
				self::assertSame( array(), $this->backend->calls );
			}
		);

		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );
	}

	/**
	 * Repeated unknown deliveries preserve the first unresolved intent generation.
	 *
	 * @return  void
	 */
	public function test_unknown_delivery_keeps_an_existing_intent_unchanged(): void {
		$this->backend->results['unschedule'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Keep the intent pending across deliveries.' ) );
		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );
		$original_raw = $this->wpdb->rows[ $this->intent_option_name() ] ?? null;
		self::assertIsString( $original_raw );
		$this->clock->timestamp = self::NOW + 1;

		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( $original_raw, $this->wpdb->rows[ $this->intent_option_name() ] ?? null );
	}

	/**
	 * A current registration resolves its intent without consulting scheduler readiness.
	 *
	 * @return  void
	 */
	public function test_registered_chain_clears_intent_without_scheduler_access(): void {
		$this->backend->results['unschedule'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Keep the intent pending until registration.' ) );
		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );
		unset( $this->backend->results['unschedule'] );
		$this->sync_schedule( $this->schedule() );
		$this->backend->ready = false;

		$this->cleanup_intents->converge_pending_intents();

		self::assertArrayNotHasKey( $this->intent_option_name(), $this->wpdb->rows );
		self::assertSame( array(), $this->backend->calls );
	}

	/**
	 * Exact-value deletion loses to an intent generation reinserted after the read.
	 *
	 * @fixture StoreFixtureBuilder
	 *
	 * @return  void
	 */
	public function test_intent_cas_delete_loses_to_a_delete_reinsert(): void {
		$this->backend->results['unschedule'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Keep the first intent pending.' ) );
		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );
		unset( $this->backend->results['unschedule'] );
		$this->clock->timestamp = self::NOW + 1;

		[ $replacement_name, $replacement_raw ] = StoreFixtureBuilder::for_identity( self::REGISTRATION_KEY )->cleanup_intent( $this->clock->timestamp );
		self::assertSame( $this->intent_option_name(), $replacement_name );
		$this->wpdb->before_next(
			'delete',
			function ( WpdbLockSpy $wpdb ) use ( $replacement_raw ): void {
				unset( $wpdb->rows[ $this->intent_option_name() ] );
				$wpdb->put( $this->intent_option_name(), $replacement_raw );
			}
		);

		$this->cleanup_intents->converge_pending_intents();

		self::assertSame( $replacement_raw, $this->wpdb->rows[ $this->intent_option_name() ] ?? null );
	}

	/**
	 * A malformed intent row is skipped without aborting valid pending convergence.
	 *
	 * @return  void
	 */
	public function test_pending_intent_convergence_never_throws_on_a_poisoned_row(): void {
		$this->backend->results['unschedule'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Keep the valid intent pending.' ) );
		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );
		unset( $this->backend->results['unschedule'] );
		$this->logger->records = array();
		$poisoned_names        = array(
			'a8csp_bgje_cleanup_intent_' . \str_repeat( '0', 64 ),
			'a8csp_bgje_cleanup_intent_' . \str_repeat( '1', 64 ),
		);
		foreach ( $poisoned_names as $poisoned_name ) {
			$this->wpdb->put( $poisoned_name, 'O:8:"stdClass":0:{}' );
		}

		$this->cleanup_intents->converge_pending_intents();

		foreach ( $poisoned_names as $poisoned_name ) {
			self::assertArrayHasKey( $poisoned_name, $this->wpdb->rows );
		}
		self::assertArrayNotHasKey( $this->intent_option_name(), $this->wpdb->rows );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 2, $this->logger->records[0]['context']['count'] ?? null );
		self::assertSame( CleanupIntents::OPTION_PREFIX, $this->logger->records[0]['context']['option_prefix'] ?? null );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns one schedule declaration for the requested timing policy.
	 *
	 * @param   CatchUpPolicy $catch_up Catch-up policy.
	 *
	 * @return  Schedule
	 */
	private function schedule( CatchUpPolicy $catch_up = CatchUpPolicy::RunOnce ): Schedule {
		return new Schedule( self::NAME, Recurrence::every( self::INTERVAL ), self::JOB, self::ARGS, $catch_up, 23 );
	}

	/**
	 * Returns request-local declarations keyed by complete schedule identity.
	 *
	 * @param   string   $owner     Owner identifier.
	 * @param   Schedule ...$schedules Schedule value objects.
	 *
	 * @return  array<string, array{schedule: Schedule, job: string}>
	 */
	private static function declarations( string $owner, Schedule ...$schedules ): array {
		$declarations = array();
		foreach ( $schedules as $schedule ) {
			$declarations[ $owner . ':' . $schedule->name ] = array(
				'schedule' => $schedule,
				'job'      => $owner . ':' . $schedule->job,
			);
		}

		return $declarations;
	}

	/**
	 * Synchronizes one declaration and clears setup observations.
	 *
	 * @param   Schedule $schedule Schedule declaration.
	 *
	 * @return  void
	 */
	private function sync_schedule( Schedule $schedule ): void {
		$result = $this->api->sync( self::OWNER, self::declarations( self::OWNER, $schedule ) );
		self::assertInstanceOf( Success::class, $result );

		$this->backend->calls                     = array();
		$this->logger->records                    = array();
		$GLOBALS['a8csp_bgje_test_fired_actions'] = array();
		$GLOBALS['a8csp_bgje_test_option_calls']  = array();
	}

	/**
	 * Returns another occurrence delivery service over the same runtime seams.
	 *
	 * @param   ScheduleRegistry $registry  Request-local schedule registry.
	 * @param   SchedulerFacade  $scheduler Scheduling facade, or null for the default recording backend.
	 *
	 * @return  OccurrenceDelivery
	 */
	private function new_delivery( ScheduleRegistry $registry, ?SchedulerFacade $scheduler = null ): OccurrenceDelivery {
		$work = new JobRegistry();
		$work->register_job( self::JOB_IDENTITY, new RecordingJob( self::JOB ) );
		$guard                = new OverlapGuard( $this->clock, $this->logger, new OptionRows( $this->wpdb ) );
		$stores               = new StoreFactory( $this->clock, new OptionRows( $this->wpdb ), $this->logger );
		$randomizer           = new RecordingRandomizer( 42 );
		$lock_windows         = new LockWindows( $this->clock, $this->logger );
		$terminal_effects     = new LifecycleEffects( $guard, $stores, $this->logger );
		$terminal_transitions = new RunTransitions( $guard, $stores, $this->clock, $lock_windows, $this->logger, $terminal_effects );
		$dispatcher           = new Dispatcher( $work, $this->backend, $guard, $stores, $this->clock, $randomizer, $this->logger, $lock_windows, $terminal_transitions, $terminal_effects, );

		$scheduler           ??= new SchedulerFacade( array( $this->backend ) );
		$this->cleanup_intents = new CleanupIntents( $registry, $scheduler, new OptionRows( $this->wpdb ), $this->clock, $this->logger );

		return new OccurrenceDelivery( $registry, $dispatcher, new OccurrenceLease( new OptionRows( $this->wpdb ), $this->clock, new RecordingRandomizer( 42 ) ), $this->cleanup_intents, $this->clock, $this->logger );
	}

	/**
	 * Returns the durable cleanup-intent option for the fixture registration.
	 *
	 * @return  string
	 */
	private function intent_option_name(): string {
		return CleanupIntents::OPTION_PREFIX . \hash( 'sha256', self::REGISTRATION_KEY );
	}

	// endregion.
}
