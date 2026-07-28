<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;

\defined( 'ABSPATH' ) || exit;

/**
 * Delivers every persisted lifecycle action through its resolved kind handler.
 *
 * Fresh execution markers exclude same-sequence redelivery; stale crash recovery remains at-least-once
 * and relies on job and chunked-job idempotency.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ActionDeliveries {
	// region FIELDS AND CONSTANTS

	/**
	 * Internal hook that delivers every persisted lifecycle stage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string DELIVER_HOOK = 'a8csp_bgje/internal/deliver';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, KindHandlerInterface> $handlers
	 *
	 * @param   array          $handlers             Kind handlers keyed by their persisted keys.
	 * @param   StoreFactory   $stores               Name-bound store factory.
	 * @param   RunTransitions $terminal_transitions Fenced transition coordinator.
	 */
	public function __construct(
		private array $handlers,
		private StoreFactory $stores,
		private RunTransitions $terminal_transitions,
	) {}

	// endregion

	// region METHODS

	/**
	 * Resolves and executes one persisted lifecycle action.
	 *
	 * Scheduler-wire identity bytes stay raw because they are untrusted and corrupt values still
	 * drive exact stale-delivery lookup and diagnostics.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity        Complete scope-qualified work identity.
	 * @param   string $run_id          Run identifier.
	 * @param   int    $action_sequence Expected lifecycle action sequence.
	 *
	 * @return  void
	 */
	public function handle_deliver_action( string $identity, string $run_id, int $action_sequence ): void {
		$run_store = $this->stores->raw_run_store( $identity );
		$claimed   = $this->terminal_transitions->claim_delivery_ownership( $this->handlers, $identity, $run_id, $action_sequence, $run_store );
		if ( null === $claimed ) {
			return;
		}

		$claimed->handler->deliver( $claimed->identity, $run_id, $claimed->state, $this->stores->run_store( $claimed->identity ) );
	}

	/**
	 * Registers the single internal lifecycle action.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function register_hooks(): void {
		\add_action( self::DELIVER_HOOK, array( $this, 'handle_deliver_action' ), 10, 3 );
	}

	// endregion
}
