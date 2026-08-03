<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use Psr\Log\LoggerInterface;

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

	/**
	 * Prefix length retained from scheduler-wire digests in operator diagnostics.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int WIRE_SHA256_LENGTH = 16;

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
	 * @param   array           $handlers             Kind handlers keyed by their persisted keys.
	 * @param   StoreFactory    $stores               Name-bound store factory.
	 * @param   RunTransitions  $terminal_transitions Fenced transition coordinator.
	 * @param   LoggerInterface $logger               Log event sink.
	 */
	public function __construct(
		private array $handlers,
		private StoreFactory $stores,
		private RunTransitions $terminal_transitions,
		private LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Resolves and executes one persisted lifecycle action.
	 *
	 * Persisted run state uses only canonical identities, so rejected identity bytes carry no
	 * downstream state that requires recovery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity        Scheduler-wire work identity.
	 * @param   string $run_id          Run identifier.
	 * @param   int    $action_sequence Expected lifecycle action sequence.
	 *
	 * @return  void
	 */
	public function handle_deliver_action( string $identity, string $run_id, int $action_sequence ): void {
		$canonical_identity = Identity::tryFrom( $identity );
		$canonical_run_id   = RunIdentity::parse( $run_id );
		if ( null === $canonical_identity || null === $canonical_run_id ) {
			$rejected_component = null === $canonical_identity ? ( null === $canonical_run_id ? 'identity and run identifier' : 'identity' ) : 'run identifier';

			// Canonical components stay clear for diagnosis; arbitrary rejected bytes stay bounded to length and digest.
			if ( null === $canonical_identity ) {
				$context = array(
					'identity_length' => \strlen( $identity ),
					'identity_sha256' => \substr( \hash( 'sha256', $identity ), 0, self::WIRE_SHA256_LENGTH ),
				);
			} else {
				$context = array( 'identity' => (string) $canonical_identity );
			}

			if ( null === $canonical_run_id ) {
				$context['run_id_length'] = \strlen( $run_id );
				$context['run_id_sha256'] = \substr( \hash( 'sha256', $run_id ), 0, self::WIRE_SHA256_LENGTH );
			} else {
				$context['run_id'] = $canonical_run_id;
			}

			$this->logger->warning(
				\sprintf( 'Background-work delivery carried a malformed %s and was dropped before storage access; correct the scheduler delivery arguments before retrying.', $rejected_component ),
				$context
			);

			return;
		}

		$run_store = $this->stores->run_store( $canonical_identity );
		$claimed   = $this->terminal_transitions->claim_delivery_ownership( $this->handlers, $canonical_identity, $canonical_run_id, $action_sequence, $run_store );
		if ( null === $claimed ) {
			return;
		}

		$claimed->handler->deliver( $claimed->identity, $canonical_run_id, $claimed->state, $this->stores->run_store( $claimed->identity ) );
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
