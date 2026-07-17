<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\CLI\Output;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;

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
 *     failed_at: int,
 *     start_args: array<array-key, mixed>,
 *     attempts: int,
 *     error: array{class: string|null, message: string}
 * }
 * @phpstan-type FailedRunRow array{
 *     owner: string,
 *     identity: string,
 *     run_id: string,
 *     failed_at: string,
 *     attempts: int,
 *     error_class: string|null,
 *     error_message: string
 * }
 */
final readonly class FailedRunOutput {
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
		'owner',
		'identity',
		'run_id',
		'failed_at',
		'attempts',
		'error_class',
		'error_message',
	);

	// endregion

	// region METHODS

	/**
	 * Shapes and orders failed-run entries without requiring WordPress or WP-CLI state.
	 *
	 * @internal Command formatting seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, list<FailedRunEntry>> $entries_by_name
	 *
	 * @param   array       $entries_by_name Failed runs keyed by composed task or batch identity.
	 * @param   string|null $owner           Exact owner filter, or null for every owner.
	 *
	 * @phpstan-return list<FailedRunRow>
	 *
	 * @return  array
	 */
	public static function rows_from_entries( array $entries_by_name, ?string $owner = null ): array {
		\ksort( $entries_by_name, \SORT_STRING );

		$rows = array();
		foreach ( $entries_by_name as $name => $entries ) {
			$parts = WorkIdentity::parts( $name );
			if ( null === $parts || ( null !== $owner && $owner !== $parts[0] ) ) {
				continue;
			}

			\usort(
				$entries,
				static function ( array $left, array $right ): int {
					$failed_at_order = $left['failed_at'] <=> $right['failed_at'];
					return 0 !== $failed_at_order ? $failed_at_order : $left['run_id'] <=> $right['run_id'];
				}
			);

			foreach ( $entries as $entry ) {
				$rows[] = array(
					'owner'         => $parts[0],
					'identity'      => $name,
					'run_id'        => $entry['run_id'],
					'failed_at'     => \gmdate( \DATE_ATOM, $entry['failed_at'] ),
					'attempts'      => $entry['attempts'],
					'error_class'   => $entry['error']['class'],
					'error_message' => $entry['error']['message'],
				);
			}
		}

		return $rows;
	}

	/**
	 * Renders every retained failed run through the requested WP-CLI formatter.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, list<FailedRunEntry>> $entries_by_name
	 *
	 * @param   array       $entries_by_name Failed runs keyed by composed identity.
	 * @param   string|null $owner           Exact owner filter, or null for every owner.
	 * @param   string      $format          WP-CLI output format.
	 *
	 * @return  void
	 */
	public static function render( array $entries_by_name, ?string $owner, string $format ): void {
		$rows = self::rows_from_entries( $entries_by_name, $owner );
		if ( array() === $rows && 'table' === $format ) {
			\WP_CLI::line( 'No failed runs are retained.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, self::FIELDS );
	}

	// endregion
}
