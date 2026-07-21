<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Guards the public models against dependencies on the internal engine graph.
 *
 * @load-bearing structural-guard
 * @pin-rationale `Engine` is the sole sanctioned bridge; every other public model stays independent of the internal engine graph.
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

	private const array PERMITTED_INTERNAL_REFERENCES = array(
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Internal\\JobInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Internal\\Job\\OneOffJobInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Internal\\ChunkedJob\\ChunkedJobInterface',
	);

	/**
	 * `Engine` wraps the internal `Client`/`Component` by design; every other `models/` file stays clean.
	 */
	private const array PERMITTED_ENGINE_GRAPH_FILES = array(
		'Engine.php',
	);

	// endregion.

	// region TESTS.

	/**
	 * Root-namespace model files reference no internal implementation or facade type.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_models_do_not_depend_on_the_internal_engine_graph(): void {
		$files = self::public_model_files();
		self::assertNotEmpty( $files );

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local model source is the subject of this WP-less structural test.
			$contents = \file_get_contents( $file );
			self::assertIsString( $contents );
			$location = 'models/' . \basename( $file );
			if ( \in_array( \basename( $file ), self::PERMITTED_ENGINE_GRAPH_FILES, true ) ) {
				continue;
			}

			foreach ( self::FORBIDDEN_REFERENCES as $reference ) {
				self::assertStringNotContainsString( $reference, $contents, $location . ' references forbidden internal dependency ' . $reference );
			}

			self::assertSame( 0, \preg_match( '/\b[A-Za-z_][A-Za-z0-9_]*EngineInterface\b/', $contents ), $location . ' references an internal engine interface.' );

			foreach ( self::PERMITTED_INTERNAL_REFERENCES as $permitted ) {
				$extended_reference_pattern = '/' . \preg_quote( $permitted, '/' ) . '[A-Za-z0-9_\\\\]/';
				self::assertSame( 0, \preg_match( $extended_reference_pattern, $contents ), $location . ' extends a permitted Internal type name into an unsupported dependency.' );
			}

			$without_permitted_references = \str_replace( self::PERMITTED_INTERNAL_REFERENCES, '', $contents );
			self::assertStringNotContainsString( self::ROOT_NAMESPACE . 'Internal\\', $without_permitted_references, $location . ' references an Internal type other than the three permitted genus interfaces.' );
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns model files that declare a type directly in the public root namespace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>
	 */
	private static function public_model_files(): array {
		$files        = \glob( \dirname( __DIR__, 2 ) . '/models/*.php' );
		$public_files = array();

		self::assertIsArray( $files );
		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local model source is the subject of this WP-less structural test.
			$contents = \file_get_contents( $file );
			self::assertIsString( $contents );

			$namespace_pattern   = '/^namespace\s+' . \preg_quote( \rtrim( self::ROOT_NAMESPACE, '\\' ), '/' ) . '\s*;/m';
			$declaration_pattern = '/^(?:(?:abstract|final|readonly)\s+)*(?:class|interface|enum)\s+[A-Za-z_][A-Za-z0-9_]*\b/m';
			if ( 1 === \preg_match( $namespace_pattern, $contents ) && 1 === \preg_match( $declaration_pattern, $contents ) ) {
				$public_files[] = $file;
			}
		}

		\sort( $public_files );

		return $public_files;
	}

	// endregion.
}
