<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Logging\HookLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;

/**
 * Pins the PSR-3 adapter onto the engine's public log hook.
 *
 */
#[CoversClass( HookLogger::class )]
final class HookLoggerTest extends TestCase {
	/**
	 * Satisfies the production boot guard and loads the recording action stub.
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
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_fired_actions']     = array();
		$GLOBALS['a8csp_bgte_test_action_callbacks']  = array();
		$GLOBALS['a8csp_bgte_test_action_throwables'] = array();
	}

	/**
	 * Logging interpolates supported placeholders and retains the complete context on the exact hook.
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
					'hook_name' => 'a8csp_background_tasks/log',
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
	 * Throwable context reaches subscribers unchanged without entering placeholder interpolation.
	 *
	 * @return  void
	 */
	public function test_log_passes_throwable_context_without_interpolating_it(): void {
		$throwable = new \RuntimeException( 'Consumer token secret.' );
		$context   = array(
			'exception' => $throwable,
			'task'      => 'email-digest',
		);

		( new HookLogger() )->error( 'Task {task} failed with {exception}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_background_tasks/log',
					'args'      => array(
						'error',
						'Task email-digest failed with {exception}.',
						$context,
					),
				),
			),
			$GLOBALS['a8csp_bgte_test_fired_actions']
		);
		self::assertSame( $throwable, $GLOBALS['a8csp_bgte_test_fired_actions'][0]['args'][2]['exception'] );
	}

	/**
	 * A failing Stringable context value leaves its placeholder intact without aborting dispatch.
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
					'hook_name' => 'a8csp_background_tasks/log',
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
	 * @return  void
	 */
	public function test_inherited_warning_dispatches_warning_level(): void {
		( new HookLogger() )->warning( 'Task failed.' );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_background_tasks/log',
					'args'      => array( 'warning', 'Task failed.', array() ),
				),
			),
			$GLOBALS['a8csp_bgte_test_fired_actions']
		);
	}

	/**
	 * Plain string context values interpolate into matching placeholders.
	 *
	 * @return  void
	 */
	public function test_plain_string_context_value_is_interpolated(): void {
		$context = array( 'task' => 'email-digest' );

		( new HookLogger() )->info( 'Running {task}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_background_tasks/log',
					'args'      => array( 'info', 'Running email-digest.', $context ),
				),
			),
			$GLOBALS['a8csp_bgte_test_fired_actions']
		);
	}

	/**
	 * Null context values remain structured data and leave matching placeholders intact.
	 *
	 * @return  void
	 */
	public function test_null_context_value_leaves_placeholder_verbatim(): void {
		$context = array( 'task' => null );

		( new HookLogger() )->info( 'Running {task}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_background_tasks/log',
					'args'      => array( 'info', 'Running {task}.', $context ),
				),
			),
			$GLOBALS['a8csp_bgte_test_fired_actions']
		);
	}

	/**
	 * A failing log subscriber cannot interrupt the engine caller.
	 *
	 * @return  void
	 */
	public function test_throwing_subscriber_is_contained_and_reported_to_error_log(): void {
		$GLOBALS['a8csp_bgte_test_action_throwables'] = array(
			'a8csp_background_tasks/log' => new \RuntimeException( "Subscriber failed.\nRetry is unsafe." ),
		);

		$output = $this->capture_error_log(
			static function (): void {
				( new HookLogger() )->warning( 'Task {task} failed.', array( 'task' => 'do-not-log' ) );
			}
		);

		$this->assert_error_log_line(
			'a8csp-background-tasks-engine: log dispatch failed [hook=a8csp_background_tasks/log] [level=warning] [exception=RuntimeException]',
			$output
		);
		self::assertStringNotContainsString( 'do-not-log', $output );
		self::assertStringNotContainsString( 'Subscriber failed', $output );
		self::assertStringNotContainsString( 'Retry is unsafe', $output );
		$fired = $GLOBALS['a8csp_bgte_test_fired_actions'];
		self::assertIsArray( $fired );
		self::assertCount( 1, $fired );
	}

	/**
	 * A failing message conversion cannot interrupt the engine caller.
	 *
	 * @return  void
	 */
	public function test_throwing_message_stringable_is_contained_and_reported_to_error_log(): void {
		$message = new class() implements \Stringable {
			/** @return string */
			#[\Override]
			public function __toString(): string {
				return throw new class( 'Message conversion failed.' ) extends \RuntimeException {};
			}
		};

		$output = $this->capture_error_log(
			static function () use ( $message ): void {
				( new HookLogger() )->error( $message );
			}
		);

		$this->assert_error_log_line(
			'a8csp-background-tasks-engine: log dispatch failed [hook=a8csp_background_tasks/log] [level=error] [exception=RuntimeException@anonymous]',
			$output
		);
		self::assertStringNotContainsString( 'Message conversion failed', $output );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_fired_actions'] );
	}

	/**
	 * A failing level conversion cannot interrupt the engine caller.
	 *
	 * @return  void
	 */
	public function test_throwing_level_stringable_is_contained_and_reported_to_error_log(): void {
		$level = new class() implements \Stringable {
			/** @return string */
			#[\Override]
			public function __toString(): string {
				return throw new \RuntimeException( 'Level conversion failed.' );
			}
		};

		$output = $this->capture_error_log(
			static function () use ( $level ): void {
				( new HookLogger() )->log( $level, 'Task failed.' );
			}
		);

		$this->assert_error_log_line(
			'a8csp-background-tasks-engine: log dispatch failed [hook=a8csp_background_tasks/log] [level=<unrenderable:Stringable@anonymous>] [exception=RuntimeException]',
			$output
		);
		self::assertStringNotContainsString( 'Level conversion failed', $output );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_fired_actions'] );
	}

	/**
	 * A log level without a scalar or Stringable representation remains a caller error.
	 *
	 * @return  void
	 */
	public function test_non_representable_level_still_throws_invalid_argument_exception(): void {
		$GLOBALS['a8csp_bgte_test_action_throwables'] = array(
			'a8csp_background_tasks/log' => new \RuntimeException( 'Subscriber must not run.' ),
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Use a scalar or Stringable PSR-3 log level.' );

		( new HookLogger() )->log( array(), 'Task failed.' );
	}

	/**
	 * Captures PHP's configured error-log destination and restores it after the log call.
	 *
	 * @param   callable(): void $operation The log call to capture.
	 *
	 * @return  string
	 */
	private function capture_error_log( callable $operation ): string {
		$temp_file = \tempnam( \sys_get_temp_dir(), 'a8csp-bgte-hook-log-' );
		if ( false === $temp_file ) {
			self::fail( 'Unable to create the error-log capture file; make the system temporary directory writable.' );
		}

		$previous_error_log = \ini_get( 'error_log' );
		if ( false === $previous_error_log ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WordPress is not loaded in this Unit test, so its file helper is unavailable.
			\unlink( $temp_file );
			self::fail( 'Unable to read the error_log setting; enable the PHP error_log configuration directive.' );
		}

		// phpcs:ignore WordPress.PHP.IniSet.Risky -- The test redirects PHP's error log to its isolated capture file.
		if ( false === \ini_set( 'error_log', $temp_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WordPress is not loaded in this Unit test, so its file helper is unavailable.
			\unlink( $temp_file );
			self::fail( 'Unable to capture error_log output; allow the error_log setting to change at runtime.' );
		}

		try {
			$operation();

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The capture is a local Unit-test file, while wp_remote_get() is for remote URLs.
			$output = \file_get_contents( $temp_file );
			if ( false === $output ) {
				self::fail( 'Unable to read captured error-log output; keep the temporary file readable.' );
			}

			return $output;
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- The test restores PHP's error-log destination after its isolated capture.
			\ini_set( 'error_log', $previous_error_log );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WordPress is not loaded in this Unit test, so its file helper is unavailable.
			\unlink( $temp_file );
		}
	}

	/**
	 * Accepts PHP's SAPI-specific timestamp prefix while pinning one exact payload line.
	 *
	 * @param   string $expected The expected log payload.
	 * @param   string $actual   The captured error-log output.
	 *
	 * @return  void
	 */
	private function assert_error_log_line( string $expected, string $actual ): void {
		self::assertMatchesRegularExpression(
			'/^(?:\[[^\r\n]+\] )?' . \preg_quote( $expected, '/' ) . '\r?\n$/D',
			$actual
		);
	}
}
