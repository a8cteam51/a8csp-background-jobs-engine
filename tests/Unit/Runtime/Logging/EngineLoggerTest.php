<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Logging;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging\EngineLogger;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging\ThrowableContextNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * Exercises the engine logger's public hook and default error-log sink.
 *
 */
#[CoversClass( EngineLogger::class )]
#[CoversClass( ThrowableContextNormalizer::class )]
#[UsesClass( PortableArguments::class )]
final class EngineLoggerTest extends TestCase {
	// region LIFECYCLE.

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
	 * Starts each test with isolated hook ledgers and the default sink disabled.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgje_test_hooks']                = array();
		$GLOBALS['a8csp_bgje_test_action_registrations'] = array();
		$GLOBALS['a8csp_bgje_test_fired_actions']        = array();
		$GLOBALS['a8csp_bgje_test_action_callbacks']     = array();
		$GLOBALS['a8csp_bgje_test_action_throwables']    = array();
		$GLOBALS['a8csp_bgje_test_action_observers']     = array();
		$GLOBALS['a8csp_bgje_test_filter_values']        = array(
			'a8csp_bgje/log_to_error_log' => false,
		);
		$GLOBALS['a8csp_bgje_test_filter_registrations'] = array();
	}

	// endregion.

	// region TESTS.

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

		( new EngineLogger() )->log( 300, 'Job {job_id}: {label}; {missing}; {metadata}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_bgje/log',
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

		( new EngineLogger() )->error( 'Job {job} failed with {exception}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_bgje/log',
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
	 * Throwable context reached within the traversal bound is projected before subscribers receive it.
	 *
	 * @load-bearing security
	 * @pin-rationale Nested throwable details may contain secrets, so reached values become projected metadata.
	 *
	 * @return  void
	 */
	public function test_log_projects_a_nested_throwable_without_exposing_its_message_or_trace(): void {
		$throwable  = new \RuntimeException( 'Nested client token secret.', 503 );
		$context    = array(
			'job'      => 'email-digest',
			'metadata' => array(
				'attempt' => array(
					'failure' => $throwable,
				),
			),
		);
		$projection = array(
			'class'      => \RuntimeException::class,
			'code'       => 503,
			'file'       => \basename( $throwable->getFile() ) . ':' . $throwable->getLine(),
			'trace_hash' => \substr( \hash( 'sha256', $throwable->getTraceAsString() ), 0, 16 ),
		);

		( new EngineLogger() )->error( 'Job {job} failed.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_bgje/log',
					'args'      => array(
						'error',
						'Job email-digest failed.',
						array(
							'job'      => 'email-digest',
							'metadata' => array(
								'attempt' => array(
									'failure' => $projection,
								),
							),
						),
					),
				),
			),
			$GLOBALS['a8csp_bgje_test_fired_actions']
		);

		$subscriber_json = \wp_json_encode( $GLOBALS['a8csp_bgje_test_fired_actions'], \JSON_THROW_ON_ERROR );
		self::assertIsString( $subscriber_json );
		self::assertStringNotContainsString( $throwable->getMessage(), $subscriber_json );
		self::assertStringNotContainsString( $throwable->getTraceAsString(), $subscriber_json );
	}

	/**
	 * Normalization terminates on recursive arrays and returns a finite redacted payload.
	 *
	 * @return  void
	 */
	public function test_normalize_terminates_on_a_self_referential_array(): void {
		$throwable          = new \RuntimeException( 'Recursive client token secret.' );
		$recursive          = array( 'failure' => $throwable );
		$recursive['self']  = &$recursive;
		$normalized_context = ThrowableContextNormalizer::normalize( array( 'metadata' => $recursive ) );
		$subscriber_json    = \wp_json_encode( $normalized_context, \JSON_THROW_ON_ERROR );

		self::assertIsString( $subscriber_json );
		self::assertStringContainsString( '"trace_hash"', $subscriber_json );
		self::assertStringNotContainsString( $throwable->getMessage(), $subscriber_json );
		self::assertStringNotContainsString( $throwable->getTraceAsString(), $subscriber_json );
	}

	/**
	 * Normalization replaces an over-deep throwable subtree with finite JSON-safe context.
	 *
	 * @return  void
	 */
	public function test_normalize_replaces_an_over_deep_throwable_subtree_with_json_safe_context(): void {
		$throwable = new \RuntimeException( 'Over-deep client token secret.' );
		$over_deep = array( 'failure' => $throwable );
		for ( $depth = 0; 16 > $depth; ++$depth ) {
			$over_deep = array( 'level' => $over_deep );
		}

		$expected = 'array';
		for ( $depth = 0; 16 > $depth; ++$depth ) {
			$expected = array( 'level' => $expected );
		}

		$normalized_context = ThrowableContextNormalizer::normalize( array( 'metadata' => $over_deep ) );
		$subscriber_json    = \wp_json_encode( $normalized_context, \JSON_THROW_ON_ERROR );

		self::assertSame( array( 'metadata' => $expected ), $normalized_context );
		self::assertIsString( $subscriber_json );
		self::assertStringNotContainsString( $throwable->getMessage(), $subscriber_json );
		self::assertStringNotContainsString( $throwable->getTraceAsString(), $subscriber_json );
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

		( new EngineLogger() )->info( 'Job {label} failed.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_bgje/log',
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
		( new EngineLogger() )->warning( 'Job failed.' );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_bgje/log',
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

		( new EngineLogger() )->info( 'Running {job}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_bgje/log',
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

		( new EngineLogger() )->info( 'Running {job}.', $context );

		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_bgje/log',
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
			'a8csp_bgje/log' => new \RuntimeException( "Subscriber failed.\nRetry is unsafe." ),
		);

		$output = $this->capture_error_log(
			static function (): void {
				( new EngineLogger() )->warning( 'Job {job} failed.', array( 'job' => 'do-not-log' ) );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine: log dispatch failed [hook=a8csp_bgje/log] [level=warning] [exception=RuntimeException]', $output );
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
				( new EngineLogger() )->error( $message );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine: log dispatch failed [hook=a8csp_bgje/log] [level=error] [exception=RuntimeException@anonymous]', $output );
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
				( new EngineLogger() )->log( $level, 'Job failed.' );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine: log dispatch failed [hook=a8csp_bgje/log] [level=<unrenderable:Stringable@anonymous>] [exception=RuntimeException]', $output );
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
			'a8csp_bgje/log' => new \RuntimeException( 'Subscriber must not run.' ),
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Use a scalar or Stringable PSR-3 log level.' );

		( new EngineLogger() )->log( array(), 'Job failed.' );
	}

	/**
	 * The error-log opt-out is evaluated for every event rather than once during boot.
	 *
	 * @return  void
	 */
	public function test_log_to_error_log_filter_is_evaluated_for_each_event(): void {
		$filter_calls = 0;
		$this->set_filter_value(
			'a8csp_bgje/log_to_error_log',
			static function ( bool $enabled ) use ( &$filter_calls ): bool {
				++$filter_calls;

				return 1 < $filter_calls;
			}
		);

		$output = $this->capture_error_log(
			static function (): void {
				$logger = new EngineLogger();
				$logger->warning( 'First event' );
				$logger->warning( 'Second event' );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.warning: Second event', $output );
		self::assertSame( 2, $filter_calls );
		$fired = $GLOBALS['a8csp_bgje_test_fired_actions'];
		self::assertIsArray( $fired );
		self::assertCount( 2, $fired );
	}

	/**
	 * A throwing sink gate falls back to the enabled default without suppressing publication.
	 *
	 * @return  void
	 */
	public function test_throwing_log_to_error_log_filter_falls_back_to_default_sink_and_preserves_action(): void {
		$this->set_filter_value(
			'a8csp_bgje/log_to_error_log',
			static function (): bool {
				throw new \RuntimeException( 'Consumer gate failed.' );
			}
		);

		$output = $this->capture_error_log(
			static function (): void {
				( new EngineLogger() )->warning( 'Gate fallback event' );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.warning: Gate fallback event', $output );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_bgje/log',
					'args'      => array( 'warning', 'Gate fallback event', array() ),
				),
			),
			$GLOBALS['a8csp_bgje_test_fired_actions']
		);
	}

	/**
	 * The unfiltered default sink writes warning events to PHP's configured error log.
	 *
	 * @return  void
	 */
	public function test_default_sink_writes_warning_events_to_error_log(): void {
		$this->unset_filter_value( 'a8csp_bgje/log_to_error_log' );

		$output = $this->capture_error_log(
			static function (): void {
				( new EngineLogger() )->warning( 'Default sink enabled' );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.warning: Default sink enabled', $output );
	}

	/**
	 * A per-event filter opt-out suppresses the default sink without suppressing publication.
	 *
	 * @return  void
	 */
	public function test_filter_false_disables_the_default_sink_but_preserves_the_log_action(): void {
		$this->set_filter_value( 'a8csp_bgje/log_to_error_log', false );

		$output = $this->capture_error_log(
			static function (): void {
				( new EngineLogger() )->warning( 'Default sink disabled' );
			}
		);

		self::assertSame( '', $output );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_bgje/log',
					'args'      => array( 'warning', 'Default sink disabled', array() ),
				),
			),
			$GLOBALS['a8csp_bgje_test_fired_actions']
		);
	}

	/**
	 * The default warning floor suppresses only the sink's lower-severity records.
	 *
	 * @return  void
	 */
	public function test_default_warning_floor_publishes_info_and_writes_warning(): void {
		$this->set_filter_value( 'a8csp_bgje/log_to_error_log', true );

		$output = $this->capture_error_log(
			static function (): void {
				$logger = new EngineLogger();
				$logger->info( 'Informational event' );
				$logger->warning( 'Warning event' );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.warning: Warning event', $output );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_bgje/log',
					'args'      => array( 'info', 'Informational event', array() ),
				),
				array(
					'hook_name' => 'a8csp_bgje/log',
					'args'      => array( 'warning', 'Warning event', array() ),
				),
			),
			$GLOBALS['a8csp_bgje_test_fired_actions']
		);
	}

	/**
	 * The error-log level filter moves the sink floor without changing publication.
	 *
	 * @return  void
	 */
	public function test_error_log_level_filter_moves_the_sink_floor(): void {
		$this->set_filter_value( 'a8csp_bgje/log_to_error_log', true );
		$this->set_filter_value( 'a8csp_bgje/error_log_level', LogLevel::INFO );

		$output = $this->capture_error_log(
			static function (): void {
				$logger = new EngineLogger();
				$logger->info( 'Included informational event' );
				$logger->debug( 'Excluded debug event' );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.info: Included informational event', $output );
		$fired = $GLOBALS['a8csp_bgje_test_fired_actions'];
		self::assertIsArray( $fired );
		self::assertCount( 2, $fired );
	}

	/**
	 * A throwing sink-floor filter falls back to warning without suppressing publication.
	 *
	 * @return  void
	 */
	public function test_throwing_error_log_level_filter_falls_back_to_warning_and_preserves_action(): void {
		$this->set_filter_value( 'a8csp_bgje/log_to_error_log', true );
		$this->set_filter_value(
			'a8csp_bgje/error_log_level',
			static function (): string {
				throw new \RuntimeException( 'Consumer floor failed.' );
			}
		);

		$output = $this->capture_error_log(
			static function (): void {
				$logger = new EngineLogger();
				$logger->info( 'Excluded informational event' );
				$logger->warning( 'Floor fallback event' );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.warning: Floor fallback event', $output );
		self::assertSame(
			array(
				array(
					'hook_name' => 'a8csp_bgje/log',
					'args'      => array( 'info', 'Excluded informational event', array() ),
				),
				array(
					'hook_name' => 'a8csp_bgje/log',
					'args'      => array( 'warning', 'Floor fallback event', array() ),
				),
			),
			$GLOBALS['a8csp_bgje_test_fired_actions']
		);
	}

	/**
	 * An unrecognized event level is written rather than silently discarded.
	 *
	 * @return  void
	 */
	public function test_unrecognized_level_is_written_to_the_default_sink(): void {
		$this->set_filter_value( 'a8csp_bgje/log_to_error_log', true );

		$output = $this->capture_error_log(
			static function (): void {
				( new EngineLogger() )->log( 'vendor', 'Vendor event' );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.vendor: Vendor event', $output );
	}

	/**
	 * An invalid filtered floor falls back to warning.
	 *
	 * @return  void
	 */
	public function test_invalid_error_log_level_filter_falls_back_to_warning(): void {
		$this->set_filter_value( 'a8csp_bgje/log_to_error_log', true );
		$this->set_filter_value( 'a8csp_bgje/error_log_level', array() );

		$output = $this->capture_error_log(
			static function (): void {
				$logger = new EngineLogger();
				$logger->info( 'Excluded informational event' );
				$logger->warning( 'Included warning event' );
			}
		);

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.warning: Included warning event', $output );
		$fired = $GLOBALS['a8csp_bgje_test_fired_actions'];
		self::assertIsArray( $fired );
		self::assertCount( 2, $fired );
	}

	/**
	 * A throwing subscriber cannot prevent the engine-owned record from being written first.
	 *
	 * @return  void
	 */
	public function test_default_record_is_written_before_a_throwing_listener_runs(): void {
		$this->set_filter_value( 'a8csp_bgje/log_to_error_log', true );
		$GLOBALS['a8csp_bgje_test_action_throwables'] = array(
			'a8csp_bgje/log' => new \RuntimeException( 'Subscriber failed.' ),
		);

		$output     = $this->capture_error_log(
			static function (): void {
				( new EngineLogger() )->warning( 'Work failed' );
			}
		);
		$record     = 'a8csp-background-jobs-engine.warning: Work failed';
		$breadcrumb = 'a8csp-background-jobs-engine: log dispatch failed [hook=a8csp_bgje/log] [level=warning] [exception=RuntimeException]';

		self::assertStringContainsString( $record, $output );
		self::assertStringContainsString( $breadcrumb, $output );
		$record_position     = \strpos( $output, $record );
		$breadcrumb_position = \strpos( $output, $breadcrumb );
		self::assertIsInt( $record_position );
		self::assertIsInt( $breadcrumb_position );
		self::assertTrue( $record_position < $breadcrumb_position );
	}

	/**
	 * A populated context is JSON-encoded after the level and message.
	 *
	 * @return  void
	 */
	public function test_log_writes_a_single_line_with_context(): void {
		$output = $this->capture_engine_log(
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
		$projection         = array(
			'class'      => \RuntimeException::class,
			'code'       => 401,
			'file'       => \basename( $throwable->getFile() ) . ':' . $throwable->getLine(),
			'trace_hash' => \substr( \hash( 'sha256', $throwable->getTraceAsString() ), 0, 16 ),
		);
		$normalized_context = \wp_json_encode(
			array(
				'exception' => $projection,
				'failure'   => $projection,
				'job_id'    => 42,
			),
			\JSON_THROW_ON_ERROR
		);
		self::assertIsString( $normalized_context );

		$output = $this->capture_engine_log( 'error', 'Work failed', $context );

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
		$output = $this->capture_engine_log( 'info', 'Work skipped' );

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.info: Work skipped', $output );
	}

	/**
	 * Line breaks remain visible without splitting the error-log record.
	 *
	 * @return  void
	 */
	public function test_log_escapes_line_breaks(): void {
		$output = $this->capture_engine_log( "notice\nlevel", "First line\r\nSecond line", array( 'detail' => "Third line\r\nFourth line" ) );

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.notice\nlevel: First line\r\nSecond line {"detail":"Third line\\r\\nFourth line"}', $output );
	}

	/**
	 * NUL bytes remain visible without truncating the error-log record or its context tail.
	 *
	 * @return  void
	 */
	public function test_log_escapes_nul_bytes_without_truncating_the_record(): void {
		$output = $this->capture_engine_log( 'warning', "Before\0after", array( 'detail' => "Context\0tail" ) );

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.warning: Before\\0after {"detail":"Context\\u0000tail"}', $output );
	}

	/**
	 * An unencodable context retains the message and identifies the encoding failure.
	 *
	 * @return  void
	 */
	public function test_log_reports_an_unencodable_context_without_throwing(): void {
		$output = $this->capture_engine_log( 'error', 'Work failed', array( 'duration' => \INF ) );

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

		$output = $this->capture_engine_log( 'error', 'Work failed', array( 'value' => $unencodable_value ) );

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

		$output = $this->capture_engine_log( 'error', 'Storage failed', array( 'exception' => $exception ) );

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

		$output = $this->capture_engine_log(
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
	 * Recursive context is made finite before the sink's transport pass.
	 *
	 * @return  void
	 */
	public function test_log_makes_a_recursive_context_array_finite(): void {
		$recursive         = array();
		$recursive['self'] = &$recursive;

		$output = $this->capture_engine_log( 'debug', 'Recursive context', array( 'value' => $recursive ) );

		$this->assert_error_log_line( 'a8csp-background-jobs-engine.debug: Recursive context {"value":{"self":{"self":[]}}}', $output );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Captures one engine log call with the default sink enabled for every recognized level.
	 *
	 * @param   mixed                   $level   The log level.
	 * @param   string|\Stringable      $message The log message.
	 * @param   array<array-key, mixed> $context The structured context.
	 *
	 * @return  string
	 */
	private function capture_engine_log( mixed $level, string|\Stringable $message, array $context = array() ): string {
		$this->set_filter_value( 'a8csp_bgje/log_to_error_log', true );
		$this->set_filter_value( 'a8csp_bgje/error_log_level', LogLevel::DEBUG );

		return $this->capture_error_log(
			static function () use ( $level, $message, $context ): void {
				( new EngineLogger() )->log( $level, $message, $context );
			}
		);
	}

	/**
	 * Scripts one hook value for the recording filter stub.
	 *
	 * @param   string $hook_name Hook name.
	 * @param   mixed  $value     Scripted return value or callback.
	 *
	 * @return  void
	 */
	private function set_filter_value( string $hook_name, mixed $value ): void {
		$filter_values = $GLOBALS['a8csp_bgje_test_filter_values'] ?? array();
		self::assertIsArray( $filter_values );
		$filter_values[ $hook_name ]              = $value;
		$GLOBALS['a8csp_bgje_test_filter_values'] = $filter_values;
	}

	/**
	 * Removes one scripted hook value so the filter receives its production default.
	 *
	 * @param   string $hook_name Hook name.
	 *
	 * @return  void
	 */
	private function unset_filter_value( string $hook_name ): void {
		$filter_values = $GLOBALS['a8csp_bgje_test_filter_values'] ?? array();
		self::assertIsArray( $filter_values );
		unset( $filter_values[ $hook_name ] );
		$GLOBALS['a8csp_bgje_test_filter_values'] = $filter_values;
	}

	/**
	 * Captures PHP's configured error-log destination and restores it after the log call.
	 *
	 * @param   callable(): void $operation The log call to capture.
	 *
	 * @return  string
	 */
	private function capture_error_log( callable $operation ): string {
		$temp_file = \tempnam( \sys_get_temp_dir(), 'a8csp-bgje-hook-log-' );
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

	// endregion.
}
