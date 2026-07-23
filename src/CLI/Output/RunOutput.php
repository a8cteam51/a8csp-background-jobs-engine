<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Inspection;

\defined( 'ABSPATH' ) || exit;

/**
 * Shapes and renders live-run and bounded-history inspection output.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-import-type LiveRunEntry from Inspection
 * @phpstan-import-type HistoryEntry from Inspection
 * @phpstan-type LiveRunRow array{
 *     run_id: string,
 *     status: 'running',
 *     phase: 'executing'|'waiting',
 *     attempts: int,
 *     queue: int|'unknown'|'—',
 *     heartbeat: string
 * }
 * @phpstan-type HistoryRow array{
 *     run_id: string,
 *     outcome: 'completed'|'failed'|'cancelled'|'superseded'|'started',
 *     failed_store: 'failed store'|'—'
 * }
 */
final readonly class RunOutput {
	// region FIELDS AND CONSTANTS

	/**
	 * Fields exposed by the live-run section.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const array LIVE_FIELDS = array(
		'run_id',
		'status',
		'phase',
		'attempts',
		'queue',
		'heartbeat',
	);

	/**
	 * Fields exposed by the recent-history section.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const array HISTORY_FIELDS = array(
		'run_id',
		'outcome',
		'failed_store',
	);

	// endregion

	// region METHODS

	/**
	 * Shapes live run entries into their exact public columns.
	 *
	 * @internal Command formatting seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<LiveRunEntry> $entries
	 *
	 * @param   array $entries     Validated live-run entries.
	 * @param   int   $observed_at Inspection timestamp.
	 *
	 * @phpstan-return list<LiveRunRow>
	 *
	 * @return  array
	 */
	public static function live_rows_from_entries( array $entries, int $observed_at ): array {
		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[] = array(
				'run_id'    => $entry['run_id'],
				'status'    => 'running',
				'phase'     => $entry['executing'] ? 'executing' : 'waiting',
				'attempts'  => $entry['attempts'],
				'queue'     => $entry['queue_depth'] ?? ( $entry['queue_known'] ? '—' : 'unknown' ),
				'heartbeat' => self::heartbeat_label( $entry['heartbeat_at'], $observed_at, $entry['stale'] ),
			);
		}

		return $rows;
	}

	/**
	 * Shapes bounded history entries into their exact public columns.
	 *
	 * @internal Command formatting seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<HistoryEntry> $entries
	 *
	 * @param   array $entries Validated recent-history entries.
	 *
	 * @phpstan-return list<HistoryRow>
	 *
	 * @return  array
	 */
	public static function history_rows_from_entries( array $entries ): array {
		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[] = array(
				'run_id'       => $entry['run_id'],
				'outcome'      => $entry['outcome'],
				'failed_store' => $entry['failed_store'] ? 'failed store' : '—',
			);
		}

		return $rows;
	}

	/**
	 * Returns the corrective CLI error for a compromised live-run listing.
	 *
	 * @internal Command honesty seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'enumeration_failed'|'read_failed'|null $error Inspection failure state.
	 *
	 * @return  string|null
	 */
	public static function error_message( ?string $error ): ?string {
		return match ( $error ) {
			'enumeration_failed' => 'Live-run state is unknown (run enumeration failed); resolve the database error and try again.',
			'read_failed'        => 'Live-run state is unknown (run read failed); resolve the database error and try again.',
			default              => null,
		};
	}

	/**
	 * Returns the warning carried by every output format when live-run inspection is truncated.
	 *
	 * @internal Command honesty seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $scanned     Number of matching run rows inspected.
	 * @param   int $uninspected Number of matching run rows excluded by the cap.
	 *
	 * @return  string|null
	 */
	public static function truncation_message( int $scanned, int $uninspected ): ?string {
		if ( 1 > $uninspected ) {
			return null;
		}

		return \sprintf( 'Showing first %1$d matching run rows; %2$d more %3$s not inspected.', $scanned, $uninspected, 1 === $uninspected ? 'was' : 'were' );
	}

	/**
	 * Returns the warning carried by every output format when live-run rows are unreadable.
	 *
	 * @internal Command honesty seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $unreadable Number of unreadable live-run rows omitted from inspection.
	 *
	 * @return  string|null
	 */
	public static function unreadable_message( int $unreadable ): ?string {
		if ( 1 > $unreadable ) {
			return null;
		}

		return \sprintf( '%1$d unreadable live-run %2$s %3$s omitted; maintenance reclaims corrupt state, but repair malformed option names manually.', $unreadable, 1 === $unreadable ? 'row' : 'rows', 1 === $unreadable ? 'was' : 'were' );
	}

	/**
	 * Formats one live heartbeat as a non-negative relative age and optional stale signal.
	 *
	 * @internal Command time seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int  $timestamp   Persisted heartbeat timestamp.
	 * @param   int  $observed_at Inspection timestamp.
	 * @param   bool $stale       Whether the effective window is strictly exceeded.
	 *
	 * @return  string
	 */
	public static function heartbeat_label( int $timestamp, int $observed_at, bool $stale ): string {
		$age   = $timestamp > $observed_at ? 0 : RelativeTime::distance( $observed_at, $timestamp );
		$label = RelativeTime::duration( $age ) . ' ago';

		return $stale ? $label . ' (stale)' : $label;
	}

	/**
	 * Renders one run snapshot through the format-specific public contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{
	 *     observed_at: int,
	 *     live: list<LiveRunEntry>,
	 *     live_scanned: int,
	 *     live_uninspected: int,
	 *     live_unreadable: int,
	 *     live_error: 'enumeration_failed'|'read_failed'|null,
	 *     history: list<HistoryEntry>|null
	 * } $snapshot
	 *
	 * @param   array  $snapshot Run inspection snapshot.
	 * @param   string $name     Composed job or chunked job identity.
	 * @param   string $format   WP-CLI output format.
	 *
	 * @return  void
	 */
	public static function render( array $snapshot, string $name, string $format ): void {
		$error_message = self::error_message( $snapshot['live_error'] );
		if ( null !== $error_message ) {
			\WP_CLI::error( $error_message );
			return;
		}

		$live_rows           = self::live_rows_from_entries( $snapshot['live'], $snapshot['observed_at'] );
		$history_unavailable = null === $snapshot['history'];
		$history_rows        = $history_unavailable ? array() : self::history_rows_from_entries( $snapshot['history'] );
		$truncation          = self::truncation_message( $snapshot['live_scanned'], $snapshot['live_uninspected'] );
		$unreadable          = self::unreadable_message( $snapshot['live_unreadable'] );
		if (
			array() === $live_rows
			&& array() === $history_rows
			&& 'table' === $format
			&& ! $history_unavailable
			&& null === $truncation
			&& null === $unreadable
		) {
			\WP_CLI::line( \sprintf( 'No live runs or history are retained for "%s".', $name ) );
			return;
		}

		switch ( $format ) {
			case 'table':
				if ( array() !== $live_rows ) {
					\WP_CLI::line( 'live runs' );
					\WP_CLI\Utils\format_items( 'table', $live_rows, self::LIVE_FIELDS );
				}
				if ( array() !== $history_rows ) {
					\WP_CLI::line( 'recent history' );
					\WP_CLI\Utils\format_items( 'table', $history_rows, self::HISTORY_FIELDS );
				}
				break;
			case 'csv':
			case 'count':
				\WP_CLI\Utils\format_items( $format, $live_rows, self::LIVE_FIELDS );
				break;
			case 'json':
			case 'yaml':
				\WP_CLI::print_value( \array_merge( $live_rows, $history_rows ), array( 'format' => $format ) );
				break;
		}

		if ( $history_unavailable ) {
			\WP_CLI::warning( 'Recent run history is unavailable because an authoritative database read failed.' );
		}
		if ( null !== $unreadable ) {
			\WP_CLI::warning( $unreadable );
		}
		if ( null !== $truncation ) {
			\WP_CLI::warning( $truncation );
		}
	}

	// endregion
}
