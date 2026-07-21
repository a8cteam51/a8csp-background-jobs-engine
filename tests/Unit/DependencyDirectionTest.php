<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Guards the public models against dependencies on the internal engine graph.
 *
 * @load-bearing structural-guard
 * @pin-rationale Services under src/ bridge to the engine graph; every public model stays independent of the internal engine graph.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversNothing]
final class DependencyDirectionTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ROOT_NAMESPACE = 'A8C\\SpecialProjects\\BackgroundJobsEngine\\';

	private const array FORBIDDEN_REFERENCES = array(
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Engine\\',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Internal\\Client',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Internal\\Result\\',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Internal\\Job\\Jobs',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Internal\\ChunkedJob\\ChunkedJobs',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Internal\\Run\\Runs',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Internal\\Schedule\\Schedules',
	);

	// endregion.

	// region TESTS.

	/**
	 * Public model files reference no internal implementation or facade type.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_models_do_not_depend_on_the_internal_engine_graph(): void {
		$models_directory = \dirname( __DIR__, 2 ) . '/models';
		$files            = self::public_model_files();
		self::assertNotEmpty( $files );

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local model source is the subject of this WP-less structural test.
			$contents = \file_get_contents( $file );
			self::assertIsString( $contents );
			$relative_path = \substr( $file, \strlen( $models_directory ) + 1 );
			$location      = 'models/' . $relative_path;
			foreach ( self::FORBIDDEN_REFERENCES as $reference ) {
				self::assertStringNotContainsString( $reference, $contents, $location . ' references forbidden internal dependency ' . $reference );
			}

			self::assertSame( 0, \preg_match( '/\b[A-Za-z_][A-Za-z0-9_]*EngineInterface\b/', $contents ), $location . ' references an internal engine interface.' );

			self::assertStringNotContainsString( self::ROOT_NAMESPACE . 'Internal\\', $contents, $location . ' references an Internal type.' );
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns model files that declare a type in the public package namespace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>
	 */
	private static function public_model_files(): array {
		$models_directory = \dirname( __DIR__, 2 ) . '/models';
		$public_files     = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $models_directory, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			self::assertInstanceOf( \SplFileInfo::class, $entry );
			if ( 'php' !== $entry->getExtension() ) {
				continue;
			}
			$file = $entry->getPathname();

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local model source is the subject of this WP-less structural test.
			$contents = \file_get_contents( $file );
			self::assertIsString( $contents );

			$namespace_pattern   = '/^namespace\s+' . \preg_quote( \rtrim( self::ROOT_NAMESPACE, '\\' ), '/' ) . '(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\s*;/m';
			$declaration_pattern = '/^(?:(?:abstract|final|readonly)\s+)*(?:class|interface|enum|trait)\s+[A-Za-z_][A-Za-z0-9_]*\b/m';
			if ( 1 === \preg_match( $namespace_pattern, $contents ) && 1 === \preg_match( $declaration_pattern, $contents ) ) {
				$public_files[] = $file;
			}
		}

		\sort( $public_files );

		return $public_files;
	}

	// endregion.
}
