<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;

\defined( 'ABSPATH' ) || exit;

/**
 * Persists the bounded failed-run data required by manual retry.
 *
 * The nested error class preserves `EngineError::$exception_class` exactly; null records that the
 * failure carries no throwable class.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class FailedRunStore {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum number of failed runs retained for manual retry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const ENTRY_LIMIT = 20;

	/**
	 * Prefix for failed-run option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const OPTION_PREFIX = 'a8csp_bgte_failed_';

	/**
	 * Maximum exact-delete attempts after concurrent writes change the selected row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const PURGE_ATTEMPTS = 3;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string     $name Stable task or batch name.
	 * @param   OptionRows $rows Authoritative raw option-row I/O.
	 */
	public function __construct(
		private string $name,
		private OptionRows $rows,
	) {}

	// endregion

	// region METHODS

	/**
	 * Appends the data required to retry a failed run manually.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   int                     $failed_at  Failure timestamp.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   int                     $attempts   Attempts consumed before failure.
	 * @param   EngineError             $error      Persisted failure detail.
	 *
	 * @return  void
	 */
	public function record( string $run_id, int $failed_at, array $start_args, int $attempts, EngineError $error ): void {
		$read = $this->all();
		if ( $read->is_failure() ) {
			return;
		}

		$entries   = $read->value;
		$entries[] = array(
			'run_id'     => $run_id,
			'failed_at'  => $failed_at,
			'start_args' => $start_args,
			'attempts'   => $attempts,
			'error'      => array(
				'class'   => $error->exception_class,
				'message' => $error->message,
			),
		);

		\update_option( $this->option_name(), \array_slice( $entries, -self::ENTRY_LIMIT ), false );
	}

	/**
	 * Returns retained failed runs, newest last.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<list<array{
	 *     run_id: string,
	 *     failed_at: int,
	 *     start_args: array<array-key, mixed>,
	 *     attempts: int,
	 *     error: array{class: string|null, message: string}
	 * }>, EngineError>
	 */
	#[\NoDiscard( 'a failed-run read outcome must be handled, not dropped' )]
	public function all(): AbstractResult {
		$selected = $this->rows->read( $this->option_name() );
		if ( $selected->is_failure() ) {
			return $selected;
		}

		$raw = $selected->value;

		return new Success( self::entries_from_option( null === $raw ? null : RawOptionDecoder::decode( $raw ) ) );
	}

	/**
	 * Removes every entry for a run identifier.
	 *
	 * An absent identifier performs no option write because the persisted value is unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  void
	 */
	public function remove( string $run_id ): void {
		$read = $this->all();
		if ( $read->is_failure() ) {
			return;
		}

		$entries   = $read->value;
		$remaining = \array_values(
			\array_filter(
				$entries,
				static fn ( array $entry ): bool => $run_id !== $entry['run_id']
			)
		);

		if ( $entries === $remaining ) {
			return;
		}

		\update_option( $this->option_name(), \array_slice( $remaining, -self::ENTRY_LIMIT ), false );
	}

	/**
	 * Removes the complete failed-run store and returns its retained-entry count.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int|null Deleted valid-entry count, or null when the authoritative operation fails.
	 */
	public function purge(): ?int {
		$key      = $this->option_name();
		$selected = $this->rows->read( $key );
		if ( $selected->is_failure() ) {
			return null;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return 0;
		}

		for ( $attempt = 0; $attempt < self::PURGE_ATTEMPTS; ++$attempt ) {
			$count = \count( self::entries_from_option( RawOptionDecoder::decode( $raw ) ) );
			if ( $this->rows->delete( $key, $raw ) ) {
				return $count;
			}

			if ( $this->rows->last_delete_failed() ) {
				return null;
			}

			$next = $this->rows->read( $key );
			if ( $next->is_failure() ) {
				return null;
			}

			$next_raw = $next->value;
			if ( null === $next_raw ) {
				return 0;
			}

			// An unchanged row rules out comparison loss, so the exact delete itself failed.
			if ( $raw === $next_raw ) {
				return null;
			}

			$raw = $next_raw;
		}

		return null;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the failed-run option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function option_name(): string {
		return self::OPTION_PREFIX . $this->name;
	}

	/**
	 * Normalizes valid failed-run entries from a persisted option.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $value Persisted option value.
	 *
	 * @return  list<array{
	 *     run_id: string,
	 *     failed_at: int,
	 *     start_args: array<array-key, mixed>,
	 *     attempts: int,
	 *     error: array{class: string|null, message: string}
	 * }>
	 */
	private static function entries_from_option( mixed $value ): array {
		if ( ! \is_array( $value ) ) {
			return array();
		}

		$entries = array();
		foreach ( $value as $raw_entry ) {
			$entry = self::entry_from_option( $raw_entry );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Normalizes one complete failed-run entry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $value Persisted entry value.
	 *
	 * @return  array{
	 *     run_id: string,
	 *     failed_at: int,
	 *     start_args: array<array-key, mixed>,
	 *     attempts: int,
	 *     error: array{class: string|null, message: string}
	 * }|null
	 */
	private static function entry_from_option( mixed $value ): ?array {
		if (
			! \is_array( $value )
			|| ! \is_string( $value['run_id'] ?? null )
			|| ! \is_int( $value['failed_at'] ?? null )
			|| ! \is_array( $value['start_args'] ?? null )
			|| ! \is_int( $value['attempts'] ?? null )
			|| ! \is_array( $value['error'] ?? null )
			|| ! \array_key_exists( 'class', $value['error'] )
			|| ( null !== $value['error']['class'] && ! \is_string( $value['error']['class'] ) )
			|| ! \is_string( $value['error']['message'] ?? null )
		) {
			return null;
		}

		return array(
			'run_id'     => $value['run_id'],
			'failed_at'  => $value['failed_at'],
			'start_args' => $value['start_args'],
			'attempts'   => $value['attempts'],
			'error'      => array(
				'class'   => $value['error']['class'],
				'message' => $value['error']['message'],
			),
		);
	}

	// endregion
}
