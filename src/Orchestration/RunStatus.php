<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

\defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle state persisted for a task or batch run.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum RunStatus: string {
	// region FIELDS AND CONSTANTS

	case Running    = 'running';
	case Completed  = 'completed';
	case Failed     = 'failed';
	case Stopped    = 'stopped';
	case Superseded = 'superseded';

	// endregion

	// region METHODS

	/**
	 * Returns whether the run accepts no further state transitions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public function is_terminal(): bool {
		return self::Running !== $this;
	}

	// endregion
}
