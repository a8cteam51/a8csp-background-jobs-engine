<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Container;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingTask;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the owner-bound front door and its lifecycle contract.
 *
 */
#[CoversFunction( 'a8csp_bgte' )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ApiTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads the WordPress lifecycle and persistence seams with the public front door.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-cron-stubs.php';
		require_once __DIR__ . '/wp-lock-stubs.php';
		require_once __DIR__ . '/wp-time-constant-stubs.php';
		require_once __DIR__ . '/Engine/Backends/wp-json-encode-stub.php';
		require_once \dirname( __DIR__, 2 ) . '/functions.php';
	}

	/**
	 * Resets the lifecycle and storage ledgers.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_did_actions']          = array();
		$GLOBALS['a8csp_bgte_test_doing_actions']        = array();
		$GLOBALS['a8csp_bgte_test_hooks']                = array();
		$GLOBALS['a8csp_bgte_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_filter_registrations'] = array();
		$GLOBALS['a8csp_bgte_test_fired_actions']        = array();
		$GLOBALS['a8csp_bgte_test_action_callbacks']     = array();
		$GLOBALS['a8csp_bgte_test_action_throwables']    = array();
		$GLOBALS['a8csp_bgte_test_filter_values']        = array();
		$GLOBALS['a8csp_bgte_test_options']              = array();
		$GLOBALS['a8csp_bgte_test_option_calls']         = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']      = array();
		$GLOBALS['a8csp_bgte_test_cron_array']           = array();
		$GLOBALS['a8csp_bgte_test_cron_calls']           = array();
		$GLOBALS['a8csp_bgte_test_cron_results']         = array();
		$GLOBALS['a8csp_bgte_test_cron_event_sequence']  = 0;
		$GLOBALS['a8csp_bgte_test_blog_id']              = 1;
		$GLOBALS['wpdb']                                 = new WpdbLockSpy();
	}

	// endregion.

	// region TESTS.

	/**
	 * Access before the earliest safe hook fails with the exact availability contract.
	 *
	 * @return  void
	 */
	public function test_access_before_plugins_loaded_throws_with_the_earliest_safe_hook(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs(
			'The background tasks consumer is available from the plugins_loaded hook; call a8csp_bgte() from a plugins_loaded callback or later.'
		);

		\a8csp_bgte( 'consumer-plugin' );
	}

	/**
	 * Resolution from inside plugins_loaded boots lazily and returns a usable owner-bound facade.
	 *
	 * @return  void
	 */
	public function test_access_during_plugins_loaded_returns_a_working_consumer_at_any_priority(): void {
		$GLOBALS['a8csp_bgte_test_did_actions']   = array( 'plugins_loaded' => 1 );
		$GLOBALS['a8csp_bgte_test_doing_actions'] = array( 'plugins_loaded' );

		$consumer = \a8csp_bgte( 'consumer-plugin' );
		$task     = new RecordingTask( 'sync' );
		$consumer->tasks()->register( $task );

		self::assertInstanceOf( Consumer::class, $consumer );
		self::assertInstanceOf( Engine::class, Container::get_engine() );
	}

	/**
	 * Resolution remains available after plugins_loaded completes.
	 *
	 * @return  void
	 */
	public function test_access_after_plugins_loaded_returns_a_consumer(): void {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'plugins_loaded' => 1 );

		self::assertInstanceOf( Consumer::class, \a8csp_bgte( 'consumer-plugin' ) );
	}

	/**
	 * Equal local names under different owners retain distinct runtime and storage identities.
	 *
	 * @return  void
	 */
	public function test_two_owners_can_register_enqueue_and_run_the_same_local_task_name(): void {
		$GLOBALS['a8csp_bgte_test_did_actions'] = array( 'plugins_loaded' => 1 );

		$left       = \a8csp_bgte( 'owner-left' );
		$right      = \a8csp_bgte( 'owner-right' );
		$left_task  = new RecordingTask( 'sync' );
		$right_task = new RecordingTask( 'sync' );
		$left->tasks()->register( $left_task );
		$right->tasks()->register( $right_task );

		$left_result  = $left->tasks()->enqueue( 'sync', array( 'owner' => 'left' ) );
		$right_result = $right->tasks()->enqueue( 'sync', array( 'owner' => 'right' ) );
		if ( $left_result->is_failure() ) {
			self::fail( 'The left owner enqueue returned an unexpected failure.' );
		}
		if ( $right_result->is_failure() ) {
			self::fail( 'The right owner enqueue returned an unexpected failure.' );
		}

		/** @var array<string, mixed> $options */
		$options = $GLOBALS['a8csp_bgte_test_options'];
		$wpdb    = $GLOBALS['wpdb'];
		self::assertInstanceOf( WpdbLockSpy::class, $wpdb );
		$identities       = array( 'owner-left:sync', 'owner-right:sync' );
		$option_names     = \array_keys( $options );
		$raw_option_names = \array_keys( $wpdb->rows );
		foreach ( $identities as $identity ) {
			self::assertTrue( self::has_option_with_prefix( $raw_option_names, 'a8csp_bgte_lock_' . $identity . '_' ) );
			self::assertTrue( self::has_option_with_prefix( $option_names, 'a8csp_bgte_run_' . $identity . '_' ) );
			self::assertContains( 'a8csp_bgte_latest_' . $identity, $raw_option_names );
		}

		/** @var list<array{hook_name: string, callback: callable, priority: int, accepted_args: int}> $action_registrations */
		$action_registrations = $GLOBALS['a8csp_bgte_test_action_registrations'];
		$run_callback         = null;
		foreach ( $action_registrations as $registration ) {
			if ( 'a8csp_background_tasks/run' === $registration['hook_name'] ) {
				$run_callback = $registration['callback'];
				break;
			}
		}
		self::assertIsCallable( $run_callback );

		/** @var array<int, array<string, array<int, array{schedule: string|false, args: list<mixed>}>>> $cron */
		$cron       = $GLOBALS['a8csp_bgte_test_cron_array'];
		$run_events = array();
		foreach ( $cron as $hooks ) {
			foreach ( $hooks['a8csp_background_tasks/run'] ?? array() as $event ) {
				$run_events[] = $event['args'];
			}
		}
		\usort( $run_events, static fn ( array $left_event, array $right_event ): int => $left_event[0] <=> $right_event[0] );
		self::assertSame( $identities, \array_column( $run_events, 0 ) );
		foreach ( $run_events as $event_args ) {
			$run_callback( ...$event_args );
		}

		self::assertSame( array( array( 'owner' => 'left' ) ), $left_task->calls );
		self::assertSame( array( array( 'owner' => 'right' ) ), $right_task->calls );
		/** @var list<array{hook_name: string, args: list<mixed>}> $fired_actions */
		$fired_actions = $GLOBALS['a8csp_bgte_test_fired_actions'];
		$hook_names    = \array_column( $fired_actions, 'hook_name' );
		foreach ( $identities as $identity ) {
			self::assertContains( 'a8csp_background_tasks/started/' . $identity, $hook_names );
			self::assertContains( 'a8csp_background_tasks/completed/' . $identity, $hook_names );
		}
	}

	/**
	 * Invalid and reserved owners fail at the front door.
	 *
	 * @param   string $owner Invalid consumer owner.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_owners' )]
	public function test_front_door_rejects_invalid_or_reserved_owners( string $owner ): void {
		$GLOBALS['a8csp_bgte_test_did_actions']   = array( 'plugins_loaded' => 1 );
		$GLOBALS['a8csp_bgte_test_doing_actions'] = array( 'plugins_loaded' );

		$this->expectException( \InvalidArgumentException::class );
		\a8csp_bgte( $owner );
	}

	/**
	 * Supplies the required owner rejection table.
	 *
	 * @return  array<string, array{owner: string}>
	 */
	public static function invalid_owners(): array {
		return array(
			'empty'           => array( 'owner' => '' ),
			'uppercase'       => array( 'owner' => 'Consumer' ),
			'colon'           => array( 'owner' => 'consumer:plugin' ),
			'reserved owner'  => array( 'owner' => 'a8csp-bgte' ),
			'reserved prefix' => array( 'owner' => 'a8csp-bgte-addon' ),
		);
	}

	/**
	 * The public surface contains only the owner-bound consumer front door.
	 *
	 * @return  void
	 */
	public function test_old_engine_and_per_concept_functions_are_absent(): void {
		self::assertFalse( \function_exists( 'a8csp_bgte_engine' ) );
		self::assertFalse( \function_exists( 'a8csp_bgte_enqueue_task' ) );
		self::assertFalse( \function_exists( 'a8csp_bgte_start_batch' ) );
		self::assertFalse( \function_exists( 'a8csp_bgte_sync_schedules' ) );
		self::assertFalse( \function_exists( 'a8csp_bgte_run_schedule_now' ) );
		self::assertFalse( \function_exists( 'a8csp_bgte_retry_failed_run' ) );
		self::assertFalse( \function_exists( 'a8csp_bgte_cancel_run' ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns whether any option name begins with the complete expected prefix.
	 *
	 * @phpstan-param list<string> $option_names
	 *
	 * @param   array  $option_names Option names to inspect.
	 * @param   string $prefix       Required option-name prefix.
	 *
	 * @return  bool
	 */
	private static function has_option_with_prefix( array $option_names, string $prefix ): bool {
		foreach ( $option_names as $option_name ) {
			if ( \str_starts_with( $option_name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	// endregion.
}
