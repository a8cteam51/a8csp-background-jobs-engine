<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\OptionRows;
use Psr\Clock\ClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Constructs the stores bound to one task or batch name.
 *
 * A single factory keeps name binding at the orchestration boundary without exposing four
 * untyped closure dependencies.
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
	 * Constructs the active-run store for a task or batch name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Stable task or batch name.
	 *
	 * @return  RunStore
	 */
	public function run_store( string $name ): RunStore {
		return new RunStore( $name, $this->clock, $this->rows );
	}

	/**
	 * Constructs the latest-run pointer for a task or batch name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Stable task or batch name.
	 *
	 * @return  LatestRunPointer
	 */
	public function latest_run_pointer( string $name ): LatestRunPointer {
		return new LatestRunPointer( $name );
	}

	/**
	 * Constructs the run history for a task or batch name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Stable task or batch name.
	 *
	 * @return  RunHistory
	 */
	public function run_history( string $name ): RunHistory {
		return new RunHistory( $name );
	}

	/**
	 * Constructs the failed-run store for a task or batch name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Stable task or batch name.
	 *
	 * @return  FailedRunStore
	 */
	public function failed_run_store( string $name ): FailedRunStore {
		return new FailedRunStore( $name );
	}

	// endregion
}
