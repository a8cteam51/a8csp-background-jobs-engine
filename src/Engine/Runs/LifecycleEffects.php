<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\StoreFactory;
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
		'started'    => 'a8csp_jobs_engine/started',
		'completed'  => 'a8csp_jobs_engine/completed',
		'failed'     => 'a8csp_jobs_engine/failed',
		'cancelled'  => 'a8csp_jobs_engine/cancelled',
		'superseded' => 'a8csp_jobs_engine/superseded',
	);

	/**
	 * Required durable effects in their client-observable execution order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, array<'Job'|'ChunkedJob', list<string>>>
	 */
	private const array TERMINAL_EFFECTS = array(
		'failed'     => array(
			JobType::ChunkedJob->value => array( 'retention', 'callbacks', 'hooks', 'history' ),
			JobType::Job->value        => array( 'retention', 'callbacks', 'hooks', 'history' ),
		),
		'completed'  => array(
			JobType::ChunkedJob->value => array( 'callbacks', 'hooks', 'history' ),
			JobType::Job->value        => array( 'callbacks', 'hooks', 'history' ),
		),
		'cancelled'  => array(
			JobType::ChunkedJob->value => array( 'hooks', 'history' ),
			JobType::Job->value        => array( 'hooks', 'history' ),
		),
		'superseded' => array(
			JobType::ChunkedJob->value => array( 'hooks', 'history' ),
			JobType::Job->value        => array( 'hooks', 'history' ),
		),
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
	 * Returns the required durable effects for one terminal work kind.
	 *
	 * @internal Engine terminalization and maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunStatus $status    Terminal run status.
	 * @param   JobType   $work_type Work contract type.
	 *
	 * @throws  \InvalidArgumentException When the status is not terminal or the work type is invalid.
	 *
	 * @return  list<string>
	 */
	public static function expected_effects( RunStatus $status, JobType $work_type ): array {
		$effects = self::TERMINAL_EFFECTS[ $status->value ][ $work_type->value ] ?? null;
		if ( null === $effects ) {
			throw new \InvalidArgumentException( 'Terminal effects require a terminal status and a Job or Chunked Job work type.' );
		}

		return $effects;
	}

	/**
	 * Fires the started lifecycle hooks for one admitted run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $identity   Complete owner-qualified job or chunked job identity.
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 *
	 * @return  void
	 */
	public function fire_started( string $identity, string $run_id, array $start_args ): void {
		$this->fire_lifecycle_hooks( 'started', $identity, $run_id, $start_args );
	}

	/**
	 * Finishes an already-claimed terminal transition only after every required effect is marked.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $identity     Complete owner-qualified job or chunked job identity.
	 * @param   string   $run_id       Run identifier.
	 * @param   RunState $state        Terminalizing run state.
	 * @param   string   $terminal_raw Exact terminal snapshot bytes.
	 * @param   RunStore $run_store    Active-run store.
	 * @param   JobType  $work_type    Work contract type.
	 *
	 * @return  bool Whether the run option is confirmed absent.
	 */
	public function finish_claimed_transition( string $identity, string $run_id, RunState $state, string $terminal_raw, RunStore $run_store, JobType $work_type ): bool {
		return $this->finish_terminal_run( $identity, $run_id, $state, $terminal_raw, $run_store, $work_type );
	}

	/**
	 * Replays missing effects for one already-claimed terminal transition and attempts its finish.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string            $identity        Complete owner-qualified job or chunked job identity.
	 * @param   string            $run_id          Run identifier.
	 * @param   RunState          $state           Terminal run state.
	 * @param   string            $terminal_raw    Exact terminal snapshot bytes.
	 * @param   RunStore          $run_store       Active-run store.
	 * @param   JobType           $work_type       Resolved work contract type.
	 * @param   JobInterface|null $callback_target Resolved callback target, or null when unavailable.
	 *
	 * @return  bool Whether the run option is confirmed absent.
	 */
	public function replay_terminal_run( string $identity, string $run_id, RunState $state, string $terminal_raw, RunStore $run_store, JobType $work_type, ?JobInterface $callback_target = null ): bool {
		return $this->execute_claimed_transition( $identity, $run_id, $state, $terminal_raw, $run_store, $work_type, $callback_target );
	}

	/**
	 * Executes and marks every missing effect before attempting terminal cleanup.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string            $identity        Complete owner-qualified job or chunked job identity.
	 * @param   string            $run_id          Run identifier.
	 * @param   RunState          $state           Terminal run state.
	 * @param   string            $terminal_raw    Exact terminal snapshot bytes.
	 * @param   RunStore          $run_store       Active-run store.
	 * @param   JobType           $work_type       Work contract type.
	 * @param   JobInterface|null $callback_target Resolved callback target, or null when unavailable.
	 *
	 * @throws  \Throwable When an effect fails; a trustworthy refreshed snapshot permits the remaining effects and gated finish before rethrow, while a failed refresh causes an immediate rethrow.
	 *
	 * @return  bool Whether the run option is confirmed absent.
	 */
	public function execute_claimed_transition( string $identity, string $run_id, RunState $state, string $terminal_raw, RunStore $run_store, JobType $work_type, ?JobInterface $callback_target = null ): bool {
		$expected       = self::expected_effects( $state->status, $work_type );
		$missing        = \array_values( \array_diff( $expected, $state->effects ) );
		$failure_detail = RunStatus::Failed === $state->status && array() !== \array_intersect( array( 'retention', 'callbacks', 'hooks' ), $missing )
			? $this->failure_detail( $identity, $run_id, $state, $work_type )
			: null;
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
				$landed = $this->execute_terminal_effect( $effect, $identity, $run_id, $current, $work_type, $callback_target, $failure_detail );
			} catch ( \Throwable $throwable ) {
				$effect_failure ??= $throwable;
				$refreshed        = $this->refresh_terminal_snapshot( $run_id, $state->status, $run_store );
				if ( ! \is_array( $refreshed ) ) {
					throw $effect_failure;
				}

				$snapshot = $refreshed;
				continue;
			}

			if ( ! $landed ) {
				$refreshed = $this->refresh_terminal_snapshot( $run_id, $state->status, $run_store );
				if ( null === $refreshed ) {
					if ( null !== $effect_failure ) {
						throw $effect_failure;
					}

					return true;
				}
				if ( false === $refreshed ) {
					if ( null !== $effect_failure ) {
						throw $effect_failure;
					}

					return false;
				}

				$snapshot = $refreshed;
				continue;
			}

			$updated = $run_store->append_terminal_effect( $run_id, $current, $snapshot['raw'], $effect );
			if ( null === $updated ) {
				if ( null !== $effect_failure ) {
					throw $effect_failure;
				}

				return false;
			}

			$snapshot = $updated;
		}

		$finished = $this->finish_terminal_run( $identity, $run_id, $snapshot['state'], $snapshot['raw'], $run_store, $work_type );
		if ( null !== $effect_failure ) {
			throw $effect_failure;
		}

		return $finished;
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
	 * @return  array{raw: string, state: RunState}|false|null Current terminal snapshot, false when it cannot be trusted, or null when another worker finished it.
	 */
	private function refresh_terminal_snapshot( string $run_id, RunStatus $status, RunStore $run_store ): array|false|null {
		$inspected = $run_store->inspect( $run_id );
		if ( $inspected->is_failure() ) {
			return false;
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot ) {
			return null;
		}

		$state = $snapshot['state'];
		if ( null === $state || $status !== $state->status ) {
			return false;
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
	 * @param   string            $effect          Terminal effect key.
	 * @param   string            $identity        Complete owner-qualified job or chunked job identity.
	 * @param   string            $run_id          Run identifier.
	 * @param   RunState          $state           Current terminal state.
	 * @param   JobType           $work_type       Work contract type.
	 * @param   JobInterface|null $callback_target Resolved callback target, or null when unavailable.
	 * @param   array|null        $failure_detail  Reconstructed internal and client failure detail.
	 *
	 * @throws  \LogicException When the effect table contains an unsupported key.
	 * @throws  \Throwable      When an effect cannot complete.
	 *
	 * @return  bool Whether the effect landed and may be marked complete.
	 */
	private function execute_terminal_effect( string $effect, string $identity, string $run_id, RunState $state, JobType $work_type, ?JobInterface $callback_target, ?array $failure_detail ): bool {
		return match ( $effect ) {
			'retention' => $this->record_failed_run( $identity, $run_id, $state, $work_type, $failure_detail ),
			'callbacks' => $this->fire_terminal_callback( $identity, $run_id, $state, $callback_target, $failure_detail['failure'] ?? null ),
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
	 * @param   string     $identity       Complete owner-qualified job or chunked job identity.
	 * @param   string     $run_id         Run identifier.
	 * @param   RunState   $state          Failed terminal state.
	 * @param   JobType    $work_type      Work contract type.
	 * @param   array|null $failure_detail Reconstructed internal and client failure detail.
	 *
	 * @throws  \LogicException When failure detail is absent.
	 *
	 * @return  bool Whether the failed-run entry is confirmed persisted.
	 */
	private function record_failed_run( string $identity, string $run_id, RunState $state, JobType $work_type, ?array $failure_detail ): bool {
		if ( null === $failure_detail ) {
			throw new \LogicException( 'Failed-run retention requires persisted terminal failure detail.' );
		}

		$retained = $this->stores->failed_run_store( $identity )->record( $run_id, $state->heartbeat_at, $state->start_args, $failure_detail['failure']->attempts, $failure_detail['error'], $failure_detail['failure'] );
		if ( $retained ) {
			return true;
		}

		$context_name = $work_type->machine_key() . '_name';
		$this->logger->warning(
			\sprintf( 'Failed run "%s" could not be retained for manual retry.', $run_id ),
			array(
				$context_name => $identity,
				'run_id'      => $run_id,
			)
		);

		return false;
	}

	/**
	 * Fires one completed or failed callback with its established throwable policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string            $identity        Complete owner-qualified job or chunked job identity.
	 * @param   string            $run_id          Run identifier.
	 * @param   RunState          $state           Terminal run state.
	 * @param   JobInterface|null $callback_target Registered work, or null when the callback must be skipped.
	 * @param   RunFailure|null   $failure         Reconstructed client failure value.
	 *
	 * @throws  \LogicException When the state cannot support a terminal callback.
	 * @throws  \Throwable      When a failed callback fails.
	 *
	 * @return  true
	 */
	private function fire_terminal_callback( string $identity, string $run_id, RunState $state, ?JobInterface $callback_target, ?RunFailure $failure ): bool {
		$context_name = $state->kind->machine_key() . '_name';
		if ( null === $callback_target ) {
			$this->logger->warning(
				'Terminal callback was skipped because the work is not registered in this request.',
				array(
					$context_name => $identity,
					'run_id'      => $run_id,
					'status'      => $state->status->value,
				)
			);

			return true;
		}

		if ( RunStatus::Completed === $state->status ) {
			try {
				$callback_target->on_completed( $run_id, $state->start_args, $state->previous_completed_run_id );
			} catch ( \Throwable $throwable ) {
				$this->logger->error(
					'Terminal on_completed callback failed after the work completed; fix the callback.',
					array(
						$context_name => $identity,
						'run_id'      => $run_id,
						'exception'   => $throwable,
					)
				);
			}

			return true;
		}

		if ( RunStatus::Failed !== $state->status || null === $failure ) {
			throw new \LogicException( 'Terminal on_failed callbacks require a failed terminal state and failure detail.' );
		}

		$callback_target->on_failed( $run_id, $state->start_args, $failure );

		return true;
	}

	/**
	 * Fires the lifecycle hook sequence for one terminal state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string          $identity Complete owner-qualified job or chunked job identity.
	 * @param   string          $run_id   Run identifier.
	 * @param   RunState        $state    Terminal run state.
	 * @param   RunFailure|null $failure  Reconstructed client failure value.
	 *
	 * @throws  \LogicException When the state is not terminal.
	 * @throws  \Throwable      When a lifecycle hook fails.
	 *
	 * @return  true
	 */
	private function fire_terminal_hooks( string $identity, string $run_id, RunState $state, ?RunFailure $failure ): bool {
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
	 * @param   string   $identity Complete owner-qualified job or chunked job identity.
	 * @param   string   $run_id   Run identifier.
	 * @param   RunState $state    Terminal run state.
	 *
	 * @return  bool Whether the history entry is confirmed persisted.
	 */
	private function record_terminal_history( string $identity, string $run_id, RunState $state ): bool {
		if ( $this->stores->run_history( $identity )->record_terminal( $run_id, $state->args_hash, $state->status ) ) {
			return true;
		}

		$this->logger->warning(
			'Terminal run history could not be persisted; inspection data may be incomplete.',
			array(
				'name'   => $identity,
				'run_id' => $run_id,
			)
		);

		return false;
	}

	/**
	 * Reconstructs persisted internal and client terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $identity  Complete owner-qualified job or chunked job identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Failed terminal state.
	 * @param   JobType  $work_type Work contract type.
	 *
	 * @return  array{error: EngineError, failure: RunFailure}
	 */
	private function failure_detail( string $identity, string $run_id, RunState $state, JobType $work_type ): array {
		if ( null !== $state->error ) {
			$error = new EngineError( $state->error['message'], $state->error['class'] );

			// Terminal transitions encode enum-backed values, and store reads reject unknown values before replay.
			return array(
				'error'   => $error,
				'failure' => new RunFailure( identity: $identity, run_id: $run_id, attempts: \max( 1, $state->failed_attempts ), stage: RunFailureStage::from( $state->error['stage'] ), code: ErrorCode::from( $state->error['code'] ), summary: $error->message, failed_chunk: $state->error['failed_chunk'] ?? null, ),
			);
		}

		$this->logger->warning(
			'Failed terminal run has no persisted failure detail; replay uses a generic failure.',
			array(
				'name'   => $identity,
				'run_id' => $run_id,
			)
		);

		$error = new EngineError( \sprintf( 'Run "%1$s" for background-work "%2$s" failed before recoverable terminal detail was persisted.', $run_id, $identity ) );

		return array(
			'error'   => $error,
			'failure' => new RunFailure( identity: $identity, run_id: $run_id, attempts: RunState::increment_attempts_safely( $state->failed_attempts ), stage: RunFailureStage::CrashReclaim, code: ErrorCode::StorageFailure, summary: $error->message, failed_chunk: self::failed_chunk_for_state( $work_type, $state ), ),
		);
	}

	/**
	 * Returns the queued chunk associated with a chunk-processing continuation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobType  $work_type Work contract type.
	 * @param   RunState $state     Run state at terminalization.
	 *
	 * @return  array<array-key, mixed>|null
	 */
	private static function failed_chunk_for_state( JobType $work_type, RunState $state ): ?array {
		if ( JobType::ChunkedJob !== $work_type || 'continue' !== $state->pending?->stage ) {
			return null;
		}

		$chunk = $state->queue[0] ?? null;

		return \is_array( $chunk ) ? $chunk : null;
	}

	/**
	 * Releases owned overlap state and exact-deletes only a fully effected terminal row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $identity     Complete owner-qualified job or chunked job identity.
	 * @param   string   $run_id       Run identifier.
	 * @param   RunState $state        Terminal run state.
	 * @param   string   $terminal_raw Exact terminal snapshot bytes.
	 * @param   RunStore $run_store    Active-run store.
	 * @param   JobType  $work_type    Work contract type.
	 *
	 * @return  bool Whether the run option is confirmed absent.
	 */
	private function finish_terminal_run( string $identity, string $run_id, RunState $state, string $terminal_raw, RunStore $run_store, JobType $work_type ): bool {
		$this->overlap_guard->release( $identity, $state->args_hash, $run_id );
		if ( array() !== \array_values( \array_diff( self::expected_effects( $state->status, $work_type ), $state->effects ) ) ) {
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
				'name'   => $identity,
				'run_id' => $run_id,
				'status' => $state->status->value,
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
	 * @phpstan-param 'started'|'completed'|'failed'|'cancelled'|'superseded' $event
	 *
	 * @param   string                  $event                     Lifecycle event name.
	 * @param   string                  $identity                  Complete owner-qualified job or chunked job identity.
	 * @param   string                  $run_id                    Run identifier.
	 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
	 * @param   RunFailure|null         $failure                   Failure detail for a failed event.
	 * @param   string|null             $previous_completed_run_id Previous completed run identifier for a completed event, or null.
	 *
	 * @return  void
	 */
	private function fire_lifecycle_hooks( string $event, string $identity, string $run_id, array $start_args, ?RunFailure $failure = null, ?string $previous_completed_run_id = null ): void {
		$hook = self::LIFECYCLE_HOOKS[ $event ];

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Map values are full prefixed lifecycle hook literals.
		if ( 'completed' === $event ) {
			try {
				/**
				 * Fires when a work run completes.
				 *
				 * The dynamic portion of the hook name, `$identity`, refers to the owner-qualified work identity.
				 *
				 * @since   1.0.0
				 * @version 1.0.0
				 *
				 * @param   string                  $run_id                    Run identifier.
				 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
				 * @param   string|null             $previous_completed_run_id Previous completed run identifier for this identity, or null.
				 */
				\do_action( $hook . '/' . $identity, $run_id, $start_args, $previous_completed_run_id );
			} finally {
				/**
				 * Fires after the identity-specific completed lifecycle hook.
				 *
				 * @since   1.0.0
				 * @version 1.0.0
				 *
				 * @param   string                  $identity                  Complete owner-qualified job or chunked job identity.
				 * @param   string                  $run_id                    Run identifier.
				 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
				 * @param   string|null             $previous_completed_run_id Previous completed run identifier for this identity, or null.
				 */
				\do_action( $hook, $identity, $run_id, $start_args, $previous_completed_run_id );
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
				 * The dynamic portion of the hook name, `$identity`, refers to the owner-qualified work identity.
				 *
				 * @since   1.0.0
				 * @version 1.0.0
				 *
				 * @param   string                  $run_id     Run identifier.
				 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
				 */
				\do_action( $hook . '/' . $identity, $run_id, $start_args );
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
				 * @param   string                  $identity   Complete owner-qualified job or chunked job identity.
				 * @param   string                  $run_id     Run identifier.
				 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
				 */
				\do_action( $hook, $identity, $run_id, $start_args );
			}

			return;
		}

		/**
		 * Fires when a work run fails.
		 *
		 * The failure value carries the owner-qualified identity and run identifier, so a consumer
		 * scoping to one work item filters on the failure's identity.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   RunFailure $failure Reconstructed client failure value.
		 */
		\do_action( $hook, $failure );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	// endregion
}
