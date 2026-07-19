<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs;

\defined( 'ABSPATH' ) || exit;

/**
 * Durable successor delivery for one running lifecycle stage.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class PendingAction {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param 'start'|'run'|'continue'|'cleanup' $stage
	 * @phpstan-param 'async'|'single' $mode
	 *
	 * @param   string   $stage    Lifecycle stage delivered by the successor.
	 * @param   string   $mode     Scheduler delivery mode.
	 * @param   int|null $fire_at  Scheduled Unix timestamp, or null for asynchronous delivery.
	 * @param   int      $priority Scheduler priority.
	 */
	private function __construct(
		public string $stage,
		public string $mode,
		public ?int $fire_at,
		public int $priority,
	) {}

	// endregion

	// region METHODS

	/**
	 * Creates an immediate asynchronous lifecycle delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $stage    Lifecycle stage delivered by the successor.
	 * @param   int    $priority Scheduler priority.
	 *
	 * @throws  \InvalidArgumentException When the lifecycle stage does not support asynchronous delivery.
	 *
	 * @return  self
	 */
	public static function async( string $stage, int $priority ): self {
		if ( ! \in_array( $stage, array( 'start', 'run', 'continue', 'cleanup' ), true ) ) {
			throw new \InvalidArgumentException( 'Pending asynchronous actions require a supported lifecycle stage.' );
		}

		return new self( $stage, 'async', null, $priority );
	}

	/**
	 * Creates a scheduled single lifecycle delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $stage    Lifecycle stage delivered by the successor.
	 * @param   int    $fire_at  Scheduled Unix timestamp.
	 * @param   int    $priority Scheduler priority.
	 *
	 * @throws  \InvalidArgumentException When the lifecycle stage does not support scheduled single delivery.
	 *
	 * @return  self
	 */
	public static function single( string $stage, int $fire_at, int $priority ): self {
		if ( ! \in_array( $stage, array( 'run', 'continue' ), true ) ) {
			throw new \InvalidArgumentException( 'Pending single actions require the run or continue lifecycle stage.' );
		}

		return new self( $stage, 'single', $fire_at, $priority );
	}

	// endregion
}
