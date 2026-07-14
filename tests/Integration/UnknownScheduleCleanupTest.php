<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\MaintenanceTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Logging\ErrorLogSink;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;

/**
 * Verifies durable cleanup intents and live maintenance sweeps converge unknown recurring chains.
 */
final class UnknownScheduleCleanupTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Schedule-delivery hook shared with the live engine. */
	private const HOOK = 'a8csp/background_tasks/schedule_due';

	/** Unknown registration identity isolated to this integration test. */
	private const KEY = 'integration-owner:unknown-cleanup';

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
		\remove_action( 'a8csp/background_tasks/log', array( ErrorLogSink::class, 'log' ), 10 );
		\add_action(
			'a8csp/background_tasks/log',
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
	 * A complete-before-repeat convergence race re-records its intent when the recurring successor delivers.
	 *
	 * @return  void
	 */
	public function test_complete_before_repeat_race_re_records_the_intent_on_the_successor_delivery(): void {
		$intent_option = 'a8csp_bgte_cleanup_' . \hash( 'sha256', self::KEY );
		$this->expect_option( $intent_option );
		\remove_action( 'a8csp/background_tasks/log', array( ErrorLogSink::class, 'log' ), 10 );

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

	// endregion.
}
