<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;

\defined( 'ABSPATH' ) || exit;

/**
 * Scheduling backend over Action Scheduler.
 *
 * Action Scheduler is commonly bundled by a client rather than activated as a standalone
 * plugin. Readiness derives from its complete procedural table and the lifecycle state reported by
 * the action_scheduler_init and init actions. Every procedural call remains guarded because load
 * order can change between requests and clients can supply partial or competing copies of the
 * library.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ActionSchedulerBackend implements BackendInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * Procedural functions required by this backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<non-empty-string>
	 */
	private const array REQUIRED_FUNCTIONS = array(
		'as_enqueue_async_action',
		'as_get_scheduled_actions',
		'as_schedule_recurring_action',
		'as_schedule_single_action',
		'as_unschedule_all_actions',
		'as_has_scheduled_action',
		'as_next_scheduled_action',
	);

	/**
	 * Lowest Action Scheduler version this backend accepts.
	 *
	 * Chunked work schedules each successor with delivery arguments containing its identity, run ID,
	 * and sequence. Action Scheduler made unique scheduling args-aware in 4.0.0; before that the
	 * running row blocks the successor's insert, and the run fails terminally at its first
	 * continuation. Action Scheduler publishes no version constant, so `ActionScheduler_Versions`
	 * is the only surface this can read.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     non-empty-string
	 */
	private const string MINIMUM_VERSION = '4.0.0';

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function schedule_recurring( string $hook, int $interval, array $args = array(), ?int $first_run_timestamp = null, string $group = '', int $priority = 10 ): AbstractResult {
		if ( ! $this->is_ready() ) {
			return $this->backend_not_ready( $this->readiness_facts() );
		}

		if ( 1 > $interval ) {
			return new Failure( new SchedulingError( SchedulingErrorReason::InvalidTimeInput, 'Action Scheduler requires recurring intervals of at least one second.', array( 'interval' => $interval ), ) );
		}

		$function_failure = $this->missing_function_failure( 'as_next_scheduled_action' );
		if ( null !== $function_failure ) {
			return $function_failure;
		}

		$next = \as_next_scheduled_action( $hook, $args, $group );
		// A running action is the current occurrence, so treating its true sentinel as future work starves the chain.
		if ( \is_int( $next ) ) {
			return new Success( true );
		}

		$function_failure = $this->missing_function_failure( 'as_schedule_recurring_action' );
		if ( null !== $function_failure ) {
			return $function_failure;
		}

		$action_id = \as_schedule_recurring_action( $first_run_timestamp ?? \time(), $interval, $hook, $args, $group, true, $priority );

		return $this->result_for_unique_action_id( $action_id, $hook, $args, $group, 'as_schedule_recurring_action' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function schedule_single( string $hook, int $timestamp, array $args = array(), string $group = '', int $priority = 10 ): AbstractResult {
		if ( ! $this->is_ready() ) {
			return $this->backend_not_ready( $this->readiness_facts() );
		}

		$function_failure = $this->missing_function_failure( 'as_next_scheduled_action' );
		if ( null !== $function_failure ) {
			return $function_failure;
		}

		$next = \as_next_scheduled_action( $hook, $args, $group );
		// A running action is the current occurrence, so treating its true sentinel as future work starves the chain.
		if ( \is_int( $next ) ) {
			return new Success( true );
		}

		$function_failure = $this->missing_function_failure( 'as_schedule_single_action' );
		if ( null !== $function_failure ) {
			return $function_failure;
		}

		$action_id = \as_schedule_single_action( $timestamp, $hook, $args, $group, false, $priority );

		return $this->result_for_action_id( $action_id, $hook, 'as_schedule_single_action' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Action Scheduler uses zero for both duplicate suppression and store failures. Exact arguments
	 * in a non-empty group make the follow-up identity specific enough to distinguish the duplicate
	 * safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function enqueue_async( string $hook, array $args = array(), string $group = '', int $priority = 10 ): AbstractResult {
		if ( ! $this->is_ready() ) {
			return $this->backend_not_ready( $this->readiness_facts() );
		}

		$function_failure = $this->missing_function_failure( 'as_enqueue_async_action' );
		if ( null !== $function_failure ) {
			return $function_failure;
		}

		$action_id = \as_enqueue_async_action( $hook, $args, $group, true, $priority );

		return $this->result_for_unique_action_id( $action_id, $hook, $args, $group, 'as_enqueue_async_action' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A concurrently completing recurring action may insert a successor after the postcheck; engine
	 * run fencing absorbs the resurrected occurrence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule( string $hook, array $args = array(), string $group = '' ): AbstractResult {
		if ( ! $this->is_ready() ) {
			return $this->backend_not_ready( $this->readiness_facts() );
		}

		$function_failure = $this->missing_function_failure( 'as_unschedule_all_actions' );
		if ( null !== $function_failure ) {
			return $function_failure;
		}

		\as_unschedule_all_actions( $hook, $args, $group );

		$function_failure = $this->missing_function_failure( 'as_has_scheduled_action' );
		if ( null !== $function_failure ) {
			return $function_failure;
		}

		$postcheck_args = '' === $hook && array() === $args && '' !== $group ? null : $args;
		if ( \as_has_scheduled_action( $hook, $postcheck_args, $group ) ) {
			return new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, \sprintf( 'Action Scheduler still reports a matching pending or in-progress action for hook "%s"; retry after any running action finishes.', $hook ), array( 'hook' => $hook ), ) );
		}

		return new Success( true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Group and arguments remain independent predicates: the identity group bounds the query, while
	 * the run ID in the second argument selects deliveries belonging to the cancelled run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook     Delivery hook.
	 * @param   string $identity Complete work identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule_run( string $hook, string $identity, string $run_id ): AbstractResult {
		if ( ! $this->is_ready() ) {
			return $this->backend_not_ready( $this->readiness_facts() );
		}

		foreach ( array( 'as_get_scheduled_actions', 'as_unschedule_all_actions' ) as $function_name ) {
			$function_failure = $this->missing_function_failure( $function_name );
			if ( null !== $function_failure ) {
				return $function_failure;
			}
		}

		foreach ( $this->pending_run_args( $hook, $identity, $run_id ) as $args ) {
			\as_unschedule_all_actions( $hook, $args, $identity );
		}

		if ( array() !== $this->pending_run_args( $hook, $identity, $run_id ) ) {
			return new Failure(
				new SchedulingError(
					SchedulingErrorReason::ScheduleFailed,
					\sprintf( 'Action Scheduler still reports a pending delivery for run "%1$s" on hook "%2$s"; retry after the queue store accepts cancellation.', $run_id, $hook ),
					array(
						'hook'     => $hook,
						'identity' => $identity,
						'run_id'   => $run_id,
					),
				)
			);
		}

		return new Success( true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<int, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule_hooks( array $hooks ): AbstractResult {
		if ( ! $this->is_ready() ) {
			return $this->backend_not_ready( $this->readiness_facts() );
		}

		foreach ( array( 'as_get_scheduled_actions', 'as_unschedule_all_actions' ) as $function_name ) {
			$function_failure = $this->missing_function_failure( $function_name );
			if ( null !== $function_failure ) {
				return $function_failure;
			}
		}

		$count = 0;
		foreach ( \array_values( \array_unique( $hooks ) ) as $hook ) {
			$pending = \as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'status'   => 'pending',
					'per_page' => -1,
					'orderby'  => 'none',
				),
				'ids'
			);
			$count  += \count( $pending );

			\as_unschedule_all_actions( $hook );

			$remaining = \as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'status'   => 'pending',
					'per_page' => 1,
					'orderby'  => 'none',
				),
				'ids'
			);
			if ( array() !== $remaining ) {
				return new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, \sprintf( 'Action Scheduler still reports a pending action for hook "%s"; retry after the queue store accepts cancellation.', $hook ), array( 'hook' => $hook ), ) );
			}
		}

		return new Success( $count );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A running action is the consumed occurrence rather than a queued successor, so multiplicity is
	 * pending-only. A zero count during that in-progress window is safe to schedule against because
	 * Action Scheduler's unique insert also treats a running action as a live occurrence and no-ops.
	 * The procedural API has no count format; requesting IDs avoids fetching action objects while
	 * retaining the exact total. An empty group remains unconstrained, matching Action Scheduler's
	 * other query functions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int<0, max>
	 */
	#[\Override]
	public function scheduled_count( string $hook, array $args = array(), string $group = '' ): int {
		if ( ! $this->is_ready() || ! \function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$action_ids = \as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'status'   => 'pending',
				'per_page' => -1,
				'orderby'  => 'none',
			),
			'ids'
		);

		return \count( $action_ids );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Action Scheduler cannot query multiple exact argument-and-group pairs together, so each identity
	 * costs one exact query either way. Reading a cadence needs the action itself rather than its ID,
	 * and an identity's own query matches only its own chain, so hydration stays proportional to the
	 * chains a scope declares instead of every pending action sharing the hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{count: int<0, max>, interval: positive-int|null}>
	 */
	#[\Override]
	public function scheduled_chains( string $hook, array $identities ): array {
		$chains = array();
		foreach ( $identities as $schedule_identity ) {
			$chains[ $schedule_identity ] = $this->scheduled_chain( $hook, $schedule_identity );
		}

		return $chains;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Both a future timestamp and Action Scheduler's true sentinel for async or in-progress state
	 * count as scheduled.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function is_scheduled( string $hook, array $args = array(), string $group = '' ): bool {
		if ( ! $this->is_ready() || ! \function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		return (bool) \as_next_scheduled_action( $hook, $args, $group );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Action Scheduler returns true for an async or in-progress action; that state is scheduled but
	 * has no next timestamp to expose.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function get_next_scheduled( string $hook, array $args = array(), string $group = '' ): ?int {
		if ( ! $this->is_ready() || ! \function_exists( 'as_next_scheduled_action' ) ) {
			return null;
		}

		$next = \as_next_scheduled_action( $hook, $args, $group );

		return \is_int( $next ) ? $next : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function is_ready(): bool {
		return self::facts_are_ready( $this->readiness_facts() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	#[\Override]
	public function is_absent(): bool {
		return ! \array_any( self::REQUIRED_FUNCTIONS, fn ( string $function_name ): bool => \function_exists( $function_name ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function register_hooks(): void {
		// Action Scheduler owns its runner and lifecycle hook registration.
	}

	// endregion

	// region HELPERS

	/**
	 * Returns one runtime readiness snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, action_scheduler_version_supported: bool, wp_init_fired: bool}
	 */
	private function readiness_facts(): array {
		$functions_exist = \array_all( self::REQUIRED_FUNCTIONS, fn ( string $function_name ): bool => \function_exists( $function_name ) );

		return array(
			'action_scheduler_functions_exist'   => $functions_exist,
			'action_scheduler_init_fired'        => 0 < \did_action( 'action_scheduler_init' ),
			'action_scheduler_version_supported' => self::version_is_supported(),
			'wp_init_fired'                      => 0 < \did_action( 'init' ),
		);
	}

	/**
	 * Returns exact arguments for pending deliveries belonging to one run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook     Delivery hook.
	 * @param   string $identity Complete work identity.
	 * @param   string $run_id   Run identifier.
	 *
	 * @return  list<list<mixed>>
	 */
	private function pending_run_args( string $hook, string $identity, string $run_id ): array {
		$actions = \as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => $identity,
				'status'   => 'pending',
				'per_page' => -1,
				'orderby'  => 'none',
			),
			'OBJECT'
		);

		$matching_args = array();
		foreach ( $actions as $action ) {
			if ( ! \is_object( $action ) || ! \method_exists( $action, 'get_args' ) ) {
				continue;
			}

			$args = $action->get_args();
			if ( ! \is_array( $args ) || ! \array_is_list( $args ) ) {
				continue;
			}

			$delivery_run_id = $args[1] ?? null;
			if ( $run_id === $delivery_run_id ) {
				$matching_args[] = $args;
			}
		}

		return $matching_args;
	}

	/**
	 * Returns one identity's pending chain cardinality and cadence from a single exact query.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook              Hook to query.
	 * @param   string $schedule_identity Canonical schedule identity.
	 *
	 * @return  array{count: int<0, max>, interval: positive-int|null}
	 */
	private function scheduled_chain( string $hook, string $schedule_identity ): array {
		if ( ! $this->is_ready() || ! \function_exists( 'as_get_scheduled_actions' ) ) {
			return array(
				'count'    => 0,
				'interval' => null,
			);
		}

		$actions = \as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => array( $schedule_identity ),
				'group'    => $schedule_identity,
				'status'   => 'pending',
				'per_page' => -1,
				'orderby'  => 'none',
			),
			'OBJECT'
		);
		$count   = \count( $actions );

		return array(
			'count'    => $count,
			// Several chains are the surplus the caller already replaces, so no single cadence represents the identity.
			'interval' => 1 === $count ? self::recurrence_of( \reset( $actions ) ) : null,
		);
	}

	/**
	 * Returns a pending action's fixed recurrence in seconds when it carries one.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed $action Hydrated Action Scheduler action.
	 *
	 * @return  positive-int|null
	 */
	private static function recurrence_of( mixed $action ): ?int {
		if ( ! \is_object( $action ) || ! \method_exists( $action, 'get_schedule' ) ) {
			return null;
		}

		$schedule = $action->get_schedule();
		// Cron schedules answer get_recurrence() with an expression rather than a number of seconds.
		if ( ! \is_object( $schedule ) || ! \method_exists( $schedule, 'get_recurrence' ) ) {
			return null;
		}

		$recurrence = $schedule->get_recurrence();

		return \is_numeric( $recurrence ) && 0 < (int) $recurrence ? (int) $recurrence : null;
	}

	/**
	 * Returns whether the elected Action Scheduler reaches the supported floor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	private static function version_is_supported(): bool {
		if ( ! \class_exists( '\ActionScheduler_Versions' ) ) {
			return false;
		}

		// Action Scheduler elects the highest registered version across every bundled copy, so the registry reports the
		// version that actually initialized rather than whichever copy this plugin sits beside.
		$elected = \ActionScheduler_Versions::instance()->latest_version();

		return \is_string( $elected ) && 0 <= \version_compare( $elected, self::MINIMUM_VERSION );
	}

	/**
	 * Returns whether a readiness snapshot permits procedural calls.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, action_scheduler_version_supported: bool, wp_init_fired: bool} $facts Readiness facts.
	 *
	 * @return  bool
	 */
	private static function facts_are_ready( array $facts ): bool {
		return $facts['action_scheduler_functions_exist'] && $facts['action_scheduler_init_fired'] && $facts['action_scheduler_version_supported'];
	}

	/**
	 * Returns a corrective failure when a direct procedural dependency is absent.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   non-empty-string $function_name Required function.
	 *
	 * @return  Failure<SchedulingError>|null
	 */
	private function missing_function_failure( string $function_name ): ?Failure {
		if ( \function_exists( $function_name ) ) {
			return null;
		}

		$facts = $this->readiness_facts();

		$facts['action_scheduler_functions_exist'] = false;

		return $this->backend_not_ready( $facts, $function_name );
	}

	/**
	 * Builds the failure returned before an unready backend can touch its procedural API.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, action_scheduler_version_supported: bool, wp_init_fired: bool} $facts            Readiness facts.
	 * @param   non-empty-string|null                                                                                                                           $missing_function Missing function.
	 *
	 * @return  Failure<SchedulingError>
	 */
	private function backend_not_ready( array $facts, ?string $missing_function = null ): Failure {
		$context = $facts;
		if ( null !== $missing_function ) {
			$context['missing_function'] = $missing_function;
		}

		$message = null === $missing_function
			? 'Action Scheduler is not ready; load or activate Action Scheduler, then call this scheduling operation after action_scheduler_init fires.'
			: \sprintf( 'Action Scheduler function "%s" is unavailable; load or activate a complete Action Scheduler API, then retry after action_scheduler_init fires.', $missing_function );

		return new Failure( new SchedulingError( SchedulingErrorReason::BackendNotReady, $message, $context, ) );
	}

	/**
	 * Maps an action ID while disambiguating Action Scheduler's unique zero sentinel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int              $action_id     Positive action ID, or a non-positive rejection value.
	 * @param   string           $hook          Hook being scheduled.
	 * @param   list<mixed>      $args          Hook arguments.
	 * @param   string           $group         Action group.
	 * @param   non-empty-string $function_name Procedural function called.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function result_for_unique_action_id( int $action_id, string $hook, array $args, string $group, string $function_name ): AbstractResult {
		$diagnostic_facts = null;
		$failure_cause    = null;
		if ( 0 === $action_id ) {
			if ( '' === $group ) {
				$failure_cause = 'a unique scheduling write in the empty group returned zero, which is ambiguous between a duplicate and a store failure; use a non-empty group for verifiable uniqueness.';
			} elseif ( ! \function_exists( 'as_has_scheduled_action' ) ) {
				$diagnostic_facts = $this->readiness_facts();

				$diagnostic_facts['action_scheduler_functions_exist'] = false;
			} elseif ( \as_has_scheduled_action( $hook, $args, $group ) ) {
				return new Success( true );
			}
		}

		return $this->result_for_action_id( $action_id, $hook, $function_name, $diagnostic_facts, $failure_cause );
	}

	/**
	 * Maps an Action Scheduler action ID into the checked scheduling result.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int                                                                                                                                                  $action_id     Positive action ID, or a non-positive rejection value.
	 * @param   string                                                                                                                                               $hook          Hook being scheduled.
	 * @param   non-empty-string                                                                                                                                     $function_name Procedural function called.
	 * @param   array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, action_scheduler_version_supported: bool, wp_init_fired: bool}|null $facts         Known diagnostic facts.
	 * @param   non-empty-string|null                                                                                                                                $failure_cause Known rejection cause.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function result_for_action_id( int $action_id, string $hook, string $function_name, ?array $facts = null, ?string $failure_cause = null ): AbstractResult {
		if ( 0 < $action_id ) {
			return new Success( true );
		}

		$facts ??= $this->readiness_facts();

		if ( null !== $failure_cause ) {
			$cause = $failure_cause;
		} elseif ( 0 > $action_id ) {
			$cause = \sprintf( '%1$s returned negative action ID %2$d; only a positive ID confirms that Action Scheduler persisted the action.', $function_name, $action_id );
		} elseif ( ! $facts['action_scheduler_functions_exist'] ) {
			$cause = 'the Action Scheduler function table is unavailable; load or activate Action Scheduler before retrying.';
		} elseif ( ! $facts['wp_init_fired'] ) {
			$cause = 'WordPress init has not fired; call the scheduling operation after action_scheduler_init instead of before init.';
		} elseif ( ! $facts['action_scheduler_init_fired'] ) {
			$cause = 'action_scheduler_init has not fired; load Action Scheduler early enough to initialize, then retry after that action.';
		} elseif ( ! $facts['action_scheduler_version_supported'] ) {
			$cause = \sprintf( 'the initialized Action Scheduler is older than %s, which chunked work requires for args-aware unique scheduling; upgrade the copy that wins version election.', self::MINIMUM_VERSION );
		} else {
			$cause = \sprintf( 'the Action Scheduler store rejected the action; inspect the PHP error log for a store or database exception, or a %s filter returning zero.', 'pre_' . $function_name );
		}

		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				\sprintf( 'Action Scheduler could not schedule hook "%1$s": %2$s', $hook, $cause ),
				array(
					'hook'                               => $hook,
					'action_scheduler_function'          => $function_name,
					'action_scheduler_functions_exist'   => $facts['action_scheduler_functions_exist'],
					'action_scheduler_init_fired'        => $facts['action_scheduler_init_fired'],
					'action_scheduler_version_supported' => $facts['action_scheduler_version_supported'],
					'wp_init_fired'                      => $facts['wp_init_fired'],
				),
			)
		);
	}

	// endregion
}
