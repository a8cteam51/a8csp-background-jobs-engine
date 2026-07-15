<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Records the single task-and-batch kind assigned to each work identity.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class WorkRegistry {
	// region FIELDS AND CONSTANTS

	/**
	 * Registered work kinds keyed by complete identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, 'batch'|'task'>
	 */
	private array $kinds = array();

	// endregion

	// region METHODS

	/**
	 * Claims one complete identity for exactly one work kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $identity Complete owner-qualified identity.
	 * @param   'batch'|'task' $kind     Registered work kind.
	 *
	 * @throws  \InvalidArgumentException When the identity is invalid or belongs to the other kind.
	 * @throws  \LogicException           When the same kind already owns the identity.
	 *
	 * @return  void
	 */
	public function claim( string $identity, string $kind ): void {
		if ( null === WorkIdentity::parts( $identity ) ) {
			throw new \InvalidArgumentException(
				'Background-work identity is invalid; pass one canonical {owner}:{name} identity.'
			);
		}

		$existing = $this->kinds[ $identity ] ?? null;
		if ( $kind === $existing ) {
			throw new \LogicException(
				'task' === $kind
					? 'Task name is already registered; register each task name exactly once.'
					: 'Batch name is already registered; register each batch name exactly once.'
			);
		}
		if ( null !== $existing ) {
			// Exception values are diagnostic data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \InvalidArgumentException(
				\sprintf(
					'Background-work identity "%1$s" is already registered as a %2$s; it cannot also be registered as a %3$s.',
					$identity,
					$existing,
					$kind
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->kinds[ $identity ] = $kind;
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the recorded kind for one complete identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified identity.
	 *
	 * @return  'batch'|'task'|null
	 */
	public function kind( string $identity ): ?string {
		return $this->kinds[ $identity ] ?? null;
	}

	// endregion
}
