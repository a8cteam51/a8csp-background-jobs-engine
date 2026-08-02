<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\ChunkedRunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
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
final class ChunkedRunContext implements ChunkedRunContextInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum encoded JSON bytes accepted for one context-supplied chunked job chunk.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int MAX_CHUNK_BYTES = 8_192;

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
	 * Accumulated serialization bytes for the base queue and buffered mutation chunks, once measured.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int|null
	 */
	private ?int $queue_bytes = null;

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
		$this->assert_room_for( $chunk_args );

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
		$this->assert_room_for( $chunk_args );

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
			throw InvalidChunkException::non_portable();
		}

		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- The round trip detaches the snapshot from caller-owned containers before recursive rebuilding removes repeated aliases.
			$snapshot = \unserialize( \serialize( $arguments ), array( 'allowed_classes' => false ) );
		} catch ( \Throwable ) {
			throw InvalidChunkException::non_portable();
		}

		if ( ! \is_array( $snapshot ) || ! PortableArguments::is_valid( $snapshot ) ) {
			throw InvalidChunkException::non_portable();
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
			throw InvalidChunkException::non_portable();
		}

		try {
			$encoded_chunk = \wp_json_encode( $chunk_args, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION );
		} catch ( \JsonException ) {
			throw InvalidChunkException::non_portable();
		}
		if ( ! \is_string( $encoded_chunk ) ) {
			throw InvalidChunkException::non_portable();
		}

		$chunk_bytes = \strlen( $encoded_chunk );
		if ( self::MAX_CHUNK_BYTES < $chunk_bytes ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw InvalidChunkException::chunk_too_large( $chunk_bytes, self::MAX_CHUNK_BYTES );
		}
	}

	/**
	 * Rejects a queue mutation whose accumulated serialization bytes exceed the aggregate limit.
	 *
	 * The base seed includes its list envelope, while mutations add only member bytes, keeping the
	 * estimate below the merged queue without rebuilding buffered mutations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Validated chunk arguments.
	 *
	 * @throws  InvalidChunkException When the queue this mutation would produce exceeds the persisted byte limit.
	 *
	 * @return  void
	 */
	private function assert_room_for( array $chunk_args ): void {
		$this->queue_bytes ??= self::persisted_bytes( $this->queue );
		$queue_bytes         = $this->queue_bytes + self::persisted_bytes( $chunk_args );
		if ( self::MAX_QUEUE_BYTES < $queue_bytes ) {
			// A refused mutation is never buffered, so the reported total measures the queue exactly rather than the estimate that refused it.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw InvalidChunkException::queue_too_large( self::persisted_bytes( array( ...$this->get_queue(), $chunk_args ) ), self::MAX_QUEUE_BYTES );
		}

		$this->queue_bytes = $queue_bytes;
	}

	/**
	 * Returns the byte length of one argument array's persisted representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $arguments Portable argument array.
	 *
	 * @return  int
	 */
	private static function persisted_bytes( array $arguments ): int {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A portable argument array is a known array, so this is the branch maybe_serialize() takes; it measures the persisted representation without producing one.
		return \strlen( \serialize( $arguments ) );
	}

	// endregion
}
