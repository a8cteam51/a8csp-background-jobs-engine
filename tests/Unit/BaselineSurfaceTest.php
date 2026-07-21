<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the baseline public-surface guarantees: exhaustive error codes, strict schedule
 * specifications, a single global door, and the per-concept procedural facade files.
 *
 * @load-bearing structural-guard
 * @pin-rationale Every emitted error code is an ErrorCode case, unknown specification keys fail loudly instead of silently applying defaults, and the sole global door is a8csp_bgje().
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversNothing]
final class BaselineSurfaceTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ROOT_NAMESPACE = 'A8C\\SpecialProjects\\BackgroundJobsEngine\\';

	/**
	 * Every procedural function, keyed by the includes/ concept file that must declare it.
	 */
	private const array PROCEDURAL_FILES = array(
		'job-functions.php'         => array( 'a8csp_bgje_register', 'a8csp_bgje_register_callable', 'a8csp_bgje_enqueue' ),
		'chunked-job-functions.php' => array( 'a8csp_bgje_start' ),
		'schedule-functions.php'    => array( 'a8csp_bgje_sync_schedules', 'a8csp_bgje_dispatch_schedule' ),
		'run-functions.php'         => array( 'a8csp_bgje_inspect_run', 'a8csp_bgje_last_completed_run', 'a8csp_bgje_retry_failed_run', 'a8csp_bgje_cancel_run' ),
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
	 * The sole global door is a8csp_bgje(); no plugin accessor is declared or referenced.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_sole_global_door_is_the_engine_accessor(): void {
		self::assertFalse( \function_exists( 'a8csp_bgje_plugin' ), 'The plugin accessor must not be declared.' );

		foreach ( self::shipped_php_files() as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin source is the subject of this WP-less structural test.
			$contents = \file_get_contents( $file );
			self::assertIsString( $contents );
			self::assertStringNotContainsString( 'a8csp_bgje_plugin', $contents, \basename( $file ) . ' references the removed plugin accessor.' );
		}
	}

	/**
	 * The procedural facade lives in per-concept includes/ files.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_procedural_facade_lives_in_per_concept_files(): void {
		$includes = (string) \realpath( \dirname( __DIR__, 2 ) . '/includes' );
		self::assertFileDoesNotExist( $includes . \DIRECTORY_SEPARATOR . 'aliases.php' );

		foreach ( self::PROCEDURAL_FILES as $file => $functions ) {
			self::assertFileExists( $includes . \DIRECTORY_SEPARATOR . $file );

			foreach ( $functions as $function ) {
				self::assertTrue( \function_exists( $function ), $function . '() must be declared.' );
				$reflection = new \ReflectionFunction( $function );
				self::assertSame( $includes . \DIRECTORY_SEPARATOR . $file, (string) \realpath( (string) $reflection->getFileName() ), $function . '() must be declared by its concept file.' );
			}
		}

		$door = new \ReflectionFunction( 'a8csp_bgje' );
		self::assertSame( (string) \realpath( \dirname( __DIR__, 2 ) . '/functions.php' ), (string) \realpath( (string) $door->getFileName() ), 'The global door must stay in functions.php.' );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns every shipped PHP source file: the plugin-root files plus the src, models, and includes trees.
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

		foreach ( array( '/src', '/models', '/includes' ) as $directory ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $directory, \FilesystemIterator::SKIP_DOTS ) );
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
