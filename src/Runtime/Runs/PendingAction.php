<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Durable lifecycle-delivery descriptor for one running stage.
 *
 * A failed terminal state may retain the descriptor as manual-retry priority provenance.
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
	 * Returns whether the lifecycle delivery is asynchronous.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 *
	 * @phpstan-assert-if-true null $this->fire_at
	 * @phpstan-assert-if-false int $this->fire_at
	 */
	public function is_async(): bool {
		return 'async' === $this->mode;
	}

	/**
	 * Creates an immediate asynchronous lifecycle delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $stage    Lifecycle stage delivered by the successor.
	 * @param   int    $priority Scheduler priority.
	 *
	 * @throws  \InvalidArgumentException When the lifecycle stage is lexically malformed.
	 *
	 * @return  self
	 */
	public static function async( string $stage, int $priority ): self {
		if ( 1 !== \preg_match( KindHandlerInterface::KEY_PATTERN, $stage ) ) {
			throw new \InvalidArgumentException( 'Pending asynchronous actions require a grammar-valid lifecycle stage.' );
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
	 * @throws  \InvalidArgumentException When the lifecycle stage is lexically malformed.
	 *
	 * @return  self
	 */
	public static function single( string $stage, int $fire_at, int $priority ): self {
		if ( 1 !== \preg_match( KindHandlerInterface::KEY_PATTERN, $stage ) ) {
			throw new \InvalidArgumentException( 'Pending single actions require a grammar-valid lifecycle stage.' );
		}

		return new self( $stage, 'single', $fire_at, $priority );
	}

	// endregion
}
