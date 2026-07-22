<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Couples one admitted delivery state to the handler resolved from its persisted kind.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ClaimedDelivery {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   KindHandlerInterface $handler Resolved kind handler.
	 * @param   RunState             $state   Fenced executing state.
	 */
	public function __construct(
		public KindHandlerInterface $handler,
		public RunState $state,
	) {}

	// endregion
}
