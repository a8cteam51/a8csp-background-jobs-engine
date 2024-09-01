<?php declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * A background task that calls a given callback.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
class A8CSP_Call_User_Func_Task extends A8CSP_Abstract_Background_Task {
	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public static function get_name(): string {
		return 'a8csp_call_generic_callback';
	}

	/**
	 * Registers a task run to call the given callback.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   callable     $callback  The callback to call.
	 * @param   array        $args      The arguments to pass to the callback.
	 * @param   integer|null $timestamp The timestamp at which to run the task.
	 *
	 * @return  void
	 */
	public static function register( callable $callback, array $args = array(), ?int $timestamp = null ): void {
		$run_args  = compact( 'callback', 'args' );
		$scheduler = static::get_scheduler();

		if ( \is_null( $timestamp ) ) {
			$scheduler::enqueue_task_run( static::get_name(), $run_args );
		} else {
			$scheduler::schedule_task_run( static::get_name(), $timestamp, $run_args );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public static function process( array $chunk, string $run_id ): void {
		$callback = $chunk['callback'] ?? ( static fn() => null );
		$args     = $chunk['args'] ?? array();

		\call_user_func_array( $callback, $args );
	}
}
