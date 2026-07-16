<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\CLI\Output;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Inspection;

\defined( 'ABSPATH' ) || exit;

/**
 * Shapes and renders persisted schedule inspection output.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-import-type ScheduleEntry from Inspection
 * @phpstan-type ScheduleRow array{
 *     owner: string,
 *     identity: string,
 *     recurrence: int|string,
 *     next_due: string,
 *     last_fired: string,
 *     misfire_skips: int,
 *     overlap_skips: int,
 *     occurrence_visible: 'yes'|'no',
 *     lock: string
 * }
 */
final readonly class ScheduleOutput {
	// region FIELDS AND CONSTANTS

	/**
	 * Fields exposed by the persisted-schedule list.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const FIELDS = array(
		'owner',
		'identity',
		'recurrence',
		'next_due',
		'last_fired',
		'misfire_skips',
		'overlap_skips',
		'occurrence_visible',
		'lock',
	);

	/**
	 * Explanation printed when union reads exclude a present scheduling backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const DORMANT_BACKEND_NOTE = 'note: a scheduling backend is not ready; dormant occurrences are not visible.';

	// endregion

	// region METHODS

	/**
	 * Shapes schedule inspection entries into their exact public columns.
	 *
	 * @internal Command formatting seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<ScheduleEntry> $entries
	 *
	 * @param   array $entries     Validated schedule inspection entries.
	 * @param   int   $observed_at Inspection timestamp.
	 *
	 * @phpstan-return list<ScheduleRow>
	 *
	 * @return  array
	 */
	public static function rows_from_entries( array $entries, int $observed_at ): array {
		\usort(
			$entries,
			static function ( array $left, array $right ): int {
				$owner_order = $left['owner'] <=> $right['owner'];
				return 0 !== $owner_order ? $owner_order : $left['name'] <=> $right['name'];
			}
		);

		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[] = array(
				'owner'              => $entry['owner'],
				'identity'           => $entry['name'],
				'recurrence'         => $entry['recurrence'] ?? 'unknown (not declared this request)',
				'next_due'           => self::due_label( $entry['next_due'], $observed_at ),
				'last_fired'         => null === $entry['last_fired']
					? 'never'
					: \gmdate( \DATE_ATOM, $entry['last_fired'] ),
				'misfire_skips'      => $entry['misfire_skips'],
				'overlap_skips'      => $entry['overlap_skips'],
				'occurrence_visible' => $entry['occurrence_visible'] ? 'yes' : 'no',
				'lock'               => self::lock_label( $entry['lock'] ),
			);
		}

		return $rows;
	}

	/**
	 * Returns the dormant-backend footer only when union reads exclude a present candidate.
	 *
	 * @internal Command honesty seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   bool $has_dormant_candidate Whether a backend is present but not ready.
	 *
	 * @return  string|null
	 */
	public static function dormant_backend_note( bool $has_dormant_candidate ): ?string {
		return $has_dormant_candidate ? self::DORMANT_BACKEND_NOTE : null;
	}

	/**
	 * Formats one persisted due timestamp as UTC plus its schedule-relative state.
	 *
	 * @internal Command time seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $timestamp   Persisted due timestamp.
	 * @param   int $observed_at Inspection timestamp.
	 *
	 * @return  string
	 */
	public static function due_label( int $timestamp, int $observed_at ): string {
		if ( $timestamp > $observed_at ) {
			$relative = 'in ' . RelativeTime::duration( RelativeTime::distance( $timestamp, $observed_at ) );
		} elseif ( $timestamp === $observed_at ) {
			$relative = 'due now';
		} else {
			$relative = 'overdue ' . RelativeTime::duration( RelativeTime::distance( $observed_at, $timestamp ) );
		}

		return \sprintf( '%1$s (%2$s)', \gmdate( \DATE_ATOM, $timestamp ), $relative );
	}

	/**
	 * Formats one complete schedule lock snapshot.
	 *
	 * @internal Command honesty seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{state: 'free'|'invalid'|'not_declared'|'overlap_allowed'|'read_failed'}
	 *                |array{state: 'held', run_id: string, stale: bool} $lock
	 *
	 * @param   array $lock Complete discriminated lock state.
	 *
	 * @return  string
	 */
	public static function lock_label( array $lock ): string {
		if ( 'held' !== $lock['state'] ) {
			return match ( $lock['state'] ) {
				'free'            => 'free',
				'invalid'         => 'unknown (invalid lock row)',
				'not_declared'    => 'unknown (not declared this request)',
				'overlap_allowed' => 'not blocking (overlap allowed)',
				'read_failed'     => 'unknown (lock read failed)',
			};
		}

		$label = 'held by ' . $lock['run_id'];

		return $lock['stale'] ? $label . ' (stale)' : $label;
	}

	/**
	 * Renders one persisted schedule snapshot through the requested WP-CLI formatter.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{entries: list<ScheduleEntry>, observed_at: int, dormant_candidate: bool} $snapshot
	 *
	 * @param   array       $snapshot Persisted schedule inspection snapshot.
	 * @param   string|null $owner    Exact owner filter, or null for every owner.
	 * @param   string      $format   WP-CLI output format.
	 *
	 * @return  void
	 */
	public static function render( array $snapshot, ?string $owner, string $format ): void {
		$rows = self::rows_from_entries( $snapshot['entries'], $snapshot['observed_at'] );
		if ( array() === $rows && 'table' === $format ) {
			\WP_CLI::line( null === $owner ? 'No schedule registrations are persisted.' : \sprintf( 'No schedule registrations are persisted for owner "%s".', $owner ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, self::FIELDS );
		if ( 'table' !== $format ) {
			return;
		}

		$note = self::dormant_backend_note( $snapshot['dormant_candidate'] );
		if ( null !== $note ) {
			\WP_CLI::line( $note );
		}
	}

	// endregion
}
