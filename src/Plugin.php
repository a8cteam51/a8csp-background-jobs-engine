<?php declare( strict_types = 1 );

namespace A8C\SpecialProjects\BackgroundTasks;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
class Plugin {
	// region MAGIC METHODS

	/**
	 * Plugin constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	protected function __construct() {
		/* Empty on purpose. */
	}

	/**
	 * Prevent cloning.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function __clone() {
		/* Empty on purpose. */
	}

	/**
	 * Prevent unserializing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function __wakeup() {
		/* Empty on purpose. */
	}

	// endregion

	// region METHODS

	/**
	 * Returns the singleton instance of the plugin.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Plugin
	 */
	public static function get_instance(): self {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Initializes the plugin components.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function initialize(): void {
		\add_action( 'a8csp/background_tasks/start', array( $this, 'start_background_task' ), 10, 2 );
		\add_action( 'a8csp/background_tasks/continue', array( $this, 'continue_background_task' ), 10, 2 );
		\add_action( 'a8csp/background_tasks/process', array( $this, 'process_background_task' ), 10, 3 );
		\add_action( 'a8csp/background_tasks/cleanup', array( $this, 'cleanup_background_task' ), 10, 2 );
	}

	// endregion

	// region HOOKS

	/**
	 * Starts a new run of a background task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $task_name The name of the task.
	 * @param   array  $run_args  The arguments of the task run.
	 *
	 * @return  void
	 */
	public function start_background_task( string $task_name, array $run_args ): void {
		$this->stop_background_task( $task_name, $run_args ); // Multiple parallel runs of the same task with the same arguments are not allowed.

		$run_id = $this->generate_task_run_id( $task_name, $run_args );
		$this->generate_task_run_queue( $task_name, $run_id, $run_args );
		$this->continue_background_task( $task_name, $run_id );
	}

	/**
	 * Determines the next step of a running background task and continues it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $task_name The name of the task.
	 * @param   string $run_id    The ID of the task run.
	 *
	 * @return  void
	 */
	public function continue_background_task( string $task_name, string $run_id ): void {
		$chunk = a8csp_bgt_dequeue_from_task_queue( $task_name, $run_id );
		if ( \is_null( $chunk ) ) {
			$this->cleanup_background_task( $task_name, $run_id );
		} else {
			$this->process_background_task( $task_name, $run_id, $chunk );
		}
	}

	/**
	 * Performs a unit of work of a running background task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $task_name The name of the task.
	 * @param   string $run_id    The ID of the task run.
	 * @param   array  $chunk     The arguments for the chunk of work.
	 *
	 * @return  void
	 */
	public function process_background_task( string $task_name, string $run_id, array $chunk ): void {
		$this->validate_task_run_event( $task_name, $run_id );
	}

	/**
	 * Cleans up a finished background task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $task_name The name of the task.
	 * @param   string $run_id    The ID of the task run.
	 *
	 * @return  void
	 */
	public function cleanup_background_task( string $task_name, string $run_id ): void {
		$this->validate_task_run_event( $task_name, $run_id );

		$task = $this->get_task( $task_name );
		$task::cleanup( $run_id );

		\do_action( "a8csp/background_tasks/cleanup/$task_name", $run_id, $task_name );
		\delete_option( "a8csp_bg-task_{$task_name}_run-{$run_id}_retries" );

		$this->store_task_completed_run_id( $task_name, $run_id );
	}

	/**
	 * Stops a running background task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $task_name The name of the task.
	 * @param   array  $run_args  The arguments of the task run.
	 *
	 * @return  void
	 */
	public function stop_background_task( string $task_name, array $run_args ): void {
		$scheduler = $this->get_task_scheduler( $task_name );
		$scheduler::unschedule_task_runs( $task_name, $run_args );

		$latest_run_id = a8csp_bgt_get_task_latest_run_id( $task_name, $run_args );
		if ( ! \is_null( $latest_run_id ) ) { // Null on the very first run.
			$scheduler::unschedule_task_run_events( $task_name, $latest_run_id );

			\delete_option( "a8csp_bg-task_{$task_name}_run-{$latest_run_id}_retries" );
			a8csp_bgt_clear_task_run_queue( $task_name, $latest_run_id );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Retrieves a task by name.
	 *
	 * @param   string $task_name The name of the task.
	 *
	 * @throws  \RuntimeException If the task is not found.
	 *
	 * @return  \A8CSP_Abstract_Background_Task
	 */
	protected function get_task( string $task_name ): \A8CSP_Abstract_Background_Task {
		return a8csp_bgt_get_task( $task_name ) ?? throw new \RuntimeException( \wp_kses_post( "Background task `$task_name` not found." ) );
	}

	/**
	 * Retrieves the scheduler for a task by name.
	 *
	 * @param   string $task_name The name of the task.
	 *
	 * @return  \A8CSP_Task_Scheduler_Adapter_Interface
	 */
	protected function get_task_scheduler( string $task_name ): \A8CSP_Task_Scheduler_Adapter_Interface {
		return $this->get_task( $task_name )::get_scheduler();
	}

	/**
	 * Generates a unique ID for a task run.
	 *
	 * @param   string $task_name The name of the task.
	 * @param   array  $run_args  The arguments of the task run.
	 *
	 * @return  string
	 */
	protected function generate_task_run_id( string $task_name, array $run_args ): string {
		$run_id    = \wp_generate_uuid4(); // Technically not unique-proof, but should be good enough for our purposes.
		$args_hash = a8csp_bgt_hash_task_args( $run_args );

		\update_option( "a8csp_bg-task_{$task_name}_latest-run-id", $run_id, false );
		\update_option( "a8csp_bg-task_{$task_name}_latest-run-id_$args_hash", $run_id, false );
		\update_option( "a8csp_bg-task_{$task_name}_run-{$run_id}_args", $run_args, false );

		$this->store_task_started_run_id( $task_name, $run_id, $run_args );
		return $run_id;
	}

	/**
	 * Stores the ID of a started task run.
	 *
	 * @param   string $task_name The name of the task.
	 * @param   string $run_id    The ID of the task run.
	 * @param   array  $run_args  The arguments of the task run.
	 *
	 * @return  void
	 */
	protected function store_task_started_run_id( string $task_name, string $run_id, array $run_args ): void {
		$run_ids_to_keep = a8csp_bgt_task_run_ids_to_keep();

		$run_ids_all  = a8csp_bgt_get_task_run_ids( $task_name );
		$run_ids_args = a8csp_bgt_get_task_run_ids( $task_name, $run_args );

		$run_ids_all[] = $run_id;
		$run_ids_all   = \array_slice( $run_ids_all, -1 * $run_ids_to_keep );

		$run_ids_args[] = $run_id;
		$run_ids_args   = \array_slice( $run_ids_args, -1 * $run_ids_to_keep );

		$args_hash = a8csp_bgt_hash_task_args( $run_args );
		\update_option( "a8csp_bg-task_{$task_name}_run-ids", $run_ids_all, false );
		\update_option( "a8csp_bg-task_{$task_name}_run-ids_$args_hash", $run_ids_args, false );
	}

	/**
	 * Generates the queue of chunks to process for a task run.
	 *
	 * @param   string $task_name The name of the task.
	 * @param   string $run_id    The ID of the task run.
	 * @param   array  $run_args  The arguments of the task run.
	 *
	 * @return  void
	 */
	protected function generate_task_run_queue( string $task_name, string $run_id, array $run_args ): void {
		$queue = $this->get_task( $task_name )::generate_queue( $run_args );

		$queue = \apply_filters( "a8csp/background_tasks/queue/$task_name", $queue, $run_args, $task_name, $run_id );
		$queue = \apply_filters( 'a8csp/background_tasks/queue', $queue, $run_args, $task_name, $run_id );

		a8csp_bgt_set_task_run_queue( $task_name, $run_id, $queue );
	}

	/**
	 * Ensures that we don't handle stale events.
	 *
	 * @param   string $task_name The name of the task.
	 * @param   string $run_id    The ID of the task run.
	 *
	 * @throws  \RuntimeException If the event is stale.
	 *
	 * @return  void
	 */
	protected function validate_task_run_event( string $task_name, string $run_id ): void {
		$run_args = a8csp_bgt_get_task_run_args( $task_name, $run_id );
		if ( a8csp_bgt_get_task_latest_run_id( $task_name, $run_args ) !== $run_id ) {
			throw new \RuntimeException( 'Skipping stale event.' );
		}
	}

	/**
	 * Stores the ID of a completed task run.
	 *
	 * @param   string $task_name The name of the task.
	 * @param   string $run_id    The ID of the task run.
	 *
	 * @return  void
	 */
	protected function store_task_completed_run_id( string $task_name, string $run_id ): void {
		$run_ids_to_keep = a8csp_bgt_task_run_ids_to_keep();

		$run_ids_all   = a8csp_bgt_get_task_completed_run_ids( $task_name );
		$run_ids_all[] = $run_id;
		$run_ids_all   = \array_slice( $run_ids_all, -1 * $run_ids_to_keep );

		\update_option( "a8csp_bg-task_{$task_name}_completed-run-ids", $run_ids_all, false );

		$run_args = a8csp_bgt_get_task_run_args( $task_name, $run_id );
		if ( \is_null( $run_args ) ) {
			a8csp_bgt_log_task_error( $task_name, $run_id, 'Task run args not found.' );
			return;
		}

		$run_ids_args   = a8csp_bgt_get_task_completed_run_ids( $task_name, $run_args );
		$run_ids_args[] = $run_id;
		$run_ids_args   = \array_slice( $run_ids_args, -1 * $run_ids_to_keep );

		$args_hash = a8csp_bgt_hash_task_args( $run_args );
		\update_option( "a8csp_bg-task_{$task_name}_completed-run-ids_$args_hash", $run_ids_args, false );
	}

	// endregion
}
