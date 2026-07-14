<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;

\defined( 'ABSPATH' ) || exit;

/**
 * Persists bounded started and terminal run histories.
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
	private const DEFAULT_SIZE = 30;

	/**
	 * Prefix for run-history option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const OPTION_PREFIX = 'a8csp_bgte_history_';

	/**
	 * Distinct argument identities are evicted least-recently-recorded past this count; without
	 * a bucket cap the by_hash map grows one entry per identity forever, which is the unbounded
	 * option-row growth this store exists to prevent.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const MAX_HASH_BUCKETS = 20;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string          $name Stable task or batch name.
	 * @param   OptionRows|null $rows Authoritative raw option-row I/O, when inspection is required.
	 */
	public function __construct(
		private string $name,
		private ?OptionRows $rows = null,
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
	 * @param   string $args_hash Stable identity of the start arguments.
	 *
	 * @return  void
	 */
	public function record_started( string $run_id, string $args_hash ): void {
		$this->record( $run_id, $args_hash );
	}

	/**
	 * Appends a run and its outcome to the terminal histories.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string    $run_id    Run identifier.
	 * @param   string    $args_hash Stable identity of the start arguments.
	 * @param   RunStatus $status    Terminal run status.
	 *
	 * @throws  \InvalidArgumentException When the supplied status is not terminal.
	 *
	 * @return  void
	 */
	public function record_terminal( string $run_id, string $args_hash, RunStatus $status ): void {
		$this->record( $run_id, $args_hash, $status );
	}

	/**
	 * Returns validated run identifiers from the global started buffer, oldest first.
	 *
	 * @internal Read-only engine inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When authoritative option-row I/O is unavailable.
	 *
	 * @return  list<string>
	 */
	public function started_entries(): array {
		return $this->history_from_raw_row()['started'];
	}

	/**
	 * Returns validated global terminal entries, oldest first.
	 *
	 * @internal Read-only engine inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When authoritative option-row I/O is unavailable.
	 *
	 * @return  list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded'}>
	 */
	public function terminal_entries(): array {
		return $this->history_from_raw_row()['completed'];
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
	 * @param   string         $args_hash Stable identity of the start arguments.
	 * @param   RunStatus|null $status    Terminal run status, or null for a started entry.
	 *
	 * @throws  \InvalidArgumentException When the supplied status is not terminal.
	 *
	 * @return  void
	 */
	private function record( string $run_id, string $args_hash, ?RunStatus $status = null ): void {
		$history      = self::history_from_option( \get_option( $this->option_name(), null ) );
		$hash_history = $history['by_hash'][ $args_hash ] ?? array(
			'started'   => array(),
			'completed' => array(),
		);
		if ( null === $status ) {
			if (
				\in_array( $run_id, $history['started'], true )
				|| \in_array( $run_id, $hash_history['started'], true )
			) {
				return;
			}

			$history['started'][]      = $run_id;
			$hash_history['started'][] = $run_id;
		} else {
			if ( RunStatus::Running === $status ) {
				throw new \InvalidArgumentException( 'Run history records only terminal outcomes.' );
			}

			if (
				\in_array( $run_id, self::terminal_run_ids( $history['completed'] ), true )
				|| \in_array( $run_id, self::terminal_run_ids( $hash_history['completed'] ), true )
			) {
				return;
			}

			$entry = array(
				'run_id' => $run_id,
				'status' => $status->value,
			);

			$history['completed'][]      = $entry;
			$hash_history['completed'][] = $entry;
		}

		// Re-inserting at the tail keeps the map ordered by recording recency for the bucket cap.
		unset( $history['by_hash'][ $args_hash ] );
		$history['by_hash'][ $args_hash ] = $hash_history;
		$history['by_hash']               = \array_slice( $history['by_hash'], -self::MAX_HASH_BUCKETS, null, true );

		$size                 = $this->history_size();
		$history['started']   = self::tail( $history['started'], $size );
		$history['completed'] = self::tail( $history['completed'], $size );
		foreach ( $history['by_hash'] as $hash => $buffers ) {
			$history['by_hash'][ $hash ] = array(
				'started'   => self::tail( $buffers['started'], $size ),
				'completed' => self::tail( $buffers['completed'], $size ),
			);
		}

		\update_option( $this->option_name(), $history, false );
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
		$size = \apply_filters( 'a8csp_background_tasks/history_size', self::DEFAULT_SIZE );

		return \is_int( $size ) && 0 < $size ? $size : self::DEFAULT_SIZE;
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
		return self::OPTION_PREFIX . $this->name;
	}

	/**
	 * Returns validated history from the authoritative raw row without constructing serialized classes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When authoritative option-row I/O is unavailable.
	 *
	 * @return  array{
	 *     started: list<string>,
	 *     completed: list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded'}>,
	 *     by_hash: array<array-key, array{
	 *         started: list<string>,
	 *         completed: list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded'}>
	 *     }>
	 * }
	 */
	private function history_from_raw_row(): array {
		$rows = $this->rows;
		if ( null === $rows ) {
			$wpdb = $GLOBALS['wpdb'] ?? null;
			if ( ! $wpdb instanceof \wpdb ) {
				throw new \LogicException( 'Run-history inspection requires authoritative option-row I/O.' );
			}

			$rows = new OptionRows( $wpdb );
		}

		$raw = $rows->select( $this->option_name() );

		return self::history_from_option( null === $raw ? null : RawOptionDecoder::decode( $raw ) );
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
	 *     completed: list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded'}>,
	 *     by_hash: array<array-key, array{
	 *         started: list<string>,
	 *         completed: list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded'}>
	 *     }>
	 * }
	 */
	private static function history_from_option( mixed $value ): array {
		if ( ! \is_array( $value ) ) {
			return array(
				'started'   => array(),
				'completed' => array(),
				'by_hash'   => array(),
			);
		}

		$by_hash = array();
		if ( \is_array( $value['by_hash'] ?? null ) ) {
			foreach ( $value['by_hash'] as $args_hash => $buffers ) {
				if ( ! \is_array( $buffers ) ) {
					continue;
				}

				$by_hash[ $args_hash ] = array(
					'started'   => self::string_list( $buffers['started'] ?? null ),
					'completed' => self::terminal_list( $buffers['completed'] ?? null ),
				);
			}
		}

		return array(
			'started'   => self::string_list( $value['started'] ?? null ),
			'completed' => self::terminal_list( $value['completed'] ?? null ),
			'by_hash'   => $by_hash,
		);
	}

	/**
	 * Returns only string entries from a persisted list value.
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

		$strings = array();
		foreach ( $value as $entry ) {
			if ( \is_string( $entry ) ) {
				$strings[] = $entry;
			}
		}

		return $strings;
	}

	/**
	 * Returns only well-formed terminal entries from a persisted list value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $value Persisted list value.
	 *
	 * @return  list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded'}>
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
				|| ! \is_string( $entry['status'] ?? null )
			) {
				continue;
			}

			$status = RunStatus::tryFrom( $entry['status'] );
			if ( null === $status || RunStatus::Running === $status ) {
				continue;
			}

			$terminals[] = array(
				'run_id' => $entry['run_id'],
				'status' => $status->value,
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
	 * @param   list<array{run_id: string, status: 'completed'|'failed'|'cancelled'|'superseded'}> $entries Terminal history entries.
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
