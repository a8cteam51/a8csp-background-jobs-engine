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
}
