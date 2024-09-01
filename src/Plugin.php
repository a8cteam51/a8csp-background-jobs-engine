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
		\add_action( 'a8csp/start_background_task', array( $this, 'start_background_task' ), 10, 2 );
		\add_action( 'a8csp/continue_background_task', array( $this, 'continue_background_task' ), 10, 2 );
		\add_action( 'a8csp/run_background_task', array( $this, 'run_background_task' ), 10, 3 );
		\add_action( 'a8csp/cleanup_background_task', array( $this, 'cleanup_background_task' ), 10, 2 );
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
	 * @param   array  $args      The arguments to start the task.
	 *
	 * @return  void
	 */
	public function start_background_task( string $task_name, array $args ): void {
		$this->stop_background_task( $task_name, $args );

		$run_id = $this->generate_task_run_id( $task_name, $args );
		$this->generate_task_run_queue( $task_name, $run_id, $args );
		$this->continue_background_task( $task_name, $run_id );
	}

	/**
	 * Continues a previously-started run of a background task.
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
		$next_args = a8csp_bgt_dequeue_from_task_queue( $task_name, $run_id );
		if ( \is_null( $next_args ) ) {
			$this->cleanup_background_task( $task_name, $run_id );
		} else {
			$this->run_background_task( $task_name, $run_id, $next_args );
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
	 * @param   array  $args      The arguments for the chunk of work.
	 *
	 * @return  void
	 */
	public function run_background_task( string $task_name, string $run_id, array $args ): void {
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
	}

	/**
	 * Stops a running background task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $task_name  The name of the task.
	 * @param   array  $start_args The arguments used to start the task.
	 *
	 * @return  void
	 */
	public function stop_background_task( string $task_name, array $start_args ): void {
	}

	// endregion

	// region HELPERS

	/**
	 * Generates a unique ID for a task run.
	 *
	 * @param   string $task_name  The name of the task.
	 * @param   array  $start_args The arguments used to start the task.
	 *
	 * @return  string
	 */
	protected function generate_task_run_id( string $task_name, array $start_args ): string {
		$run_id    = \wp_generate_uuid4(); // Technically not unique-proof, but should be good enough for our purposes.
		$args_hash = a8csp_bgt_hash_task_args( $start_args );

		\update_option( "a8csp_bg-task_{$task_name}_latest-run-id", $run_id, false );
		\update_option( "a8csp_bg-task_{$task_name}_latest-run-id_$args_hash", $run_id, false );
		\update_option( "a8csp_bg-task_{$task_name}_run-{$run_id}_start-args", $start_args, false );

		$this->store_task_run_id( $task_name, $run_id );
		return $run_id;
	}

	/**
	 * Generates the queue of work for a task run.
	 *
	 * @param   string $task_name The name of the task.
	 * @param   string $run_id    The ID of the task run.
	 * @param   array  $args      The arguments used to start the task.
	 *
	 * @return  void
	 */
	protected function generate_task_run_queue( string $task_name, string $run_id, array $args ): void {
		$queue = \apply_filters( "a8csp/background_task_queue/$task_name", array(), $args, $run_id, $task_name );
		a8csp_bgt_set_task_run_queue( $task_name, $run_id, $queue );
	}

	/**
	 * Returns the arguments used to start a given task run.
	 *
	 * @param   string $task_name The name of the task.
	 * @param   string $run_id    The ID of the task run.
	 *
	 * @return  array|null
	 */
	protected function get_task_run_start_args( string $task_name, string $run_id ): ?array {
		return \get_option( "a8csp_bg-task_{$task_name}_run-{$run_id}_start-args", null );
	}

	/**
	 * Stores the run ID for a task.
	 *
	 * @param   string $task_name The name of the task.
	 * @param   string $run_id    The ID of the task run.
	 *
	 * @return  void
	 */
	protected function store_task_run_id( string $task_name, string $run_id ): void {
		$start_args = $this->get_task_run_start_args( $task_name, $run_id );
		if ( \is_null( $start_args ) ) {
			throw new \LogicException( \wp_kses_post( "Missing start args for run `$run_id` of task `$task_name`." ) );
		}

		$run_ids_to_keep = \defined( 'A8CSP_BGT_MAX_RUN_IDS' ) ? A8CSP_BGT_MAX_RUN_IDS : 30;
		$run_ids_to_keep = \absint( $run_ids_to_keep );

		$task_run_ids_all   = a8csp_bgt_get_task_run_ids( $task_name );
		$task_run_ids_all[] = $run_id;
		$task_run_ids_all   = \array_slice( $task_run_ids_all, -1 * $run_ids_to_keep );

		$task_run_ids_args   = a8csp_bgt_get_task_run_ids( $task_name, $start_args );
		$task_run_ids_args[] = $run_id;
		$task_run_ids_args   = \array_slice( $task_run_ids_args, -1 * $run_ids_to_keep );

		$args_hash = a8csp_bgt_hash_task_args( $start_args );
		\update_option( "a8csp_bg-task_{$task_name}_run-ids", $task_run_ids_all, false );
		\update_option( "a8csp_bg-task_{$task_name}_run-ids_$args_hash", $task_run_ids_args, false );
	}

	// endregion
}
