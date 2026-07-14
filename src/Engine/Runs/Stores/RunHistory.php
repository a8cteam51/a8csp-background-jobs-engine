<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores;

\defined( 'ABSPATH' ) || exit;

/**
 * Persists bounded started and completed run histories.
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
	 * @param   string $name Stable task or batch name.
	 */
	public function __construct( private string $name ) {}

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
		$this->record( 'started', $run_id, $args_hash );
	}

	/**
	 * Appends a run to the completed histories.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id    Run identifier.
	 * @param   string $args_hash Stable identity of the start arguments.
	 *
	 * @return  void
	 */
	public function record_completed( string $run_id, string $args_hash ): void {
		$this->record( 'completed', $run_id, $args_hash );
	}

	// endregion

	// region HELPERS

	/**
	 * Appends a run to one global and per-hash buffer before capping every buffer.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param 'started'|'completed' $buffer
	 *
	 * @param   string $buffer    History buffer name.
	 * @param   string $run_id    Run identifier.
	 * @param   string $args_hash Stable identity of the start arguments.
	 *
	 * @return  void
	 */
	private function record( string $buffer, string $run_id, string $args_hash ): void {
		$history      = self::history_from_option( \get_option( $this->option_name(), null ) );
		$hash_history = $history['by_hash'][ $args_hash ] ?? array(
			'started'   => array(),
			'completed' => array(),
		);
		if (
			\in_array( $run_id, $history[ $buffer ], true )
			|| \in_array( $run_id, $hash_history[ $buffer ], true )
		) {
			return;
		}

		$history[ $buffer ][]      = $run_id;
		$hash_history[ $buffer ][] = $run_id;

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
		$size = \apply_filters( 'a8csp/background_tasks/history_size', self::DEFAULT_SIZE );

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
	 * Normalizes valid history buffers from a persisted option.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $value Persisted option value.
	 *
	 * @return  array{
	 *     started: list<string>,
	 *     completed: list<string>,
	 *     by_hash: array<array-key, array{started: list<string>, completed: list<string>}>
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
					'completed' => self::string_list( $buffers['completed'] ?? null ),
				);
			}
		}

		return array(
			'started'   => self::string_list( $value['started'] ?? null ),
			'completed' => self::string_list( $value['completed'] ?? null ),
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
	 * Returns the newest entries within a positive cap.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string> $values Entries in oldest-first order.
	 * @param   int          $size   Positive maximum entry count.
	 *
	 * @return  list<string>
	 */
	private static function tail( array $values, int $size ): array {
		return \array_slice( $values, -$size );
	}

	// endregion
}
