<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Job\ClosureJobExecution;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkedJobExecution;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobExecution;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobKind;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins definition composition without coupling kind values to execution contracts.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( JobDefinition::class )]
#[UsesClass( ClosureJobExecution::class )]
#[UsesClass( JobKind::class )]
#[UsesClass( JobOptions::class )]
final class JobDefinitionTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies production-file boot guards before the model types autoload.
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
	 * Typed constructors compose the requested kind, execution, and policy by identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_typed_constructors_compose_exact_values(): void {
		$job_execution     = new class() implements JobExecution {
			/** {@inheritDoc} */
			#[\Override]
			public function handle( array $args, RunContext $context ): void {}
		};
		$chunked_execution = new class() implements ChunkedJobExecution {
			/** {@inheritDoc} */
			#[\Override]
			public function generate_queue( array $start_args, RunContext $context ): iterable {
				return array();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function process_chunk( array $chunk_args, ChunkContext $context ): void {}
		};
		$options           = new JobOptions( max_runtime: 42 );
		$job               = JobDefinition::job( 'refresh-index', $job_execution, $options );
		$chunked_job       = JobDefinition::chunked_job( 'recount-comments', $chunked_execution, $options );

		self::assertSame( 'refresh-index', $job->name );
		self::assertSame( 'job', $job->kind->value );
		self::assertSame( $job_execution, $job->execution );
		self::assertSame( $options, $job->options );
		self::assertSame( 'recount-comments', $chunked_job->name );
		self::assertSame( 'chunked_job', $chunked_job->kind->value );
		self::assertSame( $chunked_execution, $chunked_job->execution );
		self::assertSame( $options, $chunked_job->options );
	}

	/**
	 * Generic composition accepts a grammar-valid kind without inspecting its execution object.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_for_kind_defers_execution_compatibility_to_registration(): void {
		$kind       = JobKind::from( 'vendor.future' );
		$execution  = new \stdClass();
		$definition = JobDefinition::for_kind( 'future-work', $kind, $execution );

		self::assertSame( 'future-work', $definition->name );
		self::assertSame( $kind, $definition->kind );
		self::assertSame( $execution, $definition->execution );
		self::assertNull( $definition->options->max_runtime );
		self::assertNull( $definition->options->retry );
		self::assertNull( $definition->options->overlap );
		self::assertNull( $definition->options->overlap_key );
	}

	/**
	 * Closure composition is defaults-only and forwards the exact execution arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_closure_composition_uses_engine_defaults_and_forwards_execution_arguments(): void {
		$calls      = array();
		$definition = JobDefinition::closure(
			'refresh-index',
			static function ( array $args, RunContext $context ) use ( &$calls ): void {
				$calls[] = array( $args, $context );
			}
		);
		$context    = self::createStub( RunContext::class );
		$args       = array( 'site_id' => 7 );

		self::assertSame( array( 'name', 'handler' ), \array_map( static fn ( \ReflectionParameter $parameter ): string => $parameter->getName(), ( new \ReflectionMethod( JobDefinition::class, 'closure' ) )->getParameters() ) );
		self::assertSame( 'job', $definition->kind->value );
		self::assertInstanceOf( JobExecution::class, $definition->execution );
		self::assertNull( $definition->options->max_runtime );
		self::assertNull( $definition->options->retry );
		self::assertNull( $definition->options->overlap );
		self::assertNull( $definition->options->overlap_key );

		$definition->execution->handle( $args, $context );

		self::assertSame( array( array( $args, $context ) ), $calls );
	}

	// endregion.
}
