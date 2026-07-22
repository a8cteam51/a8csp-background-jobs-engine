<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkContext as ChunkContextContract;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Client;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ChunkContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins attempt-local chunked job queue mutations and run metadata through real chunked job delivery.
 *
 */
#[CoversClass( ChunkContext::class )]
#[UsesClass( PortableArguments::class )]
final class ChunkContextTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const int NOW      = 1_700_000_000;
	private const string OWNER = 'chunked-job-context-tests';

	private Client $client;
	private EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads the guarded WordPress seams used by the behavioral rig.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
	}

	/**
	 * Boots one deterministic production graph for each scenario.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig    = EngineRig::set_up( self::NOW );
		$this->client = $this->rig->client( self::OWNER );
	}

	/**
	 * Releases request-local engine state after each scenario.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function tearDown(): void {
		try {
			$this->rig->tear_down();
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * Accessors retain the exact run identity and start arguments supplied by orchestration.
	 *
	 * @return  void
	 */
	public function test_accessors_return_the_attempt_run_metadata(): void {
		$start_args  = array( 'site_id' => 7 );
		$observed    = null;
		$chunked_job = new RecordingChunkedJob( 'metadata' );

		$chunked_job->queue      = array( array( 'chunk' => 'only' ) );
		$chunked_job->on_process = static function ( array $chunk_args, ChunkContextContract $context ) use ( &$observed ): void {
			$observed = array(
				'run_id'     => $context->get_run_id(),
				'start_args' => $context->get_start_args(),
			);
		};

		$run_id = $this->start_and_deliver_first_chunk( $chunked_job, $start_args );

		self::assertSame(
			array(
				'run_id'     => $run_id,
				'start_args' => $start_args,
			),
			$observed
		);
	}

	/**
	 * Appends retain call order while each prepend becomes the new queue head.
	 *
	 * @return  void
	 */
	public function test_queue_mutations_remain_buffered_in_exact_processing_order(): void {
		$observed    = null;
		$chunked_job = new RecordingChunkedJob( 'mutations' );

		$chunked_job->queue      = array(
			array( 'chunk' => 'trigger' ),
			array( 'chunk' => 'existing-1' ),
			array( 'chunk' => 'existing-2' ),
		);
		$chunked_job->on_process = static function ( array $chunk_args, ChunkContextContract $context ) use ( &$observed ): void {
			self::assertInstanceOf( ChunkContext::class, $context );
			$context->enqueue( array( 'chunk' => 'appended-1' ) );
			$context->prepend( array( 'chunk' => 'prepended-1' ) );
			$context->prepend( array( 'chunk' => 'prepended-2' ) );
			$context->enqueue( array( 'chunk' => 'appended-2' ) );
			$observed = $context->get_queue();
		};

		$this->start_and_deliver_first_chunk( $chunked_job, array( 'site_id' => 7 ) );

		self::assertSame(
			array(
				array( 'chunk' => 'prepended-2' ),
				array( 'chunk' => 'prepended-1' ),
				array( 'chunk' => 'existing-1' ),
				array( 'chunk' => 'existing-2' ),
				array( 'chunk' => 'appended-1' ),
				array( 'chunk' => 'appended-2' ),
			),
			$observed
		);
	}

	/**
	 * Buffered mutations sever caller-held references before retaining validated chunks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_queue_mutations_snapshot_referenced_arguments_before_retaining_them(): void {
		$chunked_job = new RecordingChunkedJob( 'referenced-mutations' );

		$chunked_job->queue      = array( array( 'chunk' => 'trigger' ) );
		$chunked_job->on_process = static function ( array $chunk_args, ChunkContextContract $context ): void {
			if ( 'trigger' !== ( $chunk_args['chunk'] ?? null ) ) {
				$chunk_args['value'] = 'copy-mutated';

				return;
			}

			$value     = 'accepted';
			$appended  = array(
				'value'  => &$value,
				'mirror' => &$value,
			);
			$prepended = array(
				'value'  => &$value,
				'mirror' => &$value,
			);
			$context->enqueue( $appended );
			$context->prepend( $prepended );

			$value = 'mutated';
		};

		$this->start_and_deliver_first_chunk( $chunked_job, array() );
		$this->rig->run_due();
		$this->rig->run_due();

		$expected = array(
			'value'  => 'accepted',
			'mirror' => 'accepted',
		);
		self::assertCount( 3, $chunked_job->process_calls );
		self::assertSame( $expected, $chunked_job->process_calls[1]['chunk_args'] ?? null );
		self::assertSame( $expected, $chunked_job->process_calls[2]['chunk_args'] ?? null );
	}

	/**
	 * Construction severs caller-held references before retaining start arguments.
	 *
	 * @load-bearing security
	 * @pin-rationale The production constructor is the only seam that can prove references are severed before the context retains start arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_constructor_snapshots_referenced_start_arguments_before_retaining_them(): void {
		$site_id    = 7;
		$start_args = array(
			'site_id' => &$site_id,
			'mirror'  => &$site_id,
		);
		$context    = new ChunkContext( 'run-id', $start_args, array() );

		$site_id             = 8;
		$retained            = $context->get_start_args();
		$retained['site_id'] = 9;

		self::assertSame(
			array(
				'site_id' => 7,
				'mirror'  => 7,
			),
			$context->get_start_args()
		);
	}

	/**
	 * Appending non-portable arguments throws without changing the buffered queue.
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_non_portable_arguments_without_mutating_the_queue(): void {
		$this->assert_non_portable_mutation_is_atomic(
			'enqueue-rejection',
			static fn ( ChunkContextContract $context ) => $context->enqueue( array( 'private-payload' => static fn (): null => null ) )
		);
	}

	/**
	 * Prepending non-portable arguments throws without changing the buffered queue.
	 *
	 * @return  void
	 */
	public function test_prepend_rejects_non_portable_arguments_without_mutating_the_queue(): void {
		$this->assert_non_portable_mutation_is_atomic(
			'prepend-rejection',
			static fn ( ChunkContextContract $context ) => $context->prepend( array( 'private-payload' => new \stdClass() ) )
		);
	}

	/**
	 * Appending a resource rejects the original value instead of retaining its serialized integer form.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_a_resource_without_mutating_the_queue(): void {
		$stream = \fopen( 'php://memory', 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A resource payload is required to exercise the portability boundary.
		self::assertIsResource( $stream );

		try {
			$this->assert_non_portable_mutation_is_atomic(
				'enqueue-resource-rejection',
				static fn ( ChunkContextContract $context ) => $context->enqueue( array( 'private-payload' => $stream ) )
			);
		} finally {
			\fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The test-owned resource must be released.
		}
	}

	/**
	 * Prepending a resource rejects the original value instead of retaining its serialized integer form.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_prepend_rejects_a_resource_without_mutating_the_queue(): void {
		$stream = \fopen( 'php://memory', 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A resource payload is required to exercise the portability boundary.
		self::assertIsResource( $stream );

		try {
			$this->assert_non_portable_mutation_is_atomic(
				'prepend-resource-rejection',
				static fn ( ChunkContextContract $context ) => $context->prepend( array( 'private-payload' => $stream ) )
			);
		} finally {
			\fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The test-owned resource must be released.
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Starts one chunked job and drives its start and first continuation actions through registered hooks.
	 *
	 * @param   RecordingChunkedJob          $chunked_job      Registered chunked job fake.
	 * @param   array<array-key, mixed> $start_args Chunked Job start arguments.
	 *
	 * @return  string
	 */
	private function start_and_deliver_first_chunk( RecordingChunkedJob $chunked_job, array $start_args ): string {
		$this->client->chunked_jobs()->register( $chunked_job );
		$result = $this->client->chunked_jobs()->start( $chunked_job->get_name(), $start_args );

		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );
		$this->rig->run_due();
		$this->rig->run_due();

		return $result->value;
	}

	/**
	 * Proves one rejected context mutation leaves the delivery-owned queue unchanged.
	 *
	 * @phpstan-param \Closure(ChunkContextContract): void $mutate
	 *
	 * @param   string   $name   Chunked Job name.
	 * @param   \Closure $mutate Invalid context mutation.
	 *
	 * @return  void
	 */
	private function assert_non_portable_mutation_is_atomic( string $name, \Closure $mutate ): void {
		$initial     = array( array( 'chunk' => 'existing' ) );
		$caught      = null;
		$chunked_job = new RecordingChunkedJob( $name );

		$chunked_job->queue      = array( array( 'chunk' => 'trigger' ), ...$initial );
		$chunked_job->on_process = static function ( array $chunk_args, ChunkContextContract $context ) use ( $mutate, &$caught ): void {
			if ( 'trigger' !== ( $chunk_args['chunk'] ?? null ) ) {
				return;
			}

			try {
				$mutate( $context );
			} catch ( \InvalidArgumentException $exception ) {
				$caught = $exception;
			}
		};

		$this->start_and_deliver_first_chunk( $chunked_job, array( 'site_id' => 7 ) );
		for ( $delivery = 0; $delivery < 4; ++$delivery ) {
			$this->rig->run_due();
		}

		self::assertInstanceOf( \InvalidArgumentException::class, $caught );
		self::assertSame( 'Chunked Job chunk arguments must contain only null, scalar, or nested array values.', $caught->getMessage() );
		self::assertCount( 2, $chunked_job->process_calls );
		self::assertSame( $initial[0], $chunked_job->process_calls[1]['chunk_args'] ?? null );
	}

	// endregion.
}
