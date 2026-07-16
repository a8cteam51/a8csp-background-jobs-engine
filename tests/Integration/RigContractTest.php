<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * Verifies the integration rig restores request-local state and clears persistent test data.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class RigContractTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Hook populated by the plugin before the parent fixture captures its snapshot. */
	private const EXISTING_HOOK = 'plugins_loaded';

	/** Hook introduced after the probe snapshot. */
	private const NEW_HOOK = 'a8csp_bgte/rig_contract/new';

	/** Engine option used to exercise the leftover declaration contract. */
	private const FAILED_PROBE_OPTION = 'a8csp_bgte_failed_runs_probe';

	/** Action Scheduler hook used to exercise custom-table cleanup. */
	private const ACTION_SCHEDULER_HOOK = 'a8csp_bgte/rig_contract/action_scheduler';

	// endregion.

	// region TESTS.

	/**
	 * Hook restoration preserves the captured listener while removing later listeners from old and new hooks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_hook_restore_preserves_snapshot_and_removes_later_listeners(): void {
		$existing_hook_listener = static function (): void {};
		$new_hook_listener      = static fn ( mixed $value = null ): mixed => $value;

		$this->restore_wordpress_hooks();
		$this->snapshot_wordpress_hooks();

		$action_count_before = \did_action( self::NEW_HOOK );
		$filter_count_before = \did_filter( self::NEW_HOOK );
		$plugin_listener     = \has_action( self::EXISTING_HOOK, 'a8csp_bgte_plugin' );
		self::assertIsInt( $plugin_listener, 'The plugin listener must exist before the hook-restoration probe runs' );

		try {
			\add_action( self::EXISTING_HOOK, $existing_hook_listener );
			\add_filter( self::NEW_HOOK, $new_hook_listener );
			\do_action( self::NEW_HOOK );
			\apply_filters( self::NEW_HOOK, 'probe' );

			$this->restore_wordpress_hooks();

			self::assertSame( $plugin_listener, \has_action( self::EXISTING_HOOK, 'a8csp_bgte_plugin' ) );
			self::assertFalse( \has_action( self::EXISTING_HOOK, $existing_hook_listener ) );
			self::assertFalse( \has_filter( self::NEW_HOOK, $new_hook_listener ) );
			self::assertSame( $action_count_before, \did_action( self::NEW_HOOK ) );
			self::assertSame( $filter_count_before, \did_filter( self::NEW_HOOK ) );
		} finally {
			$this->restore_wordpress_hooks();
		}
	}

	/**
	 * Undeclared engine state fails hygiene and is removed before teardown rechecks the contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[Group( 'degraded' )]
	public function test_option_hygiene_rejects_undeclared_state(): void {
		self::assertTrue( \update_option( self::FAILED_PROBE_OPTION, 'sentinel', false ) );

		try {
			$this->assert_engine_option_hygiene();
		} catch ( ExpectationFailedException $exception ) {
			self::assertStringContainsString( 'Delete transient engine rows before test completion or declare deliberate leftovers with expect_option().', $exception->getMessage() );
			self::assertStringContainsString( 'Unexpected rows: ' . self::FAILED_PROBE_OPTION, $exception->getMessage() );

			return;
		} finally {
			\delete_option( self::FAILED_PROBE_OPTION );
		}

		self::fail( 'Option hygiene accepted undeclared engine state.' );
	}

	/**
	 * Declared engine state passes hygiene and is removed before teardown.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[Group( 'degraded' )]
	public function test_option_hygiene_accepts_declared_state(): void {
		self::assertTrue( \update_option( self::FAILED_PROBE_OPTION, 'sentinel', false ) );

		try {
			$this->expect_option( self::FAILED_PROBE_OPTION );
			$this->assert_engine_option_hygiene();
		} finally {
			\delete_option( self::FAILED_PROBE_OPTION );
		}
	}

	/**
	 * Action Scheduler cleanup removes one scheduled occurrence from facade reads.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_action_scheduler_cleanup_removes_pending_actions(): void {
		$scheduler = $this->scheduler_facade_with_action_scheduler_probe( static fn (): bool => true );
		$scheduled = $scheduler->schedule_single( self::ACTION_SCHEDULER_HOOK, \time() + \HOUR_IN_SECONDS );
		self::assertInstanceOf( Success::class, $scheduled );
		self::assertTrue( $scheduler->is_scheduled( self::ACTION_SCHEDULER_HOOK ) );

		$this->truncate_action_scheduler_tables();

		self::assertFalse( $scheduler->is_scheduled( self::ACTION_SCHEDULER_HOOK ) );
	}

	// endregion.
}
