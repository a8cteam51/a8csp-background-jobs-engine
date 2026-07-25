<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\ChunkedJobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\ChunkedRunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\JobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\JobKind;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\KindExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContextInterface;
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
		$job_execution     = new class() implements JobExecutionInterface {
			/** {@inheritDoc} */
			#[\Override]
			public function handle( array $start_args, RunContextInterface $context ): void {}
		};
		$chunked_execution = new class() implements ChunkedJobExecutionInterface {
			/** {@inheritDoc} */
			#[\Override]
			public function generate_queue( array $start_args, RunContextInterface $context ): iterable {
				return array();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function process_chunk( array $chunk_args, ChunkedRunContextInterface $context ): void {}
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
	 * Generic composition accepts a grammar-valid kind without inspecting its execution role.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_for_kind_defers_execution_compatibility_to_registration(): void {
		$kind       = JobKind::from( 'vendor.future' );
		$execution  = new class() implements KindExecutionInterface {};
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
			name: 'refresh-index',
			handler: static function ( array $start_args, RunContextInterface $context ) use ( &$calls ): void {
				$calls[] = array( $start_args, $context );
			}
		);
		$context    = self::createStub( RunContextInterface::class );
		$args       = array( 'site_id' => 7 );

		self::assertSame( 'job', $definition->kind->value );
		self::assertInstanceOf( JobExecutionInterface::class, $definition->execution );
		self::assertNull( $definition->options->max_runtime );
		self::assertNull( $definition->options->retry );
		self::assertNull( $definition->options->overlap );
		self::assertNull( $definition->options->overlap_key );

		$definition->execution->handle( $args, $context );

		self::assertSame( array( array( $args, $context ) ), $calls );
	}

	// endregion.
}
