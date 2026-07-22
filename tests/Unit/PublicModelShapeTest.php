<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the representation layer to singular concept namespaces with by-kind job sub-namespaces.
 *
 * @load-bearing structural-guard
 * @pin-rationale The default job is the unqualified `Job\AbstractJob`; only special kinds carry a sub-namespace, and the plural namespace grain is reserved for capability managers.
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
		'Job\\JobInterface',
		'Job\\JobDefaults',
		'Job\\AbstractJob',
		'Job\\CallableJob',
		'Job\\RetryPolicy',
		'Job\\OverlapPolicy',
		'Job\\RunContext',
		'Job\\NonRetryableException',
		'Job\\Chunked\\ChunkedJobInterface',
		'Job\\Chunked\\AbstractChunkedJob',
		'Job\\Chunked\\ChunkContext',
		'Job\\Batch\\BatchJobInterface',
		'Job\\Batch\\AbstractBatchJob',
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
	 * The default job base is complete for one-off work: extend it, name it, implement handle().
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_default_job_base_is_the_unqualified_abstract_job(): void {
		$reflection = new \ReflectionClass( self::ROOT_NAMESPACE . 'Job\\AbstractJob' );

		self::assertTrue( $reflection->isAbstract() );
		self::assertTrue( $reflection->implementsInterface( self::ROOT_NAMESPACE . 'Job\\JobInterface' ) );
		$used_traits = \class_uses( $reflection->getName() );
		self::assertIsArray( $used_traits );
		self::assertContains( self::ROOT_NAMESPACE . 'Job\\JobDefaults', $used_traits, 'AbstractJob must share cross-kind defaults through the JobDefaults trait.' );

		$handle = $reflection->getMethod( 'handle' );
		self::assertTrue( $handle->isAbstract(), 'AbstractJob must leave handle() to the extending job.' );
		self::assertSame( $reflection->getName(), $handle->getDeclaringClass()->getName(), 'handle() must be declared by AbstractJob itself.' );

		$parameters = $handle->getParameters();
		self::assertCount( 2, $parameters );
		$context_type = $parameters[1]->getType();
		self::assertInstanceOf( \ReflectionNamedType::class, $context_type );
		self::assertSame( self::ROOT_NAMESPACE . 'Job\\RunContext', $context_type->getName() );

		self::assertSame( array( 'get_name', 'handle' ), self::abstract_method_names( $reflection ), 'Extending AbstractJob must require exactly a name and a handler.' );
	}

	/**
	 * The chunked kind is a sub-namespace base that shares the cross-kind defaults without handle().
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_chunked_kind_extends_the_shared_defaults_without_a_handler(): void {
		$reflection = new \ReflectionClass( self::ROOT_NAMESPACE . 'Job\\Chunked\\AbstractChunkedJob' );

		self::assertTrue( $reflection->isAbstract() );
		self::assertTrue( $reflection->implementsInterface( self::ROOT_NAMESPACE . 'Job\\Chunked\\ChunkedJobInterface' ) );
		$used_traits = \class_uses( $reflection->getName() );
		self::assertIsArray( $used_traits );
		self::assertContains( self::ROOT_NAMESPACE . 'Job\\JobDefaults', $used_traits, 'AbstractChunkedJob must share cross-kind defaults through the JobDefaults trait.' );

		self::assertFalse( $reflection->hasMethod( 'handle' ), 'The chunked kind must not inherit the one-off handler.' );
		self::assertSame( array( 'generate_queue', 'get_name', 'process_chunk' ), self::abstract_method_names( $reflection ), 'Extending AbstractChunkedJob must require exactly a name, queue generation, and chunk processing.' );

		$contract = new \ReflectionClass( self::ROOT_NAMESPACE . 'Job\\Chunked\\ChunkedJobInterface' );
		self::assertTrue( $contract->implementsInterface( self::ROOT_NAMESPACE . 'Job\\JobInterface' ) );
	}

	/**
	 * The batch kind scaffold declares the sub-namespace contract pair on the shared defaults.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_batch_kind_scaffold_declares_the_contract_pair(): void {
		$reflection = new \ReflectionClass( self::ROOT_NAMESPACE . 'Job\\Batch\\AbstractBatchJob' );

		self::assertTrue( $reflection->isAbstract() );
		self::assertTrue( $reflection->implementsInterface( self::ROOT_NAMESPACE . 'Job\\Batch\\BatchJobInterface' ) );
		$used_traits = \class_uses( $reflection->getName() );
		self::assertIsArray( $used_traits );
		self::assertContains( self::ROOT_NAMESPACE . 'Job\\JobDefaults', $used_traits, 'AbstractBatchJob must share cross-kind defaults through the JobDefaults trait.' );

		$contract = new \ReflectionClass( self::ROOT_NAMESPACE . 'Job\\Batch\\BatchJobInterface' );
		self::assertTrue( $contract->implementsInterface( self::ROOT_NAMESPACE . 'Job\\JobInterface' ) );
	}

	/**
	 * The callable-backed job is a final leaf of the default base.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_callable_job_is_a_final_leaf_of_the_default_base(): void {
		$reflection = new \ReflectionClass( self::ROOT_NAMESPACE . 'Job\\CallableJob' );

		self::assertTrue( $reflection->isFinal() );
		$parent = $reflection->getParentClass();
		self::assertNotFalse( $parent );
		self::assertSame( self::ROOT_NAMESPACE . 'Job\\AbstractJob', $parent->getName() );
	}

	/**
	 * No retired root-namespace, machinery-contract, or OneOff name survives.
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
	 * Returns the sorted abstract-method names extending code must implement.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @template T of object
	 * @phpstan-param \ReflectionClass<T> $reflection
	 *
	 * @param   \ReflectionClass $reflection Reflected abstract base.
	 *
	 * @return  list<string>
	 */
	private static function abstract_method_names( \ReflectionClass $reflection ): array {
		$names = array();
		foreach ( $reflection->getMethods( \ReflectionMethod::IS_ABSTRACT ) as $method ) {
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
