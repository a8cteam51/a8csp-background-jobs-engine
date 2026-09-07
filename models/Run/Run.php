<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Immutable snapshot returned by run-producing engine commands.
 *
 * The projection captures the run at response time; it is not a live handle.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Run {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string    $identity    Complete {scope}:{name} job or chunked-job identity.
	 * @param   RunId     $id          Run identifier.
	 * @param   RunStatus $status      Run lifecycle state at projection time.
	 * @param   int|null  $terminal_at Unix timestamp the run reached its terminal state, or null when the projection does not carry one.
	 */
	public function __construct(
		public string $identity,
		public RunId $id,
		public RunStatus $status,
		public ?int $terminal_at = null,
	) {}

	// endregion
}
