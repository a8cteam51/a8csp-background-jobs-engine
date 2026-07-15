<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\BatchRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\EngineErrorReason;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;
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
 *     name: string,
 *     recurrence: int|null,
 *     next_due: int,
 *     last_fired: int|null,
 *     misfires: int,
 *     skips: int,
 *     scheduled: bool,
 *     lock: array{state: 'free'|'invalid'|'not_declared'|'overlap_allowed'|'read_failed'}
 *         |array{state: 'held', run_id: string, stale: bool}
 * }
 * @phpstan-type LiveRunEntry array{
 *     run_id: string,
 *     kind: 'batch'|'task'|'unknown',
 *     status: 'running',
 *     executing: bool,
 *     attempts: int,
 *     queue_depth: int|null,
 *     heartbeat_at: int,
 *     stale: bool
 * }
 * @phpstan-type HistoryEntry array{
 *     run_id: string,
 *     outcome: 'completed'|'failed'|'cancelled'|'superseded'|'started',
 *     retained: bool
 * }
 */
final readonly class Inspection {
	// region FIELDS AND CONSTANTS

	/**
	 * Prefix for active-run option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const RUN_OPTION_PREFIX = 'a8csp_bgte_run_';

	/**
	 * Maximum authoritative live-run rows inspected for one command invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const LIVE_RUN_LIMIT = 20;

	/**
	 * Fixed character length of the canonical timestamp-randomness run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const RUN_ID_LENGTH = 40;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ScheduleRegistry $schedules    Persisted and request-local schedule state.
	 * @param   TaskRegistry     $tasks        Request-local task registrations.
	 * @param   BatchRegistry    $batches      Request-local batch registrations.
	 * @param   SchedulerFacade  $scheduler    Union scheduling reads.
	 * @param   OverlapGuard     $guard        Persisted overlap-lock reads.
	 * @param   StoreFactory     $stores       Name-bound run stores.
	 * @param   OptionRows       $option_rows  Active-run option enumeration.
	 * @param   LockWindows      $lock_windows Effective heartbeat staleness policy.
	 * @param   ClockInterface   $clock        Inspection timestamp source.
	 */
	public function __construct(
		private ScheduleRegistry $schedules,
		private TaskRegistry $tasks,
		private BatchRegistry $batches,
		private SchedulerFacade $scheduler,
		private OverlapGuard $guard,
		private StoreFactory $stores,
		private OptionRows $option_rows,
		private LockWindows $lock_windows,
		private ClockInterface $clock,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns the last completed run in the retained terminal recording order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Complete owner-qualified task or batch identity.
	 *
	 * @return  AbstractResult<string|null, EngineError>
	 */
	#[\NoDiscard( 'a last-completed-run inspection result must be handled, not dropped' )]
	public function last_completed_run( string $name ): AbstractResult {
		$entries = $this->stores->run_history( $name )->terminal_entries();
		if ( null === $entries ) {
			return new Failure(
				new EngineError(
					'Authoritative option-row read failed; repair WordPress option reads and retry.',
					reason: EngineErrorReason::StorageFailure,
					context: array( 'option_name' => RunHistory::OPTION_PREFIX . $name ),
				)
			);
		}

		foreach ( \array_reverse( $entries ) as $entry ) {
			if ( RunStatus::Completed->value === $entry['status'] ) {
				return new Success( $entry['run_id'] );
			}
		}

		return new Success( null );
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
			$parts = WorkIdentity::parts( $registration_key );
			if ( null === $parts ) {
				continue;
			}

			$registration_owner = $parts[0];
			if ( null !== $owner && $owner !== $registration_owner ) {
				continue;
			}

			$declaration = $this->schedules->get( $registration_key );
			$entries[]   = array(
				'owner'      => $registration_owner,
				'name'       => $registration_key,
				'recurrence' => null === $declaration ? null : $declaration['schedule']->recurrence->interval(),
				'next_due'   => $registration['next_due'],
				'last_fired' => $registration['last_fired'],
				'misfires'   => $registration['misfires'],
				'skips'      => $registration['skips'],
				'scheduled'  => $this->scheduler->is_scheduled(
					OccurrenceDelivery::SCHEDULE_HOOK,
					array( $registration_key ),
					$registration_key
				),
				'lock'       => $this->schedule_lock( $declaration, $observed_at ),
			);
		}

		return array(
			'observed_at'       => $observed_at,
			'dormant_candidate' => $this->scheduler->has_dormant_candidate(),
			'entries'           => $entries,
		);
	}

	/**
	 * Returns validated live runs and bounded recent history for one background-work identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Complete owner-qualified task or batch identity.
	 *
	 * @phpstan-return array{
	 *     observed_at: int,
	 *     live: list<LiveRunEntry>,
	 *     history: list<HistoryEntry>|null,
	 *     live_error: 'enumeration_failed'|'read_failed'|null,
	 *     live_scanned: int,
	 *     live_uninspected: int
	 * }
	 *
	 * @return  array
	 */
	public function runs( string $name ): array {
		$observed_at = $this->clock->now()->getTimestamp();
		$run_store   = $this->stores->run_store( $name );
		$prefix      = self::RUN_OPTION_PREFIX . $name . '_';
		$page        = $this->option_rows->option_names_page(
			$prefix,
			\strlen( $prefix ) + self::RUN_ID_LENGTH,
			self::LIVE_RUN_LIMIT,
			static function ( string $option_name ) use ( $name ): bool {
				$identity = self::run_identity_from_option_name( $option_name );

				return null !== $identity && $name === $identity['name'];
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
			);
		}

		$kind = $this->work_kind( $name );
		$live = array();

		foreach ( $page['names'] as $option_name ) {
			$identity = self::run_identity_from_option_name( $option_name );
			if ( null === $identity || $name !== $identity['name'] ) {
				continue;
			}

			$run_id    = $identity['run_id'];
			$inspected = $run_store->inspect( $run_id );
			if ( $inspected->is_failure() ) {
				return array(
					'observed_at'      => $observed_at,
					'live'             => array(),
					'history'          => array(),
					'live_error'       => 'read_failed',
					'live_scanned'     => \count( $page['names'] ),
					'live_uninspected' => \max( 0, $page['total'] - \count( $page['names'] ) ),
				);
			}

			$snapshot = $inspected->value;
			$state    = $snapshot['state'] ?? null;
			if ( null === $state || RunStatus::Running !== $state->status ) {
				continue;
			}

			$staleness = $this->lock_windows->lock_staleness( $name, $run_id );
			$live[]    = array(
				'run_id'       => $run_id,
				'kind'         => $kind,
				'status'       => 'running',
				'executing'    => $state->executing,
				'attempts'     => $state->chunk_retries,
				'queue_depth'  => 'task' === $kind ? null : \count( $state->queue ),
				'heartbeat_at' => $state->heartbeat_at,
				'stale'        => self::heartbeat_is_stale( $state->heartbeat_at, $observed_at, $staleness ),
			);
		}

		return array(
			'observed_at'      => $observed_at,
			'live'             => $live,
			'history'          => $this->history( $name ),
			'live_error'       => null,
			'live_scanned'     => \count( $page['names'] ),
			'live_uninspected' => \max( 0, $page['total'] - \count( $page['names'] ) ),
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
	 * @phpstan-param array{schedule: Schedule, task: string}|null $declaration
	 *
	 * @param   array|null $declaration Current-request schedule declaration, when available.
	 * @param   int        $observed_at Inspection timestamp.
	 *
	 * @return  array{state: 'free'|'invalid'|'not_declared'|'overlap_allowed'|'read_failed'}
	 *          |array{state: 'held', run_id: string, stale: bool}
	 */
	private function schedule_lock( ?array $declaration, int $observed_at ): array {
		if ( null === $declaration ) {
			return array( 'state' => 'not_declared' );
		}

		$schedule = $declaration['schedule'];
		if ( OverlapPolicy::Allow === $schedule->overlap ) {
			return array( 'state' => 'overlap_allowed' );
		}

		try {
			$encoded_args = \wp_json_encode(
				$schedule->args,
				\JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( \JsonException ) {
			return array( 'state' => 'invalid' );
		}
		if ( ! \is_string( $encoded_args ) ) {
			return array( 'state' => 'invalid' );
		}

		$args_hash = \hash( 'sha256', $encoded_args );
		$inspected = $this->guard->inspect_persisted_lock( $declaration['task'], $args_hash );
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

		$staleness = $this->lock_windows->lock_staleness( $declaration['task'], $lock['run_id'] );

		return array(
			'state'  => 'held',
			'run_id' => $lock['run_id'],
			'stale'  => self::heartbeat_is_stale( $lock['heartbeat_at'], $observed_at, $staleness ),
		);
	}

	/**
	 * Returns the recorded work kind, or unknown when absent.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Complete owner-qualified background-work identity.
	 *
	 * @return  'batch'|'task'|'unknown'
	 */
	private function work_kind( string $name ): string {
		$kind = $this->tasks->kind( $name );
		if ( 'task' === $kind ) {
			return null === $this->tasks->get( $name ) ? 'unknown' : 'task';
		}
		if ( 'batch' === $kind ) {
			return null === $this->batches->get( $name ) ? 'unknown' : 'batch';
		}

		return 'unknown';
	}

	/**
	 * Parses the canonical fixed-width run-ID suffix from one complete run option name.
	 *
	 * @internal Inspection decision seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Complete option name.
	 *
	 * @return  array{name: string, run_id: string}|null
	 */
	public static function run_identity_from_option_name( string $option_name ): ?array {
		$matched = \preg_match(
			'/\A' . \preg_quote( self::RUN_OPTION_PREFIX, '/' ) . '(?<name>.+)_(?<run_id>\d{20}-\d{19})\z/D',
			$option_name,
			$matches
		);
		if ( 1 !== $matched || null === WorkIdentity::parts( $matches['name'] ) ) {
			return null;
		}

		return array(
			'name'   => $matches['name'],
			'run_id' => $matches['run_id'],
		);
	}

	/**
	 * Merges bounded terminal and started buffers into deterministic newest-first rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Complete owner-qualified background-work identity.
	 *
	 * @phpstan-return list<HistoryEntry>|null
	 *
	 * @return  array|null Null when authoritative failed-run or history inspection fails.
	 */
	private function history( string $name ): ?array {
		$history     = $this->stores->run_history( $name );
		$failed_ids  = array();
		$entries     = array();
		$seen        = array();
		$failed_runs = $this->stores->failed_run_store( $name )->all();
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
				'run_id'   => $entry['run_id'],
				'outcome'  => $entry['status'],
				'retained' => isset( $failed_ids[ $entry['run_id'] ] ),
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
				'run_id'   => $run_id,
				'outcome'  => 'started',
				'retained' => isset( $failed_ids[ $run_id ] ),
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
