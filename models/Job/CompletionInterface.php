<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Optional post-run role letting an execution object react to its own completion.
 *
 * A chunked job has no last chunk, so `ChunkedJobExecutionInterface` offers nowhere to put work
 * that belongs after the queue drains. Without this role every such consumer hand-assembles
 * `'a8csp_bgje/completed/' . $scope . ':' . $name` and re-derives the argument order, which is a
 * per-consumer restatement of what the consumer already told `jobs()->register()`. A hook name
 * spelled by hand cannot be checked, and one spelled wrong is a listener that silently never fires.
 *
 * This is sugar over `a8csp_bgje/completed/{identity}`, not a second channel. Registration
 * subscribes the declared object to that hook and nothing else changes: the hook still fires for
 * every listener, the payload is the hook's payload, and the delivery guarantees are the hook's
 * guarantees. A consumer that prefers the hook keeps using it, and one that declares this role can
 * still add its own listeners beside it.
 *
 * Two consequences follow from being a hook listener rather than a separate mechanism, and both
 * matter to an implementation:
 *
 * - **Delivery can repeat.** Terminal hooks are durable under Action Scheduler and best-effort
 *   under WP-Cron, and crash-recovery replay can deliver one completion more than once. Key what
 *   this method does to the run identifier so a repeated delivery converges.
 * - **It runs only where the job was registered.** Registration is per-request, so a request that
 *   did not register the work subscribes nothing. That is the same condition every consumer-side
 *   listener attached during registration already has.
 *
 * The role is orthogonal to the kind roles and extends none of them, so a standard job, a chunked
 * job, or a `JobDefinition::for_kind()` object may all declare it.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface CompletionInterface {
	// region METHODS

	/**
	 * Reacts to one completed run of the work this object executes.
	 *
	 * The parameters mirror `a8csp_bgje/completed/{identity}` exactly, so one signature documents
	 * both channels.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunId                   $run_id                    Run identifier of the completed run.
	 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
	 * @param   RunId|null              $previous_completed_run_id Previous completed run identifier for this identity, or null.
	 *
	 * @throws  \Throwable When the reaction fails, which leaves the terminal hooks effect unmarked for replay.
	 *
	 * @return  void
	 */
	public function on_completed( RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ): void;

	// endregion
}
