<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Defines the allowlist census for the supported public surface.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversNothing]
final class PublicSurfaceTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string INTERNAL_NAMESPACE      = 'A8C\\SpecialProjects\\BackgroundJobsEngine\\Internal\\';
	private const string ROOT_NAMESPACE          = 'A8C\\SpecialProjects\\BackgroundJobsEngine\\';
	private const array PROCEDURAL_BUILTIN_TYPES = array( 'array', 'bool', 'callable', 'false', 'int', 'null', 'string', 'true', 'void' );
	private const array PROCEDURAL_PUBLIC_TYPES  = array( 'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\JobInterface', 'A8C\\SpecialProjects\\BackgroundJobsEngine\\Run\\Run', 'WP_Error' );
	private const array PROCEDURAL_FUNCTIONS     = array(
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

	private const array PUBLIC_SERVICE_TYPES = array(
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Engine',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Jobs',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Schedules',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Runs',
	);

	private const array PUBLIC_MODEL_TYPES = array(
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Error\\ErrorCode',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\AbstractJob',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\Batch\\AbstractBatchJob',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\Batch\\BatchJobInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\CallableJob',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\Chunked\\AbstractChunkedJob',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\Chunked\\ChunkContext',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\Chunked\\ChunkedJobInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\JobDefaults',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\JobInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\NonRetryableException',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\OverlapPolicy',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\RetryPolicy',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Job\\RunContext',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Run\\Run',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Run\\RunFailure',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Run\\RunFailureStage',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Run\\RunStatus',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Schedule\\CatchUpPolicy',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Schedule\\Recurrence',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Schedule\\Schedule',
	);

	// endregion.

	// region LIFECYCLE.

	/**
	 * Allows guarded model declarations to autoload during reflection.
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
	 * The public service census is declared by the corresponding root src files.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_services_are_declared_from_the_src_boundary(): void {
		$src_directory = \dirname( __DIR__, 2 ) . '/src';

		foreach ( self::PUBLIC_SERVICE_TYPES as $type ) {
			self::assertTrue( \class_exists( $type ), 'The service layer must declare ' . $type );

			$reflection       = new \ReflectionClass( $type );
			$declaration_file = $reflection->getFileName();
			self::assertIsString( $declaration_file );
			$short_name = \substr( $type, \strlen( self::ROOT_NAMESPACE ) );
			self::assertSame( \realpath( $src_directory . '/' . $short_name . '.php' ), \realpath( $declaration_file ), $type . ' must be declared by its root src file.' );

			$parent = $reflection->getParentClass();
			while ( false !== $parent ) {
				self::assert_supported_type_name( $parent->getName(), $type . ' parent' );
				$parent = $parent->getParentClass();
			}

			foreach ( $reflection->getInterfaceNames() as $interface ) {
				self::assert_supported_type_name( $interface, $type . ' interface' );
			}

			foreach ( $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
				$location = $type . '::' . $method->getName() . '()';
				foreach ( $method->getParameters() as $parameter ) {
					self::assert_supported_reflection_type( $parameter->getType(), $method->getDeclaringClass(), $location . ' $' . $parameter->getName() );
				}
				self::assert_supported_reflection_type( $method->getReturnType(), $method->getDeclaringClass(), $location . ' return' );
			}

			foreach ( $reflection->getProperties( \ReflectionProperty::IS_PUBLIC ) as $property ) {
				self::assert_supported_reflection_type( $property->getType(), $property->getDeclaringClass(), $type . '::$' . $property->getName() );
			}
		}
	}

	/**
	 * Every public model declaration and signature remains inside the supported boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_model_declarations_do_not_reference_internal_namespaces(): void {
		$types    = self::declared_public_model_types();
		$expected = self::PUBLIC_MODEL_TYPES;
		\sort( $expected );

		self::assertNotEmpty( $types );
		self::assertSame( $expected, $types, 'The declarations in models/ must be the exact public model surface.' );

		foreach ( $types as $type ) {
			$reflection = new \ReflectionClass( $type );
			$parent     = $reflection->getParentClass();
			while ( false !== $parent ) {
				self::assert_supported_type_name( $parent->getName(), $type . ' parent' );
				$parent = $parent->getParentClass();
			}

			foreach ( $reflection->getInterfaceNames() as $interface ) {
				self::assert_supported_type_name( $interface, $type . ' interface' );
			}

			foreach ( $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
				$location = $type . '::' . $method->getName() . '()';
				foreach ( $method->getParameters() as $parameter ) {
					self::assert_supported_reflection_type( $parameter->getType(), $method->getDeclaringClass(), $location . ' $' . $parameter->getName() );
				}
				self::assert_supported_reflection_type( $method->getReturnType(), $method->getDeclaringClass(), $location . ' return' );
			}

			foreach ( $reflection->getProperties( \ReflectionProperty::IS_PUBLIC ) as $property ) {
				self::assert_supported_reflection_type( $property->getType(), $property->getDeclaringClass(), $type . '::$' . $property->getName() );
			}
		}
	}

	/**
	 * Procedural signatures expose only supported public and native types.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_procedural_signatures_do_not_reference_internal_namespaces(): void {
		foreach ( self::PROCEDURAL_FUNCTIONS as $function ) {
			$reflection = new \ReflectionFunction( $function );
			foreach ( $reflection->getParameters() as $parameter ) {
				self::assert_supported_procedural_type( $parameter->getType(), $function . '() $' . $parameter->getName() );
			}
			self::assert_supported_procedural_type( $reflection->getReturnType(), $function . '() return' );
		}
	}

	/**
	 * The engine accessor exposes only the public Engine handle.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_accessor_returns_the_public_engine(): void {
		$reflection = new \ReflectionFunction( 'a8csp_bgje' );
		foreach ( $reflection->getParameters() as $parameter ) {
			self::assert_supported_procedural_type( $parameter->getType(), 'a8csp_bgje() $' . $parameter->getName() );
		}

		$return = $reflection->getReturnType();
		self::assertInstanceOf( \ReflectionNamedType::class, $return );
		self::assertSame( self::ROOT_NAMESPACE . 'Engine', $return->getName(), 'a8csp_bgje() must return the public Engine handle.' );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns every package declaration physically defined by a model file.
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
			$file = $entry->getPathname();

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local model source is the subject of this WP-less structural test.
			$contents = \file_get_contents( $file );
			self::assertIsString( $contents );

			$namespace_matches = array();
			if ( 1 !== \preg_match( '/^namespace\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*;/m', $contents, $namespace_matches ) ) {
				continue;
			}
			$namespace = $namespace_matches[1] . '\\';
			if ( ! \str_starts_with( $namespace, self::ROOT_NAMESPACE ) ) {
				continue;
			}

			$declaration_matches = array();
			$result              = \preg_match_all( '/^(?:(?:abstract|final|readonly)\s+)*(?:class|interface|enum|trait)\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $contents, $declaration_matches );
			self::assertNotFalse( $result );
			foreach ( $declaration_matches[1] as $short_name ) {
				$type = $namespace . $short_name;
				self::assertTrue( \class_exists( $type ) || \interface_exists( $type ) || \enum_exists( $type ) || \trait_exists( $type ), 'The model file must declare its discovered type: ' . $type );

				$reflection       = new \ReflectionClass( $type );
				$declaration_file = $reflection->getFileName();
				self::assertIsString( $declaration_file );
				self::assertSame( \realpath( $file ), \realpath( $declaration_file ), $type . ' must be declared by its discovered model file.' );
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
	private static function assert_supported_reflection_type( ?\ReflectionType $type, \ReflectionClass $declarer, string $location ): void {
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
			self::assert_supported_type_name( $name, $location );

			return;
		}

		if ( $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ) {
			foreach ( $type->getTypes() as $member ) {
				self::assert_supported_reflection_type( $member, $declarer, $location );
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
	private static function assert_supported_procedural_type( ?\ReflectionType $type, string $location ): void {
		if ( null === $type ) {
			return;
		}

		if ( $type instanceof \ReflectionNamedType ) {
			$name = $type->getName();
			if ( $type->isBuiltin() ) {
				self::assertContains( $name, self::PROCEDURAL_BUILTIN_TYPES, $location . ' exposes unsupported built-in type ' . $name );

				return;
			}

			if ( \str_starts_with( $name, self::INTERNAL_NAMESPACE ) ) {
				self::fail( $location . ' exposes internal machinery type ' . $name );
			}

			self::assertContains( $name, self::PROCEDURAL_PUBLIC_TYPES, $location . ' exposes unsupported procedural type ' . $name );

			return;
		}

		if ( $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ) {
			foreach ( $type->getTypes() as $member ) {
				self::assert_supported_procedural_type( $member, $location );
			}
		}
	}

	/**
	 * Allows public services, public models, PHP-native types, and PSR contracts.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name     Fully qualified reflected type name.
	 * @param   string $location Signature location for assertion diagnostics.
	 *
	 * @return  void
	 */
	private static function assert_supported_type_name( string $name, string $location ): void {
		if ( 'WP_Error' === $name || \in_array( $name, self::PUBLIC_SERVICE_TYPES, true ) || \in_array( $name, self::PUBLIC_MODEL_TYPES, true ) || \str_starts_with( $name, 'Psr\\' ) ) {
			return;
		}

		if ( \str_starts_with( $name, self::ROOT_NAMESPACE ) ) {
			self::fail( $location . ' exposes an unsupported internal engine type ' . $name );
		}

		if ( ! \class_exists( $name ) && ! \interface_exists( $name ) && ! \enum_exists( $name ) ) {
			self::fail( $location . ' exposes an unknown type ' . $name );
		}
		$reflection = new \ReflectionClass( $name );
		self::assertTrue( $reflection->isInternal(), $location . ' exposes unsupported external type ' . $name );
	}

	// endregion.
}
