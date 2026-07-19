<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Engine\Logging;

use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Logging\HookLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Logging\ThrowableContextNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;

/**
 * Pins the PSR-3 adapter onto the engine's public log hook.
 *
 */
#[CoversClass( HookLogger::class )]
#[CoversClass( ThrowableContextNormalizer::class )]
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

		require_once \dirname( __DIR__, 2 ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__ ) . '/Backends/wp-json-encode-stub.php';
	}

	/**
	 * Starts each test with an empty fired-action ledger.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgje_test_fired_actions']     = array();
		$GLOBALS['a8csp_bgje_test_action_callbacks']  = array();
		$GLOBALS['a8csp_bgje_test_action_throwables'] = array();
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
			'job_id'   => 42,
			'label'    => $stringable,
			'metadata' => array( 'attempt' => 2 ),
		);

		( new HookLogger() )->log( 300, 'Job {job_id}: {label}; {missing}; {metadata}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_jobs_engine/log',
					'args'      => array(
						'300',
						'Job 42: printable; {missing}; {metadata}.',
						$context,
					),
				),
			),
			$GLOBALS['a8csp_bgje_test_fired_actions']
		);
	}

	/**
	 * Throwable context reaches subscribers projected without entering placeholder interpolation.
	 *
	 * @load-bearing security
	 * @pin-rationale Throwable messages and non-standard codes may contain secrets, so subscribers receive projected metadata without the raw exception or its sensitive prose.
	 *
	 * @return  void
	 */
	public function test_log_projects_throwable_context_without_interpolating_it(): void {
		$throwable = new class() extends \RuntimeException {
			/** Creates one throwable with secrets in both message and its non-standard code. */
			public function __construct() {
				parent::__construct( 'Client token secret.' );

				$this->code = 'Bearer code secret.';
			}
		};

		$context = array(
			'exception' => $throwable,
			'failure'   => $throwable,
			'job'       => 'email-digest',
		);

		$projection = array(
			'class'      => \get_debug_type( $throwable ),
			'code'       => 'string',
			'file'       => \basename( $throwable->getFile() ) . ':' . $throwable->getLine(),
			'trace_hash' => \substr( \hash( 'sha256', $throwable->getTraceAsString() ), 0, 16 ),
		);

		( new HookLogger() )->error( 'Job {job} failed with {exception}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_jobs_engine/log',
					'args'      => array(
						'error',
						'Job email-digest failed with {exception}.',
						array(
							'exception' => $projection,
							'failure'   => $projection,
							'job'       => 'email-digest',
						),
					),
				),
			),
			$GLOBALS['a8csp_bgje_test_fired_actions']
		);

		$subscriber_context = $GLOBALS['a8csp_bgje_test_fired_actions'][0]['args'][2];
		self::assertNotSame( $throwable, $subscriber_context['exception'] );
		self::assertNotSame( $throwable, $subscriber_context['failure'] );
		$subscriber_json = \wp_json_encode( $subscriber_context, \JSON_THROW_ON_ERROR );
		self::assertIsString( $subscriber_json );
		self::assertStringNotContainsString( 'Client token secret.', $subscriber_json );
		self::assertStringNotContainsString( 'Bearer code secret.', $subscriber_json );
	}

	/**
	 * A failing Stringable context value leaves its placeholder intact without aborting dispatch.
	 *
	 * @load-bearing security
	 * @pin-rationale An untrusted Stringable conversion failure must remain contained so diagnostic dispatch cannot be interrupted by context rendering.
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

		( new HookLogger() )->info( 'Job {label} failed.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_jobs_engine/log',
					'args'      => array(
						'info',
						'Job {label} failed.',
						$context,
					),
				),
			),
			$GLOBALS['a8csp_bgje_test_fired_actions']
		);
	}

	/**
	 * The inherited warning convenience method preserves its named PSR-3 level.
	 *
	 * @return  void
	 */
	public function test_inherited_warning_dispatches_warning_level(): void {
		( new HookLogger() )->warning( 'Job failed.' );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_jobs_engine/log',
					'args'      => array( 'warning', 'Job failed.', array() ),
				),
			),
			$GLOBALS['a8csp_bgje_test_fired_actions']
		);
	}

	/**
	 * Plain string context values interpolate into matching placeholders.
	 *
	 * @return  void
	 */
	public function test_plain_string_context_value_is_interpolated(): void {
		$context = array( 'job' => 'email-digest' );

		( new HookLogger() )->info( 'Running {job}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_jobs_engine/log',
					'args'      => array( 'info', 'Running email-digest.', $context ),
				),
			),
			$GLOBALS['a8csp_bgje_test_fired_actions']
		);
	}

	/**
	 * Null context values remain structured data and leave matching placeholders intact.
	 *
	 * @return  void
	 */
	public function test_null_context_value_leaves_placeholder_verbatim(): void {
		$context = array( 'job' => null );

		( new HookLogger() )->info( 'Running {job}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_jobs_engine/log',
					'args'      => array( 'info', 'Running {job}.', $context ),
				),
			),
			$GLOBALS['a8csp_bgje_test_fired_actions']
		);
	}

	/**
	 * A failing log subscriber cannot interrupt the engine caller.
	 *
	 * @load-bearing security
	 * @pin-rationale Third-party subscriber failures stay contained, while the emergency breadcrumb excludes the original context and exception prose that may contain secrets.
	 *
	 * @return  void
	 */
	public function test_throwing_subscriber_is_contained_and_reported_to_error_log(): void {
		$GLOBALS['a8csp_bgje_test_action_throwables'] = array(
			'a8csp_jobs_engine/log' => new \RuntimeException( "Subscriber failed.\nRetry is unsafe." ),
		);

		$output = $this->capture_error_log(
			static function (): void {
				( new HookLogger() )->warning( 'Job {job} failed.', array( 'job' => 'do-not-log' ) );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine: log dispatch failed [hook=a8csp_jobs_engine/log] [level=warning] [exception=RuntimeException]', $output );
		self::assertStringNotContainsString( 'do-not-log', $output );
		self::assertStringNotContainsString( 'Subscriber failed', $output );
		self::assertStringNotContainsString( 'Retry is unsafe', $output );
		$fired = $GLOBALS['a8csp_bgje_test_fired_actions'];
		self::assertIsArray( $fired );
		self::assertCount( 1, $fired );
	}

	/**
	 * A failing message conversion cannot interrupt the engine caller.
	 *
	 * @load-bearing security
	 * @pin-rationale Message rendering failures stay contained, and the emergency breadcrumb identifies the failure without exposing exception prose.
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

		$this->assert_error_log_line( 'a8csp-background-jobs-engine: log dispatch failed [hook=a8csp_jobs_engine/log] [level=error] [exception=RuntimeException@anonymous]', $output );
		self::assertStringNotContainsString( 'Message conversion failed', $output );
		self::assertSame( array(), $GLOBALS['a8csp_bgje_test_fired_actions'] );
	}

	/**
	 * A failing level conversion cannot interrupt the engine caller.
	 *
	 * @load-bearing security
	 * @pin-rationale Level rendering failures stay contained, and the emergency breadcrumb identifies the unrenderable type without exposing exception prose.
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
				( new HookLogger() )->log( $level, 'Job failed.' );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine: log dispatch failed [hook=a8csp_jobs_engine/log] [level=<unrenderable:Stringable@anonymous>] [exception=RuntimeException]', $output );
		self::assertStringNotContainsString( 'Level conversion failed', $output );
		self::assertSame( array(), $GLOBALS['a8csp_bgje_test_fired_actions'] );
	}

	/**
	 * A log level without a scalar or Stringable representation remains a caller error.
	 *
	 * @return  void
	 */
	public function test_non_representable_level_still_throws_invalid_argument_exception(): void {
		$GLOBALS['a8csp_bgje_test_action_throwables'] = array(
			'a8csp_jobs_engine/log' => new \RuntimeException( 'Subscriber must not run.' ),
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Use a scalar or Stringable PSR-3 log level.' );

		( new HookLogger() )->log( array(), 'Job failed.' );
	}

	/**
	 * Captures PHP's configured error-log destination and restores it after the log call.
	 *
	 * @param   callable(): void $operation The log call to capture.
	 *
	 * @return  string
	 */
	private function capture_error_log( callable $operation ): string {
		$temp_file = \tempnam( \sys_get_temp_dir(), 'a8csp-jobs-engine-hook-log-' );
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
		self::assertMatchesRegularExpression( '/^(?:\[[^\r\n]+\] )?' . \preg_quote( $expected, '/' ) . '\r?\n$/D', $actual );
	}
}
