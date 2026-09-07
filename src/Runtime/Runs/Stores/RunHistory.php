<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowWriteOutcome;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Persists bounded started and terminal run histories.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RunHistory {
	// region FIELDS AND CONSTANTS

	/**
	 * Default maximum number of runs retained in each history buffer.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int DEFAULT_SIZE = 30;

	/**
	 * Maximum exact-row compare-and-swap attempts before a contended write fails safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int UPDATE_ATTEMPTS = 5;

	/**
	 * Prefix for run-history option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string OPTION_PREFIX = 'a8csp_bgje_run_history_';

	/**
	 * Distinct single-flight identities are evicted least-recently-recorded past this count; without
	 * a bucket cap the by_hash map grows one entry per identity forever, which is the unbounded
	 * option-row growth this store exists to prevent.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_HASH_BUCKETS = 20;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity        $identity Complete scope-qualified job or chunked job identity.
	 * @param   OptionRows      $rows     Authoritative raw option-row I/O.
	 * @param   LoggerInterface $logger   Engine diagnostic sink.
	 */
	public function __construct(
		private Identity $identity,
		private OptionRows $rows,
		private LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Appends a run to the started histories.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id    Run identifier.
	 * @param   string $args_hash Stable single-flight identity.
	 *
	 * @throws  \LogicException When the current site differs from the bound site or serialization fails.
	 *
	 * @return  bool True when the entry is already present or confirmed persisted.
	 */
	#[\NoDiscard( 'a run-history persistence failure must be handled, not dropped' )]
	public function record_started( string $run_id, string $args_hash ): bool {
		return $this->record( $run_id, $args_hash );
	}

	/**
	 * Appends a run and its outcome to the terminal histories.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string    $run_id    Run identifier.
	 * @param   string    $args_hash Stable single-flight identity.
	 * @param   RunStatus $status    Terminal run status.
	 * @param   int       $at        Terminalization timestamp, as `FailedRunStore::record()` retains it.
	 *
	 * @throws  \InvalidArgumentException When the supplied status is not terminal.
	 * @throws  \LogicException           When the current site differs from the bound site or serialization fails.
	 *
	 * @return  bool True when the entry is already present or confirmed persisted.
	 */
	#[\NoDiscard( 'a run-history persistence failure must be handled, not dropped' )]
	public function record_terminal( string $run_id, string $args_hash, RunStatus $status, int $at ): bool {
		return $this->record( $run_id, $args_hash, $status, $at );
	}

	/**
	 * Returns validated run identifiers from the global started buffer, oldest first.
	 *
	 * @internal Read-only engine inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  list<string>|null Null when the authoritative row read fails.
	 */
	public function started_entries(): ?array {
		$history = $this->history_from_raw_row();

		return null === $history ? null : $history['started'];
	}

	/**
	 * Returns validated global terminal entries, oldest first.
	 *
	 * @internal Read-only engine inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded', at: int|null}>|null Null when the authoritative row read fails.
	 */
	public function terminal_entries(): ?array {
		$history = $this->history_from_raw_row();

		return null === $history ? null : $history['terminal'];
	}

	/**
	 * Returns the identity's last completed run, which no later terminal outcome evicts.
	 *
	 * The terminal buffers are capped and hold every outcome, so a run of failures long enough to
	 * fill one carries the last completion out of it. This slot is written beside those buffers and
	 * is never trimmed, so "when did this last succeed" stays answerable however many failures
	 * follow.
	 *
	 * @internal Read-only engine inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  array{run_id: string, at: int}|array{} Empty when the identity has recorded no completion.
	 *
	 * @phpstan-return array{run_id: string, at: int}|array{}|null
	 */
	public function last_completed(): ?array {
		$history = $this->history_from_raw_row();

		return null === $history ? null : $history['last_completed'];
	}

	// endregion

	// region HELPERS

	/**
	 * Appends a run to the global and per-hash buffers before capping every buffer.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string         $run_id    Run identifier.
	 * @param   string         $args_hash Stable single-flight identity.
	 * @param   RunStatus|null $status    Terminal run status, or null for a started entry.
	 * @param   int|null       $at        Terminalization timestamp, or null for a started entry.
	 *
	 * @throws  \InvalidArgumentException When the supplied status is not terminal.
	 * @throws  \LogicException           When the current site differs from the bound site or serialization fails.
	 *
	 * @return  bool True when the entry is already present or confirmed persisted.
	 */
	private function record( string $run_id, string $args_hash, ?RunStatus $status = null, ?int $at = null ): bool {
		if ( RunStatus::Running === $status ) {
			throw new \InvalidArgumentException( 'Run history records only terminal outcomes.' );
		}

		$rows = $this->rows;
		$key  = $this->option_name();
		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$selected = $rows->read( $key );
			if ( $selected->is_failure() ) {
				return false;
			}

			$expected_raw = $selected->value;
			$history      = self::history_from_option( null === $expected_raw ? null : RawOptionDecoder::decode( $expected_raw ) );
			// Per-hash entries preserve record() idempotency for replayed terminal writes after global-buffer eviction and remain query-internal.
			$hash_history = $history['by_hash'][ $args_hash ] ?? array(
				'started'  => array(),
				'terminal' => array(),
			);
			if ( null === $status ) {
				if (
					\in_array( $run_id, $history['started'], true )
					|| \in_array( $run_id, $hash_history['started'], true )
				) {
					return true;
				}

				$history['started'][]      = $run_id;
				$hash_history['started'][] = $run_id;
			} else {
				if (
					\in_array( $run_id, self::terminal_run_ids( $history['terminal'] ), true )
					|| \in_array( $run_id, self::terminal_run_ids( $hash_history['terminal'] ), true )
				) {
					return true;
				}

				$entry = array(
					'run_id' => $run_id,
					'status' => $status->value,
					'at'     => $at,
				);

				$history['terminal'][]      = $entry;
				$hash_history['terminal'][] = $entry;

				// Compared on terminalization time rather than run identifier: identifiers order by a
				// random suffix within one second, so they cannot rank two completions. `>=` keeps
				// recording order deciding a tie, while a replayed older completion cannot move the
				// slot backwards once a later one has landed.
				if ( RunStatus::Completed === $status && null !== $at && $at >= ( $history['last_completed']['at'] ?? \PHP_INT_MIN ) ) {
					$history['last_completed'] = array(
						'run_id' => $run_id,
						'at'     => $at,
					);
				}
			}

			// Re-inserting at the tail keeps the map ordered by recording recency for the bucket cap.
			unset( $history['by_hash'][ $args_hash ] );
			$history['by_hash'][ $args_hash ] = $hash_history;
			$history['by_hash']               = \array_slice( $history['by_hash'], -self::MAX_HASH_BUCKETS, null, true );

			$size                = $this->history_size();
			$history['started']  = self::tail( $history['started'], $size );
			$history['terminal'] = self::tail( $history['terminal'], $size );
			foreach ( $history['by_hash'] as $hash => $buffers ) {
				$history['by_hash'][ $hash ] = array(
					'started'  => self::tail( $buffers['started'], $size ),
					'terminal' => self::tail( $buffers['terminal'], $size ),
				);
			}

			$replacement_raw = self::serialize_history( $history );
			if ( null === $expected_raw ) {
				if ( RowWriteOutcome::Won === $rows->insert_if_absent( $key, $replacement_raw ) ) {
					return true;
				}

				continue;
			}

			$write = $rows->compare_and_swap( $key, $expected_raw, $replacement_raw );
			if ( RowWriteOutcome::Won === $write ) {
				return true;
			}
			if ( RowWriteOutcome::WriteFailed === $write ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Returns the positive history cap observed by the current write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	private function history_size(): int {
		/**
		 * Filters the number of runs retained in each history buffer.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   int $size Default per-buffer history cap.
		 */
		$size = \apply_filters( 'a8csp_bgje/history_size', self::DEFAULT_SIZE );
		if ( \is_int( $size ) && 0 < $size ) {
			return $size;
		}

		$this->logger->warning(
			'Run-history-size filter returned an invalid value; return a positive integer to override the default retention size.',
			array(
				'identity'      => (string) $this->identity,
				'returned_type' => \get_debug_type( $size ),
				'default_size'  => self::DEFAULT_SIZE,
			)
		);

		return self::DEFAULT_SIZE;
	}

	/**
	 * Returns the history option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function option_name(): string {
		return self::OPTION_PREFIX . (string) $this->identity;
	}

	/**
	 * Returns validated history from the authoritative raw row without constructing serialized classes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  array{
	 *     started: list<string>,
	 *     terminal: list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded', at: int|null}>,
	 *     by_hash: array<array-key, array{
	 *         started: list<string>,
	 *         terminal: list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded', at: int|null}>
	 *     }>,
	 *     last_completed: array{run_id: string, at: int}|array{}
	 * }|null Null when the authoritative row read fails.
	 */
	private function history_from_raw_row(): ?array {
		$selected = $this->rows->read( $this->option_name() );
		if ( $selected->is_failure() ) {
			return null;
		}

		$raw = $selected->value;

		return self::history_from_option( null === $raw ? null : RawOptionDecoder::decode( $raw ) );
	}

	/**
	 * Returns a history's exact WordPress option representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $history Complete history state.
	 *
	 * @throws  \LogicException When WordPress does not serialize the history to a string.
	 *
	 * @return  string
	 */
	private static function serialize_history( array $history ): string {
		$raw = \maybe_serialize( $history );
		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'WordPress must serialize run history to a string.' );
		}

		return $raw;
	}

	/**
	 * Normalizes valid history buffers from a persisted option.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $value Persisted option value.
	 *
	 * @return  array{
	 *     started: list<string>,
	 *     terminal: list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded', at: int|null}>,
	 *     by_hash: array<array-key, array{
	 *         started: list<string>,
	 *         terminal: list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded', at: int|null}>
	 *     }>,
	 *     last_completed: array{run_id: string, at: int}|array{}
	 * }
	 */
	private static function history_from_option( mixed $value ): array {
		if ( ! \is_array( $value ) ) {
			return array(
				'started'        => array(),
				'terminal'       => array(),
				'by_hash'        => array(),
				'last_completed' => array(),
			);
		}

		$by_hash = array();
		if ( \is_array( $value['by_hash'] ?? null ) ) {
			foreach ( $value['by_hash'] as $args_hash => $buffers ) {
				if ( ! \is_array( $buffers ) ) {
					continue;
				}

				$by_hash[ $args_hash ] = array(
					'started'  => self::string_list( $buffers['started'] ?? null ),
					'terminal' => self::terminal_list( $buffers['terminal'] ?? null ),
				);
			}
		}

		return array(
			'started'        => self::string_list( $value['started'] ?? null ),
			'terminal'       => self::terminal_list( $value['terminal'] ?? null ),
			'by_hash'        => $by_hash,
			'last_completed' => self::last_completed_from_option( $value['last_completed'] ?? null ),
		);
	}

	/**
	 * Returns a well-formed last-completed slot from a persisted option.
	 *
	 * A row written before the slot existed carries none, so the identity reports no completion
	 * until its next one lands rather than reporting a wrong one.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $value Persisted slot value.
	 *
	 * @return  array{run_id: string, at: int}|array{}
	 */
	private static function last_completed_from_option( mixed $value ): array {
		if (
			! \is_array( $value )
			|| ! \is_string( $value['run_id'] ?? null )
			|| null === RunIdentity::parse( $value['run_id'] )
			|| ! \is_int( $value['at'] ?? null )
		) {
			return array();
		}

		return array(
			'run_id' => $value['run_id'],
			'at'     => $value['at'],
		);
	}

	/**
	 * Returns only canonical run identifiers from a persisted list value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $value Persisted list value.
	 *
	 * @return  list<string>
	 */
	private static function string_list( mixed $value ): array {
		if ( ! \is_array( $value ) ) {
			return array();
		}

		return \array_values( \array_filter( $value, static fn ( mixed $entry ): bool => \is_string( $entry ) && null !== RunIdentity::parse( $entry ) ) );
	}

	/**
	 * Returns only well-formed terminal entries from a persisted list value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $value Persisted list value.
	 *
	 * @return  list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded', at: int|null}>
	 */
	private static function terminal_list( mixed $value ): array {
		if ( ! \is_array( $value ) ) {
			return array();
		}

		$terminals = array();
		foreach ( $value as $entry ) {
			if (
				! \is_array( $entry )
				|| ! \is_string( $entry['run_id'] ?? null )
				|| null === RunIdentity::parse( $entry['run_id'] )
				|| ! \is_string( $entry['status'] ?? null )
			) {
				continue;
			}

			$status = RunStatus::tryFrom( $entry['status'] );
			if ( null === $status || RunStatus::Running === $status ) {
				continue;
			}

			$at = $entry['at'] ?? null;

			$terminals[] = array(
				'run_id' => $entry['run_id'],
				'status' => $status->value,
				'at'     => \is_int( $at ) ? $at : null,
			);
		}

		return $terminals;
	}

	/**
	 * Returns the identifiers carried by terminal history entries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded', at: int|null}> $entries Terminal history entries.
	 *
	 * @return  list<string>
	 */
	private static function terminal_run_ids( array $entries ): array {
		return \array_column( $entries, 'run_id' );
	}

	/**
	 * Returns the newest entries within a positive cap.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @template T
	 *
	 * @param   list<T> $values Entries in oldest-first order.
	 * @param   int     $size   Positive maximum entry count.
	 *
	 * @return  list<T>
	 */
	private static function tail( array $values, int $size ): array {
		return \array_slice( $values, -$size );
	}

	// endregion
}
