<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\LocksOutput;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;

\defined( 'ABSPATH' ) || exit;

/**
 * Inspects and explicitly repairs persisted execution-overlap locks.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class LocksCommand {
	// region METHODS

	/**
	 * Lists execution-overlap locks or repairs one malformed lane.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Operation to perform: list or repair.
	 *
	 * [<identity>]
	 * : Owner-qualified job identity required by repair.
	 *
	 * [--args-hash=<hash>]
	 * : Select one malformed lane when the identity has more than one.
	 *
	 * [--format=<format>]
	 * : Render list output as table, json, csv, or yaml. Defaults to table.
	 *
	 * [--yes]
	 * : Skip the interactive repair confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp a8csp-bgje locks list
	 *     $ wp a8csp-bgje locks list --format=json
	 *     $ wp a8csp-bgje locks repair consumer-plugin:email-digest
	 *     $ wp a8csp-bgje locks repair consumer-plugin:email-digest --args-hash=<hash> --yes
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
			LocksOutput::error( $request['message'] );
			return;
		}

		$repair = Component::get_lock_repair();
		if ( null === $repair ) {
			LocksOutput::error( 'The background jobs lock-repair service is unavailable; run the command after plugins_loaded.' );
			return;
		}

		if ( 'list' === $request['action'] ) {
			$lanes = $repair->inspect_lanes();
			if ( $lanes->is_failure() ) {
				LocksOutput::error( $lanes->error->message );
				return;
			}

			LocksOutput::render( $lanes->value, $request['format'] );
			return;
		}

		$prepared = $repair->prepare( $request['identity'], $request['args_hash'] );
		if ( $prepared->is_failure() ) {
			LocksOutput::error( $prepared->error->message );
			return;
		}
		if ( null === $prepared->value ) {
			LocksOutput::nothing_to_repair( $request['identity'] );
			return;
		}
		if ( \is_array( $prepared->value ) ) {
			LocksOutput::render_ambiguity( $prepared->value );
			LocksOutput::error( \sprintf( 'Multiple malformed execution-overlap lock lanes exist for "%s"; re-run with --args-hash=<one shown>.', $request['identity'] ) );
			return;
		}

		$plan = $prepared->value;
		LocksOutput::confirm( $plan, $assoc_args );
		$result = $repair->repair( $plan );
		if ( $result->is_failure() ) {
			LocksOutput::report_failure( $plan, $result->error );
			return;
		}

		LocksOutput::report_success( $plan, $result->value );
	}

	/**
	 * Validates lock arguments without requiring WordPress or WP-CLI state.
	 *
	 * @internal Command decision seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string>         $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  array{action: 'error', message: string}
	 *          |array{action: 'list', format: string}
	 *          |array{action: 'repair', identity: string, args_hash: string|null}
	 */
	public static function request_from_args( array $args, array $assoc_args ): array {
		if ( array() === $args ) {
			return array(
				'action'  => 'error',
				'message' => 'A lock action is required; use list or repair <identity>.',
			);
		}

		if ( 'list' === $args[0] ) {
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

		if ( 'repair' !== $args[0] ) {
			return array(
				'action'  => 'error',
				'message' => \sprintf( 'Lock action "%s" is invalid; use list or repair.', $args[0] ),
			);
		}

		if (
			2 !== \count( $args )
			|| ! self::has_only_keys( $assoc_args, array( 'args-hash', 'yes' ) )
			|| ( \array_key_exists( 'yes', $assoc_args ) && ! \is_bool( $assoc_args['yes'] ) )
		) {
			return array(
				'action'  => 'error',
				'message' => 'Lock repair requires exactly one identity and accepts only --args-hash and --yes; use wp a8csp-bgje locks repair <identity> [--args-hash=<hash>] [--yes].',
			);
		}

		$identity = $args[1];
		if ( null === JobIdentity::parts( $identity ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Lock repair identity is invalid; use a composed {owner}:{name} identity.',
			);
		}

		$args_hash = $assoc_args['args-hash'] ?? null;
		if ( null !== $args_hash && ( ! \is_string( $args_hash ) || 1 !== \preg_match( '/\A[a-f0-9]{64}\z/D', $args_hash ) ) ) {
			return array(
				'action'  => 'error',
				'message' => 'Lock repair argument hash is invalid; copy one lowercase SHA-256 hash from locks list.',
			);
		}

		return array(
			'action'    => 'repair',
			'identity'  => $identity,
			'args_hash' => $args_hash,
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
