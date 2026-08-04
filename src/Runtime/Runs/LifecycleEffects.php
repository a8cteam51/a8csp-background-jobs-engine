<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Executes client lifecycle effects and durable claimed-transition effects.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class LifecycleEffects {
	// region FIELDS AND CONSTANTS

	/**
	 * Literal client lifecycle hooks keep their names greppable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, string>
	 */
	private const array LIFECYCLE_HOOKS = array(
		'started'         => 'a8csp_bgje/started',
		'retry_scheduled' => 'a8csp_bgje/retry_scheduled',
		'completed'       => 'a8csp_bgje/completed',
		'failed'          => 'a8csp_bgje/failed',
		'cancelled'       => 'a8csp_bgje/cancelled',
		'superseded'      => 'a8csp_bgje/superseded',
	);

	/**
	 * Required durable effects in their client-observable execution order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, list<string>>
	 */
	private const array TERMINAL_EFFECTS = array(
		'failed'     => array( 'retention', 'hooks', 'history' ),
		'completed'  => array( 'hooks', 'history' ),
		'cancelled'  => array( 'hooks', 'history' ),
		'superseded' => array( 'hooks', 'history' ),
	);

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OverlapGuard    $overlap_guard Execution-overlap guard.
	 * @param   StoreFactory    $stores        Name-bound store factory.
	 * @param   LoggerInterface $logger        Log event sink.
	 */
	public function __construct(
		private OverlapGuard $overlap_guard,
		private StoreFactory $stores,
		private LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns the required durable effects for one terminal status.
	 *
	 * @internal Engine terminalization and maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunStatus $status Terminal run status.
	 *
	 * @throws  \InvalidArgumentException When the status is not terminal.
	 *
	 * @return  list<string>
	 */
	public static function expected_effects( RunStatus $status ): array {
		$effects = self::TERMINAL_EFFECTS[ $status->value ] ?? null;
		if ( null === $effects ) {
			throw new \InvalidArgumentException( 'Terminal effects require a terminal status.' );
		}

		return $effects;
	}

	/**
	 * Fires the started lifecycle hooks for one admitted run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity                $identity   Complete scope-qualified job or chunked job identity.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 *
	 * @return  void
	 */
	public function fire_started( Identity $identity, string $run_id, array $start_args ): void {
		$this->fire_lifecycle_hooks( 'started', $identity, $run_id, $start_args );
	}

	/**
	 * Fires the retry-scheduled lifecycle hooks after retry state persists.
	 *
	 * The identity-specific hook precedes its generic companion and the retry action scheduling write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity                $identity   Complete scope-qualified job or chunked job identity.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   int                     $attempt    One-indexed number of the failed attempt.
	 * @param   int                     $delay      Delay before the next attempt in seconds.
	 *
	 * @return  void
	 */
	public function fire_retry_scheduled( Identity $identity, string $run_id, array $start_args, int $attempt, int $delay ): void {
		$this->fire_lifecycle_hooks( 'retry_scheduled', $identity, $run_id, $start_args, attempt: $attempt, delay: $delay );
	}

	/**
	 * Finishes an already-claimed terminal transition only after every required effect is marked.
	 *
	 * @internal Engine terminalization and maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity     Complete scope-qualified job or chunked job identity.
	 * @param   string   $run_id       Run identifier.
	 * @param   RunState $state        Terminalizing run state.
	 * @param   string   $terminal_raw Exact terminal snapshot bytes.
	 * @param   RunStore $run_store    Active-run store.
	 *
	 * @return  bool Whether the run option is confirmed absent.
	 */
	public function finish_claimed_transition( Identity $identity, string $run_id, RunState $state, string $terminal_raw, RunStore $run_store ): bool {
		$this->overlap_guard->release( $identity, $state->args_hash, $run_id );
		if ( array() !== \array_values( \array_diff( self::expected_effects( $state->status ), $state->effects ) ) ) {
			return false;
		}

		if ( $run_store->delete_exact( $run_id, $terminal_raw ) ) {
			return true;
		}

		$inspected = $run_store->inspect( $run_id );
		if ( ! $inspected->is_failure() ) {
			$snapshot = $inspected->value;
			if ( null === $snapshot ) {
				return true;
			}
			if ( $terminal_raw !== $snapshot['raw'] ) {
				return false;
			}
		}

		$this->logger->error(
			'Terminal run option could not be deleted; repair WordPress option writes before cleanup retries.',
			array(
				'identity' => (string) $identity,
				'run_id'   => $run_id,
				'status'   => $state->status->value,
			)
		);

		return false;
	}

	/**
	 * Executes and marks every missing effect before attempting terminal cleanup.
	 *
	 * @internal Engine terminalization and maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{error: EngineError, failure: RunFailure}|null $failure_detail
	 *
	 * @param   Identity   $identity       Complete scope-qualified job or chunked job identity.
	 * @param   string     $run_id         Run identifier.
	 * @param   RunState   $state          Terminal run state.
	 * @param   string     $terminal_raw   Exact terminal snapshot bytes.
	 * @param   RunStore   $run_store      Active-run store.
	 * @param   array|null $failure_detail Reconstructed internal and client failure detail.
	 *
	 * @throws  \Throwable When an effect fails; a trustworthy refreshed snapshot permits the remaining effects and gated finish before rethrow, while a failed refresh causes an immediate rethrow.
	 *
	 * @return  bool Whether the run option is confirmed absent.
	 */
	public function execute_claimed_transition( Identity $identity, string $run_id, RunState $state, string $terminal_raw, RunStore $run_store, ?array $failure_detail = null ): bool {
		$expected       = self::expected_effects( $state->status );
		$snapshot       = array(
			'raw'   => $terminal_raw,
			'state' => $state,
		);
		$effect_failure = null;

		foreach ( $expected as $effect ) {
			$current = $snapshot['state'];
			if ( \in_array( $effect, $current->effects, true ) ) {
				continue;
			}

			try {
				$landed = $this->execute_terminal_effect( $effect, $identity, $run_id, $current, $failure_detail );
			} catch ( \Throwable $throwable ) {
				$effect_failure ??= $throwable;
				$refreshed        = $this->refresh_terminal_snapshot( $run_id, $state->status, $run_store );
				if ( $refreshed instanceof TerminalSnapshotRefreshOutcome ) {
					throw $effect_failure;
				}

				$snapshot = $refreshed;
				continue;
			}

			if ( ! $landed ) {
				$refreshed = $this->refresh_terminal_snapshot( $run_id, $state->status, $run_store );
				if ( $refreshed instanceof TerminalSnapshotRefreshOutcome ) {
					if ( null !== $effect_failure ) {
						throw $effect_failure;
					}

					return TerminalSnapshotRefreshOutcome::AlreadyFinished === $refreshed;
				}

				$snapshot = $refreshed;
				continue;
			}

			$updated = $run_store->append_terminal_effect( $run_id, $current, $snapshot['raw'], $effect );
			if ( $updated instanceof Failure ) {
				$this->logger->error(
					$updated->error->message,
					array(
						'identity'     => (string) $identity,
						'run_id'       => $run_id,
						'status'       => $current->status->value,
						'effect'       => $effect,
						'error_class'  => $updated->error::class,
						'error_reason' => $updated->error->reason?->value,
					)
				);
				if ( null !== $effect_failure ) {
					throw $effect_failure;
				}

				return false;
			}
			if ( null === $updated ) {
				if ( null !== $effect_failure ) {
					throw $effect_failure;
				}

				return false;
			}

			$snapshot = $updated;
		}

		$finished = $this->finish_claimed_transition( $identity, $run_id, $snapshot['state'], $snapshot['raw'], $run_store );
		if ( null !== $effect_failure ) {
			throw $effect_failure;
		}

		return $finished;
	}

	/**
	 * Reconstructs persisted internal and client terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<array-key, mixed>|null $fallback_details
	 *
	 * @param   Identity   $identity         Complete scope-qualified job or chunked job identity.
	 * @param   string     $run_id           Run identifier.
	 * @param   RunState   $state            Failed terminal state.
	 * @param   array|null $fallback_details Kind-specific detail available when persisted detail is missing.
	 *
	 * @return  array{error: EngineError, failure: RunFailure}
	 */
	public function resolve_failure_detail( Identity $identity, string $run_id, RunState $state, ?array $fallback_details ): array {
		if ( null !== $state->error ) {
			$error = new EngineError( $state->error['message'], $state->error['class'] );

			// Store reads reject malformed stages before replay, while grammar-valid extension stages remain opaque.
			return array(
				'error'   => $error,
				'failure' => new RunFailure( identity: (string) $identity, run_id: RunId::from( $run_id ), attempts: \max( 1, $state->failed_attempts ), stage: RunFailureStage::from( $state->error['stage'] ), code: ErrorCode::from( $state->error['code'] ), summary: $error->message, details: $state->error['details'] ?? null, ),
			);
		}

		$this->logger->warning(
			'Failed terminal run has no persisted failure detail; replay uses a generic failure.',
			array(
				'identity' => (string) $identity,
				'run_id'   => $run_id,
			)
		);

		$error = new EngineError( \sprintf( 'Run "%1$s" for background-work "%2$s" failed before recoverable terminal detail was persisted.', $run_id, (string) $identity ) );

		return array(
			'error'   => $error,
			'failure' => new RunFailure( identity: (string) $identity, run_id: RunId::from( $run_id ), attempts: RunState::increment_attempts_safely( $state->failed_attempts ), stage: RunFailureStage::crash_reclamation(), code: ErrorCode::StorageFailed, summary: $error->message, details: $fallback_details, ),
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Re-reads terminal progress before a worker continues after an effect did not land.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string    $run_id    Run identifier.
	 * @param   RunStatus $status    Claimed terminal status.
	 * @param   RunStore  $run_store Active-run store.
	 *
	 * @return  array{raw: string, state: RunState}|TerminalSnapshotRefreshOutcome Trustworthy snapshot or exceptional refresh classification.
	 */
	private function refresh_terminal_snapshot( string $run_id, RunStatus $status, RunStore $run_store ): array|TerminalSnapshotRefreshOutcome {
		$inspected = $run_store->inspect( $run_id );
		if ( $inspected->is_failure() ) {
			return TerminalSnapshotRefreshOutcome::Untrusted;
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot ) {
			return TerminalSnapshotRefreshOutcome::AlreadyFinished;
		}

		$state = $snapshot['state'];
		if ( null === $state || $status !== $state->status ) {
			return TerminalSnapshotRefreshOutcome::Untrusted;
		}

		return array(
			'raw'   => $snapshot['raw'],
			'state' => $state,
		);
	}

	/**
	 * Executes one terminal effect and reports whether its durable outcome landed.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{error: EngineError, failure: RunFailure}|null $failure_detail
	 *
	 * @param   string     $effect         Terminal effect key.
	 * @param   Identity   $identity       Complete scope-qualified job or chunked job identity.
	 * @param   string     $run_id         Run identifier.
	 * @param   RunState   $state          Current terminal state.
	 * @param   array|null $failure_detail Reconstructed internal and client failure detail.
	 *
	 * @throws  \LogicException When the effect table contains an unsupported key.
	 * @throws  \Throwable      When an effect cannot complete.
	 *
	 * @return  bool Whether the effect landed and may be marked complete.
	 */
	private function execute_terminal_effect( string $effect, Identity $identity, string $run_id, RunState $state, ?array $failure_detail ): bool {
		return match ( $effect ) {
			'retention' => $this->record_failed_run( $identity, $run_id, $state, $failure_detail ),
			'hooks'     => $this->fire_terminal_hooks( $identity, $run_id, $state, $failure_detail['failure'] ?? null ),
			'history'   => $this->record_terminal_history( $identity, $run_id, $state ),
			default     => throw new \LogicException( 'The terminal effect table contains an unsupported effect key.' ),
		};
	}

	/**
	 * Persists one idempotent manual-retry entry for a failed run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{error: EngineError, failure: RunFailure}|null $failure_detail
	 *
	 * @param   Identity   $identity       Complete scope-qualified job or chunked job identity.
	 * @param   string     $run_id         Run identifier.
	 * @param   RunState   $state          Failed terminal state.
	 * @param   array|null $failure_detail Reconstructed internal and client failure detail.
	 *
	 * @throws  \LogicException When failure detail is absent.
	 *
	 * @return  bool Whether the failed-run entry is confirmed persisted.
	 */
	private function record_failed_run( Identity $identity, string $run_id, RunState $state, ?array $failure_detail ): bool {
		if ( null === $failure_detail ) {
			throw new \LogicException( 'Failed-run retention requires persisted terminal failure detail.' );
		}

		$retained = $this->stores->failed_run_store( $identity )->record( $run_id, $state->kind, $state->heartbeat_at, $state->start_args, $state->priority, $failure_detail['failure']->attempts, $failure_detail['error'], $failure_detail['failure'] );
		if ( $retained ) {
			return true;
		}

		$this->logger->warning(
			\sprintf( 'Failed run "%s" could not be retained for manual retry.', $run_id ),
			array(
				'identity' => (string) $identity,
				'run_id'   => $run_id,
				'kind'     => $state->kind,
			)
		);

		return false;
	}

	/**
	 * Fires the lifecycle hook sequence for one terminal state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity        $identity Complete scope-qualified job or chunked job identity.
	 * @param   string          $run_id   Run identifier.
	 * @param   RunState        $state    Terminal run state.
	 * @param   RunFailure|null $failure  Reconstructed client failure value.
	 *
	 * @throws  \LogicException When the state is not terminal.
	 * @throws  \Throwable      When a lifecycle hook fails.
	 *
	 * @return  true
	 */
	private function fire_terminal_hooks( Identity $identity, string $run_id, RunState $state, ?RunFailure $failure ): bool {
		$event = match ( $state->status ) {
			RunStatus::Completed  => 'completed',
			RunStatus::Failed     => 'failed',
			RunStatus::Cancelled  => 'cancelled',
			RunStatus::Superseded => 'superseded',
			RunStatus::Running    => throw new \LogicException( 'Terminal hooks require a terminal run state.' ),
		};
		$this->fire_lifecycle_hooks( $event, $identity, $run_id, $state->start_args, $failure, $state->previous_completed_run_id );

		return true;
	}

	/**
	 * Persists one idempotent terminal-history entry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified job or chunked job identity.
	 * @param   string   $run_id   Run identifier.
	 * @param   RunState $state    Terminal run state.
	 *
	 * @return  bool Whether the history entry is confirmed persisted.
	 */
	private function record_terminal_history( Identity $identity, string $run_id, RunState $state ): bool {
		if ( $this->stores->run_history( $identity )->record_terminal( $run_id, $state->args_hash, $state->status ) ) {
			return true;
		}

		$this->logger->warning(
			'Terminal run history could not be persisted; inspection data may be incomplete.',
			array(
				'identity' => (string) $identity,
				'run_id'   => $run_id,
			)
		);

		return false;
	}

	/**
	 * Fires the lifecycle hook sequence for one event.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param 'started'|'retry_scheduled'|'completed'|'failed'|'cancelled'|'superseded' $event
	 *
	 * @param   string                  $event                     Lifecycle event name.
	 * @param   Identity                $identity                  Complete scope-qualified job or chunked job identity.
	 * @param   string                  $run_id                    Run identifier.
	 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
	 * @param   RunFailure|null         $failure                   Failure detail for a failed event.
	 * @param   string|null             $previous_completed_run_id Previous completed run identifier for a completed event, or null.
	 * @param   int|null                $attempt                   One-indexed retry attempt, or null for another event.
	 * @param   int|null                $delay                     Retry delay in seconds, or null for another event.
	 *
	 * @throws  \LogicException When retry-scheduled metadata is absent.
	 *
	 * @return  void
	 */
	private function fire_lifecycle_hooks( string $event, Identity $identity, string $run_id, array $start_args, ?RunFailure $failure = null, ?string $previous_completed_run_id = null, ?int $attempt = null, ?int $delay = null ): void {
		$hook                             = self::LIFECYCLE_HOOKS[ $event ];
		$wire_identity                    = (string) $identity;
		$public_run_id                    = RunId::from( $run_id );
		$public_previous_completed_run_id = null === $previous_completed_run_id ? null : RunId::from( $previous_completed_run_id );
		if ( 'failed' !== $event ) {
			$start_args = PortableArguments::without_references( $start_args );
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Map values are full prefixed lifecycle hook literals.
		if ( 'completed' === $event ) {
			try {
				/**
				 * Fires when a work run completes.
				 *
				 * The dynamic portion of the hook name, `$identity`, refers to the scope-qualified work identity.
				 *
				 * @since   1.0.0
				 * @version 1.0.0
				 *
				 * @param   RunId                   $run_id                    Run identifier.
				 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
				 * @param   RunId|null              $previous_completed_run_id Previous completed run identifier for this identity, or null.
				 */
				\do_action( $hook . '/' . $wire_identity, $public_run_id, $start_args, $public_previous_completed_run_id );
			} finally {
				/**
				 * Fires after the identity-specific completed lifecycle hook.
				 *
				 * @since   1.0.0
				 * @version 1.0.0
				 *
				 * @param   string                  $identity                  Complete scope-qualified job or chunked job identity.
				 * @param   RunId                   $run_id                    Run identifier.
				 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
				 * @param   RunId|null              $previous_completed_run_id Previous completed run identifier for this identity, or null.
				 */
				\do_action( $hook, $wire_identity, $public_run_id, $start_args, $public_previous_completed_run_id );
			}

			return;
		}

		if ( 'retry_scheduled' === $event ) {
			if ( null === $attempt || null === $delay ) {
				throw new \LogicException( 'Retry-scheduled hooks require attempt and delay values.' );
			}

			try {
				/**
				 * Fires after retry state is persisted for one failed work attempt.
				 *
				 * The dynamic portion of the hook name, `$identity`, refers to the scope-qualified work identity.
				 *
				 * @since   1.0.0
				 * @version 1.0.0
				 *
				 * @param   RunId                   $run_id     Run identifier.
				 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
				 * @param   int                     $attempt    One-indexed number of the failed attempt.
				 * @param   int                     $delay      Delay before the next attempt in seconds.
				 */
				\do_action( $hook . '/' . $wire_identity, $public_run_id, $start_args, $attempt, $delay );
			} finally {
				/**
				 * Fires after the identity-specific retry-scheduled hook.
				 *
				 * @since   1.0.0
				 * @version 1.0.0
				 *
				 * @param   string                  $identity   Complete scope-qualified job or chunked job identity.
				 * @param   RunId                   $run_id     Run identifier.
				 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
				 * @param   int                     $attempt    One-indexed number of the failed attempt.
				 * @param   int                     $delay      Delay before the next attempt in seconds.
				 */
				\do_action( $hook, $wire_identity, $public_run_id, $start_args, $attempt, $delay );
			}

			return;
		}

		if ( null === $failure ) {
			try {
				/**
				 * Fires when a work run starts, is cancelled, or is superseded.
				 *
				 * The dynamic portion of the hook name, `$hook`, refers to the `started`, `cancelled`, or
				 * `superseded` lifecycle event.
				 * The dynamic portion of the hook name, `$identity`, refers to the scope-qualified work identity.
				 *
				 * @since   1.0.0
				 * @version 1.0.0
				 *
				 * @param   RunId                   $run_id     Run identifier.
				 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
				 */
				\do_action( $hook . '/' . $wire_identity, $public_run_id, $start_args );
			} finally {
				/**
				 * Fires after the identity-specific started, cancelled, or superseded lifecycle hook.
				 *
				 * The dynamic portion of the hook name, `$hook`, refers to the `started`, `cancelled`, or
				 * `superseded` lifecycle event.
				 *
				 * @since   1.0.0
				 * @version 1.0.0
				 *
				 * @param   string                  $identity   Complete scope-qualified job or chunked job identity.
				 * @param   RunId                   $run_id     Run identifier.
				 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
				 */
				\do_action( $hook, $wire_identity, $public_run_id, $start_args );
			}

			return;
		}

		try {
			/**
			 * Fires when one work identity's run fails.
			 *
			 * The dynamic portion of the hook name, `$identity`, refers to the scope-qualified work identity.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   RunFailure $failure Reconstructed client failure value.
			 */
			\do_action( $hook . '/' . $wire_identity, $failure );
		} finally {
			/**
			 * Fires after the identity-specific failed lifecycle hook.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   RunFailure $failure Reconstructed client failure value.
			 */
			\do_action( $hook, $failure );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	// endregion
}
