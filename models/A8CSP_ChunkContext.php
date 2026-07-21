<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\ChunkContext as InternalContext;

\defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- The consumer contract requires this global class name.
/**
 * Gives a consumer chunk controlled access to its own run.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class A8CSP_ChunkContext {
	// phpcs:enable

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @internal Adapter boundary; consumers receive instances from the engine.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   InternalContext $inner Internal chunk context.
	 */
	public function __construct(
		private InternalContext $inner,
	) {}

	// endregion

	// region METHODS

	/**
	 * Adds a chunk at the back of the run queue. The engine throws \InvalidArgumentException for a
	 * non-portable chunk or one over the payload-size limit, which fails the current attempt.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for the appended chunk.
	 *
	 * @return  void
	 */
	public function enqueue( array $chunk_args ): void {
		$this->inner->enqueue( $chunk_args );
	}

	/**
	 * Adds a chunk at the front of the run queue. The engine throws \InvalidArgumentException for a
	 * non-portable chunk or one over the payload-size limit, which fails the current attempt.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $chunk_args Arguments for the prepended chunk.
	 *
	 * @return  void
	 */
	public function prepend( array $chunk_args ): void {
		$this->inner->prepend( $chunk_args );
	}

	/**
	 * Returns the engine-assigned run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function get_run_id(): string {
		return $this->inner->get_run_id();
	}

	/**
	 * Returns the arguments supplied when the run started.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>
	 */
	public function get_start_args(): array {
		return $this->inner->get_start_args();
	}

	// endregion
}
