<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Backends\WPCronBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Logging\ErrorLogSink;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies durable cleanup intents and live maintenance sweeps converge unknown recurring chains.
 */
final class UnknownScheduleCleanupTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Schedule-delivery hook shared with the live engine. */
	private const HOOK = 'a8csp_background_tasks/schedule_due';

	/** Unknown registration identity isolated to this integration test. */
	private const KEY = 'integration-owner:unknown-cleanup';

	/** Owner component of the unknown registration identity. */
	private const OWNER = 'integration-owner';

	/** Schedule component of the unknown registration identity. */
	private const SCHEDULE = 'unknown-cleanup';

	/** Task invoked by the legitimately re-declared schedule. */
	private const REDECLARED_TASK = 'integration-unknown-cleanup-redeclared-task';

	/** Unknown registration identity isolated to the degraded WP-Cron probe. */
	private const WP_CRON_KEY = 'integration-owner:unknown-wp-cron-cleanup';

	/** Engine-reserved maintenance registration identity. */
	private const MAINTENANCE_KEY = 'a8csp-bgte:maintenance';

	// endregion.

	// region TESTS.

	/**
	 * An unknown delivery records an intent that the live maintenance schedule converges.
	 *
	 * @return  void
	 */
	public function test_live_maintenance_sweep_converges_the_unknown_recurring_chain(): void {
		$intent_option = 'a8csp_bgte_cleanup_' . \hash( 'sha256', self::KEY );
		$this->expect_option( 'a8csp_bgte_schedules' );
		$this->expect_option( 'a8csp_bgte_latest_' . MaintenanceTask::NAME );

		/** @var list<array{string, string, array<array-key, mixed>}> $log_records */
		$log_records = array();
		\remove_action( 'a8csp_background_tasks/log', array( ErrorLogSink::class, 'log' ), 10 );
		\add_action(
			'a8csp_background_tasks/log',
			static function ( string $level, string $message, array $context ) use ( &$log_records ): void {
				$log_records[] = array( $level, $message, $context );
			},
			10,
			3
		);

		$action_id = \as_schedule_recurring_action(
			\time() - 1,
			300,
			self::HOOK,
			array( self::KEY ),
			self::KEY,
			true,
			10
		);
		self::assertGreaterThan( 0, $action_id );

		self::assertSame( 1, $this->run_next_due_action() );
		$intent = \get_option( $intent_option, null );
		self::assertIsArray( $intent, 'The unknown delivery must persist its exact cleanup-intent option' );
		self::assertCount( 2, $intent );
		self::assertSame( self::KEY, $intent['key'] ?? null );
		self::assertIsInt( $intent['created_at'] ?? null );

		$store       = $this->action_scheduler_store();
		$pending_ids = $store->query_actions(
			array(
				'group'    => self::KEY,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
		self::assertIsArray( $pending_ids );
		self::assertCount( 1, $pending_ids, 'Only the recurring successor may remain after the unknown callback' );
		self::assertIsString( $pending_ids[0] ?? null );
		$successor = $store->fetch_action( $pending_ids[0] );
		self::assertInstanceOf( \ActionScheduler_Action::class, $successor );
		self::assertSame( self::HOOK, $successor->get_hook() );
		self::assertSame( array( self::KEY ), $successor->get_args() );
		self::assertContains(
			array(
				'warning',
				'Unknown schedule registration "integration-owner:unknown-cleanup" was delivered; re-declare the schedule or remove the leftover occurrence.',
				array(
					'registration_key' => self::KEY,
					'converged'        => false,
				),
			),
			$log_records,
			'The unknown delivery must publish the current registration warning'
		);

		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before maintenance convergence' );
		$synced = $engine->schedules()->sync_owner(
			'a8csp-bgte',
			array(
				new Schedule(
					'maintenance',
					Recurrence::every( \HOUR_IN_SECONDS ),
					MaintenanceTask::NAME,
					array(),
					OverlapPolicy::Skip,
					CatchUpPolicy::RunOnce
				),
			)
		);
		self::assertInstanceOf( Success::class, $synced, 'The reserved maintenance schedule must re-synchronize' );
		self::assertTrue( $synced->value );

		$registry = \get_option( 'a8csp_bgte_schedules', null );
		self::assertIsArray( $registry );
		$maintenance_owner = $registry['a8csp-bgte'] ?? null;
		self::assertIsArray( $maintenance_owner );
		$maintenance_registration = $maintenance_owner['maintenance'] ?? null;
		self::assertIsArray( $maintenance_registration );
		$maintenance_registration['next_due'] = \time() - 1;
		$maintenance_owner['maintenance']     = $maintenance_registration;
		$registry['a8csp-bgte']               = $maintenance_owner;
		self::assertTrue(
			\update_option( 'a8csp_bgte_schedules', $registry, false ),
			'The maintenance occurrence must be due before its live delivery fires'
		);

		\do_action( self::HOOK, self::MAINTENANCE_KEY );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must execute the live maintenance task' );

		self::assertFalse(
			\as_has_scheduled_action( self::HOOK, array( self::KEY ), self::KEY ),
			'The maintenance sweep must verify that the unknown recurring chain is clear'
		);
		$missing_intent = new \stdClass();
		self::assertSame(
			$missing_intent,
			\get_option( $intent_option, $missing_intent ),
			'The authoritative verified-clear must consume the observed cleanup intent'
		);
		self::assertSame(
			array(),
			$store->query_actions(
				array(
					'group'    => self::KEY,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				)
			),
			'No pending action may remain in the unknown registration group after convergence'
		);
	}

	/**
	 * A live redeclaration consumes its stale intent without clearing the replacement chain.
	 *
	 * @return  void
	 */
	public function test_sweep_convergence_preserves_a_redeclared_action_scheduler_chain(): void {
		$intent_option = 'a8csp_bgte_cleanup_' . \hash( 'sha256', self::KEY );
		$this->expect_option( 'a8csp_bgte_schedules' );
		$this->expect_option( 'a8csp_bgte_latest_' . self::REDECLARED_TASK );
		\remove_action( 'a8csp_background_tasks/log', array( ErrorLogSink::class, 'log' ), 10 );

		$unknown_action_id = \as_schedule_recurring_action(
			\time() - 1,
			300,
			self::HOOK,
			array( self::KEY ),
			self::KEY,
			true,
			10
		);
		self::assertGreaterThan( 0, $unknown_action_id );
		self::assertSame( 1, $this->run_next_due_action(), 'Action Scheduler must deliver the unknown occurrence' );

		$intent = \get_option( $intent_option, null );
		self::assertIsArray( $intent, 'The unknown delivery must leave its cleanup intent for the sweep' );
		self::assertSame( self::KEY, $intent['key'] ?? null );

		$store                 = $this->action_scheduler_store();
		$unknown_successor_ids = $this->pending_schedule_action_ids( self::KEY );
		self::assertCount( 1, $unknown_successor_ids, 'The unknown recurrence must birth one successor' );
		$unknown_successor_id = $unknown_successor_ids[0];

		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before schedule redeclaration' );
		$task = new RecordingTask( self::REDECLARED_TASK );
		$engine->tasks()->register( $task );
		$schedule = new Schedule(
			self::SCHEDULE,
			Recurrence::every( 300 ),
			self::REDECLARED_TASK,
			array( 'generation' => 'redeclared' ),
			OverlapPolicy::Skip,
			CatchUpPolicy::RunOnce
		);
		$synced   = $engine->schedules()->sync( self::OWNER, array( $schedule ) );
		self::assertInstanceOf( Success::class, $synced, 'The unknown key must accept a legitimate live redeclaration' );
		self::assertTrue( $synced->value );
		self::assertSame(
			\ActionScheduler_Store::STATUS_CANCELED,
			$store->get_status( $unknown_successor_id ),
			'Redeclaration must cancel the stale unknown-chain successor before creating its live chain'
		);

		$live_action_ids = $this->pending_schedule_action_ids( self::KEY );
		self::assertCount( 1, $live_action_ids, 'Redeclaration must persist exactly one live Action Scheduler occurrence' );
		$live_action_id = $live_action_ids[0];
		self::assertNotSame( $unknown_successor_id, $live_action_id );
		$live_scheduled_at = $store->get_date( $live_action_id );
		self::assertInstanceOf( \DateTime::class, $live_scheduled_at );
		$registry_next_due = $this->registration_next_due( self::OWNER, self::SCHEDULE );
		self::assertSame(
			$registry_next_due,
			$live_scheduled_at->getTimestamp(),
			'The live Action Scheduler occurrence must use the redeclared registration next-due token'
		);

		$this->occurrence_delivery()->converge_pending_intents();

		$missing_intent = new \stdClass();
		self::assertSame(
			$missing_intent,
			\get_option( $intent_option, $missing_intent ),
			'The sweep must consume the intent after finding the live registration'
		);
		self::assertSame(
			array( $live_action_id ),
			$this->pending_schedule_action_ids( self::KEY ),
			'The sweep must retain the exact redeclared Action Scheduler occurrence'
		);
		self::assertSame( \ActionScheduler_Store::STATUS_PENDING, $store->get_status( $live_action_id ) );
		$scheduled_at_after_sweep = $store->get_date( $live_action_id );
		self::assertInstanceOf( \DateTime::class, $scheduled_at_after_sweep );
		self::assertSame( $live_scheduled_at->getTimestamp(), $scheduled_at_after_sweep->getTimestamp() );
		self::assertSame( $registry_next_due, $this->registration_next_due( self::OWNER, self::SCHEDULE ) );

		$this->set_registration_next_due( self::OWNER, self::SCHEDULE, \time() - 1 );
		$runner = \ActionScheduler::runner();
		self::assertInstanceOf( \ActionScheduler_QueueRunner::class, $runner );
		$runner->process_action( (int) $live_action_id, 'Integration Test' );
		self::assertSame( \ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $live_action_id ) );
		self::assertGreaterThan(
			\time(),
			$this->registration_next_due( self::OWNER, self::SCHEDULE ),
			'The retained occurrence must advance the live registration when delivered'
		);
		self::assertCount(
			1,
			$this->pending_schedule_action_ids( self::KEY ),
			'The retained recurring action must create its live successor after delivery'
		);
		self::assertSame(
			1,
			$this->run_matching_due_action(
				static fn ( string $hook, array $args ): bool => 'a8csp_background_tasks/run' === $hook
					&& self::REDECLARED_TASK === ( $args[0] ?? null )
			),
			'The retained schedule occurrence must dispatch its declared task'
		);
		self::assertSame( array( array( 'generation' => 'redeclared' ) ), $task->calls );
	}

	/**
	 * A WP-Cron-only clearing pass consumes an unknown-chain intent inline.
	 *
	 * @return  void
	 */
	#[Group( 'degraded' )]
	public function test_unknown_wp_cron_chain_converges_inline(): void {
		$intent_option = 'a8csp_bgte_cleanup_' . \hash( 'sha256', self::WP_CRON_KEY );
		/** @var list<array{string, string, array<array-key, mixed>}> $log_records */
		$log_records = array();
		\remove_action( 'a8csp_background_tasks/log', array( ErrorLogSink::class, 'log' ), 10 );
		\add_action(
			'a8csp_background_tasks/log',
			static function ( string $level, string $message, array $context ) use ( &$log_records ): void {
				$log_records[] = array( $level, $message, $context );
			},
			10,
			3
		);

		$scheduled = ( new WPCronBackend() )->schedule_recurring(
			self::HOOK,
			300,
			array( self::WP_CRON_KEY ),
			\time() - 1,
			self::WP_CRON_KEY
		);
		self::assertInstanceOf( Success::class, $scheduled, 'WP-Cron must persist the unknown recurring occurrence' );
		self::assertCount(
			1,
			$this->wordpress_cron_events( self::HOOK, array( self::WP_CRON_KEY ) ),
			'The degraded fixture must begin with one unknown WP-Cron chain'
		);

		self::assertSame( 1, $this->run_next_due_cron_event(), 'WP-Cron must deliver the unknown occurrence' );

		$missing_intent = new \stdClass();
		self::assertSame(
			$missing_intent,
			\get_option( $intent_option, $missing_intent ),
			'The authoritative WP-Cron-only clear must consume the intent inline'
		);
		self::assertSame(
			array(),
			$this->wordpress_cron_events( self::HOOK, array( self::WP_CRON_KEY ) ),
			'The inline clear must remove the recurring WP-Cron successor'
		);
		self::assertContains(
			array(
				'warning',
				'Unknown schedule registration "integration-owner:unknown-wp-cron-cleanup" was delivered; re-declare the schedule or remove the leftover occurrence.',
				array(
					'registration_key' => self::WP_CRON_KEY,
					'converged'        => true,
				),
			),
			$log_records,
			'The unknown delivery must report authoritative inline convergence'
		);
	}

	/**
	 * A complete-before-repeat convergence race re-records its intent when the recurring successor delivers.
	 *
	 * @return  void
	 */
	public function test_complete_before_repeat_race_re_records_the_intent_on_the_successor_delivery(): void {
		$intent_option = 'a8csp_bgte_cleanup_' . \hash( 'sha256', self::KEY );
		$this->expect_option( $intent_option );
		\remove_action( 'a8csp_background_tasks/log', array( ErrorLogSink::class, 'log' ), 10 );

		$engine = \a8csp_bgte_engine();
		self::assertNotNull( $engine, 'The live plugin must publish its engine before convergence' );
		$delivery = $this->occurrence_delivery();

		$action_id = \as_schedule_recurring_action(
			\time() - 1,
			300,
			self::HOOK,
			array( self::KEY ),
			self::KEY,
			true,
			10
		);
		self::assertGreaterThan( 0, $action_id );

		$store                         = $this->action_scheduler_store();
		$missing_intent                = new \stdClass();
		$completed_hook_calls          = 0;
		$gap_status                    = null;
		$gap_intent_before_convergence = null;
		$gap_chain_present             = null;
		$gap_intent_after_convergence  = null;
		$gap_pending_ids               = null;
		$completed_hook                = static function ( int $completed_action_id ) use (
			$action_id,
			$delivery,
			$intent_option,
			$missing_intent,
			$store,
			&$completed_hook_calls,
			&$gap_status,
			&$gap_intent_before_convergence,
			&$gap_chain_present,
			&$gap_intent_after_convergence,
			&$gap_pending_ids
		): void {
			if ( $action_id !== $completed_action_id ) {
				return;
			}

			++$completed_hook_calls;
			$gap_status                    = $store->get_status( (string) $completed_action_id );
			$gap_intent_before_convergence = \get_option( $intent_option, $missing_intent );
			$gap_chain_present             = \as_has_scheduled_action(
				self::HOOK,
				array( self::KEY ),
				self::KEY
			);
			$delivery->converge_pending_intents();
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
	 * Returns the live occurrence-delivery callback registered on the shared schedule hook.
	 *
	 * @return  OccurrenceDelivery
	 */
	private function occurrence_delivery(): OccurrenceDelivery {
		$wp_filter = $GLOBALS['wp_filter'] ?? null;
		if ( ! \is_array( $wp_filter ) ) {
			throw new \LogicException( 'The WordPress hook registry is unavailable.' );
		}

		$hook = $wp_filter[ self::HOOK ] ?? null;
		self::assertInstanceOf( \WP_Hook::class, $hook, 'The live schedule hook must be registered' );

		foreach ( $hook->callbacks as $callbacks ) {
			if ( ! \is_array( $callbacks ) ) {
				continue;
			}

			foreach ( $callbacks as $callback ) {
				if ( ! \is_array( $callback ) ) {
					continue;
				}

				$function = $callback['function'] ?? null;
				if ( ! \is_array( $function ) ) {
					continue;
				}

				$object = $function[0] ?? null;
				$method = $function[1] ?? null;
				if ( $object instanceof OccurrenceDelivery && 'handle_schedule_due' === $method ) {
					return $object;
				}
			}
		}

		throw new \LogicException( 'The live occurrence-delivery callback is unavailable.' );
	}

	/**
	 * Returns pending Action Scheduler occurrences for one registration key.
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
	 * @param   string $owner Owner identity.
	 * @param   string $name  Schedule identity.
	 *
	 * @return  int
	 */
	private function registration_next_due( string $owner, string $name ): int {
		$registry = \get_option( 'a8csp_bgte_schedules', null );
		self::assertIsArray( $registry );
		$owner_rows = $registry[ $owner ] ?? null;
		self::assertIsArray( $owner_rows );
		$registration = $owner_rows[ $name ] ?? null;
		self::assertIsArray( $registration );
		$next_due = $registration['next_due'] ?? null;
		self::assertIsInt( $next_due );

		return $next_due;
	}

	/**
	 * Makes one persisted registration due without changing its backend occurrence.
	 *
	 * @param   string $owner    Owner identity.
	 * @param   string $name     Schedule identity.
	 * @param   int    $next_due Replacement next-due token.
	 *
	 * @return  void
	 */
	private function set_registration_next_due( string $owner, string $name, int $next_due ): void {
		$registry = \get_option( 'a8csp_bgte_schedules', null );
		self::assertIsArray( $registry );
		$owner_rows = $registry[ $owner ] ?? null;
		self::assertIsArray( $owner_rows );
		$registration = $owner_rows[ $name ] ?? null;
		self::assertIsArray( $registration );
		$registration['next_due'] = $next_due;
		$owner_rows[ $name ]      = $registration;
		$registry[ $owner ]       = $owner_rows;
		self::assertTrue(
			\update_option( 'a8csp_bgte_schedules', $registry, false ),
			'The live redeclaration must be due before its retained occurrence fires'
		);
	}

	// endregion.
}
