<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Prevents supported API declarations from exposing engine implementation types.
 *
 */
#[CoversNothing]
final class ApiBoundaryTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string API_NAMESPACE           = 'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\';
	private const string ROOT_NAMESPACE          = 'A8C\\SpecialProjects\\BackgroundJobsEngine\\';
	private const array PROCEDURAL_BUILTIN_TYPES = array( 'array', 'bool', 'callable', 'false', 'int', 'null', 'string', 'true', 'void' );
	private const array PROCEDURAL_PUBLIC_TYPES  = array( 'A8CSP_ChunkContext', 'A8CSP_ChunkedJob', 'A8CSP_Job', 'WP_Error' );
	private const array PROCEDURAL_FUNCTIONS     = array(
		'a8csp_bgje_job_register',
		'a8csp_bgje_job_register_object',
		'a8csp_bgje_job_enqueue',
		'a8csp_bgje_chunked_job_register',
		'a8csp_bgje_chunked_job_start',
		'a8csp_bgje_schedule_sync',
		'a8csp_bgje_schedule_dispatch',
		'a8csp_bgje_run_last_completed',
		'a8csp_bgje_run_retry_failed',
		'a8csp_bgje_run_cancel',
		'a8csp_bgje_run_on_completed',
		'a8csp_bgje_run_on_failed',
	);

	private const array EXPECTED_API_TYPES = array(
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\AdmissionValidator',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\ChunkedJob\\AbstractChunkedJob',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\ChunkedJob\\ChunkContextInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\ChunkedJob\\ChunkedJobInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\ChunkedJob\\ChunkedJobs',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\ChunkedJob\\ChunkedJobsEngineInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\ChunkedJob\\ExistingRunPolicy',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Client',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Error\\ApiError',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Error\\ApiErrorCode',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Error\\ErrorInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Error\\RunFailure',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Error\\RunFailureStage',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\JobIdentity',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\JobInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Job\\AbstractJob',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Job\\CallableJob',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Job\\Jobs',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Job\\JobsEngineInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Job\\OneOffJobInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\NonRetryableException',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\NonRetryableExceptionInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\PortableArguments',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Result\\AbstractResult',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Result\\Failure',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Result\\Success',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\RetryPolicy',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Run\\Runs',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Run\\RunsEngineInterface',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Schedule\\CatchUpPolicy',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Schedule\\OverlapPolicy',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Schedule\\Recurrence',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Schedule\\Schedule',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Schedule\\Schedules',
		'A8C\\SpecialProjects\\BackgroundJobsEngine\\Api\\Schedule\\SchedulesEngineInterface',
	);

	// endregion.

	// region LIFECYCLE.

	/**
	 * Allows guarded API declarations to autoload during reflection.
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
	 * Every declared API type and every public signature remains inside the supported boundary.
	 *
	 * @return  void
	 */
	public function test_api_declarations_do_not_reference_internal_namespaces(): void {
		$types = self::declared_api_types();
		self::assertNotEmpty( $types );
		self::assertCount( 35, $types );
		self::assertSame( self::EXPECTED_API_TYPES, $types );

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

	// endregion.

	// region HELPERS.

	/**
	 * Returns API declarations derived from every non-guard PHP file under src/Api.
	 *
	 * @return  list<class-string>
	 */
	private static function declared_api_types(): array {
		$api_directory = \dirname( __DIR__, 2 ) . '/src/Api';
		$iterator      = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $api_directory, \FilesystemIterator::SKIP_DOTS ) );
		$types         = array();

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() || 'index.php' === $file->getFilename() ) {
				continue;
			}

			$relative = \substr( $file->getPathname(), \strlen( $api_directory ) + 1, -4 );
			$type     = self::API_NAMESPACE . \str_replace( \DIRECTORY_SEPARATOR, '\\', $relative );
			self::assertTrue( \class_exists( $type ) || \interface_exists( $type ) || \enum_exists( $type ), 'The API file must declare its path-derived type: ' . $type );
			$types[] = $type;
		}

		\sort( $types );

		return $types;
	}

	/**
	 * Checks every named member of a nullable, union, or intersection reflection type.
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

			if ( \str_starts_with( $name, self::API_NAMESPACE ) ) {
				self::fail( $location . ' exposes internal API type ' . $name );
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
	 * Allows API, PHP-native, and PSR types while rejecting every implementation namespace.
	 *
	 * @param   string $name     Fully qualified reflected type name.
	 * @param   string $location Signature location for assertion diagnostics.
	 *
	 * @return  void
	 */
	private static function assert_supported_type_name( string $name, string $location ): void {
		if ( 'WP_Error' === $name || \str_starts_with( $name, self::API_NAMESPACE ) || \str_starts_with( $name, 'Psr\\' ) ) {
			return;
		}

		if ( \str_starts_with( $name, self::ROOT_NAMESPACE ) ) {
			self::fail( $location . ' exposes internal type ' . $name );
		}

		if ( ! \class_exists( $name ) && ! \interface_exists( $name ) ) {
			self::fail( $location . ' exposes an unknown type ' . $name );
		}
		$reflection = new \ReflectionClass( $name );
		self::assertTrue( $reflection->isInternal(), $location . ' exposes unsupported external type ' . $name );
	}

	// endregion.
}
