<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Batches;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchContextInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Retry\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches\BatchRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins batch identity validation and instance lookup.
 *
 */
#[CoversClass( BatchRegistry::class )]
final class BatchRegistryTest extends TestCase {

	/**
	 * Satisfies production boot guards before batch contracts are autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}
	}

	/**
	 * Registration retains the exact batch instance under its stable name.
	 *
	 * @return  void
	 */
	public function test_get_returns_the_registered_instance_and_null_for_an_unknown_name(): void {
		$batch    = $this->batch( 'refresh_index-2' );
		$registry = new BatchRegistry();

		$registry->register( $batch );

		self::assertSame( $batch, $registry->get( 'refresh_index-2' ) );
		self::assertNull( $registry->get( 'unknown' ) );
	}

	/**
	 * Registration accepts the storage-safe boundary and names the shortening fix beyond it.
	 *
	 * @return  void
	 */
	public function test_register_accepts_110_bytes_and_rejects_111_with_the_fix(): void {
		$accepted = $this->batch( \str_repeat( 'a', 110 ) );
		$registry = new BatchRegistry();

		$registry->register( $accepted );
		self::assertSame( $accepted, $registry->get( \str_repeat( 'a', 110 ) ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs(
			'Batch name must be at most 110 bytes; shorten the batch name.'
		);

		$registry->register( $this->batch( \str_repeat( 'a', 111 ) ) );
	}

	/**
	 * Invalid names identify the accepted spelling needed to register the batch.
	 *
	 * @param   string $name Invalid batch name.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_names' )]
	public function test_register_rejects_invalid_names_with_the_fix( string $name ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs(
			'Batch name is invalid; return a non-empty name containing only lowercase letters, digits, underscores, and hyphens.'
		);

		( new BatchRegistry() )->register( $this->batch( $name ) );
	}

	/**
	 * Supplies names outside the complete stable-name grammar.
	 *
	 * @return  array<string, array{name: string}>
	 */
	public static function invalid_names(): array {
		return array(
			'empty'     => array( 'name' => '' ),
			'uppercase' => array( 'name' => 'RefreshIndex' ),
			'space'     => array( 'name' => 'refresh index' ),
			'period'    => array( 'name' => 'refresh.index' ),
			'non-ASCII' => array( 'name' => 'réindex' ),
		);
	}

	/**
	 * Duplicate registration identifies the one-name-one-instance correction.
	 *
	 * @return  void
	 */
	public function test_register_rejects_a_duplicate_name_with_the_fix(): void {
		$registry = new BatchRegistry();
		$registry->register( $this->batch( 'refresh-index' ) );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs(
			'Batch name is already registered; register each batch name exactly once.'
		);

		$registry->register( $this->batch( 'refresh-index' ) );
	}

	/**
	 * Creates a batch with the supplied identity.
	 *
	 * @param   string $name Batch name.
	 *
	 * @return  BatchInterface
	 */
	private function batch( string $name ): BatchInterface {
		return new class( $name ) implements BatchInterface {

			/**
			 * Constructor.
			 *
			 * @param   string $name Batch name.
			 */
			public function __construct( private readonly string $name ) {}

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return $this->name;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function max_runtime(): int {
				return self::DEFAULT_MAX_RUNTIME;
			}

			/**
			 * Returns no chunks because registry coverage exercises identity only.
			 *
			 * @param   array<array-key, mixed> $start_args Unused start arguments.
			 *
			 * @return  iterable<array<array-key, mixed>>
			 */
			#[\Override]
			public function generate_queue( array $start_args ): iterable {
				return array();
			}

			/**
			 * Accepts an unused chunk because registry coverage never executes work.
			 *
			 * @param   array<array-key, mixed> $chunk_args Unused chunk arguments.
			 * @param   BatchContextInterface   $context    Unused batch context.
			 *
			 * @return  void
			 */
			#[\Override]
			public function process_chunk( array $chunk_args, BatchContextInterface $context ): void {}

			/**
			 * Accepts an unused success because registry coverage never executes work.
			 *
			 * @param   string                  $run_id     Unused run identifier.
			 * @param   array<array-key, mixed> $start_args Unused start arguments.
			 *
			 * @return  void
			 */
			#[\Override]
			public function on_success( string $run_id, array $start_args ): void {}

			/**
			 * Accepts an unused failure because registry coverage never executes work.
			 *
			 * @param   string                  $run_id     Unused run identifier.
			 * @param   array<array-key, mixed> $start_args Unused start arguments.
			 * @param   EngineError             $error      Unused failure detail.
			 *
			 * @return  void
			 */
			#[\Override]
			public function on_failure( string $run_id, array $start_args, EngineError $error ): void {}

			/** {@inheritDoc} */
			#[\Override]
			public function get_retry_policy(): RetryPolicy {
				return new RetryPolicy();
			}
		};
	}
}
