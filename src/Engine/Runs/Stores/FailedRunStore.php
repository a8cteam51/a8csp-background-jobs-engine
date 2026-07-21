<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\RowDeleteOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\RowWriteOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\PortableArguments;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Persists the bounded failed-run data required by manual retry.
 *
 * The nested error class preserves `EngineError::$exception_class` exactly; null records that the
 * failure carries no throwable class. Client failure metadata is stored with every entry, and a
 * null failed chunk remains absent from serialized entries.
 *
 * @internal
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
	private const int ENTRY_LIMIT = 20;

	/**
	 * Prefix for failed-run option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string OPTION_PREFIX = 'a8csp_bgje_failed_runs_';

	/**
	 * Maximum compare-and-swap attempts before a contended update fails safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int UPDATE_ATTEMPTS = 5;

	/**
	 * Maximum exact-delete attempts after concurrent writes change the selected row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int PURGE_ATTEMPTS = 3;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string          $identity Complete owner-qualified job or chunked job identity.
	 * @param   OptionRows      $rows     Authoritative raw option-row I/O.
	 * @param   LoggerInterface $logger   Engine diagnostic sink.
	 */
	public function __construct(
		private string $identity,
		private OptionRows $rows,
		private LoggerInterface $logger,
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
	 * @param   RunFailure              $failure    Client terminal-failure value.
	 *
	 * @throws  \LogicException When the current site differs from the bound site or WordPress does
	 *                          not serialize the entries to a string.
	 *
	 * @return  bool True when the failed-run entry is already present or confirmed persisted.
	 */
	#[\NoDiscard( 'a failed-run persistence outcome must be handled, not dropped' )]
	public function record( string $run_id, int $failed_at, array $start_args, int $attempts, EngineError $error, RunFailure $failure ): bool {
		$key = $this->option_name();
		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$read = $this->rows->read( $key );
			if ( $read->is_failure() ) {
				return false;
			}

			$expected_raw = $read->value;
			$entries      = $this->entries_from_raw( $expected_raw )['entries'];
			// First write wins per run identifier; live and replayed writers construct identical entries.
			if ( \in_array( $run_id, \array_column( $entries, 'run_id' ), true ) ) {
				return true;
			}

			$error_detail = array(
				'class'   => $error->exception_class,
				'message' => $error->message,
				'stage'   => $failure->stage->value,
				'code'    => $failure->code->value,
			);
			if ( null !== $failure->failed_chunk ) {
				$error_detail['failed_chunk'] = $failure->failed_chunk;
			}

			$entries[]       = array(
				'run_id'     => $run_id,
				'failed_at'  => $failed_at,
				'start_args' => $start_args,
				'attempts'   => $attempts,
				'error'      => $error_detail,
			);
			$trimmed         = \array_slice( $entries, -self::ENTRY_LIMIT );
			$evicted         = \array_slice( $entries, 0, \count( $entries ) - \count( $trimmed ) );
			$replacement_raw = self::serialize_entries( $trimmed );

			if ( null === $expected_raw ) {
				if ( RowWriteOutcome::Won === $this->rows->insert_if_absent( $key, $replacement_raw ) ) {
					$this->log_eviction( $evicted );

					return true;
				}

				continue;
			}

			$write = $this->rows->compare_and_swap( $key, $expected_raw, $replacement_raw );
			if ( RowWriteOutcome::Won === $write ) {
				$this->log_eviction( $evicted );

				return true;
			}
			if ( RowWriteOutcome::WriteFailed === $write ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Returns retained failed runs, newest last.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  AbstractResult<list<array{
	 *     run_id: string,
	 *     failed_at: int,
	 *     start_args: array<array-key, mixed>,
	 *     attempts: int,
	 *     error: array{class: string|null, message: string, stage: string, code: string, failed_chunk?: array<array-key, mixed>}
	 * }>, EngineError>
	 */
	#[\NoDiscard( 'a failed-run read outcome must be handled, not dropped' )]
	public function all(): AbstractResult {
		$inspection = $this->inspect();
		if ( $inspection->is_failure() ) {
			return $inspection;
		}

		return new Success( $inspection->value['entries'] );
	}

	/**
	 * Returns retained failed runs with unreadable-entry metadata for operator inspection.
	 *
	 * @internal CLI inspection only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  AbstractResult<array{
	 *     entries: list<array{
	 *         run_id: string,
	 *         failed_at: int,
	 *         start_args: array<array-key, mixed>,
	 *         attempts: int,
	 *         error: array{class: string|null, message: string, stage: string, code: string, failed_chunk?: array<array-key, mixed>}
	 *     }>,
	 *     unreadable: int,
	 *     row_unreadable: bool
	 * }, EngineError>
	 */
	#[\NoDiscard( 'a failed-run inspection outcome must be handled, not dropped' )]
	public function inspect(): AbstractResult {
		$selected = $this->rows->read( $this->option_name() );
		if ( $selected->is_failure() ) {
			return $selected;
		}

		return new Success( $this->entries_from_raw( $selected->value ) );
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
	 * @throws  \LogicException When the current site differs from the bound site or WordPress does
	 *                          not serialize the entries to a string.
	 *
	 * @return  bool True when no retained entry has the requested run identifier.
	 */
	#[\NoDiscard( 'a failed-run removal outcome must be handled, not dropped' )]
	public function remove( string $run_id ): bool {
		$key = $this->option_name();
		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$read = $this->rows->read( $key );
			if ( $read->is_failure() ) {
				return false;
			}

			$expected_raw = $read->value;
			if ( null === $expected_raw ) {
				return true;
			}

			$entries   = $this->entries_from_raw( $expected_raw )['entries'];
			$remaining = \array_values( \array_filter( $entries, static fn ( array $entry ): bool => $run_id !== $entry['run_id'] ) );
			if ( $entries === $remaining ) {
				return true;
			}

			$trimmed         = \array_slice( $remaining, -self::ENTRY_LIMIT );
			$evicted         = \array_slice( $remaining, 0, \count( $remaining ) - \count( $trimmed ) );
			$replacement_raw = self::serialize_entries( $trimmed );
			$write           = $this->rows->compare_and_swap( $key, $expected_raw, $replacement_raw );
			if ( RowWriteOutcome::Won === $write ) {
				$this->log_eviction( $evicted );

				return true;
			}
			if ( RowWriteOutcome::WriteFailed === $write ) {
				return false;
			}

			$current = $this->rows->read( $key );
			if ( $current->is_failure() ) {
				return false;
			}

			$current_raw = $current->value;
			if ( null === $current_raw ) {
				return true;
			}
			if ( $expected_raw === $current_raw ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Removes the complete failed-run store and returns its retained-entry count.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
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
			$count   = \count( $this->entries_from_raw( $raw )['entries'] );
			$outcome = $this->rows->delete_if_value_matches( $key, $raw );
			if ( RowDeleteOutcome::Deleted === $outcome ) {
				return $count;
			}

			if ( RowDeleteOutcome::DeleteFailed === $outcome ) {
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
	 * Logs failed-run identifiers evicted by one confirmed trimmed persistence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<array{run_id: string}> $entries Evicted failed-run entries, oldest first.
	 *
	 * @return  void
	 */
	private function log_eviction( array $entries ): void {
		if ( array() === $entries ) {
			return;
		}

		$this->logger->warning(
			\sprintf( 'Failed-run retention for "{identity}" evicted oldest run IDs beyond the %d-entry limit: {evicted_run_ids}.', self::ENTRY_LIMIT ),
			array(
				'identity'        => $this->identity,
				'evicted_run_ids' => \implode( ', ', \array_column( $entries, 'run_id' ) ),
			)
		);
	}

	/**
	 * Returns failed-run entries in their exact WordPress option representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<array<array-key, mixed>> $entries Retained failed-run entries.
	 *
	 * @throws  \LogicException When WordPress does not serialize the entries to a string.
	 *
	 * @return  string
	 */
	private static function serialize_entries( array $entries ): string {
		$raw = \maybe_serialize( $entries );
		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'WordPress must serialize failed-run entries to a string.' );
		}

		return $raw;
	}

	/**
	 * Returns the failed-run option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function option_name(): string {
		return self::OPTION_PREFIX . $this->identity;
	}

	/**
	 * Normalizes valid failed-run entries and unreadable metadata from exact persisted bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $raw Exact persisted option bytes, or null when the row is absent.
	 *
	 * @return  array{
	 *     entries: list<array{
	 *         run_id: string,
	 *         failed_at: int,
	 *         start_args: array<array-key, mixed>,
	 *         attempts: int,
	 *         error: array{class: string|null, message: string, stage: string, code: string, failed_chunk?: array<array-key, mixed>}
	 *     }>,
	 *     unreadable: int,
	 *     row_unreadable: bool
	 * }
	 */
	private function entries_from_raw( ?string $raw ): array {
		if ( null === $raw ) {
			return array(
				'entries'        => array(),
				'unreadable'     => 0,
				'row_unreadable' => false,
			);
		}

		$value = RawOptionDecoder::decode( $raw );
		if ( ! \is_array( $value ) ) {
			$this->warn_unreadable_row();

			return array(
				'entries'        => array(),
				'unreadable'     => 0,
				'row_unreadable' => true,
			);
		}

		$entries    = array();
		$unreadable = 0;
		foreach ( $value as $raw_entry ) {
			$entry = self::entry_from_option( $raw_entry );
			if ( null === $entry ) {
				++$unreadable;
				continue;
			}

			$entries[] = $entry;
		}

		if ( 0 < $unreadable ) {
			$this->warn_unreadable_row( $unreadable );
		}

		return array(
			'entries'        => $entries,
			'unreadable'     => $unreadable,
			'row_unreadable' => false,
		);
	}

	/**
	 * Reports one unreadable failed-run row without allowing log-hook re-entry to recurse.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int|null $unreadable_count Rejected child-entry count, or null when the whole row is unreadable.
	 *
	 * @return  void
	 */
	private function warn_unreadable_row( ?int $unreadable_count = null ): void {
		// Hook listeners can construct a fresh store for this row, so the guard spans instances.
		static $warnings_in_flight;
		if ( ! \is_array( $warnings_in_flight ) ) {
			$warnings_in_flight = array();
		}

		$option_name = $this->option_name();
		if ( isset( $warnings_in_flight[ $option_name ] ) ) {
			return;
		}

		$warnings_in_flight[ $option_name ] = true;
		try {
			if ( null === $unreadable_count ) {
				$this->logger->warning(
					'Failed-run retention for "{identity}" is unreadable at option row "{option_name}"; repair or purge the row.',
					array(
						'identity'    => $this->identity,
						'option_name' => $option_name,
					)
				);
				return;
			}

			$this->logger->warning(
				\sprintf(
					'Failed-run retention for "{identity}" contains {unreadable_count} unreadable %1$s in option row "{option_name}"; this read omits %2$s, so repair or purge the row.',
					1 === $unreadable_count ? 'entry' : 'entries',
					1 === $unreadable_count ? 'it' : 'them'
				),
				array(
					'identity'         => $this->identity,
					'option_name'      => $option_name,
					'unreadable_count' => $unreadable_count,
				)
			);
		} finally {
			unset( $warnings_in_flight[ $option_name ] );
		}
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
	 *     error: array{class: string|null, message: string, stage: string, code: string, failed_chunk?: array<array-key, mixed>}
	 * }|null
	 */
	private static function entry_from_option( mixed $value ): ?array {
		if (
			! \is_array( $value )
			|| ! \is_string( $value['run_id'] ?? null )
			|| ! \is_int( $value['failed_at'] ?? null )
			|| ! \is_array( $value['start_args'] ?? null )
			|| ! PortableArguments::is_valid( $value['start_args'] )
			|| ! \is_int( $value['attempts'] ?? null )
			|| ! \is_array( $value['error'] ?? null )
			|| ! \array_key_exists( 'class', $value['error'] )
			|| ( null !== $value['error']['class'] && ! \is_string( $value['error']['class'] ) )
			|| ! \is_string( $value['error']['message'] ?? null )
		) {
			return null;
		}
		$error            = $value['error'];
		$has_failed_chunk = \array_key_exists( 'failed_chunk', $error );
		if (
			! \in_array( \count( $error ), array( 4, 5 ), true )
			|| ! \is_string( $error['stage'] ?? null )
			|| null === RunFailureStage::tryFrom( $error['stage'] )
			|| ! \is_string( $error['code'] ?? null )
			|| null === ErrorCode::tryFrom( $error['code'] )
		) {
			return null;
		}
		$failed_chunk = null;
		if ( $has_failed_chunk ) {
			$failed_chunk = $error['failed_chunk'] ?? null;
			if ( ! \is_array( $failed_chunk ) || ! PortableArguments::is_valid( $failed_chunk ) ) {
				return null;
			}
		}

		$error_detail = array(
			'class'   => $error['class'],
			'message' => $error['message'],
			'stage'   => $error['stage'],
			'code'    => $error['code'],
		);
		if ( $has_failed_chunk ) {
			$error_detail['failed_chunk'] = $failed_chunk;
		}

		return array(
			'run_id'     => $value['run_id'],
			'failed_at'  => $value['failed_at'],
			'start_args' => $value['start_args'],
			'attempts'   => $value['attempts'],
			'error'      => $error_detail,
		);
	}

	// endregion
}
