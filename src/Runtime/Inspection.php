<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds\KindHandlerInterface;
use Psr\Clock\ClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Projects validated persisted scheduling and run state for read-only inspection.
 *
 * @internal Engine inspection only.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-type ScheduleEntry array{
 *     owner: string,
 *     identity: string,
 *     recurrence: int|null,
 *     next_due: int,
 *     last_fired: int|null,
 *     misfire_skips: int,
 *     overlap_skips: int,
 *     occurrence_visible: bool,
 *     lock: array{state: 'free'|'invalid'|'not_declared'|'overlap_allowed'|'read_failed'|'resolver_failed'}
 *         |array{state: 'held', run_id: string, stale: bool}
 * }
 * @phpstan-type LiveRunEntry array{
 *     run_id: string,
 *     kind: string,
 *     status: 'running',
 *     executing: bool,
 *     attempts: int,
 *     queue_depth: int|null,
 *     queue_known: bool,
 *     heartbeat_at: int,
 *     stale: bool
 * }
 * @phpstan-type HistoryEntry array{
 *     run_id: string,
 *     outcome: 'completed'|'failed'|'cancelled'|'superseded'|'started',
 *     failed_store: bool
 * }
 */
final readonly class Inspection {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum authoritative live-run rows inspected for one command invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int LIVE_RUN_LIMIT = 20;

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
	 * @param   ScheduleRegistry $schedules        Persisted and request-local schedule state.
	 * @param   JobRegistry      $registry         Registered Job instances.
	 * @param   array            $handlers         Kind handlers keyed by their persisted keys.
	 * @param   SchedulerFacade  $scheduler        Union scheduling reads.
	 * @param   OverlapGuard     $guard            Persisted overlap-lock reads.
	 * @param   OverlapIdentity  $overlap_identity Stable single-flight identity resolver.
	 * @param   StoreFactory     $stores           Name-bound run stores.
	 * @param   OptionRows       $option_rows      Active-run option enumeration.
	 * @param   LockWindows      $lock_windows     Effective heartbeat staleness policy.
	 * @param   ClockInterface   $clock            Inspection timestamp source.
	 */
	public function __construct(
		private ScheduleRegistry $schedules,
		private JobRegistry $registry,
		private array $handlers,
		private SchedulerFacade $scheduler,
		private OverlapGuard $guard,
		private OverlapIdentity $overlap_identity,
		private StoreFactory $stores,
		private OptionRows $option_rows,
		private LockWindows $lock_windows,
		private ClockInterface $clock,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns one retained run's observable lifecycle status.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 * @param   string $run_id   Retained run identifier.
	 *
	 * @throws  \InvalidArgumentException When the run identifier is malformed.
	 *
	 * @return  AbstractResult<RunStatus|null, EngineError>
	 */
	#[\NoDiscard( 'a run-status inspection result must be handled, not dropped' )]
	public function run_status( string $identity, string $run_id ): AbstractResult {
		if ( null === RunIdentity::parse( $run_id ) ) {
			throw new \InvalidArgumentException( 'Run identifier is malformed; pass a run ID the engine returned.' );
		}

		$inspected = $this->stores->run_store( $identity )->inspect( $run_id );
		if ( $inspected->is_failure() ) {
			return new Failure( new EngineError( 'Authoritative option-row read failed; repair WordPress option reads and retry.', reason: EngineErrorReason::StorageFailure, context: array( 'option_name' => RunIdentity::option_name( $identity, $run_id ) ), ) );
		}

		$snapshot = $inspected->value;
		if ( null !== $snapshot && null !== $snapshot['state'] ) {
			return new Success( $snapshot['state']->status );
		}

		$entries = $this->stores->run_history( $identity )->terminal_entries();
		if ( null === $entries ) {
			return new Failure( new EngineError( 'Authoritative option-row read failed; repair WordPress option reads and retry.', reason: EngineErrorReason::StorageFailure, context: array( 'option_name' => RunHistory::OPTION_PREFIX . $identity ), ) );
		}

		foreach ( \array_reverse( $entries ) as $entry ) {
			if ( $run_id === $entry['run_id'] ) {
				return new Success( RunStatus::from( $entry['status'] ) );
			}
		}

		return new Success( null );
	}

	/**
	 * Returns the last completed run ID in the retained terminal recording order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  AbstractResult<string|null, EngineError>
	 */
	#[\NoDiscard( 'a last-completed-run inspection result must be handled, not dropped' )]
	public function last_completed_run_id( string $identity ): AbstractResult {
		$entries = $this->stores->run_history( $identity )->terminal_entries();
		if ( null === $entries ) {
			return new Failure( new EngineError( 'Authoritative option-row read failed; repair WordPress option reads and retry.', reason: EngineErrorReason::StorageFailure, context: array( 'option_name' => RunHistory::OPTION_PREFIX . $identity ), ) );
		}

		return new Success( RunHistory::newest_completed_run_id( $entries ) );
	}

	/**
	 * Returns persisted schedule registrations with their currently observable live state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $owner Exact owner filter, or null for every owner.
	 *
	 * @phpstan-return array{observed_at: int, dormant_candidate: bool, entries: list<ScheduleEntry>}|null
	 *
	 * @return  array|null Null when authoritative schedule-registry inspection fails.
	 */
	public function schedules( ?string $owner = null ): ?array {
		$observed_at = $this->clock->now()->getTimestamp();
		$read        = $this->schedules->all_registrations();
		if ( $read->is_failure() ) {
			return null;
		}

		$registrations = $read->value;
		\ksort( $registrations, \SORT_STRING );

		$entries = array();
		foreach ( $registrations as $registration_key => $registration ) {
			$parts = JobIdentity::parts( $registration_key );
			if ( null === $parts ) {
				continue;
			}

			$registration_owner = $parts[0];
			if ( null !== $owner && $owner !== $registration_owner ) {
				continue;
			}

			$declaration = $this->schedules->declaration( $registration_key );
			$entries[]   = array(
				'owner'              => $registration_owner,
				'identity'           => $registration_key,
				'recurrence'         => null === $declaration ? null : $declaration['schedule']->recurrence->interval,
				'next_due'           => $registration['next_due'],
				'last_fired'         => $registration['last_fired'],
				'misfire_skips'      => $registration['misfire_skips'],
				'overlap_skips'      => $registration['overlap_skips'],
				'occurrence_visible' => $this->scheduler->is_scheduled( OccurrenceDelivery::SCHEDULE_HOOK, array( $registration_key ), $registration_key ),
				'lock'               => $this->schedule_lock( $declaration, $observed_at ),
			);
		}

		return array(
			'observed_at'       => $observed_at,
			'dormant_candidate' => $this->scheduler->has_dormant_candidate(),
			'entries'           => $entries,
		);
	}

	/**
	 * Returns whether one owner has a persisted schedule-registry row.
	 *
	 * @internal CLI inspection only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Exact client owner.
	 *
	 * @return  bool|null Null when the authoritative row read fails.
	 */
	public function schedule_owner_exists( string $owner ): ?bool {
		$read = $this->schedules->owner_exists( $owner );
		return $read->is_failure() ? null : $read->value;
	}

	/**
	 * Returns validated live runs and bounded recent history for one background-work identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @phpstan-return array{
	 *     observed_at: int,
	 *     live: list<LiveRunEntry>,
	 *     history: list<HistoryEntry>|null,
	 *     live_error: 'enumeration_failed'|'read_failed'|null,
	 *     live_scanned: int,
	 *     live_uninspected: int,
	 *     live_unreadable: int
	 * }
	 *
	 * @return  array
	 */
	public function runs( string $identity ): array {
		$observed_at     = $this->clock->now()->getTimestamp();
		$run_store       = $this->stores->run_store( $identity );
		$prefix          = RunIdentity::option_name_prefix( $identity );
		$live_unreadable = 0;
		$page            = $this->option_rows->option_names_page(
			$prefix,
			\strlen( $prefix ) + RunIdentity::LENGTH,
			self::LIVE_RUN_LIMIT,
			static function ( string $option_name ) use ( $identity, &$live_unreadable ): bool {
				$run_identity = RunIdentity::from_option_name( $option_name );
				if ( null === $run_identity ) {
					++$live_unreadable;
					return false;
				}

				return $identity === $run_identity['identity'];
			}
		);
		if ( null === $page ) {
			return array(
				'observed_at'      => $observed_at,
				'live'             => array(),
				'history'          => array(),
				'live_error'       => 'enumeration_failed',
				'live_scanned'     => 0,
				'live_uninspected' => 0,
				'live_unreadable'  => 0,
			);
		}

		$live = array();

		foreach ( $page['names'] as $option_name ) {
			$run_identity = RunIdentity::from_option_name( $option_name );
			if ( null === $run_identity || $identity !== $run_identity['identity'] ) {
				// Malformed names are counted where the page filter rejects them; accepted names cannot fail here.
				continue;
			}

			$run_id    = $run_identity['run_id'];
			$inspected = $run_store->inspect( $run_id );
			if ( $inspected->is_failure() ) {
				return array(
					'observed_at'      => $observed_at,
					'live'             => array(),
					'history'          => array(),
					'live_error'       => 'read_failed',
					'live_scanned'     => \count( $page['names'] ),
					'live_uninspected' => \max( 0, $page['total'] - \count( $page['names'] ) ),
					'live_unreadable'  => $live_unreadable,
				);
			}

			$snapshot = $inspected->value;
			if ( null === $snapshot ) {
				continue;
			}

			$state = $snapshot['state'];
			if ( null === $state ) {
				++$live_unreadable;
				continue;
			}
			if ( RunStatus::Running !== $state->status ) {
				continue;
			}

			$staleness = $this->lock_windows->lock_staleness( $identity, $run_id );
			$handler   = $this->handlers[ $state->kind ] ?? null;
			$live[]    = array(
				'run_id'       => $run_id,
				'kind'         => $state->kind,
				'status'       => 'running',
				'executing'    => $state->executing,
				'attempts'     => $state->failed_attempts,
				'queue_depth'  => $handler?->queue_depth( $state ),
				'queue_known'  => null !== $handler,
				'heartbeat_at' => $state->heartbeat_at,
				'stale'        => self::heartbeat_is_stale( $state->heartbeat_at, $observed_at, $staleness ),
			);
		}

		return array(
			'observed_at'      => $observed_at,
			'live'             => $live,
			'history'          => $this->history( $identity ),
			'live_error'       => null,
			'live_scanned'     => \count( $page['names'] ),
			'live_uninspected' => \max( 0, $page['total'] - \count( $page['names'] ) ),
			'live_unreadable'  => $live_unreadable,
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Returns a declared schedule's complete validated overlap-lock state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{schedule: Schedule, job: string}|null $declaration
	 *
	 * @param   array|null $declaration Current-request schedule declaration, when available.
	 * @param   int        $observed_at Inspection timestamp.
	 *
	 * @return  array{state: 'free'|'invalid'|'not_declared'|'overlap_allowed'|'read_failed'|'resolver_failed'}
	 *          |array{state: 'held', run_id: string, stale: bool}
	 */
	private function schedule_lock( ?array $declaration, int $observed_at ): array {
		if ( null === $declaration ) {
			return array( 'state' => 'not_declared' );
		}

		$schedule = $declaration['schedule'];
		$kind     = $this->registry->kind( $declaration['job'] );
		$handler  = null === $kind ? null : ( $this->handlers[ $kind ] ?? null );
		$options  = $handler?->options( $declaration['job'] );
		if ( null === $handler || null === $handler->execution( $declaration['job'] ) || null === $options ) {
			return array( 'state' => 'invalid' );
		}
		if ( OverlapPolicy::Allow === ( $options->overlap ?? OverlapPolicy::Reject ) ) {
			return array( 'state' => 'overlap_allowed' );
		}

		$args_hash = $this->overlap_identity->resolve( $kind, $declaration['job'], $options, $schedule->args );
		if ( $args_hash instanceof Failure ) {
			// The resolver reports a consumer overlap-key fault as ExecutionFailed and every other rejection as PayloadRejected, so that reason is what separates a broken resolver from an unusable declaration.
			return array( 'state' => EngineErrorReason::ExecutionFailed === $args_hash->error->reason ? 'resolver_failed' : 'invalid' );
		}
		$inspected = $this->guard->inspect_persisted_lock( $declaration['job'], $args_hash );
		if ( $inspected->is_failure() ) {
			return array( 'state' => 'read_failed' );
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot ) {
			return array( 'state' => 'free' );
		}

		$lock = $snapshot['lock'];
		if ( null === $lock ) {
			return array( 'state' => 'invalid' );
		}

		$staleness = $this->lock_windows->lock_staleness( $declaration['job'], $lock['run_id'] );

		return array(
			'state'  => 'held',
			'run_id' => $lock['run_id'],
			'stale'  => self::heartbeat_is_stale( $lock['heartbeat_at'], $observed_at, $staleness ),
		);
	}

	/**
	 * Merges bounded terminal and started buffers into deterministic newest-first rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified background-work identity.
	 *
	 * @phpstan-return list<HistoryEntry>|null
	 *
	 * @return  array|null Null when authoritative failed-run or history inspection fails.
	 */
	private function history( string $identity ): ?array {
		$history     = $this->stores->run_history( $identity );
		$failed_ids  = array();
		$entries     = array();
		$seen        = array();
		$failed_runs = $this->stores->failed_run_store( $identity )->all();
		if ( $failed_runs->is_failure() ) {
			return null;
		}

		foreach ( $failed_runs->value as $failed ) {
			$failed_ids[ $failed['run_id'] ] = true;
		}

		$terminal_entries = $history->terminal_entries();
		if ( null === $terminal_entries ) {
			return null;
		}

		foreach ( \array_reverse( $terminal_entries ) as $entry ) {
			if ( isset( $seen[ $entry['run_id'] ] ) ) {
				continue;
			}

			$seen[ $entry['run_id'] ] = true;
			$entries[]                = array(
				'run_id'       => $entry['run_id'],
				'outcome'      => $entry['status'],
				'failed_store' => isset( $failed_ids[ $entry['run_id'] ] ),
			);
		}

		$started_entries = $history->started_entries();
		if ( null === $started_entries ) {
			return null;
		}

		foreach ( \array_reverse( $started_entries ) as $run_id ) {
			if ( isset( $seen[ $run_id ] ) ) {
				continue;
			}

			$seen[ $run_id ] = true;
			$entries[]       = array(
				'run_id'       => $run_id,
				'outcome'      => 'started',
				'failed_store' => isset( $failed_ids[ $run_id ] ),
			);
		}

		return $entries;
	}

	/**
	 * Returns whether a heartbeat is strictly older than a resolved positive window.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $heartbeat_at Latest persisted heartbeat.
	 * @param   int $observed_at  Inspection timestamp.
	 * @param   int $staleness    Effective positive staleness window.
	 *
	 * @return  bool
	 */
	private static function heartbeat_is_stale( int $heartbeat_at, int $observed_at, int $staleness ): bool {
		return $observed_at > \PHP_INT_MIN + $staleness
			&& $heartbeat_at < $observed_at - $staleness;
	}

	// endregion
}
