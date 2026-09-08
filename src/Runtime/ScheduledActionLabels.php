<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceDelivery;

\defined( 'ABSPATH' ) || exit;

/**
 * Names the engine's own scheduled-action arguments in Action Scheduler's admin list table.
 *
 * Scheduled arguments are positional lists because that array is the row's identity key on both
 * backends and, on WP-Cron, reaches the callback through a path that reads string keys as PHP named
 * arguments. The list table renders the keys the stored array has, which numbers them for an
 * operator. Labelling belongs at the display boundary, where it leaves the stored shape untouched.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ScheduledActionLabels {
	// region FIELDS AND CONSTANTS

	/**
	 * Argument names for each engine hook, in the order that hook schedules them.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<non-empty-string, non-empty-list<non-empty-string>>
	 */
	private const array ARGUMENT_LABELS = array(
		ActionDeliveries::DELIVER_HOOK    => array( 'identity', 'run_id', 'action_sequence' ),
		OccurrenceDelivery::SCHEDULE_HOOK => array( 'schedule_identity' ),
	);

	// endregion

	// region METHODS

	/**
	 * Attaches argument labelling to the list table.
	 *
	 * Action Scheduler's admin screen is the only caller, so the filter costs nothing on a request
	 * that never renders it and needs no admin gate to stay inert. The filter is an undocumented
	 * Action Scheduler extension point carrying no stability promise, and its removal degrades to
	 * the list table's own numbered rendering rather than failing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public static function register_hooks(): void {
		\add_filter( 'action_scheduler_list_table_column_args', array( self::class, 'label_arguments' ), 10, 2 );
	}

	// endregion

	// region HOOKS

	/**
	 * Renders one engine row's arguments under their names.
	 *
	 * A foreign hook, or an engine row whose arguments do not match the arity this class declares,
	 * keeps the rendering it arrived with: numbered arguments are readable, wrongly named ones are
	 * not. Keyed arguments are left alone for the same reason — whoever wrote them owns their names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $rendered Rendering the list table produced.
	 * @param   array<array-key, mixed> $row      List-table row carrying the hook and its arguments.
	 *
	 * @return  string
	 */
	public static function label_arguments( string $rendered, array $row ): string {
		$hook   = $row['hook'] ?? null;
		$args   = $row['args'] ?? null;
		$labels = \is_string( $hook ) ? self::ARGUMENT_LABELS[ $hook ] ?? null : null;
		if ( null === $labels || ! \is_array( $args ) || ! \array_is_list( $args ) || \count( $labels ) !== \count( $args ) ) {
			return $rendered;
		}

		$labelled = '<ul>';
		foreach ( \array_combine( $labels, $args ) as $label => $value ) {
			$labelled .= \sprintf( '<li><code>%s => %s</code></li>', \esc_html( \var_export( $label, true ) ), \esc_html( \var_export( $value, true ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Reproduces the list table's own value formatting so a labelled row renders identically to a native one.
		}

		return $labelled . '</ul>';
	}

	// endregion
}
