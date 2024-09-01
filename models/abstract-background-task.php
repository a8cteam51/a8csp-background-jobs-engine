<?php declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * The base class for all background tasks.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class A8CSP_Abstract_Background_Task {
	// region MAGIC METHODS

	/**
	 * A8CSP_Abstract_Background_Task constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	protected function __construct() {
		\add_filter(
			'a8csp/background_tasks',
			static function ( array $tasks ): array {
				$tasks[ static::get_name() ] = static::get_instance();
				return $tasks;
			}
		);
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
	 * Returns the singleton instance of the task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  static
	 */
	public static function get_instance(): static {
		static $tasks = array();

		if ( ! isset( $tasks[ static::class ] ) ) {
			$tasks[ static::class ] = new static();
		}

		return $tasks[ static::class ];
	}

	/**
	 * Returns the name of the task. Must be unique across all registered tasks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	abstract public static function get_name(): string;

	// endregion

	// region LIFECYCLE

	/**
	 * Generates the queue of chunks to process.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array $run_args The arguments of the task run.
	 *
	 * @return  array[]
	 */
	public static function generate_queue( array $run_args ): array {
		return array( $run_args ); // Default to a single chunk matching the run args.
	}

	/**
	 * Processes a chunk of the background task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array  $chunk  The chunk to process.
	 * @param   string $run_id The ID of the current run.
	 *
	 * @throws  \RuntimeException If something goes wrong and the chunk should be retried.
	 *
	 * @return  void
	 */
	abstract public static function process( array $chunk, string $run_id ): void;

	/**
	 * Cleans up the background task.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id The ID of the current run.
	 *
	 * @return  void
	 */
	public static function cleanup( string $run_id ): void {
		// Do nothing by default. Override in child classes.
	}

	// endregion
}
