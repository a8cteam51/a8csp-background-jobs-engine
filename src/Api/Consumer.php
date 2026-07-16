<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\Batches;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\Runs;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\Tasks;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound entry point for supported background-work operations.
 *
 * Deterministic contract violations detectable before side effects, including invalid or reserved
 * identities, priority or delay bounds, non-portable arguments, and cross-kind registration, throw
 * {@see \InvalidArgumentException}. A valid command refused by current registration, lock, backend,
 * storage, or run state returns `Failure<ApiError>`.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Consumer {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string    $owner     Consumer plugin owner.
	 * @param   Tasks     $tasks     Owner-bound task facade.
	 * @param   Batches   $batches   Owner-bound batch facade.
	 * @param   Schedules $schedules Owner-bound schedule facade.
	 * @param   Runs      $runs      Owner-bound run facade.
	 *
	 * @throws  \InvalidArgumentException When the owner violates the consumer-owner contract.
	 */
	public function __construct(
		private string $owner,
		private Tasks $tasks,
		private Batches $batches,
		private Schedules $schedules,
		private Runs $runs,
	) {
		WorkIdentity::validate_owner( $this->owner );
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the owner-bound task facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Tasks
	 */
	public function tasks(): Tasks {
		return $this->tasks;
	}

	/**
	 * Returns the owner-bound batch facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Batches
	 */
	public function batches(): Batches {
		return $this->batches;
	}

	/**
	 * Returns the owner-bound schedule facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Schedules
	 */
	public function schedules(): Schedules {
		return $this->schedules;
	}

	/**
	 * Returns the owner-bound run facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Runs
	 */
	public function runs(): Runs {
		return $this->runs;
	}

	// endregion
}
