<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchContextInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\PortableArguments;

\defined( 'ABSPATH' ) || exit;

/**
 * Buffers one chunk attempt's queue mutations until orchestration commits them.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class BatchContext implements BatchContextInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum encoded JSON bytes accepted for one context-supplied batch chunk.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const MAX_CHUNK_BYTES = 8_192;

	/**
	 * Maximum persisted serialization bytes accepted for one context-mutated batch queue.
	 *
	 * This bounds the queue stored in the wp_options run-state row; the JSON chunk cap separately
	 * bounds the portable payload contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const MAX_QUEUE_BYTES = 1_048_576;

	/**
	 * Attempt-local queue in processing order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<array<array-key, mixed>>
	 */
	private array $queue;

	/**
	 * Separate front mutations reverse once at commit without shifting the base queue repeatedly.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<array<array-key, mixed>>
	 */
	private array $prepended = array();

	/**
	 * Separate back mutations leave the base queue unchanged until commit.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<array<array-key, mixed>>
	 */
	private array $appended = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                        $run_id     Run identifier.
	 * @param   array<array-key, mixed>       $start_args Arguments supplied when the run started.
	 * @param   list<array<array-key, mixed>> $queue      Persisted queue awaiting this attempt's mutations.
	 */
	public function __construct(
		private readonly string $run_id,
		private readonly array $start_args,
		array $queue,
	) {
		$this->queue = $queue;
	}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function enqueue( array $chunk_args ): void {
		self::assert_valid_chunk( $chunk_args );
		self::assert_queue_within_persisted_byte_limit( array( ...$this->get_queue(), $chunk_args ) );

		$this->appended[] = $chunk_args;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function prepend( array $chunk_args ): void {
		self::assert_valid_chunk( $chunk_args );
		self::assert_queue_within_persisted_byte_limit( array( $chunk_args, ...$this->get_queue() ) );

		$this->prepended[] = $chunk_args;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function get_run_id(): string {
		return $this->run_id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function get_start_args(): array {
		return $this->start_args;
	}

	// endregion

	// region METHODS

	/**
	 * Returns the attempt-local queue for a normal-return commit.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array<array-key, mixed>>
	 */
	public function get_queue(): array {
		return \array_merge( \array_reverse( $this->prepended ), $this->queue, $this->appended );
	}

	/**
	 * Rejects chunk arguments that option storage and scheduler payloads cannot carry safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Chunk arguments.
	 *
	 * @throws  InvalidBatchChunkException When the chunk arguments are not portable.
	 *
	 * @return  void
	 */
	private static function assert_valid_chunk( array $chunk_args ): void {
		if ( ! PortableArguments::is_valid( $chunk_args ) ) {
			throw new InvalidBatchChunkException();
		}

		try {
			$encoded_chunk = \wp_json_encode( $chunk_args, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION );
		} catch ( \JsonException ) {
			throw new InvalidBatchChunkException();
		}
		if ( ! \is_string( $encoded_chunk ) ) {
			throw new InvalidBatchChunkException();
		}

		$chunk_bytes = \strlen( $encoded_chunk );
		if ( self::MAX_CHUNK_BYTES < $chunk_bytes ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw new InvalidBatchChunkException( \sprintf( 'Batch chunk arguments contain %1$d JSON bytes; the limit is %2$d bytes.', $chunk_bytes, self::MAX_CHUNK_BYTES ) );
		}
	}

	/**
	 * Rejects a queue mutation whose persisted serialization exceeds the aggregate limit.
	 *
	 * RunStore writes this representation inside the wp_options run-state row. Chunk JSON limits
	 * remain independent because they define the portable payload contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<array<array-key, mixed>> $queue Candidate queue in processing order.
	 *
	 * @throws  InvalidBatchChunkException When the candidate queue exceeds the persisted byte limit.
	 *
	 * @return  void
	 */
	private static function assert_queue_within_persisted_byte_limit( array $queue ): void {
		$serialized_queue = \maybe_serialize( $queue );
		if ( ! \is_string( $serialized_queue ) ) {
			throw new InvalidBatchChunkException();
		}
		$queue_bytes = \strlen( $serialized_queue );
		if ( self::MAX_QUEUE_BYTES < $queue_bytes ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw new InvalidBatchChunkException( \sprintf( 'Batch queue contains %1$d persisted serialization bytes; the limit is %2$d bytes.', $queue_bytes, self::MAX_QUEUE_BYTES ) );
		}
	}

	// endregion
}
