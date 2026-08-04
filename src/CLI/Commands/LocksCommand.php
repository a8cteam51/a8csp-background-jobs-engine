<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands;

use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\LocksOutput;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;

\defined( 'ABSPATH' ) || exit;

/**
 * Inspects persisted execution-overlap locks.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class LocksCommand {
	// region METHODS

	/**
	 * Lists execution-overlap locks.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Operation to perform: list.
	 *
	 * [--format=<format>]
	 * : Render list output as table, json, csv, or yaml. Defaults to table.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp a8csp-bgje locks list
	 *     $ wp a8csp-bgje locks list --format=json
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string>         $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  void
	 */
	public function locks( array $args, array $assoc_args ): void {
		$request = self::request_from_args( $args, $assoc_args );
		if ( 'error' === $request['action'] ) {
			\WP_CLI::error( $request['message'] );
			return;
		}

		$inspection = Component::get_lock_inspection();
		if ( null === $inspection ) {
			\WP_CLI::error( 'The background jobs lock-inspection service is unavailable; run the command after plugins_loaded.' );
			return;
		}

		$lanes = $inspection->inspect_lanes();
		if ( $lanes->is_failure() ) {
			\WP_CLI::error( $lanes->error->message );
			return;
		}

		LocksOutput::render( $lanes->value, $request['format'] );
	}

	/**
	 * Validates lock arguments without requiring WordPress or WP-CLI state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string>         $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  array{action: 'error', message: string}|array{action: 'list', format: string}
	 */
	public static function request_from_args( array $args, array $assoc_args ): array {
		if ( array() === $args ) {
			return array(
				'action'  => 'error',
				'message' => 'A lock action is required; use list.',
			);
		}

		if ( 'list' !== $args[0] ) {
			return array(
				'action'  => 'error',
				'message' => \sprintf( 'Lock action "%s" is invalid; use list.', $args[0] ),
			);
		}

		if ( 1 !== \count( $args ) || ! self::has_only_keys( $assoc_args, array( 'format' ) ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Lock list accepts only --format; use wp a8csp-bgje locks list [--format=<table|json|csv|yaml>].',
			);
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( ! \is_string( $format ) || ! \in_array( $format, array( 'table', 'json', 'csv', 'yaml' ), true ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Lock list format is invalid; use table, json, csv, or yaml.',
			);
		}

		return array(
			'action' => 'list',
			'format' => $format,
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Returns whether an argument map contains only the allowed keys.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<string, mixed> $args         Named arguments.
	 * @param   list<string>         $allowed_keys Allowed argument keys.
	 *
	 * @return  bool
	 */
	private static function has_only_keys( array $args, array $allowed_keys ): bool {
		return array() === \array_diff( \array_keys( $args ), $allowed_keys );
	}

	// endregion
}
