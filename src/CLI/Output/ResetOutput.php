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
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Closure(string, array<string, mixed>): void $confirm Confirmation sink.
	 * @param   \Closure(string): void                       $line    Plain-line sink.
	 * @param   \Closure(string): void                       $success Success sink.
	 * @param   \Closure(string): void                       $error   Fatal-error sink.
	 */
	public function __construct(
		private \Closure $confirm,
		private \Closure $line,
		private \Closure $success,
		private \Closure $error,
	) {}

	// endregion

	// region METHODS

	/**
	 * Builds the WP-CLI-backed output boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function runtime(): self {
		return new self(
			static function ( string $question, array $assoc_args ): void {
				\WP_CLI::confirm( $question, $assoc_args );
			},
			static function ( string $message ): void {
				\WP_CLI::line( $message );
			},
			static function ( string $message ): void {
				\WP_CLI::success( $message );
			},
			static function ( string $message ): void {
				\WP_CLI::error( $message );
			}
		);
	}

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
	public function confirm( array $assoc_args ): void {
		( $this->confirm )( 'This development reset permanently deletes every engine option row and pending backend action. In-flight work cannot be recovered. Continue?', $assoc_args );
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
	public function report( int $option_rows, int $pending_actions ): void {
		( $this->line )( \sprintf( 'Option rows deleted: %d', $option_rows ) );
		( $this->line )( \sprintf( 'Pending backend actions unscheduled: %d', $pending_actions ) );
		( $this->success )( 'Background tasks development state reset.' );
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
	public function error( string $message ): void {
		( $this->error )( $message );
	}

	// endregion
}
