<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundTasksEngine\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the always-on log channel and its bare-install error-log fallback.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Log::class )]
final class LogTest extends TestCase {
	/**
	 * Satisfies the production files' `ABSPATH` boot guard and loads the recording action stub.
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
		require_once __DIR__ . '/Scheduling/wp-json-encode-stub.php';
	}

	/**
	 * Starts each test with empty hook-registration ledgers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_hooks']                = array();
		$GLOBALS['a8csp_bgte_test_action_registrations'] = array();
	}

	/**
	 * The log channel is available on every site.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_is_always_needed(): void {
		self::assertTrue( ( new Log() )->is_needed() );
	}

	/**
	 * Initialization registers exactly one callable for all three channel arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_initialize_registers_the_default_handler(): void {
		( new Log() )->initialize();

		self::assertSame(
			array(
				array(
					'hook_name'     => 'a8csp/background_tasks/log',
					'callback'      => array( Log::class, 'log' ),
					'priority'      => 10,
					'accepted_args' => 3,
				),
			),
			$GLOBALS['a8csp_bgte_test_action_registrations']
		);
	}

	/**
	 * A populated context is JSON-encoded after the level and message.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_log_writes_a_single_line_with_context(): void {
		$output = $this->capture_error_log(
			'warning',
			'Work will retry',
			array(
				'task_id' => 42,
				'attempt' => 2,
			)
		);

		$this->assert_error_log_line(
			'a8csp-background-tasks-engine.warning: Work will retry {"task_id":42,"attempt":2}',
			$output
		);
	}

	/**
	 * An empty context leaves no JSON tail or trailing space.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_log_omits_an_empty_context(): void {
		$output = $this->capture_error_log( 'info', 'Work skipped', array() );

		$this->assert_error_log_line( 'a8csp-background-tasks-engine.info: Work skipped', $output );
	}

	/**
	 * Line breaks remain visible without splitting the error-log record.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_log_escapes_line_breaks(): void {
		$output = $this->capture_error_log( "notice\nlevel", "First line\r\nSecond line", array() );

		$this->assert_error_log_line(
			'a8csp-background-tasks-engine.notice\nlevel: First line\r\nSecond line',
			$output
		);
	}

	/**
	 * An unencodable context retains the message and identifies the encoding failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_log_reports_an_unencodable_context_without_throwing(): void {
		$output = $this->capture_error_log( 'error', 'Work failed', array( 'duration' => \INF ) );

		$this->assert_error_log_line(
			'a8csp-background-tasks-engine.error: Work failed [context JSON encoding failed: use only JSON-encodable values]',
			$output
		);
	}

	/**
	 * A context value that throws during serialization cannot interrupt the channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_log_reports_a_context_serialization_exception_without_throwing(): void {
		$unencodable_value = new class() implements \JsonSerializable {
			/**
			 * Forces the context encoder down its arbitrary-exception path.
			 *
			 * @return  mixed
			 */
			#[\Override]
			public function jsonSerialize(): mixed {
				throw new \RuntimeException( "Context serialization failed.\nRetrying is unsafe." );
			}
		};

		$output = $this->capture_error_log( 'error', 'Work failed', array( 'value' => $unencodable_value ) );

		$this->assert_error_log_line(
			'a8csp-background-tasks-engine.error: Work failed [context JSON encoding failed: use only JSON-encodable values]',
			$output
		);
	}

	/**
	 * Captures PHP's configured error-log destination and restores it after the assertion input runs.
	 *
	 * @param   string                  $level   The log level.
	 * @param   string                  $message The log message.
	 * @param   array<array-key, mixed> $context The structured context.
	 *
	 * @return  string
	 */
	private function capture_error_log( string $level, string $message, array $context ): string {
		$temp_file = \tempnam( \sys_get_temp_dir(), 'a8csp-bgte-log-' );
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
			Log::log( $level, $message, $context );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The capture is a local Unit-test file, while wp_remote_get() is for remote URLs.
			$output = \file_get_contents( $temp_file );
			if ( false === $output ) {
				self::fail( 'Unable to read captured error_log output; keep the temporary file readable.' );
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
