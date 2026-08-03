<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockInspection;

\defined( 'ABSPATH' ) || exit;

/**
 * Shapes persisted lock inspection output.
 *
 * @internal
 *
 * @phpstan-import-type LockLane from LockInspection
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class LocksOutput {
	// region FIELDS AND CONSTANTS

	/**
	 * Fields exposed by persisted execution-overlap lock inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const array FIELDS = array(
		'identity',
		'args_hash',
		'state',
		'raw_length',
		'raw_sha256',
	);

	// endregion

	// region METHODS

	/**
	 * Renders every persisted execution-overlap lock lane.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<LockLane> $lanes
	 *
	 * @param   array  $lanes  Inspected execution-overlap lock lanes.
	 * @param   string $format WP-CLI output format.
	 *
	 * @return  void
	 */
	public static function render( array $lanes, string $format ): void {
		$rows = self::rows_from_lanes( $lanes );
		if ( array() === $rows && 'table' === $format ) {
			\WP_CLI::line( 'No execution-overlap locks are persisted.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, self::FIELDS );
	}

	/**
	 * Reports a fatal lock-command error.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $message Corrective error.
	 *
	 * @return  void
	 */
	public static function error( string $message ): void {
		\WP_CLI::error( $message );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns deterministic rows in their exact public column order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<LockLane> $lanes
	 *
	 * @param   array $lanes Inspected execution-overlap lock lanes.
	 *
	 * @return  list<LockLane>
	 */
	private static function rows_from_lanes( array $lanes ): array {
		\usort(
			$lanes,
			static function ( array $left, array $right ): int {
				$identity_order = $left['identity'] <=> $right['identity'];
				return 0 !== $identity_order ? $identity_order : $left['args_hash'] <=> $right['args_hash'];
			}
		);

		$rows = array();
		foreach ( $lanes as $lane ) {
			$rows[] = array(
				'identity'   => $lane['identity'],
				'args_hash'  => $lane['args_hash'],
				'state'      => $lane['state'],
				'raw_length' => $lane['raw_length'],
				'raw_sha256' => $lane['raw_sha256'],
			);
		}

		return $rows;
	}

	// endregion
}
