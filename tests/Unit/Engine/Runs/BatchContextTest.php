<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchContextInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Client;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\BatchContext;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\PortableArguments;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins attempt-local batch queue mutations and run metadata through real batch delivery.
 *
 */
#[CoversClass( BatchContext::class )]
#[UsesClass( PortableArguments::class )]
final class BatchContextTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const int NOW      = 1_700_000_000;
	private const string OWNER = 'batch-context-tests';

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
		$start_args = array( 'site_id' => 7 );
		$observed   = null;
		$batch      = new RecordingBatch( 'metadata' );

		$batch->queue      = array( array( 'chunk' => 'only' ) );
		$batch->on_process = static function ( array $chunk_args, BatchContextInterface $context ) use ( &$observed ): void {
			$observed = array(
				'run_id'     => $context->get_run_id(),
				'start_args' => $context->get_start_args(),
			);
		};

		$run_id = $this->start_and_deliver_first_chunk( $batch, $start_args );

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
		$observed = null;
		$batch    = new RecordingBatch( 'mutations' );

		$batch->queue      = array(
			array( 'chunk' => 'trigger' ),
			array( 'chunk' => 'existing-1' ),
			array( 'chunk' => 'existing-2' ),
		);
		$batch->on_process = static function ( array $chunk_args, BatchContextInterface $context ) use ( &$observed ): void {
			self::assertInstanceOf( BatchContext::class, $context );
			$context->enqueue( array( 'chunk' => 'appended-1' ) );
			$context->prepend( array( 'chunk' => 'prepended-1' ) );
			$context->prepend( array( 'chunk' => 'prepended-2' ) );
			$context->enqueue( array( 'chunk' => 'appended-2' ) );
			$observed = $context->get_queue();
		};

		$this->start_and_deliver_first_chunk( $batch, array( 'site_id' => 7 ) );

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
	 * Appending non-portable arguments throws without changing the buffered queue.
	 *
	 * @return  void
	 */
	public function test_enqueue_rejects_non_portable_arguments_without_mutating_the_queue(): void {
		$this->assert_non_portable_mutation_is_atomic(
			'enqueue-rejection',
			static fn ( BatchContextInterface $context ) => $context->enqueue( array( 'private-payload' => static fn (): null => null ) )
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
			static fn ( BatchContextInterface $context ) => $context->prepend( array( 'private-payload' => new \stdClass() ) )
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Starts one batch and drives its start and first run actions through registered hooks.
	 *
	 * @param   RecordingBatch          $batch      Registered batch fake.
	 * @param   array<array-key, mixed> $start_args Batch start arguments.
	 *
	 * @return  string
	 */
	private function start_and_deliver_first_chunk( RecordingBatch $batch, array $start_args ): string {
		$this->client->batches()->register( $batch );
		$result = $this->client->batches()->start( $batch->get_name(), $start_args );

		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );
		$this->rig->run_due();
		$this->rig->run_due();
		$this->rig->run_due();

		return $result->value;
	}

	/**
	 * Proves one rejected context mutation leaves the delivery-owned queue unchanged.
	 *
	 * @phpstan-param \Closure(BatchContextInterface): void $mutate
	 *
	 * @param   string   $name   Batch name.
	 * @param   \Closure $mutate Invalid context mutation.
	 *
	 * @return  void
	 */
	private function assert_non_portable_mutation_is_atomic( string $name, \Closure $mutate ): void {
		$initial  = array( array( 'chunk' => 'existing' ) );
		$observed = null;
		$caught   = null;
		$batch    = new RecordingBatch( $name );

		$batch->queue      = array( array( 'chunk' => 'trigger' ), ...$initial );
		$batch->on_process = static function ( array $chunk_args, BatchContextInterface $context ) use ( $mutate, &$observed, &$caught ): void {
			self::assertInstanceOf( BatchContext::class, $context );
			try {
				$mutate( $context );
			} catch ( \InvalidArgumentException $exception ) {
				$caught = $exception;
			}

			$observed = $context->get_queue();
		};

		$this->start_and_deliver_first_chunk( $batch, array( 'site_id' => 7 ) );

		self::assertInstanceOf( \InvalidArgumentException::class, $caught );
		self::assertSame( 'Batch chunk arguments must contain only null, scalar, or nested array values.', $caught->getMessage() );
		self::assertSame( $initial, $observed );
	}

	// endregion.
}
