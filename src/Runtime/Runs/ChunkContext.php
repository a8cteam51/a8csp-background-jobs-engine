<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;

\defined( 'ABSPATH' ) || exit;

/**
 * Buffers one chunk attempt's queue mutations until orchestration commits them.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ChunkContext implements ChunkContextInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum encoded JSON bytes accepted for one context-supplied chunked job chunk.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_CHUNK_BYTES = 8_192;

	/**
	 * Maximum persisted serialization bytes accepted for one context-mutated chunked job queue.
	 *
	 * This bounds the queue stored in the wp_options run-state row; the JSON chunk cap separately
	 * bounds the portable payload contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_QUEUE_BYTES = RunStore::MAX_KIND_STATE_BYTES;

	/**
	 * Arguments supplied when the run started.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<array-key, mixed>
	 */
	private readonly array $start_args;

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
		array $start_args,
		array $queue,
	) {
		$this->start_args = PortableArguments::without_references( $start_args );
		$this->queue      = self::snapshot_queue( $queue );
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
	public function append_chunk( array $chunk_args ): void {
		$chunk_args = self::snapshot_arguments( $chunk_args );
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
	public function prepend_chunk( array $chunk_args ): void {
		$chunk_args = self::snapshot_arguments( $chunk_args );
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
	public function get_run_id(): RunId {
		return RunId::from( $this->run_id );
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

	// endregion

	// region HELPERS

	/**
	 * Severs caller-held PHP references before retaining an argument array.
	 *
	 * The preflight rejects values that PHP serialization normalizes, such as resources, before the
	 * serialization round trip creates the retained representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $arguments Arguments to snapshot.
	 *
	 * @throws  InvalidChunkException When the arguments cannot be snapshotted.
	 *
	 * @return  array<array-key, mixed>
	 */
	private static function snapshot_arguments( array $arguments ): array {
		if ( ! PortableArguments::is_valid( $arguments ) ) {
			throw InvalidChunkException::nonPortable();
		}

		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- The round trip detaches the snapshot from caller-owned containers before recursive rebuilding removes repeated aliases.
			$snapshot = \unserialize( \serialize( $arguments ), array( 'allowed_classes' => false ) );
		} catch ( \Throwable ) {
			throw InvalidChunkException::nonPortable();
		}

		if ( ! \is_array( $snapshot ) || ! PortableArguments::is_valid( $snapshot ) ) {
			throw InvalidChunkException::nonPortable();
		}

		return PortableArguments::without_references( $snapshot );
	}

	/**
	 * Returns the retained queue without references shared between chunks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<array<array-key, mixed>> $queue Persisted queue.
	 *
	 * @return  list<array<array-key, mixed>>
	 */
	private static function snapshot_queue( array $queue ): array {
		$snapshot = array();
		foreach ( $queue as $chunk_args ) {
			$snapshot[] = PortableArguments::without_references( $chunk_args );
		}

		return $snapshot;
	}

	/**
	 * Rejects chunk arguments outside the persisted portable-payload contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Chunk arguments.
	 *
	 * @throws  InvalidChunkException When the chunk arguments are not portable.
	 *
	 * @return  void
	 */
	private static function assert_valid_chunk( array $chunk_args ): void {
		if ( ! PortableArguments::is_valid( $chunk_args ) ) {
			throw InvalidChunkException::nonPortable();
		}

		try {
			$encoded_chunk = \wp_json_encode( $chunk_args, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION );
		} catch ( \JsonException ) {
			throw InvalidChunkException::nonPortable();
		}
		if ( ! \is_string( $encoded_chunk ) ) {
			throw InvalidChunkException::nonPortable();
		}

		$chunk_bytes = \strlen( $encoded_chunk );
		if ( self::MAX_CHUNK_BYTES < $chunk_bytes ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw InvalidChunkException::chunkTooLarge( $chunk_bytes, self::MAX_CHUNK_BYTES );
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
	 * @throws  InvalidChunkException When the candidate queue exceeds the persisted byte limit.
	 *
	 * @return  void
	 */
	private static function assert_queue_within_persisted_byte_limit( array $queue ): void {
		$serialized_queue = \maybe_serialize( $queue );
		if ( ! \is_string( $serialized_queue ) ) {
			// Core serializes arrays to strings; the smallest rejected count keeps a violated storage contract fail-closed.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw InvalidChunkException::queueTooLarge( self::MAX_QUEUE_BYTES + 1, self::MAX_QUEUE_BYTES );
		}
		$queue_bytes = \strlen( $serialized_queue );
		if ( self::MAX_QUEUE_BYTES < $queue_bytes ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw InvalidChunkException::queueTooLarge( $queue_bytes, self::MAX_QUEUE_BYTES );
		}
	}

	// endregion
}
