<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\HookLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the PSR-3 adapter onto the engine's public log hook.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( HookLogger::class )]
final class HookLoggerTest extends TestCase {
	/**
	 * Satisfies the production boot guard and loads the recording action stub.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once __DIR__ . '/wp-hook-stubs.php';
	}

	/**
	 * Starts each test with an empty fired-action ledger.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_fired_actions'] = array();
	}

	/**
	 * Logging interpolates supported placeholders and retains the complete context on the exact hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_log_dispatches_the_interpolated_message_and_unchanged_context(): void {
		$stringable = new class() implements \Stringable {
			/** @return string */
			#[\Override]
			public function __toString(): string {
				return 'printable';
			}
		};
		$context    = array(
			'task_id'  => 42,
			'label'    => $stringable,
			'metadata' => array( 'attempt' => 2 ),
		);

		( new HookLogger() )->log(
			300,
			'Task {task_id}: {label}; {missing}; {metadata}.',
			$context
		);

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/log',
					'args'      => array(
						'300',
						'Task 42: printable; {missing}; {metadata}.',
						$context,
					),
				),
			),
			$GLOBALS['a8csp_bgte_test_fired_actions']
		);
	}

	/**
	 * A failing Stringable context value leaves its placeholder intact without aborting dispatch.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_throwing_stringable_leaves_placeholder_verbatim_and_dispatches_log(): void {
		$stringable = new class() implements \Stringable {
			/** @return string */
			#[\Override]
			public function __toString(): string {
				return throw new \RuntimeException( 'String conversion failed.' );
			}
		};
		$context    = array( 'label' => $stringable );

		( new HookLogger() )->info( 'Task {label} failed.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/log',
					'args'      => array(
						'info',
						'Task {label} failed.',
						$context,
					),
				),
			),
			$GLOBALS['a8csp_bgte_test_fired_actions']
		);
	}

	/**
	 * The inherited warning convenience method preserves its named PSR-3 level.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_inherited_warning_dispatches_warning_level(): void {
		( new HookLogger() )->warning( 'Task failed.' );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/log',
					'args'      => array( 'warning', 'Task failed.', array() ),
				),
			),
			$GLOBALS['a8csp_bgte_test_fired_actions']
		);
	}

	/**
	 * Plain string context values interpolate into matching placeholders.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_plain_string_context_value_is_interpolated(): void {
		$context = array( 'task' => 'email-digest' );

		( new HookLogger() )->info( 'Running {task}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/log',
					'args'      => array( 'info', 'Running email-digest.', $context ),
				),
			),
			$GLOBALS['a8csp_bgte_test_fired_actions']
		);
	}

	/**
	 * Null context values remain structured data and leave matching placeholders intact.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_null_context_value_leaves_placeholder_verbatim(): void {
		$context = array( 'task' => null );

		( new HookLogger() )->info( 'Running {task}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp/background_tasks/log',
					'args'      => array( 'info', 'Running {task}.', $context ),
				),
			),
			$GLOBALS['a8csp_bgte_test_fired_actions']
		);
	}
}
