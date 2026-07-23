<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Logging;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging\ErrorLogSink;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging\ThrowableContextNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the log channel and its bare-install error-log fallback.
 *
 */
#[CoversClass( ErrorLogSink::class )]
#[UsesClass( PortableArguments::class )]
#[UsesClass( ThrowableContextNormalizer::class )]
final class ErrorLogSinkTest extends TestCase {
	/**
	 * Satisfies the production files' `ABSPATH` boot guard and loads the recording action stub.
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
	 * Starts each test with empty hook-registration ledgers.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgje_test_hooks']                = array();
		$GLOBALS['a8csp_bgje_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgje_test_filter_values']        = array();
		$GLOBALS['a8csp_bgje_test_filter_registrations'] = array();
	}

	/**
	 * Registration adds exactly one callable for all three channel arguments.
	 *
	 * @return  void
	 */
	public function test_register_adds_the_default_handler(): void {
		ErrorLogSink::register();

		self::assertSame(
			array(
				array(
					'hook_name'     => 'a8csp_jobs_engine/log',
					'callback'      => array( ErrorLogSink::class, 'log' ),
					'priority'      => 10,
					'accepted_args' => 3,
				),
			),
			$GLOBALS['a8csp_bgje_test_action_registrations']
		);
	}

	/**
	 * The enabled-by-default handler writes log-channel events to PHP's configured error log.
	 *
	 * @return  void
	 */
	public function test_default_handler_writes_log_events_to_error_log(): void {
		ErrorLogSink::register();

		$output = $this->capture_error_log( 'info', 'Default sink enabled', array(), true );

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.info: Default sink enabled', $output );
	}

	/**
	 * An explicit filter opt-out leaves the log channel without the default error-log handler.
	 *
	 * @return  void
	 */
	public function test_filter_false_disables_the_default_error_log_handler(): void {
		$filter_values = $GLOBALS['a8csp_bgje_test_filter_values'] ?? array();
		self::assertIsArray( $filter_values );
		$filter_values['a8csp_jobs_engine/log_to_error_log'] = false;
		$GLOBALS['a8csp_bgje_test_filter_values']            = $filter_values;

		ErrorLogSink::register();

		self::assertSame( '', $this->capture_error_log( 'info', 'Default sink disabled', array(), true ) );
	}

	/**
	 * A populated context is JSON-encoded after the level and message.
	 *
	 * @return  void
	 */
	public function test_log_writes_a_single_line_with_context(): void {
		$output = $this->capture_error_log(
			'warning',
			'Work will retry',
			array(
				'job_id'  => 42,
				'attempt' => 2,
			)
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.warning: Work will retry {"job_id":42,"attempt":2}', $output );
	}

	/**
	 * Throwable context retains correlation fields without exposing message or path content.
	 *
	 * @return  void
	 */
	public function test_log_normalizes_throwable_context_without_confidential_content(): void {
		$throwable          = new \RuntimeException( "Bearer secret-token\r\nuser@example.com", 401 );
		$context            = array(
			'exception' => $throwable,
			'failure'   => $throwable,
			'job_id'    => 42,
		);
		$normalized_context = \wp_json_encode(
			array(
				'exception' => array(
					'class'      => \RuntimeException::class,
					'code'       => 401,
					'file'       => \basename( $throwable->getFile() ) . ':' . $throwable->getLine(),
					'trace_hash' => \substr( \hash( 'sha256', $throwable->getTraceAsString() ), 0, 16 ),
				),
				'failure'   => \RuntimeException::class,
				'job_id'    => 42,
			),
			\JSON_THROW_ON_ERROR
		);
		self::assertIsString( $normalized_context );

		$output = $this->capture_error_log( 'error', 'Work failed', $context );

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.error: Work failed ' . $normalized_context, $output );
		self::assertStringNotContainsString( 'secret-token', $output );
		self::assertStringNotContainsString( 'user@example.com', $output );
		self::assertStringNotContainsString( __DIR__, $output );
	}

	/**
	 * An empty context leaves no JSON tail or trailing space.
	 *
	 * @return  void
	 */
	public function test_log_omits_an_empty_context(): void {
		$output = $this->capture_error_log( 'info', 'Work skipped', array() );

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.info: Work skipped', $output );
	}

	/**
	 * Line breaks remain visible without splitting the error-log record.
	 *
	 * @return  void
	 */
	public function test_log_escapes_line_breaks(): void {
		$output = $this->capture_error_log( "notice\nlevel", "First line\r\nSecond line", array( 'detail' => "Third line\r\nFourth line" ) );

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.notice\nlevel: First line\r\nSecond line {"detail":"Third line\\r\\nFourth line"}', $output );
	}

	/**
	 * An unencodable context retains the message and identifies the encoding failure.
	 *
	 * @return  void
	 */
	public function test_log_reports_an_unencodable_context_without_throwing(): void {
		$output = $this->capture_error_log( 'error', 'Work failed', array( 'duration' => \INF ) );

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.error: Work failed [context JSON encoding failed: use only JSON-encodable values]', $output );
	}

	/**
	 * An object context value is reduced to its debug type without invoking serialization code.
	 *
	 * @return  void
	 */
	public function test_log_replaces_an_object_context_value_with_its_debug_type(): void {
		$unencodable_value = new class() implements \JsonSerializable {
			/**
			 * Rejects execution of client-controlled serialization code.
			 *
			 * @return  mixed
			 */
			#[\Override]
			public function jsonSerialize(): mixed {
				throw new \RuntimeException( "Context serialization failed.\nRetrying is unsafe." );
			}
		};

		$output = $this->capture_error_log( 'error', 'Work failed', array( 'value' => $unencodable_value ) );

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.error: Work failed {"value":"JsonSerializable@anonymous"}', $output );
	}

	/**
	 * A string exception code reduces to its type so vendor codes cannot leak content.
	 *
	 * @return  void
	 */
	public function test_log_reduces_a_string_exception_code_to_its_type(): void {
		$exception = new class( 'Upstream driver detail: dsn=secret' ) extends \RuntimeException {
			/**
			 * Carries a vendor-style string code the way PDO drivers do.
			 *
			 * @param   string $message Exception message.
			 */
			public function __construct( string $message ) {
				parent::__construct( $message );
				$this->code = 'HY000';
			}
		};

		$output = $this->capture_error_log( 'error', 'Storage failed', array( 'exception' => $exception ) );

		self::assertStringContainsString( '"code":"string"', $output );
		self::assertStringNotContainsString( 'HY000', $output );
		self::assertStringNotContainsString( 'dsn=secret', $output );
	}

	/**
	 * Context arrays survive only within the sink's fixed nesting bound.
	 *
	 * @return  void
	 */
	public function test_log_bounds_nested_context_arrays(): void {
		$accepted = 'leaf';
		for ( $depth = 0; 8 > $depth; ++$depth ) {
			$accepted = array( 'level' => $accepted );
		}
		$rejected = array( 'level' => $accepted );
		$encoded  = \wp_json_encode(
			array(
				'accepted' => $accepted,
				'rejected' => 'array',
			),
			\JSON_THROW_ON_ERROR,
			9
		);
		self::assertIsString( $encoded );

		$output = $this->capture_error_log(
			'debug',
			'Bounded context',
			array(
				'accepted' => $accepted,
				'rejected' => $rejected,
			)
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.debug: Bounded context ' . $encoded, $output );
	}

	/**
	 * Recursive context arrays collapse to their debug type without escaping the sink.
	 *
	 * @return  void
	 */
	public function test_log_replaces_a_recursive_context_array_with_its_debug_type(): void {
		$recursive         = array();
		$recursive['self'] = &$recursive;

		$output = $this->capture_error_log( 'debug', 'Recursive context', array( 'value' => $recursive ) );

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.debug: Recursive context {"value":"array"}', $output );
	}

	/**
	 * Captures PHP's configured error-log destination and restores it after the assertion input runs.
	 *
	 * @param   string                  $level                      The log level.
	 * @param   string                  $message                    The log message.
	 * @param   array<array-key, mixed> $context                    The structured context.
	 * @param   bool                    $through_registered_handler Whether to invoke the registered default handler.
	 *
	 * @return  string
	 */
	private function capture_error_log( string $level, string $message, array $context, bool $through_registered_handler = false ): string {
		$temp_file = \tempnam( \sys_get_temp_dir(), 'a8csp-jobs-engine-log-' );
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
			if ( $through_registered_handler ) {
				$registrations = $GLOBALS['a8csp_bgje_test_action_registrations'] ?? array();
				self::assertIsArray( $registrations );
				foreach ( $registrations as $registration ) {
					if ( ! \is_array( $registration ) ) {
						self::fail( 'Action registrations must be arrays.' );
					}

					if ( 'a8csp_jobs_engine/log' === ( $registration['hook_name'] ?? null ) ) {
						$callback = $registration['callback'] ?? null;
						if ( ! \is_callable( $callback ) ) {
							self::fail( 'The default log handler must be callable.' );
						}

						$callback( $level, $message, $context );
					}
				}
			} else {
				ErrorLogSink::log( $level, $message, $context );
			}

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
		self::assertMatchesRegularExpression( '/^(?:\[[^\r\n]+\] )?' . \preg_quote( $expected, '/' ) . '\r?\n$/D', $actual );
	}
}
