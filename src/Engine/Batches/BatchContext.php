<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Batches;

use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Helpers\ScalarTree;

\defined( 'ABSPATH' ) || exit;

/**
 * Buffers one chunk attempt's queue mutations until orchestration commits them.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class BatchContext implements BatchContextInterface {
	// region FIELDS AND CONSTANTS

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
		return \array_merge(
			\array_reverse( $this->prepended ),
			$this->queue,
			$this->appended
		);
	}

	/**
	 * Rejects chunk arguments that option storage and scheduler payloads cannot carry safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Chunk arguments.
	 *
	 * @throws  \InvalidArgumentException When the chunk is not a scalar tree.
	 *
	 * @return  void
	 */
	private static function assert_valid_chunk( array $chunk_args ): void {
		if ( ! ScalarTree::is_valid( $chunk_args ) ) {
			throw new \InvalidArgumentException(
				'Batch chunk arguments must contain only null, scalar, or nested array values.'
			);
		}
	}

	// endregion
}
