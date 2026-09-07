<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Inspection;

\defined( 'ABSPATH' ) || exit;

/**
 * Shapes and renders persisted schedule inspection and removal output.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-import-type ScheduleEntry from Inspection
 * @phpstan-type ScheduleRow array{
 *     scope: string,
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
final class ScheduleOutput {
	// region FIELDS AND CONSTANTS

	/**
	 * Fields exposed by the persisted-schedule list.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const array FIELDS = array(
		'scope',
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
	private const string DORMANT_BACKEND_NOTE = 'a scheduling backend is not ready; dormant occurrences are not visible.';

	/**
	 * Eventual-clearance warning for destructive scope removal with an unavailable backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const string DORMANT_REMOVAL_NOTE = 'a scheduling backend is not ready; dormant recurring occurrences for this scope may remain until that backend delivers them or subsequent maintenance clears the recurring chain.';

	// endregion

	// region METHODS

	/**
	 * Requires acknowledgement of one scope's recurring-schedule removal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string               $scope      Canonical client scope.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  void
	 */
	public static function confirm_removal( string $scope, array $assoc_args ): void {
		\WP_CLI::confirm( \sprintf( 'This permanently removes every schedule registration for scope "%s" and converges its recurring occurrences on ready backends. Dormant occurrences on unavailable backends converge later. Existing runs are not cancelled. Continue?', $scope ), $assoc_args );
	}

	/**
	 * Reports successful per-scope removal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope                 Canonical client scope.
	 * @param   bool   $has_dormant_candidate Whether an unavailable backend may retain occurrences.
	 *
	 * @return  void
	 */
	public static function report_removal( string $scope, bool $has_dormant_candidate ): void {
		if ( $has_dormant_candidate ) {
			\WP_CLI::warning( self::DORMANT_REMOVAL_NOTE );
		}
		\WP_CLI::success( \sprintf( 'Removed every persisted schedule registration for scope "%s".', $scope ) );
	}

	/**
	 * Reports an incremental scope-removal failure with its retry path.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope   Canonical client scope.
	 * @param   string $message Scheduling or storage failure.
	 *
	 * @return  void
	 */
	public static function removal_error( string $scope, string $message ): void {
		\WP_CLI::error( \sprintf( '%1$s Scope removal converges incrementally; after resolving this error, rerun "wp a8csp-bgje schedules remove %2$s" to clear any remaining registrations.', $message, $scope ) );
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
	 * @param   string|null $scope    Exact scope filter, or null for every scope.
	 * @param   string      $format   WP-CLI output format.
	 *
	 * @return  void
	 */
	public static function render( array $snapshot, ?string $scope, string $format ): void {
		$rows = self::rows_from_entries( $snapshot['entries'], $snapshot['observed_at'] );
		if ( array() === $rows && 'table' === $format ) {
			\WP_CLI::line( null === $scope ? 'No schedule registrations are persisted.' : \sprintf( 'No schedule registrations are persisted for scope "%s".', $scope ) );
		} else {
			\WP_CLI\Utils\format_items( $format, $rows, self::FIELDS );
		}

		$note = self::dormant_backend_note( $snapshot['dormant_candidate'] );
		if ( null !== $note ) {
			\WP_CLI::warning( $note );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Shapes schedule inspection entries into their exact public columns.
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
	private static function rows_from_entries( array $entries, int $observed_at ): array {
		\usort(
			$entries,
			static function ( array $left, array $right ): int {
				$scope_order = $left['scope'] <=> $right['scope'];
				return 0 !== $scope_order ? $scope_order : $left['identity'] <=> $right['identity'];
			}
		);

		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[] = array(
				'scope'              => $entry['scope'],
				'identity'           => $entry['identity'],
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
	 * Returns the dormant-backend warning only when union reads exclude a present candidate.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   bool $has_dormant_candidate Whether a backend is present but not ready.
	 *
	 * @return  string|null
	 */
	private static function dormant_backend_note( bool $has_dormant_candidate ): ?string {
		return $has_dormant_candidate ? self::DORMANT_BACKEND_NOTE : null;
	}

	/**
	 * Formats one persisted due timestamp as UTC plus its schedule-relative state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $timestamp   Persisted due timestamp.
	 * @param   int $observed_at Inspection timestamp.
	 *
	 * @return  string
	 */
	private static function due_label( int $timestamp, int $observed_at ): string {
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
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{state: 'free'|'invalid'|'not_declared'|'not_inspected'|'overlap_allowed'|'read_failed'|'resolver_failed'}
	 *                |array{state: 'held', run_id: string, stale: bool} $lock
	 *
	 * @param   array $lock Complete discriminated lock state.
	 *
	 * @return  string
	 */
	private static function lock_label( array $lock ): string {
		if ( 'held' !== $lock['state'] ) {
			return match ( $lock['state'] ) {
				'free'            => 'free',
				'invalid'         => 'unknown (invalid lock row)',
				'not_declared'    => 'unknown (not declared this request)',
				'not_inspected'   => 'unknown (not inspected)',
				'overlap_allowed' => 'not blocking (overlap allowed)',
				'read_failed'     => 'unknown (lock read failed)',
				'resolver_failed' => 'unknown (overlap-key resolver failed)',
			};
		}

		$label = 'held by ' . $lock['run_id'];

		return $lock['stale'] ? $label . ' (stale)' : $label;
	}

	// endregion
}
