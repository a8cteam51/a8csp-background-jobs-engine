<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Maintenance\MaintenanceJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\SystemClock;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Logging\HookLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Logging\ErrorLogSink;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies durable cleanup intents and live maintenance sweeps converge unknown recurring chains.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class UnknownScheduleCleanupTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Schedule-delivery hook shared with the live engine. */
	private const string HOOK = 'a8csp_jobs_engine/schedule_due';

	/** Unknown registration identity isolated to this integration test. */
	private const string KEY = 'integration-owner:unknown-cleanup';

	/** Owner component of the unknown registration identity. */
	private const string OWNER = 'integration-owner';

	/** Schedule component of the unknown registration identity. */
	private const string SCHEDULE = 'unknown-cleanup';

	/** Job invoked by the legitimately re-declared schedule. */
	private const string REDECLARED_JOB = 'integration-unknown-cleanup-redeclared-job';

	/** Owner-qualified job identity invoked by the legitimately re-declared schedule. */
	private const string REDECLARED_IDENTITY = self::OWNER . ':' . self::REDECLARED_JOB;

	/** Unknown registration identity isolated to the degraded WP-Cron probe. */
	private const string WP_CRON_KEY = 'integration-owner:unknown-wp-cron-cleanup';

	/** Engine-reserved maintenance registration identity. */
	private const string MAINTENANCE_KEY = 'a8csp-jobs-engine:maintenance';

	/** Owner isolated to undeclared-registration aging. */
	private const string ZOMBIE_OWNER = 'integration-zombie-owner';

	/** Schedule isolated to undeclared-registration aging. */
	private const string ZOMBIE_SCHEDULE = 'zombie-schedule';

	/** Registration identity isolated to undeclared-registration aging. */
	private const string ZOMBIE_KEY = self::ZOMBIE_OWNER . ':' . self::ZOMBIE_SCHEDULE;

	/** Target job persisted only in the isolated declaration fixture. */
	private const string ZOMBIE_JOB = 'zombie-job';

	// endregion.

	// region TESTS.

	/**
	 * A persisted registration warns exactly once after three request-undeclared deliveries.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Request-local declarations cannot be withdrawn after sync within one process; production-built durable bytes plus the Action Scheduler runner reproduce a later undeclared request and its recurring successor.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_persisted_undeclared_schedule_escalates_once_across_recurring_deliveries(): void {
		$this->expect_option( ScheduleRegistry::option_name( self::ZOMBIE_OWNER ) );
		$schedule = new Schedule( self::ZOMBIE_SCHEDULE, Recurrence::every( 300 ), self::ZOMBIE_JOB );
		$fixture  = StoreFixtureBuilder::for_identity( self::ZOMBIE_KEY )->schedule_registration(
			array(
				'owner'         => self::ZOMBIE_OWNER,
				'declarations'  => array(
					self::ZOMBIE_KEY => array(
						'schedule' => $schedule,
						'job'      => self::ZOMBIE_OWNER . ':' . self::ZOMBIE_JOB,
					),
				),
				'registrations' => array(
					self::ZOMBIE_KEY => StoreFixtureBuilder::schedule_registration_state( $schedule->fingerprint(), \time() - 1 ),
				),
			)
		);
		self::assertTrue( \update_option( $fixture[0], \maybe_unserialize( $fixture[1] ), false ), 'The isolated production registry row must persist outside the live request declarations' );

		/** @var list<array{string, string, array<array-key, mixed>}> $log_records */
		$log_records = array();
		\remove_action( 'a8csp_jobs_engine/log', array( ErrorLogSink::class, 'log' ), 10 );
		\add_action(
			'a8csp_jobs_engine/log',
			static function ( string $level, string $message, array $context ) use ( &$log_records ): void {
				$log_records[] = array( $level, $message, $context );
			},
			10,
			3
		);

		$action_id = \as_schedule_recurring_action( \time() - 1, 300, self::HOOK, array( self::ZOMBIE_KEY ), self::ZOMBIE_KEY, true, 10 );
		self::assertGreaterThan( 0, $action_id );
		$runner = \ActionScheduler::runner();
		self::assertInstanceOf( \ActionScheduler_QueueRunner::class, $runner );
		$warnings = array();

		for ( $occurrence = 1; $occurrence <= 4; ++$occurrence ) {
			$pending = $this->pending_schedule_action_ids( self::ZOMBIE_KEY );
			self::assertCount( 1, $pending, 'Each recurring delivery must retain exactly one successor chain' );
			$runner->process_action( (int) $pending[0], 'Integration Test' );

			$warnings = \array_values(
				\array_filter(
					$log_records,
					static fn ( array $record ): bool => 'warning' === $record[0] && \str_contains( $record[1], 'fired undeclared' )
				)
			);
			self::assertCount( 3 > $occurrence ? 0 : 1, $warnings );
		}

		self::assertStringContainsString( 'wp background-jobs schedules remove ' . self::ZOMBIE_OWNER, $warnings[0][1] ?? '' );
		$debug_records = \array_values(
			\array_filter(
				$log_records,
				static fn ( array $record ): bool => 'debug' === $record[0] && 'Schedule registration is inactive in this request; leave its recurring occurrence unchanged.' === $record[1]
			)
		);
		self::assertCount( 4, $debug_records );

		global $wpdb;
		self::assertInstanceOf( \wpdb::class, $wpdb );
		$registration = ( new ScheduleRegistry( new OptionRows( $wpdb ), new HookLogger() ) )->registration( self::ZOMBIE_KEY );
		self::assertInstanceOf( Success::class, $registration );
		self::assertIsArray( $registration->value );
		self::assertSame( 3, $registration->value['undeclared_occurrences'] );
		self::assertTrue( $registration->value['undeclared_escalated'] );
	}

	/**
	 * An unknown delivery records an intent that the live maintenance schedule converges.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_live_maintenance_sweep_converges_the_unknown_recurring_chain(): void {
		$this->expect_option( ScheduleRegistry::option_name( 'a8csp-jobs-engine' ) );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::MAINTENANCE_KEY );

		/** @var list<array{string, string, array<array-key, mixed>}> $log_records */
		$log_records = array();
		\remove_action( 'a8csp_jobs_engine/log', array( ErrorLogSink::class, 'log' ), 10 );
		\add_action(
			'a8csp_jobs_engine/log',
			static function ( string $level, string $message, array $context ) use ( &$log_records ): void {
				$log_records[] = array( $level, $message, $context );
			},
			10,
			3
		);

		$scheduler = new ActionSchedulerBackend();
		$scheduled = $scheduler->schedule_recurring( self::HOOK, 300, array( self::KEY ), \time() - 1, self::KEY );
		self::assertInstanceOf( Success::class, $scheduled );

		self::assertSame( 1, $this->run_next_due_action() );
		self::assertTrue( $scheduler->is_scheduled( self::HOOK, array( self::KEY ), self::KEY ), 'The unknown recurring delivery must leave a successor for maintenance convergence' );
		self::assertTrue(
			\array_any(
				$log_records,
				static fn ( array $record ): bool => 'warning' === $record[0] && array(
					'registration_key' => self::KEY,
					'converged'        => false,
				) === $record[2]
			),
			'The public log hook must report deferred convergence for the unknown registration'
		);

		$engine = Component::get_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before maintenance convergence' );
		$synced = $engine->schedules->sync_owner(
			'a8csp-jobs-engine',
			array(
				self::MAINTENANCE_KEY => array(
					'schedule' => new Schedule( MaintenanceJob::NAME, Recurrence::every( \HOUR_IN_SECONDS ), MaintenanceJob::NAME, array(), OverlapPolicy::Skip, CatchUpPolicy::RunOnce ),
					'job'      => self::MAINTENANCE_KEY,
				),
			)
		);
		self::assertInstanceOf( Success::class, $synced, 'The reserved maintenance schedule must re-synchronize' );
		self::assertTrue( $synced->value );

		$maintenance = $engine->schedules->dispatch_now( self::MAINTENANCE_KEY );
		self::assertInstanceOf( Success::class, $maintenance, 'The live maintenance job must be dispatchable through the schedule facade' );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the live maintenance job' );

		self::assertFalse( $scheduler->is_scheduled( self::HOOK, array( self::KEY ), self::KEY ), 'The maintenance sweep must converge the unknown recurring chain' );
	}

	/**
	 * A live redeclaration consumes its stale intent without clearing the replacement chain.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Cleanup and redeclaration race for ownership of the same recurring successor; public schedule reads cannot prove that the exact redeclared row survived the stale-intent sweep.
	 *
	 * @return  void
	 */
	public function test_sweep_convergence_preserves_a_redeclared_action_scheduler_chain(): void {
		$intent_option = 'a8csp_bgje_cleanup_intent_' . \hash( 'sha256', self::KEY );
		$this->expect_option( ScheduleRegistry::option_name( self::OWNER ) );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::REDECLARED_IDENTITY );
		\remove_action( 'a8csp_jobs_engine/log', array( ErrorLogSink::class, 'log' ), 10 );

		$unknown_action_id = \as_schedule_recurring_action( \time() - 1, 300, self::HOOK, array( self::KEY ), self::KEY, true, 10 );
		self::assertGreaterThan( 0, $unknown_action_id );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must deliver the unknown occurrence' );

		$intent = \get_option( $intent_option, null );
		self::assertIsArray( $intent, 'The unknown delivery must leave its cleanup intent for the sweep' );
		self::assertSame( self::KEY, $intent['key'] ?? null );

		$store                 = $this->action_scheduler_store();
		$unknown_successor_ids = $this->pending_schedule_action_ids( self::KEY );
		self::assertCount( 1, $unknown_successor_ids, 'The unknown recurrence must birth one successor' );
		$unknown_successor_id = $unknown_successor_ids[0];

		$client = \a8csp_bgje( self::OWNER );
		$job    = new RecordingJob( self::REDECLARED_JOB );
		$client->jobs()->register( $job );
		$schedule = new Schedule( self::SCHEDULE, Recurrence::every( 300 ), self::REDECLARED_JOB, array( 'generation' => 'redeclared' ), OverlapPolicy::Skip, CatchUpPolicy::RunOnce );
		$synced   = $client->schedules()->sync( array( $schedule ) );
		self::assertInstanceOf( Success::class, $synced, 'The unknown key must accept a legitimate live redeclaration' );
		self::assertTrue( $synced->value );
		self::assertSame( \ActionScheduler_Store::STATUS_CANCELED, $store->get_status( $unknown_successor_id ), 'Redeclaration must cancel the stale unknown-chain successor before creating its live chain' );

		$live_action_ids = $this->pending_schedule_action_ids( self::KEY );
		self::assertCount( 1, $live_action_ids, 'Redeclaration must persist exactly one live Action Scheduler occurrence' );
		$live_action_id = $live_action_ids[0];
		self::assertNotSame( $unknown_successor_id, $live_action_id );
		$live_scheduled_at = $store->get_date( $live_action_id );
		self::assertInstanceOf( \DateTime::class, $live_scheduled_at );
		$registry_next_due = $this->registration_next_due( self::OWNER, self::SCHEDULE );
		self::assertSame( $registry_next_due, $live_scheduled_at->getTimestamp(), 'The live Action Scheduler occurrence must use the redeclared registration next-due token' );

		$this->cleanup_intents()->converge_pending_intents();

		$missing_intent = new \stdClass();
		self::assertSame( $missing_intent, \get_option( $intent_option, $missing_intent ), 'The sweep must consume the intent after finding the live registration' );
		self::assertSame( array( $live_action_id ), $this->pending_schedule_action_ids( self::KEY ), 'The sweep must retain the exact redeclared Action Scheduler occurrence' );
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $live_action_id ) );
		$scheduled_at_after_sweep = $store->get_date( $live_action_id );
		self::assertInstanceOf( \DateTime::class, $scheduled_at_after_sweep );
		self::assertSame( $live_scheduled_at->getTimestamp(), $scheduled_at_after_sweep->getTimestamp() );
		self::assertSame( $registry_next_due, $this->registration_next_due( self::OWNER, self::SCHEDULE ) );

		$forced_due = $registry_next_due - 2 * 300;
		$this->set_registration_next_due( self::OWNER, self::SCHEDULE, $forced_due );
		$runner = \ActionScheduler::runner();
		self::assertInstanceOf( \ActionScheduler_QueueRunner::class, $runner );
		$runner->process_action( (int) $live_action_id, 'Integration Test' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $live_action_id ) );
		$advanced_due = $this->registration_next_due( self::OWNER, self::SCHEDULE );
		self::assertGreaterThanOrEqual( $registry_next_due, $advanced_due, 'The retained occurrence must advance to the original live window or a later recurrence' );
		self::assertSame( 0, ( $advanced_due - $forced_due ) % 300, 'The advanced due time must remain aligned to the persisted recurrence' );
		self::assertCount( 1, $this->pending_schedule_action_ids( self::KEY ), 'The retained recurring action must create its live successor after delivery' );
		self::assertSame( 1, $this->run_matching_due_action( static fn ( string $hook, array $args ): bool => 'a8csp_jobs_engine/run_job' === $hook && self::REDECLARED_IDENTITY === ( $args[0] ?? null ) ), 'The retained schedule occurrence must dispatch its declared job' );
		self::assertSame( array( array( 'generation' => 'redeclared' ) ), $job->calls );
	}

	/**
	 * A WP-Cron-only clearing pass consumes an unknown-chain intent inline.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[Group( 'degraded' )]
	public function test_unknown_wp_cron_chain_converges_inline(): void {
		/** @var list<array{string, string, array<array-key, mixed>}> $log_records */
		$log_records = array();
		\remove_action( 'a8csp_jobs_engine/log', array( ErrorLogSink::class, 'log' ), 10 );
		\add_action(
			'a8csp_jobs_engine/log',
			static function ( string $level, string $message, array $context ) use ( &$log_records ): void {
				$log_records[] = array( $level, $message, $context );
			},
			10,
			3
		);

		$scheduler = new WPCronBackend();
		$scheduled = $scheduler->schedule_recurring( self::HOOK, 300, array( self::WP_CRON_KEY ), \time() - 1, self::WP_CRON_KEY );
		self::assertInstanceOf( Success::class, $scheduled, 'WP-Cron must persist the unknown recurring occurrence' );
		self::assertTrue( $scheduler->is_scheduled( self::HOOK, array( self::WP_CRON_KEY ), self::WP_CRON_KEY ) );

		self::assertSame( 1, $this->run_next_due_cron_event(), 'WP-Cron must deliver the unknown occurrence' );

		self::assertFalse( $scheduler->is_scheduled( self::HOOK, array( self::WP_CRON_KEY ), self::WP_CRON_KEY ), 'The inline clear must remove the recurring WP-Cron successor' );
		self::assertTrue(
			\array_any(
				$log_records,
				static fn ( array $record ): bool => 'warning' === $record[0] && array(
					'registration_key' => self::WP_CRON_KEY,
					'converged'        => true,
				) === $record[2]
			),
			'The public log hook must report authoritative inline convergence'
		);
	}

	/**
	 * A complete-before-repeat convergence race re-records its intent when the recurring successor delivers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Action Scheduler marks a recurring action complete before creating its successor, exposing a cleanup gap that public scheduler reads cannot distinguish from authoritative convergence.
	 *
	 * @return  void
	 */
	public function test_complete_before_repeat_race_re_records_the_intent_on_the_successor_delivery(): void {
		$intent_option = 'a8csp_bgje_cleanup_intent_' . \hash( 'sha256', self::KEY );
		$this->expect_option( $intent_option );
		\remove_action( 'a8csp_jobs_engine/log', array( ErrorLogSink::class, 'log' ), 10 );

		$cleanup_intents = $this->cleanup_intents();

		$action_id = \as_schedule_recurring_action( \time() - 1, 300, self::HOOK, array( self::KEY ), self::KEY, true, 10 );
		self::assertGreaterThan( 0, $action_id );

		$store                         = $this->action_scheduler_store();
		$missing_intent                = new \stdClass();
		$completed_hook_calls          = 0;
		$gap_status                    = null;
		$gap_intent_before_convergence = null;
		$gap_chain_present             = null;
		$gap_intent_after_convergence  = null;
		$gap_pending_ids               = null;
		$completed_hook                = static function ( int $completed_action_id ) use ( $action_id, $cleanup_intents, $intent_option, $missing_intent, $store, &$completed_hook_calls, &$gap_status, &$gap_intent_before_convergence, &$gap_chain_present, &$gap_intent_after_convergence, &$gap_pending_ids ): void {
			if ( $action_id !== $completed_action_id ) {
				return;
			}

			++$completed_hook_calls;
			$gap_status                    = $store->get_status( (string) $completed_action_id );
			$gap_intent_before_convergence = \get_option( $intent_option, $missing_intent );
			$gap_chain_present             = \as_has_scheduled_action( self::HOOK, array( self::KEY ), self::KEY );
			$cleanup_intents->converge_pending_intents();
			$gap_intent_after_convergence = \get_option( $intent_option, $missing_intent );
			$gap_pending_ids              = $store->query_actions(
				array(
					'hook'     => self::HOOK,
					'args'     => array( self::KEY ),
					'group'    => self::KEY,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				)
			);
		};
		\add_action( 'action_scheduler_completed_action', $completed_hook, 10, 1 );

		self::assertSame( 1, $this->run_next_due_action() );
		\remove_action( 'action_scheduler_completed_action', $completed_hook, 10 );

		self::assertSame( 1, $completed_hook_calls );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $gap_status );
		self::assertIsArray( $gap_intent_before_convergence );
		self::assertSame( self::KEY, $gap_intent_before_convergence['key'] ?? null );
		self::assertFalse( $gap_chain_present, 'The completed action is clear before repeat creates its successor' );
		self::assertSame( $missing_intent, $gap_intent_after_convergence );
		self::assertSame( array(), $gap_pending_ids );
		self::assertSame( $missing_intent, \get_option( $intent_option, $missing_intent ) );

		$successor_ids = $store->query_actions(
			array(
				'hook'     => self::HOOK,
				'args'     => array( self::KEY ),
				'group'    => self::KEY,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $successor_ids );
		self::assertCount( 1, $successor_ids, 'Repeat must birth one successor after the gap convergence returns' );
		self::assertIsString( $successor_ids[0] ?? null );
		$successor_id = $successor_ids[0];
		self::assertNotSame( (string) $action_id, $successor_id );

		$successor = $store->fetch_action( $successor_id );
		self::assertInstanceOf( \ActionScheduler_Action::class, $successor );
		self::assertSame( self::HOOK, $successor->get_hook() );
		self::assertSame( array( self::KEY ), $successor->get_args() );
		self::assertSame( self::KEY, $successor->get_group() );
		self::assertTrue( $successor->get_schedule()->is_recurring() );

		$runner = \ActionScheduler::runner();
		self::assertInstanceOf( \ActionScheduler_QueueRunner::class, $runner );
		$runner->process_action( (int) $successor_id, 'Integration Test' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $successor_id ) );
		self::assertSame( 1, $completed_hook_calls );

		$re_recorded_intent = \get_option( $intent_option, null );
		self::assertIsArray( $re_recorded_intent );
		self::assertCount( 2, $re_recorded_intent );
		self::assertSame( self::KEY, $re_recorded_intent['key'] ?? null );
		self::assertIsInt( $re_recorded_intent['created_at'] ?? null );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a cleanup-intent convergence seam over the live durable state.
	 *
	 * Convergence state is durable option rows rather than object state, so a fresh
	 * instance wired like the production graph converges the same pending intents.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  CleanupIntents
	 */
	private function cleanup_intents(): CleanupIntents {
		global $wpdb;
		self::assertInstanceOf( \wpdb::class, $wpdb );

		$rows   = new OptionRows( $wpdb );
		$logger = new HookLogger();

		return new CleanupIntents(
			new ScheduleRegistry( $rows, $logger ),
			new SchedulerFacade(
				array(
					new ActionSchedulerBackend(),
					new WPCronBackend(),
				)
			),
			$rows,
			new SystemClock(),
			$logger
		);
	}

	/**
	 * Returns pending Action Scheduler occurrences for one registration key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key Complete `{owner}:{name}` identity.
	 *
	 * @return  list<string>
	 */
	private function pending_schedule_action_ids( string $registration_key ): array {
		$action_ids = $this->action_scheduler_store()->query_actions(
			array(
				'hook'     => self::HOOK,
				'args'     => array( $registration_key ),
				'group'    => $registration_key,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $action_ids );
		$typed_action_ids = array();
		foreach ( $action_ids as $action_id ) {
			self::assertIsString( $action_id );
			$typed_action_ids[] = $action_id;
		}

		return $typed_action_ids;
	}

	/**
	 * Returns one persisted registration's next-due token.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Owner identity.
	 * @param   string $name  Schedule identity.
	 *
	 * @return  int
	 */
	private function registration_next_due( string $owner, string $name ): int {
		$owner_rows = \get_option( ScheduleRegistry::option_name( $owner ), null );
		self::assertIsArray( $owner_rows );
		$registration = $owner_rows[ JobIdentity::compose( $owner, $name, true ) ] ?? null;
		self::assertIsArray( $registration );
		$next_due = $registration['next_due'] ?? null;
		self::assertIsInt( $next_due );

		return $next_due;
	}

	/**
	 * Makes one persisted registration due without changing its backend occurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner    Owner identity.
	 * @param   string $name     Schedule identity.
	 * @param   int    $next_due Replacement next-due token.
	 *
	 * @return  void
	 */
	private function set_registration_next_due( string $owner, string $name, int $next_due ): void {
		$option_name = ScheduleRegistry::option_name( $owner );
		$owner_rows  = \get_option( $option_name, null );
		self::assertIsArray( $owner_rows );
		$identity     = JobIdentity::compose( $owner, $name, true );
		$registration = $owner_rows[ $identity ] ?? null;
		self::assertIsArray( $registration );
		$registration['next_due'] = $next_due;
		$owner_rows[ $identity ]  = $registration;
		self::assertTrue( \update_option( $option_name, $owner_rows, false ), 'The live redeclaration must be due before its retained occurrence fires' );
	}

	// endregion.
}
