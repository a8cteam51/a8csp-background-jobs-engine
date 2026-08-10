<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;

\defined( 'ABSPATH' ) || exit;

/**
 * Shapes and renders retained failed-run inspection output.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-type FailedRunEntry array{
 *     run_id: string,
 *     kind: string,
 *     failed_at: int,
 *     start_args: array<array-key, mixed>,
 *     priority: int,
 *     attempts: int,
 *     error: array{
 *         class: string|null,
 *         message: string,
 *         stage: string,
 *         code: string,
 *         details?: array<array-key, mixed>
 *     }
 * }
 * @phpstan-type FailedRunRow array{
 *     scope: string,
 *     identity: string,
 *     run_id: string,
 *     failed_at: string,
 *     attempts: int,
 *     stage: string,
 *     code: string,
 *     error_class: string|null,
 *     error_message: string,
 *     failed_chunk: array<array-key, mixed>|null
 * }
 * @phpstan-type FailedRunGroup array{
 *     identity: Identity,
 *     entries: list<FailedRunEntry>
 * }
 */
final class FailedRunOutput {
	// region FIELDS AND CONSTANTS

	/**
	 * Fields exposed by the failed-run list.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const array FIELDS = array(
		'scope',
		'identity',
		'run_id',
		'failed_at',
		'attempts',
		'stage',
		'code',
		'error_class',
		'error_message',
	);

	/**
	 * Fields exposed only by structured failed-run formats.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const array STRUCTURED_FIELDS = array(
		'scope',
		'identity',
		'run_id',
		'failed_at',
		'attempts',
		'stage',
		'code',
		'error_class',
		'error_message',
		'failed_chunk',
	);

	// endregion

	// region METHODS

	/**
	 * Renders every retained failed run through the requested WP-CLI formatter.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<FailedRunGroup> $groups
	 *
	 * @param   array       $groups             Failed runs grouped by composed identity.
	 * @param   string|null $scope              Exact scope filter, or null for every scope.
	 * @param   string      $format             WP-CLI output format.
	 * @param   int         $unreadable_entries Rejected child-entry count.
	 * @param   int         $unreadable_rows    Whole-row unreadable count.
	 *
	 * @return  void
	 */
	public static function render( array $groups, ?string $scope, string $format, int $unreadable_entries = 0, int $unreadable_rows = 0 ): void {
		$rows    = self::rows_from_entries( $groups, $scope );
		$warning = self::unreadable_message( $unreadable_entries, $unreadable_rows );
		if ( array() === $rows && 'table' === $format && null === $warning ) {
			\WP_CLI::line( 'No failed runs are retained.' );
			return;
		}

		$fields = \in_array( $format, array( 'json', 'yaml' ), true ) ? self::STRUCTURED_FIELDS : self::FIELDS;
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
		if ( null !== $warning ) {
			\WP_CLI::warning( $warning );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Shapes and orders failed-run entries without requiring WordPress or WP-CLI state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<FailedRunGroup> $groups
	 *
	 * @param   array       $groups Failed runs grouped by composed job or chunked job identity.
	 * @param   string|null $scope  Exact scope filter, or null for every scope.
	 *
	 * @phpstan-return list<FailedRunRow>
	 *
	 * @return  array
	 */
	private static function rows_from_entries( array $groups, ?string $scope = null ): array {
		\usort(
			$groups,
			static fn ( array $left, array $right ): int => (string) $left['identity'] <=> (string) $right['identity']
		);

		$rows = array();
		foreach ( $groups as $group ) {
			$identity = $group['identity'];
			if ( null !== $scope && $scope !== $identity->scope() ) {
				continue;
			}

			$entries = $group['entries'];
			\usort(
				$entries,
				static function ( array $left, array $right ): int {
					$failed_at_order = $left['failed_at'] <=> $right['failed_at'];
					return 0 !== $failed_at_order ? $failed_at_order : $left['run_id'] <=> $right['run_id'];
				}
			);

			foreach ( $entries as $entry ) {
				$details      = $entry['error']['details'] ?? null;
				$failed_chunk = \is_array( $details ) && \is_array( $details['failed_chunk'] ?? null )
					? $details['failed_chunk']
					: null;

				$rows[] = array(
					'scope'         => $identity->scope(),
					'identity'      => (string) $identity,
					'run_id'        => $entry['run_id'],
					'failed_at'     => \gmdate( \DATE_ATOM, $entry['failed_at'] ),
					'attempts'      => $entry['attempts'],
					'stage'         => $entry['error']['stage'],
					'code'          => $entry['error']['code'],
					'error_class'   => $entry['error']['class'],
					'error_message' => $entry['error']['message'],
					'failed_chunk'  => $failed_chunk,
				);
			}
		}

		return $rows;
	}

	/**
	 * Returns one CLI warning for unreadable failed-run entries or whole option rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $unreadable_entries Rejected child-entry count.
	 * @param   int $unreadable_rows    Whole-row unreadable count.
	 *
	 * @return  string|null
	 */
	private static function unreadable_message( int $unreadable_entries, int $unreadable_rows ): ?string {
		if ( 1 > $unreadable_entries && 1 > $unreadable_rows ) {
			return null;
		}
		if ( 1 > $unreadable_rows ) {
			return \sprintf( '%1$d unreadable failed-run %2$s %3$s omitted; repair or purge each affected option row.', $unreadable_entries, 1 === $unreadable_entries ? 'entry' : 'entries', 1 === $unreadable_entries ? 'was' : 'were' );
		}
		if ( 1 > $unreadable_entries ) {
			return \sprintf( '%1$d unreadable failed-run option %2$s %3$s omitted; repair or purge each affected option row.', $unreadable_rows, 1 === $unreadable_rows ? 'row' : 'rows', 1 === $unreadable_rows ? 'was' : 'were' );
		}

		return \sprintf( '%1$d unreadable failed-run %2$s and %3$d unreadable option %4$s were omitted; repair or purge each affected option row.', $unreadable_entries, 1 === $unreadable_entries ? 'entry' : 'entries', $unreadable_rows, 1 === $unreadable_rows ? 'row' : 'rows' );
	}

	// endregion
}
