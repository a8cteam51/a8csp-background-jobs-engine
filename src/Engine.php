<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Consumer facade for task, schedule, and batch background work.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Engine {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Tasks     $tasks     Task API.
	 * @param   Schedules $schedules Schedule API.
	 * @param   Batches   $batches   Batch API.
	 */
	public function __construct(
		private Tasks $tasks,
		private Schedules $schedules,
		private Batches $batches,
	) {}

	// endregion

	// region GETTERS

	/**
	 * Returns the task API.
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
	 * Returns the schedule API.
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
	 * Returns the batch API.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Batches
	 */
	public function batches(): Batches {
		return $this->batches;
	}

	// endregion
}
