<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Prevents supported API declarations from exposing engine implementation types.
 *
 */
#[CoversNothing]
final class ApiBoundaryTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const API_NAMESPACE  = 'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\';
	private const ROOT_NAMESPACE = 'A8C\\SpecialProjects\\BackgroundTasksEngine\\';

	private const EXPECTED_API_TYPES = array(
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Batch\\BatchContextInterface',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Batch\\BatchInterface',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Batch\\Batches',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Batch\\ExistingRunPolicy',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Consumer',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Error\\ApiError',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Error\\ApiErrorCode',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Error\\ErrorInterface',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Error\\RunFailure',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Result\\AbstractResult',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Result\\Failure',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Result\\Success',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\RetryPolicy',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Run\\RunStatus',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Run\\Runs',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Schedule\\CatchUpPolicy',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Schedule\\OverlapPolicy',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Schedule\\Recurrence',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Schedule\\Schedule',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Schedule\\Schedules',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Task\\AbstractTask',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Task\\NonRetryableExceptionInterface',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Task\\NonRetryableTaskException',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Task\\TaskInterface',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\Task\\Tasks',
		'A8C\\SpecialProjects\\BackgroundTasksEngine\\Api\\WorkInterface',
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
		self::assertCount( 26, $types );
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
	 * Allows API, PHP-native, and PSR types while rejecting every implementation namespace.
	 *
	 * @param   string $name     Fully qualified reflected type name.
	 * @param   string $location Signature location for assertion diagnostics.
	 *
	 * @return  void
	 */
	private static function assert_supported_type_name( string $name, string $location ): void {
		if ( \str_starts_with( $name, self::API_NAMESPACE ) || \str_starts_with( $name, 'Psr\\' ) ) {
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
