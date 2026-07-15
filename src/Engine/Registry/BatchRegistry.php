<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Retains registered batch instances by their stable identity.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class BatchRegistry {
	// region FIELDS AND CONSTANTS

	/**
	 * Registered batches keyed by stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, BatchInterface>
	 */
	private array $batches = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   WorkRegistry $work Shared task-and-batch identity registry.
	 */
	public function __construct(
		private readonly WorkRegistry $work
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers one batch instance under a unique identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $identity Complete owner-qualified identity.
	 * @param   BatchInterface $batch    Batch to register.
	 *
	 * @throws  \InvalidArgumentException When the identity and batch name disagree, or another kind owns the identity.
	 * @throws  \LogicException           When the batch identity is already registered.
	 *
	 * @return  void
	 */
	public function register( string $identity, BatchInterface $batch ): void {
		$name = $batch->get_name();
		WorkIdentity::validate_name( $name );
		$parts = WorkIdentity::parts( $identity );
		if ( null === $parts || $name !== $parts[1] ) {
			throw new \InvalidArgumentException(
				'Batch identity must be canonical and end with the batch\'s declared local name.'
			);
		}

		$this->work->claim( $identity, 'batch' );
		$this->batches[ $identity ] = $batch;
	}

	/**
	 * Returns the batch registered under a stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Complete owner-qualified batch identity.
	 *
	 * @return  BatchInterface|null
	 */
	public function get( string $name ): ?BatchInterface {
		return $this->batches[ $name ] ?? null;
	}

	// endregion
}
