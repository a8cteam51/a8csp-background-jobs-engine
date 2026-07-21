<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the engine spine to the Internal namespace and keeps machinery types out of public signatures.
 *
 * @load-bearing structural-guard
 * @pin-rationale `@internal` is decorative to consumers; this reflection guard is what makes the machinery boundary real.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversNothing]
final class InternalBoundaryTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ROOT_NAMESPACE     = 'A8C\\SpecialProjects\\BackgroundJobsEngine\\';
	private const string INTERNAL_NAMESPACE = self::ROOT_NAMESPACE . 'Internal\\';

	private const array MACHINERY_NAMESPACES = array(
		self::ROOT_NAMESPACE . 'Internal\\',
		self::ROOT_NAMESPACE . 'Engine\\',
		self::ROOT_NAMESPACE . 'CLI\\',
		self::ROOT_NAMESPACE . 'Api\\',
	);

	private const array INTERNAL_SPINE_TYPES = array(
		'AdmissionValidator',
		'Client',
		'JobIdentity',
		'PortableArguments',
		'ChunkedJob\\ChunkedJobs',
		'ChunkedJob\\ChunkedJobsEngineInterface',
		'Error\\ApiError',
		'Error\\ErrorInterface',
		'Job\\Jobs',
		'Job\\JobsEngineInterface',
		'Result\\AbstractResult',
		'Result\\Failure',
		'Result\\Success',
		'Run\\Runs',
		'Run\\RunsEngineInterface',
		'Schedule\\Schedules',
		'Schedule\\SchedulesEngineInterface',
	);

	private const array PUBLIC_SERVICE_TYPES = array(
		self::ROOT_NAMESPACE . 'Engine',
		self::ROOT_NAMESPACE . 'Jobs',
		self::ROOT_NAMESPACE . 'Schedules',
		self::ROOT_NAMESPACE . 'Runs',
	);

	private const array PROCEDURAL_FUNCTIONS = array(
		'a8csp_bgje',
		'a8csp_bgje_register',
		'a8csp_bgje_register_callable',
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
	 * Every engine-spine type is declared under the Internal namespace from a file inside src/Internal/.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_engine_spine_is_declared_under_the_internal_namespace(): void {
		$internal_directory = \dirname( __DIR__, 2 ) . '/src/Internal';

		foreach ( self::INTERNAL_SPINE_TYPES as $relative_name ) {
			$type = self::INTERNAL_NAMESPACE . $relative_name;
			self::assertTrue( \class_exists( $type ) || \interface_exists( $type ), 'The engine spine must declare ' . $type );

			$reflection       = new \ReflectionClass( $type );
			$declaration_file = $reflection->getFileName();
			self::assertIsString( $declaration_file );
			self::assertStringStartsWith( \realpath( $internal_directory ) . \DIRECTORY_SEPARATOR, (string) \realpath( $declaration_file ), $type . ' must be declared from src/Internal/.' );
		}
	}

	/**
	 * No shipped file declares or references the forbidden Api namespace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_no_shipped_reference_to_the_api_namespace_remains(): void {
		$root = \dirname( __DIR__, 2 );
		self::assertDirectoryDoesNotExist( $root . '/src/Api' );

		foreach ( self::shipped_php_files() as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin source is the subject of this WP-less structural test.
			$contents = \file_get_contents( $file );
			self::assertIsString( $contents );
			self::assertSame( 0, \preg_match( '/BackgroundJobsEngine\\\\Api\b/', $contents ), \substr( $file, \strlen( $root ) + 1 ) . ' references the forbidden Api namespace.' );
		}
	}

	/**
	 * No public model or service declaration or public signature references a machinery namespace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_model_signatures_reference_no_machinery_namespace(): void {
		$types = \array_merge( self::declared_public_model_types(), self::PUBLIC_SERVICE_TYPES );
		self::assertNotEmpty( $types );

		foreach ( $types as $type ) {
			$reflection = new \ReflectionClass( $type );
			$parent     = $reflection->getParentClass();
			while ( false !== $parent ) {
				self::assert_boundary_type_name( $parent->getName(), $type . ' parent' );
				$parent = $parent->getParentClass();
			}

			foreach ( $reflection->getInterfaceNames() as $interface ) {
				self::assert_boundary_type_name( $interface, $type . ' interface' );
			}

			foreach ( $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
				$location = $type . '::' . $method->getName() . '()';
				foreach ( $method->getParameters() as $parameter ) {
					self::assert_boundary_reflection_type( $parameter->getType(), $method->getDeclaringClass(), $location . ' $' . $parameter->getName() );
				}
				self::assert_boundary_reflection_type( $method->getReturnType(), $method->getDeclaringClass(), $location . ' return' );
			}

			foreach ( $reflection->getProperties( \ReflectionProperty::IS_PUBLIC ) as $property ) {
				self::assert_boundary_reflection_type( $property->getType(), $property->getDeclaringClass(), $type . '::$' . $property->getName() );
			}

			foreach ( $reflection->getReflectionConstants( \ReflectionClassConstant::IS_PUBLIC ) as $constant ) {
				self::assert_boundary_reflection_type( $constant->getType(), $constant->getDeclaringClass(), $type . '::' . $constant->getName() );
			}
		}
	}

	/**
	 * No procedural facade signature references a machinery namespace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_procedural_signatures_reference_no_machinery_namespace(): void {
		foreach ( self::PROCEDURAL_FUNCTIONS as $function ) {
			$reflection = new \ReflectionFunction( $function );
			foreach ( $reflection->getParameters() as $parameter ) {
				self::assert_boundary_procedural_type( $parameter->getType(), $function . '() $' . $parameter->getName() );
			}
			self::assert_boundary_procedural_type( $reflection->getReturnType(), $function . '() return' );
		}
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

	/**
	 * Returns every type declared by a model file, at any depth under the package namespace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<class-string>
	 */
	private static function declared_public_model_types(): array {
		$models_directory = \dirname( __DIR__, 2 ) . '/models';
		$types            = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $models_directory, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			self::assertInstanceOf( \SplFileInfo::class, $entry );
			if ( 'php' !== $entry->getExtension() ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local model source is the subject of this WP-less structural test.
			$contents = \file_get_contents( $entry->getPathname() );
			self::assertIsString( $contents );

			$namespace_matches = array();
			if ( 1 !== \preg_match( '/^namespace\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*;/m', $contents, $namespace_matches ) ) {
				continue;
			}
			$namespace = $namespace_matches[1] . '\\';
			if ( ! \str_starts_with( $namespace, \rtrim( self::ROOT_NAMESPACE, '\\' ) . '\\' ) ) {
				continue;
			}

			$declaration_matches = array();
			$result              = \preg_match_all( '/^(?:(?:abstract|final|readonly)\s+)*(?:class|interface|enum|trait)\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $contents, $declaration_matches );
			self::assertNotFalse( $result );
			foreach ( $declaration_matches[1] as $short_name ) {
				$type = $namespace . $short_name;
				self::assertTrue( \class_exists( $type ) || \interface_exists( $type ) || \enum_exists( $type ) || \trait_exists( $type ), 'The model file must declare its discovered type: ' . $type );
				$types[] = $type;
			}
		}

		\sort( $types );

		return $types;
	}

	/**
	 * Checks every named member of a nullable, union, or intersection reflection type.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \ReflectionClass<object> $declarer
	 *
	 * @param   \ReflectionType|null $type      Reflected signature type.
	 * @param   \ReflectionClass     $declarer  Declaration context for relative type names.
	 * @param   string               $location  Signature location for assertion diagnostics.
	 *
	 * @return  void
	 */
	private static function assert_boundary_reflection_type( ?\ReflectionType $type, \ReflectionClass $declarer, string $location ): void {
		if ( null === $type ) {
			return;
		}

		if ( $type instanceof \ReflectionNamedType ) {
			if ( $type->isBuiltin() ) {
				return;
			}

			$name = $type->getName();
			if ( 'self' === $name || 'static' === $name ) {
				$name = $declarer->getName();
			} elseif ( 'parent' === $name ) {
				$parent = $declarer->getParentClass();
				if ( false === $parent ) {
					self::fail( $location . ' declares parent without a parent class' );
				}
				$name = $parent->getName();
			}
			self::assert_boundary_type_name( $name, $location );

			return;
		}

		if ( $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ) {
			foreach ( $type->getTypes() as $member ) {
				self::assert_boundary_reflection_type( $member, $declarer, $location );
			}
		}
	}

	/**
	 * Checks every named member of a procedural nullable, union, or intersection type.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \ReflectionType|null $type     Reflected signature type.
	 * @param   string               $location Signature location for assertion diagnostics.
	 *
	 * @return  void
	 */
	private static function assert_boundary_procedural_type( ?\ReflectionType $type, string $location ): void {
		if ( null === $type ) {
			return;
		}

		if ( $type instanceof \ReflectionNamedType ) {
			if ( ! $type->isBuiltin() ) {
				self::assert_boundary_type_name( $type->getName(), $location );
			}

			return;
		}

		if ( $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ) {
			foreach ( $type->getTypes() as $member ) {
				self::assert_boundary_procedural_type( $member, $location );
			}
		}
	}

	/**
	 * Fails when a reflected type name belongs to a machinery namespace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name     Fully qualified reflected type name.
	 * @param   string $location Signature location for assertion diagnostics.
	 *
	 * @return  void
	 */
	private static function assert_boundary_type_name( string $name, string $location ): void {
		foreach ( self::MACHINERY_NAMESPACES as $namespace ) {
			self::assertFalse( \str_starts_with( $name, $namespace ), $location . ' exposes machinery type ' . $name );
		}
	}

	// endregion.
}
