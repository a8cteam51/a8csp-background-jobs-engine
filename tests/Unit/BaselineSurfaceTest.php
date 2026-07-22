<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the baseline public-surface guarantees: exhaustive error codes, strict schedule
 * specifications, the two global doors, and the procedural facade verbs.
 *
 * @load-bearing structural-guard
 * @pin-rationale Every emitted error code is an ErrorCode case, unknown specification keys fail loudly instead of silently applying defaults, and the global doors are a8csp_bgje() for the engine handle and a8csp_bgje_plugin() for the composition root.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversNothing]
final class BaselineSurfaceTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ROOT_NAMESPACE = 'A8C\\SpecialProjects\\BackgroundJobsEngine\\';

	/**
	 * Every procedural facade function.
	 */
	private const array PROCEDURAL_FUNCTIONS = array(
		'a8csp_bgje_register',
		'a8csp_bgje_enqueue',
		'a8csp_bgje_start',
		'a8csp_bgje_sync_schedules',
		'a8csp_bgje_dispatch_schedule',
		'a8csp_bgje_inspect_run',
		'a8csp_bgje_last_completed_run',
		'a8csp_bgje_retry_failed_run',
		'a8csp_bgje_cancel_run',
	);

	// endregion.

	// region LIFECYCLE.

	/**
	 * Allows guarded declarations to autoload during reflection.
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

		require_once \dirname( __DIR__, 2 ) . '/functions.php';
	}

	// endregion.

	// region TESTS.

	/**
	 * Every error code the plugin emits is a declared ErrorCode case.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_every_emitted_error_code_is_a_declared_case(): void {
		$error_code = self::ROOT_NAMESPACE . 'Error\\ErrorCode';
		$values     = array();
		foreach ( $error_code::cases() as $case ) {
			$values[] = $case->value;
		}

		foreach ( array( 'invalid_argument', 'already_registered' ) as $required ) {
			self::assertContains( $required, $values, 'ErrorCode must declare the emitted code ' . $required );
		}

		$root = \dirname( __DIR__, 2 );
		foreach ( self::shipped_php_files() as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin source is the subject of this WP-less structural test.
			$contents = \file_get_contents( $file );
			self::assertIsString( $contents );

			$matches = array();
			$result  = \preg_match_all( "/WP_Error\\(\\s*'([a-z_]+)'/", $contents, $matches );
			self::assertNotFalse( $result );
			foreach ( $matches[1] as $literal ) {
				self::assertContains( $literal, $values, \substr( $file, \strlen( $root ) + 1 ) . ' emits the undeclared error code ' . $literal );
			}
		}
	}

	/**
	 * Schedule specifications with unknown keys fail loudly instead of silently applying defaults.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedule_specifications_reject_unknown_keys(): void {
		$specification = array(
			'name'      => 'nightly',
			'every'     => 3_600,
			'job'       => 'some-job',
			'prioritry' => 5,
		);
		$result        = \a8csp_bgje_sync_schedules( 'strict-owner', array( $specification ) );

		self::assertInstanceOf( \WP_Error::class, $result, 'A misspelled specification key must not be silently ignored.' );
		self::assertSame( 'invalid_argument', $result->get_error_code() );
	}

	/**
	 * The global doors are a8csp_bgje() for the engine handle and a8csp_bgje_plugin() for the
	 * composition root; the Plugin class offers no static accessor of its own.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_global_doors_are_the_engine_and_plugin_accessors(): void {
		self::assertTrue( \function_exists( 'a8csp_bgje' ), 'The engine accessor must be declared.' );
		self::assertTrue( \function_exists( 'a8csp_bgje_plugin' ), 'The plugin accessor must be declared.' );

		$return = ( new \ReflectionFunction( 'a8csp_bgje_plugin' ) )->getReturnType();
		self::assertInstanceOf( \ReflectionNamedType::class, $return );
		self::assertSame( self::ROOT_NAMESPACE . 'Plugin', $return->getName(), 'a8csp_bgje_plugin() must return the composition root.' );

		// @phpstan-ignore function.impossibleType (The guard exists to fail when a singleton accessor reappears, which static analysis of the current code cannot see.)
		self::assertFalse( \method_exists( self::ROOT_NAMESPACE . 'Plugin', 'instance' ), 'The Plugin singleton accessor must not be declared.' );
	}

	/**
	 * The procedural facade declares every verb.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_procedural_facade_declares_every_verb(): void {
		foreach ( self::PROCEDURAL_FUNCTIONS as $function ) {
			self::assertTrue( \function_exists( $function ), $function . '() must be declared.' );
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns every shipped PHP source file: the plugin-root files plus every shipped source tree.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>
	 */
	private static function shipped_php_files(): array {
		$root  = \dirname( __DIR__, 2 );
		$files = \glob( $root . '/*.php' );
		self::assertIsArray( $files );

		$directories = \glob( $root . '/*', \GLOB_ONLYDIR );
		self::assertIsArray( $directories );

		foreach ( $directories as $directory ) {
			// Third-party code and development-only tooling; the remainder is first-party shipped source.
			if ( \in_array( \basename( $directory ), array( 'bin', 'build', 'changelog', 'docs', 'node_modules', 'tests', 'vendor' ), true ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $entry ) {
				self::assertInstanceOf( \SplFileInfo::class, $entry );
				if ( 'php' === $entry->getExtension() ) {
					$files[] = $entry->getPathname();
				}
			}
		}

		\sort( $files );

		return $files;
	}

	// endregion.
}
