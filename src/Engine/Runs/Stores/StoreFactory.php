<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use Psr\Clock\ClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Constructs the stores bound to one task or batch identity.
 *
 * A single factory keeps identity binding at the orchestration boundary without exposing four
 * untyped closure dependencies.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class StoreFactory {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ClockInterface $clock Run timestamp source.
	 * @param   OptionRows     $rows  Authoritative raw option-row I/O.
	 */
	public function __construct(
		private ClockInterface $clock,
		private OptionRows $rows,
	) {}

	// endregion

	// region METHODS

	/**
	 * Constructs the active-run store for a task or batch identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 *
	 * @return  RunStore
	 */
	public function run_store( string $identity ): RunStore {
		return new RunStore( $identity, $this->clock, $this->rows );
	}

	/**
	 * Constructs the latest-run pointer for a task or batch identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 *
	 * @return  LatestRunPointer
	 */
	public function latest_run_pointer( string $identity ): LatestRunPointer {
		return new LatestRunPointer( $identity, $this->rows );
	}

	/**
	 * Constructs the run history for a task or batch identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 *
	 * @return  RunHistory
	 */
	public function run_history( string $identity ): RunHistory {
		return new RunHistory( $identity, $this->rows );
	}

	/**
	 * Constructs the failed-run store for a task or batch identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified task or batch identity.
	 *
	 * @return  FailedRunStore
	 */
	public function failed_run_store( string $identity ): FailedRunStore {
		return new FailedRunStore( $identity, $this->rows );
	}

	// endregion
}
