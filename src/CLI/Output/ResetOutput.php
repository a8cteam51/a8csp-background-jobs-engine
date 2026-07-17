<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\CLI\Output;

\defined( 'ABSPATH' ) || exit;

/**
 * Owns destructive reset confirmation and count reporting.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ResetOutput {
	// region METHODS

	/**
	 * Requires acknowledgement of the reset's irreversible scope.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  void
	 */
	public static function confirm( array $assoc_args ): void {
		\WP_CLI::confirm( 'This development reset permanently deletes every engine option row and pending backend action. In-flight work cannot be recovered. Continue?', $assoc_args );
	}

	/**
	 * Reports the two destructive categories and successful completion.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $option_rows    Deleted engine option rows.
	 * @param   int $pending_actions Unscheduled pending backend actions.
	 *
	 * @return  void
	 */
	public static function report( int $option_rows, int $pending_actions ): void {
		\WP_CLI::line( \sprintf( 'Option rows deleted: %d', $option_rows ) );
		\WP_CLI::line( \sprintf( 'Pending backend actions unscheduled: %d', $pending_actions ) );
		\WP_CLI::success( 'Background tasks development state reset.' );
	}

	/**
	 * Reports a fatal reset error.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $message Corrective error.
	 *
	 * @return  void
	 */
	public static function error( string $message ): void {
		\WP_CLI::error( $message );
	}

	// endregion
}
