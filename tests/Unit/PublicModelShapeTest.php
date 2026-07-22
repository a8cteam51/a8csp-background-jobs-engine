<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the representation layer to the composition authoring surface: definitions describe jobs,
 * execution roles carry behavior, and no public job base class or authoring interface exists.
 *
 * @load-bearing structural-guard
 * @pin-rationale Authoring composes a JobDefinition over a plain execution object; the engine owns kinds and lifecycle, so there is nothing to inherit and no class-method lifecycle callback surface.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversNothing]
final class PublicModelShapeTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ROOT_NAMESPACE = 'A8C\\SpecialProjects\\BackgroundJobsEngine\\';

	/**
	 * Every public representation type, by its root-relative concept name.
	 */
	private const array PUBLIC_MODEL_TYPES = array(
		'Job\\JobDefinition',
		'Job\\JobKind',
		'Job\\JobOptions',
		'Job\\JobExecution',
		'Job\\RetryPolicy',
		'Job\\OverlapPolicy',
		'Job\\RunContext',
		'Job\\NonRetryableException',
		'Job\\Chunked\\ChunkedJobExecution',
		'Job\\Chunked\\ChunkContext',
		'Run\\Run',
		'Run\\RunId',
		'Run\\RunStatus',
		'Run\\RunFailure',
		'Run\\RunFailureStage',
		'Error\\ErrorCode',
	);

	/**
	 * Root-namespace names whose concepts live under a concept namespace instead.
	 */
	private const array RETIRED_ROOT_TYPES = array(
		'Job',
		'ChunkedJob',
		'Run',
		'RunStatus',
		'RunContext',
		'ChunkContext',
		'RunFailure',
		'RunFailureStage',
		'RetryPolicy',
		'OverlapPolicy',
		'ErrorCode',
		'NonRetryableException',
	);

	/**
	 * Retired authoring-surface names: base classes, authoring interfaces, and callable wrappers.
	 */
	private const array RETIRED_AUTHORING_TYPES = array(
		'Job\\JobInterface',
		'Job\\JobDefaults',
		'Job\\AbstractJob',
		'Job\\CallableJob',
		'Job\\Chunked\\ChunkedJobInterface',
		'Job\\Chunked\\AbstractChunkedJob',
		'Job\\Batch\\BatchJobInterface',
		'Job\\Batch\\AbstractBatchJob',
	);

	/**
	 * Contracts whose public home replaces any machinery-side declaration.
	 */
	private const array RETIRED_INTERNAL_TYPES = array(
		'Internal\\JobInterface',
		'Internal\\Job\\OneOffJobInterface',
		'Internal\\Job\\CallableJob',
		'Internal\\ChunkedJob\\ChunkedJobInterface',
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
	}

	// endregion.

	// region TESTS.

	/**
	 * Every public representation type is declared under its singular concept namespace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_representation_layer_lives_under_singular_concept_namespaces(): void {
		foreach ( self::PUBLIC_MODEL_TYPES as $relative_name ) {
			$type = self::ROOT_NAMESPACE . $relative_name;
			self::assertTrue( \class_exists( $type ) || \interface_exists( $type ) || \enum_exists( $type ) || \trait_exists( $type ), 'The representation layer must declare ' . $type );
		}
	}

	/**
	 * The execution roles are plain interfaces carrying exactly their execution methods.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_execution_roles_carry_exactly_their_execution_methods(): void {
		$job = new \ReflectionClass( self::ROOT_NAMESPACE . 'Job\\JobExecution' );
		self::assertTrue( $job->isInterface() );
		self::assertSame( array( 'handle' ), self::method_names( $job ), 'JobExecution must require exactly handle().' );

		$handle = $job->getMethod( 'handle' );
		self::assertCount( 2, $handle->getParameters() );
		$context = $handle->getParameters()[1]->getType();
		self::assertInstanceOf( \ReflectionNamedType::class, $context );
		self::assertSame( self::ROOT_NAMESPACE . 'Job\\RunContext', $context->getName() );

		$chunked = new \ReflectionClass( self::ROOT_NAMESPACE . 'Job\\Chunked\\ChunkedJobExecution' );
		self::assertTrue( $chunked->isInterface() );
		self::assertSame( array( 'generate_queue', 'process_chunk' ), self::method_names( $chunked ), 'ChunkedJobExecution must require exactly queue generation and chunk processing.' );

		$generate = $chunked->getMethod( 'generate_queue' );
		$return   = $generate->getReturnType();
		self::assertInstanceOf( \ReflectionNamedType::class, $return );
		self::assertSame( 'iterable', $return->getName(), 'generate_queue() must return an iterable queue.' );

		$process = $chunked->getMethod( 'process_chunk' );
		self::assertCount( 2, $process->getParameters() );
		$chunk_context = $process->getParameters()[1]->getType();
		self::assertInstanceOf( \ReflectionNamedType::class, $chunk_context );
		self::assertSame( self::ROOT_NAMESPACE . 'Job\\Chunked\\ChunkContext', $chunk_context->getName() );
	}

	/**
	 * The definition and kind values compose through named constructors, never `new`.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_definition_and_kind_compose_through_named_constructors(): void {
		$definition = new \ReflectionClass( self::ROOT_NAMESPACE . 'Job\\JobDefinition' );
		self::assertTrue( $definition->isFinal() && $definition->isReadOnly() );
		$constructor = $definition->getConstructor();
		self::assertNotNull( $constructor );
		self::assertFalse( $constructor->isPublic(), 'JobDefinition must compose through its named constructors.' );
		foreach ( array( 'job', 'chunked_job', 'closure', 'for_kind' ) as $named ) {
			self::assertTrue( $definition->hasMethod( $named ), 'JobDefinition must offer ' . $named . '().' );
			$method = $definition->getMethod( $named );
			self::assertTrue( $method->isStatic() && $method->isPublic(), 'JobDefinition::' . $named . '() must be a public named constructor.' );
		}

		$kind = new \ReflectionClass( self::ROOT_NAMESPACE . 'Job\\JobKind' );
		self::assertTrue( $kind->isFinal() && $kind->isReadOnly() );
		foreach ( array( 'job', 'chunked_job', 'from' ) as $named ) {
			self::assertTrue( $kind->hasMethod( $named ), 'JobKind must offer ' . $named . '().' );
			$method = $kind->getMethod( $named );
			self::assertTrue( $method->isStatic() && $method->isPublic(), 'JobKind::' . $named . '() must be a public named constructor.' );
		}
		self::assertFalse( $kind->hasProperty( 'execution_contract' ), 'JobKind carries no execution contract; compatibility checks belong to the resolved handler.' );

		$options = new \ReflectionClass( self::ROOT_NAMESPACE . 'Job\\JobOptions' );
		self::assertTrue( $options->isFinal() && $options->isReadOnly() );
		foreach ( array( 'max_runtime', 'retry', 'overlap', 'overlap_key' ) as $policy ) {
			self::assertTrue( $options->hasProperty( $policy ), 'JobOptions must carry the ' . $policy . ' policy.' );
		}
	}

	/**
	 * No retired root-namespace, authoring-surface, machinery-contract, or OneOff name survives.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_no_retired_name_survives(): void {
		foreach ( self::RETIRED_ROOT_TYPES as $short_name ) {
			self::assert_type_absent( self::ROOT_NAMESPACE . $short_name );
		}

		foreach ( self::RETIRED_AUTHORING_TYPES as $relative_name ) {
			self::assert_type_absent( self::ROOT_NAMESPACE . $relative_name );
		}

		foreach ( self::RETIRED_INTERNAL_TYPES as $relative_name ) {
			self::assert_type_absent( self::ROOT_NAMESPACE . $relative_name );
		}

		foreach ( self::shipped_php_files() as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin source is the subject of this WP-less structural test.
			$contents = \file_get_contents( $file );
			self::assertIsString( $contents );
			self::assertStringNotContainsString( 'OneOff', $contents, \basename( $file ) . ' names the default job kind, which is unqualified.' );
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

	/**
	 * Returns the sorted public-method names an interface requires.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @template T of object
	 * @phpstan-param \ReflectionClass<T> $reflection
	 *
	 * @param   \ReflectionClass $reflection Reflected interface.
	 *
	 * @return  list<string>
	 */
	private static function method_names( \ReflectionClass $reflection ): array {
		$names = array();
		foreach ( $reflection->getMethods() as $method ) {
			$names[] = $method->getName();
		}
		\sort( $names );

		return $names;
	}

	/**
	 * Asserts one fully qualified name resolves to no declaration of any kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $type Fully qualified type name.
	 *
	 * @return  void
	 */
	private static function assert_type_absent( string $type ): void {
		self::assertFalse( \class_exists( $type ) || \interface_exists( $type ) || \enum_exists( $type ) || \trait_exists( $type ), $type . ' must not be declarable.' );
	}

	// endregion.
}
