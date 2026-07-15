<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;

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
	 * Registered batches keyed by stable name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, BatchInterface>
	 */
	private array $batches = array();

	// endregion

	// region METHODS

	/**
	 * Registers one uniquely named batch instance.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   BatchInterface $batch Batch to register.
	 *
	 * @throws  \InvalidArgumentException When the batch name is outside the stable-name grammar or exceeds 110 bytes.
	 * @throws  \LogicException           When the batch name is already registered.
	 *
	 * @return  void
	 */
	public function register( BatchInterface $batch ): void {
		$name = $batch->get_name();
		if ( 1 !== \preg_match( '/\A[a-z0-9_-]+\z/', $name ) ) {
			throw new \InvalidArgumentException(
				'Batch name is invalid; return a non-empty name containing only lowercase letters, digits, underscores, and hyphens.'
			);
		}
		if ( 110 < \strlen( $name ) ) {
			throw new \InvalidArgumentException(
				'Batch name must be at most 110 bytes; shorten the batch name.'
			);
		}

		if ( isset( $this->batches[ $name ] ) ) {
			throw new \LogicException(
				'Batch name is already registered; register each batch name exactly once.'
			);
		}

		$this->batches[ $name ] = $batch;
	}

	/**
	 * Returns the batch registered under a stable name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Stable batch name.
	 *
	 * @return  BatchInterface|null
	 */
	public function get( string $name ): ?BatchInterface {
		return $this->batches[ $name ] ?? null;
	}

	// endregion
}
