<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Schedules;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\DeliveryScheduler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\FailureLifecycle;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\ChunkedJobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\JobKindHandler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceLease;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingRandomizer;
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
#[UsesClass( ScheduleOperations::class )]
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
	private const string SCOPE            = 'scope-a';
	private const string REGISTRATION_KEY = 'scope-a:nightly';
	private const string JOB              = 'refresh-index';

	private ScheduleOperations $api;
	private RecordingBackend $backend;
	private FixedClock $clock;
	private CleanupIntents $cleanup_intents;
	private OccurrenceDelivery $delivery;
	private RecordingLogger $logger;
	private RecordingRandomizer $randomizer;
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

		$this->backend    = new RecordingBackend();
		$this->clock      = new FixedClock( self::NOW );
		$this->logger     = new RecordingLogger();
		$this->randomizer = new RecordingRandomizer( 42 );
		$this->wpdb       = new WpdbLockSpy();
		$this->registry   = new ScheduleRegistry( new OptionRows( $this->wpdb ), $this->logger );
		$scheduler        = new SchedulerFacade( array( $this->backend ) );
		$this->delivery   = $this->new_delivery( $this->registry, $scheduler );
		$this->api        = new ScheduleOperations( $this->registry, $scheduler, $this->clock, $this->delivery, $this->logger );
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
		self::assertSame( self::REGISTRATION_KEY, $this->logger->records[0]['context']['schedule_identity'] ?? null );
		self::assertTrue( $this->logger->records[0]['context']['converged'] ?? null );
	}

	/**
	 * An indeterminate intent write that stored nothing reports no convergence.
	 *
	 * @return  void
	 */
	public function test_indeterminate_intent_write_storing_nothing_reports_no_convergence(): void {
		// The occurrence lease is inserted before the cleanup intent, so the failure is armed one insert late.
		$this->wpdb->before_next( 'insert', static fn ( WpdbLockSpy $wpdb ) => $wpdb->before_next( 'insert', static fn ( WpdbLockSpy $database ) => $database->script_result( 'insert', false ) ) );

		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertArrayNotHasKey( $this->intent_option_name(), $this->wpdb->rows );
		self::assertSame( array(), $this->backend->calls );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->logger->records[0]['context']['schedule_identity'] ?? null );
		self::assertFalse( $this->logger->records[0]['context']['converged'] ?? null );
	}

	/**
	 * An indeterminate intent write whose row is nevertheless readable converges its leftover chain.
	 *
	 * @return  void
	 */
	public function test_indeterminate_intent_write_with_a_readable_row_still_converges(): void {
		[ $intent_option, $intent_raw ] = StoreFixtureBuilder::for_identity( self::REGISTRATION_KEY )->cleanup_intent( 7 );
		$this->wpdb->put( $intent_option, $intent_raw );
		// The occurrence lease is inserted before the cleanup intent, so the failure is armed one insert late.
		$this->wpdb->before_next( 'insert', static fn ( WpdbLockSpy $wpdb ) => $wpdb->before_next( 'insert', static fn ( WpdbLockSpy $database ) => $database->script_result( 'insert', false ) ) );

		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertArrayNotHasKey( $intent_option, $this->wpdb->rows );
		self::assertSame( array( 'is_ready', 'unschedule' ), \array_column( $this->backend->calls, 'verb' ) );
		self::assertCount( 1, $this->logger->records );
		self::assertTrue( $this->logger->records[0]['context']['converged'] ?? null );
	}

	/**
	 * An intent an earlier occurrence recorded is a determinate write, so its removal still reads as convergence.
	 *
	 * @return  void
	 */
	public function test_concurrently_removed_incumbent_intent_reports_convergence_without_scheduler_access(): void {
		[ $intent_option, $intent_raw ] = StoreFixtureBuilder::for_identity( self::REGISTRATION_KEY )->cleanup_intent( 7 );
		self::assertSame( $this->intent_option_name(), $intent_option );
		$this->wpdb->put( $intent_option, $intent_raw );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( $intent_option, $intent_raw ): void {
				self::assertSame( $intent_raw, $wpdb->rows[ $intent_option ] ?? null );
				unset( $wpdb->rows[ $intent_option ], $wpdb->autoload[ $intent_option ] );
			}
		);

		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertArrayNotHasKey( $intent_option, $this->wpdb->rows );
		self::assertSame( array(), $this->backend->calls );
		self::assertCount( 1, $this->logger->records );
		self::assertTrue( $this->logger->records[0]['context']['converged'] ?? null );
	}

	/**
	 * A concurrently removed written intent means another actor already converged the chain.
	 *
	 * @return  void
	 */
	public function test_concurrently_removed_written_intent_reports_convergence_without_scheduler_access(): void {
		$intent_option = $this->intent_option_name();
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( $intent_option ): void {
				self::assertArrayHasKey( $intent_option, $wpdb->rows );
				unset( $wpdb->rows[ $intent_option ], $wpdb->autoload[ $intent_option ] );
			}
		);

		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertArrayNotHasKey( $intent_option, $this->wpdb->rows );
		self::assertSame( array(), $this->backend->calls );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->logger->records[0]['context']['schedule_identity'] ?? null );
		self::assertTrue( $this->logger->records[0]['context']['converged'] ?? null );
	}

	/**
	 * Malformed scheduler-wire bytes retain their exact lease, intent, cleanup, and diagnostic identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_malformed_wire_identity_drives_exact_raw_schedule_cleanup(): void {
		$registration_key    = 'malformed';
		$digest              = '60ec9bb7299d85e0cdd35d4058fabd7cb6bdc9b788c6efde44427e9bb9234e13';
		$lease_option        = OccurrenceLease::OPTION_PREFIX . $digest;
		$intent_option       = CleanupIntents::OPTION_PREFIX . $digest;
		$expected_lease_raw  = 'a:2:{s:11:"claim_token";s:19:"0000000000000000042";s:10:"claimed_at";i:1700000000;}';
		$expected_intent_raw = 'a:2:{s:17:"schedule_identity";s:9:"malformed";s:10:"generation";i:42;}';
		$before              = $this->wpdb->rows;

		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( $lease_option, $expected_lease_raw ): void {
				self::assertSame( $expected_lease_raw, $wpdb->rows[ $lease_option ] ?? null );
			}
		);
		$this->backend->before_next(
			'unschedule',
			function () use ( $intent_option, $expected_intent_raw ): void {
				self::assertSame( $expected_intent_raw, $this->wpdb->rows[ $intent_option ] ?? null );
			}
		);

		$this->delivery->handle_schedule_due( $registration_key );

		self::assertSame(
			array(
				array(
					'verb' => 'is_ready',
					'args' => array(),
				),
				array(
					'verb' => 'unschedule',
					'args' => array(
						'hook'  => OccurrenceDelivery::SCHEDULE_HOOK,
						'args'  => array( $registration_key ),
						'group' => $registration_key,
					),
				),
			),
			$this->backend->calls
		);
		self::assertSame( $before, $this->wpdb->rows );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Malformed schedule registration "malformed" was delivered; no cleanup is outstanding.',
					'context' => array(
						'schedule_identity' => $registration_key,
						'converged'         => true,
						'intent_confirmed'  => true,
					),
				),
			),
			$this->logger->records
		);
	}

	/**
	 * An unreadable registry row preserves its recurring chain and cannot create a cleanup intent.
	 *
	 * @return  void
	 */
	public function test_corrupt_registration_row_is_not_delivered_as_an_unknown_schedule(): void {
		$option_name = ScheduleRegistry::option_name( self::SCOPE );
		$this->wpdb->put( $option_name, 'poison-registry-row' );

		$this->delivery->handle_schedule_due( self::REGISTRATION_KEY );

		self::assertSame( array(), $this->backend->calls );
		self::assertArrayNotHasKey( $this->intent_option_name(), $this->wpdb->rows );
		self::assertCount( 2, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( $option_name, $this->logger->records[0]['context']['option_name'] ?? null );
		self::assertSame( 'warning', $this->logger->records[1]['level'] ?? null );
		self::assertSame( self::REGISTRATION_KEY, $this->logger->records[1]['context']['schedule_identity'] ?? null );
		$propagated_error = $this->logger->records[1]['context']['error'] ?? null;
		self::assertIsString( $propagated_error );
		self::assertStringContainsString( 'a8csp_bgje_schedule_registrations_scope-a', $propagated_error, 'The propagated corrupt-registry error must name the exact option row so an operator can act on it.' );
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
		self::assertSame( self::REGISTRATION_KEY, $this->logger->records[0]['context']['schedule_identity'] ?? null );
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
		self::assertSame( self::REGISTRATION_KEY, $this->logger->records[0]['context']['schedule_identity'] ?? null );
		self::assertSame( 'Repair the backend before retrying convergence.', $this->logger->records[0]['context']['error'] ?? null );
	}

	/**
	 * A bounded sweep resumes after its durable cursor instead of retrying retained earlier intents.
	 *
	 * @load-bearing bounded-retry-liveness
	 * @pin-rationale Exact option rows and scheduler calls are the only evidence that a bounded invocation persists and resumes its own cursor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_pending_intent_sweep_resumes_after_its_durable_cursor(): void {
		$intents = array();
		for ( $index = 0; $index < 501; ++$index ) {
			$registration_key = 'scope-a:pending-' . \sprintf( '%03d', $index );
			$option_name      = CleanupIntents::OPTION_PREFIX . \hash( 'sha256', $registration_key );
			$raw              = \maybe_serialize(
				array(
					'schedule_identity' => $registration_key,
					'generation'        => 42,
				)
			);
			self::assertIsString( $raw );
			$this->wpdb->put( $option_name, $raw );
			$intents[ $option_name ] = $registration_key;
		}
		\ksort( $intents, \SORT_STRING );
		$expected_option = \array_key_last( $intents );
		self::assertIsString( $expected_option );
		$expected_key           = $intents[ $expected_option ];
		$expected_cursor_option = \array_keys( $intents )[499] ?? null;
		self::assertIsString( $expected_cursor_option );
		$expected_cursor_raw = \maybe_serialize( array( 'after_name' => $expected_cursor_option ) );
		self::assertIsString( $expected_cursor_raw );

		$this->backend->results['unschedule'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Keep the first bounded page set pending.' ) );
		$this->cleanup_intents->converge_pending_intents();

		$first_unschedules = \array_values( \array_filter( $this->backend->calls, static fn ( array $call ): bool => 'unschedule' === $call['verb'] ) );
		self::assertCount( 500, $first_unschedules );
		self::assertCount( 501, \array_filter( \array_keys( $this->wpdb->rows ), static fn ( string $option_name ): bool => \str_starts_with( $option_name, CleanupIntents::OPTION_PREFIX ) ) );
		self::assertSame( $expected_cursor_raw, $this->wpdb->rows[ CleanupIntents::SWEEP_CURSOR_OPTION ] ?? null );
		self::assertArrayNotHasKey( 'a8csp_bgje_cleanup_intents_sweep', $this->wpdb->rows );
		self::assertCount( 502, $this->wpdb->rows );

		unset( $this->backend->results['unschedule'] );
		$this->backend->calls  = array();
		$this->logger->records = array();
		$resumed               = new CleanupIntents( $this->registry, new SchedulerFacade( array( $this->backend ) ), new OptionRows( $this->wpdb ), $this->randomizer, $this->logger );

		$resumed->converge_pending_intents();

		$second_unschedules = \array_values( \array_filter( $this->backend->calls, static fn ( array $call ): bool => 'unschedule' === $call['verb'] ) );
		self::assertCount( 1, $second_unschedules );
		$second_call = $second_unschedules[0] ?? null;
		self::assertIsArray( $second_call );
		$second_args = $second_call['args'] ?? null;
		self::assertIsArray( $second_args );
		self::assertSame( array( $expected_key ), $second_args['args'] ?? null );
		self::assertArrayNotHasKey( $expected_option, $this->wpdb->rows );
		self::assertArrayNotHasKey( 'a8csp_bgje_cleanup_sweep_cursor', $this->wpdb->rows );
		self::assertCount( 500, $this->wpdb->rows );
	}

	/**
	 * A failed cleanup-intent cursor read reports the aborted sweep without scheduler or row mutation.
	 *
	 * @fixture StoreFixtureBuilder
	 *
	 * @return  void
	 */
	public function test_pending_intent_sweep_reports_a_failed_cursor_read(): void {
		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( self::REGISTRATION_KEY )->cleanup_intent( 42 );
		self::assertSame( $this->intent_option_name(), $option_name );
		$this->wpdb->put( $option_name, $raw );
		$before = $this->wpdb->rows;
		$this->wpdb->fail_next_read_at( 'query_filtered' );

		$this->cleanup_intents->converge_pending_intents();

		self::assertSame( $before, $this->wpdb->rows );
		self::assertSame( array(), $this->backend->calls );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'Unknown-schedule cleanup sweep aborted while reading its cursor; repair WordPress option reads and retry the sweep.', $this->logger->records[0]['message'] ?? null );
		self::assertSame(
			array(
				'phase'        => 'intent-cursor-read',
				'error_class'  => EngineError::class,
				'error_reason' => 'storage_failed',
			),
			$this->logger->records[0]['context'] ?? null
		);
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
		$this->logger->records        = array();
		$this->wpdb->recorded_queries = array();
		$before                       = $this->wpdb->rows;
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
		self::assertSame( $before, $this->wpdb->rows );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( 1, $scan_failures );
		self::assertCount( 2, $this->wpdb->recorded_queries );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'Unknown-schedule cleanup sweep aborted while enumerating intent rows; repair WordPress option reads and retry the sweep.', $this->logger->records[0]['message'] ?? null );
		self::assertSame(
			array(
				'phase'        => 'intent-enumeration',
				'error_class'  => EngineError::class,
				'error_reason' => 'storage_failed',
			),
			$this->logger->records[0]['context'] ?? null
		);
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
		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( self::REGISTRATION_KEY )->cleanup_intent( 42 );
		self::assertSame( $this->intent_option_name(), $option_name );
		$this->wpdb->put( $option_name, $raw );
		$throwable = new \RuntimeException( 'Intent convergence secret.' );
		$this->wpdb->before_next( 'select', static function (): void {} );
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
		self::assertSame( self::REGISTRATION_KEY, $this->logger->records[0]['context']['schedule_identity'] ?? null );
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
		[ $option_name, $raw ] = StoreFixtureBuilder::for_identity( self::REGISTRATION_KEY )->cleanup_intent( 42 );
		self::assertSame( $this->intent_option_name(), $option_name );
		$this->wpdb->put( $option_name, $raw );
		$cursor_raw = \maybe_serialize( array( 'after_name' => CleanupIntents::OPTION_PREFIX ) );
		self::assertIsString( $cursor_raw );
		$this->wpdb->put( CleanupIntents::SWEEP_CURSOR_OPTION, $cursor_raw );
		$before            = $this->wpdb->rows;
		$row_read_failures = 0;
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( &$row_read_failures ): void {
				++$row_read_failures;
				$wpdb->last_error = 'scripted intent-row read failure';
			}
		);

		$this->cleanup_intents->converge_pending_intents();

		self::assertSame( $raw, $this->wpdb->rows[ $this->intent_option_name() ] ?? null );
		self::assertSame( $before, $this->wpdb->rows );
		self::assertSame( array(), $this->backend->calls );
		self::assertSame( 1, $row_read_failures );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 'Unknown-schedule cleanup sweep aborted while reading a page of intent rows; repair WordPress option reads and retry the sweep.', $this->logger->records[0]['message'] ?? null );
		self::assertSame(
			array(
				'phase'        => 'intent-read',
				'error_class'  => EngineError::class,
				'error_reason' => 'storage_failed',
			),
			$this->logger->records[0]['context'] ?? null
		);
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
		$this->randomizer->value = 43;

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
		$this->randomizer->value = 43;

		[ $replacement_name, $replacement_raw ] = StoreFixtureBuilder::for_identity( self::REGISTRATION_KEY )->cleanup_intent( $this->randomizer->value );
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
	 * A cleanup intent whose payload carries no integer generation is retained as malformed.
	 *
	 * @return  void
	 */
	public function test_pending_intent_sweep_classifies_a_row_without_a_generation_as_malformed(): void {
		$legacy_raw = \maybe_serialize(
			array(
				'schedule_identity' => self::REGISTRATION_KEY,
				'created_at'        => self::NOW,
			)
		);
		self::assertIsString( $legacy_raw );
		$this->wpdb->put( $this->intent_option_name(), $legacy_raw );

		$this->cleanup_intents->converge_pending_intents();

		self::assertSame( $legacy_raw, $this->wpdb->rows[ $this->intent_option_name() ] ?? null );
		self::assertSame( array(), $this->backend->calls );
		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] ?? null );
		self::assertSame( 1, $this->logger->records[0]['context']['count'] ?? null );
		self::assertSame( CleanupIntents::OPTION_PREFIX, $this->logger->records[0]['context']['option_prefix'] ?? null );
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
	 * @param   string   $scope        Scope identifier.
	 * @param   Schedule ...$schedules Schedule value objects.
	 *
	 * @return  array<string, array{schedule: Schedule, job: Identity}>
	 */
	private static function declarations( string $scope, Schedule ...$schedules ): array {
		$declarations = array();
		foreach ( $schedules as $schedule ) {
			$schedule_identity = Identity::compose( $scope, $schedule->name );

			$declarations[ (string) $schedule_identity ] = array(
				'schedule' => $schedule,
				'job'      => Identity::compose( $scope, $schedule->job ),
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
		$result = $this->api->sync( self::SCOPE, self::declarations( self::SCOPE, $schedule ) );
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
		$job_registry = new JobRegistry();
		$job_registry->register( Identity::compose( self::SCOPE, self::JOB ), ( new RecordingJob( self::JOB ) )->definition() );
		$guard                 = new OverlapGuard( $this->clock, $this->logger, new OptionRows( $this->wpdb ), new LockWindows( $this->clock, $this->logger ) );
		$overlap_identity      = new OverlapIdentity();
		$stores                = new StoreFactory( $this->clock, new OptionRows( $this->wpdb ), $this->logger );
		$randomizer            = new RecordingRandomizer( 42 );
		$lock_windows          = new LockWindows( $this->clock, $this->logger );
		$scheduler           ??= new SchedulerFacade( array( $this->backend ) );
		$delivery_scheduler    = new DeliveryScheduler( $scheduler, $this->clock );
		$terminal_effects      = new LifecycleEffects( $guard, $stores, $this->logger );
		$terminal_transitions  = new RunTransitions( $guard, $stores, $this->clock, $lock_windows, $delivery_scheduler, $this->logger, $terminal_effects );
		$failure_lifecycle     = new FailureLifecycle( $delivery_scheduler, $this->clock, $randomizer, $this->logger, $terminal_transitions, $terminal_effects );
		$job_handler           = new JobKindHandler( $job_registry, $this->logger, $this->clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
		$chunked_job_handler   = new ChunkedJobKindHandler( $job_registry, $delivery_scheduler, $this->logger, $this->clock, $lock_windows, $terminal_transitions, $terminal_effects, $failure_lifecycle );
		$handlers              = array(
			$job_handler->key()         => $job_handler,
			$chunked_job_handler->key() => $chunked_job_handler,
		);
		$dispatcher            = new Dispatcher( $job_registry, $handlers, $delivery_scheduler, $guard, $overlap_identity, $stores, $this->clock, $randomizer, $this->logger, $terminal_transitions );
		$this->cleanup_intents = new CleanupIntents( $registry, $scheduler, new OptionRows( $this->wpdb ), $this->randomizer, $this->logger );

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
