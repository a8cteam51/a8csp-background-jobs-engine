<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulingErrorReason;

\defined( 'ABSPATH' ) || exit;

/**
 * Scheduling backend over Action Scheduler.
 *
 * Action Scheduler is commonly bundled by a consumer rather than activated as a standalone
 * plugin. Readiness therefore requires its complete procedural table and the signal that its data
 * store has initialized. Every procedural call remains guarded because load order can change
 * between requests and consumers can supply partial or competing copies of the library.
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
	private const REQUIRED_FUNCTIONS = array(
		'as_enqueue_async_action',
		'as_get_scheduled_actions',
		'as_schedule_recurring_action',
		'as_schedule_single_action',
		'as_unschedule_all_actions',
		'as_has_scheduled_action',
		'as_next_scheduled_action',
	);

	/**
	 * Predicate backing {@see self::is_ready()}.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     \Closure(): bool
	 */
	private \Closure $readiness_probe;

	/**
	 * Predicate reporting whether one runtime function exists.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     \Closure(string): bool
	 */
	private \Closure $function_exists_probe;

	/**
	 * Predicate reporting how many times one WordPress action fired.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     \Closure(string): int
	 */
	private \Closure $did_action_probe;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param (callable(): bool)|null        $readiness_probe
	 * @phpstan-param (callable(string): bool)|null $function_exists_probe
	 * @phpstan-param (callable(string): int)|null  $did_action_probe
	 *
	 * @param   callable|null $readiness_probe       Readiness predicate, or null for runtime facts.
	 * @param   callable|null $function_exists_probe Function-existence predicate for runtime facts.
	 * @param   callable|null $did_action_probe      Action-fire-count predicate for runtime facts.
	 */
	public function __construct( ?callable $readiness_probe = null, ?callable $function_exists_probe = null, ?callable $did_action_probe = null ) {
		$this->function_exists_probe = \Closure::fromCallable(
			$function_exists_probe ?? static fn ( string $function_name ): bool => \function_exists( $function_name )
		);

		$this->did_action_probe = \Closure::fromCallable(
			$did_action_probe ?? static fn ( string $hook ): int => \function_exists( 'did_action' ) ? \did_action( $hook ) : 0
		);

		$this->readiness_probe = null === $readiness_probe
			? fn (): bool => self::facts_are_ready( $this->readiness_facts() )
			: \Closure::fromCallable( $readiness_probe );
	}

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
			return new Failure(
				new SchedulingError(
					SchedulingErrorReason::InvalidTimeInput,
					'Action Scheduler requires recurring intervals of at least one second.',
					array( 'interval' => $interval ),
				)
			);
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

		$action_id = \as_schedule_recurring_action(
			$first_run_timestamp ?? \time(),
			$interval,
			$hook,
			$args,
			$group,
			true,
			$priority
		);

		return $this->result_for_unique_action_id(
			$action_id,
			$hook,
			$args,
			$group,
			'as_schedule_recurring_action'
		);
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
	 * Action Scheduler uses zero for both duplicate suppression and store failures. A non-empty group
	 * makes the follow-up identity specific enough to distinguish the duplicate safely.
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

		return $this->result_for_unique_action_id(
			$action_id,
			$hook,
			$args,
			$group,
			'as_enqueue_async_action'
		);
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
			return new Failure(
				new SchedulingError(
					SchedulingErrorReason::ScheduleFailed,
					\sprintf(
						'Action Scheduler still reports a matching pending or in-progress action for hook "%s"; retry after any running action finishes.',
						$hook
					),
					array( 'hook' => $hook ),
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
				return new Failure(
					new SchedulingError(
						SchedulingErrorReason::ScheduleFailed,
						\sprintf(
							'Action Scheduler still reports a pending action for hook "%s"; retry after the queue store accepts cancellation.',
							$hook
						),
						array( 'hook' => $hook ),
					)
				);
			}
		}

		return new Success( $count );
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
		return ( $this->readiness_probe )();
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
		foreach ( self::REQUIRED_FUNCTIONS as $function_name ) {
			if ( ( $this->function_exists_probe )( $function_name ) ) {
				return false;
			}
		}

		return true;
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
	public function supports_cron_expressions(): bool {
		return false;
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
	 * @return  array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, wp_init_fired: bool}
	 */
	private function readiness_facts(): array {
		$functions_exist = true;
		foreach ( self::REQUIRED_FUNCTIONS as $function_name ) {
			if ( ! ( $this->function_exists_probe )( $function_name ) ) {
				$functions_exist = false;
				break;
			}
		}

		return array(
			'action_scheduler_functions_exist' => $functions_exist,
			'action_scheduler_init_fired'      => 0 < ( $this->did_action_probe )( 'action_scheduler_init' ),
			'wp_init_fired'                    => 0 < ( $this->did_action_probe )( 'init' ),
		);
	}

	/**
	 * Returns whether a readiness snapshot permits procedural calls.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, wp_init_fired: bool} $facts Readiness facts.
	 *
	 * @return  bool
	 */
	private static function facts_are_ready( array $facts ): bool {
		return $facts['action_scheduler_functions_exist'] && $facts['action_scheduler_init_fired'];
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
		if ( ( $this->function_exists_probe )( $function_name ) ) {
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
	 * @param   array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, wp_init_fired: bool} $facts            Readiness facts.
	 * @param   non-empty-string|null                                                                                 $missing_function Missing function.
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
			: \sprintf(
				'Action Scheduler function "%s" is unavailable; load or activate a complete Action Scheduler API, then retry after action_scheduler_init fires.',
				$missing_function
			);

		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::BackendNotReady,
				$message,
				$context,
			)
		);
	}

	/**
	 * Maps an action ID while disambiguating Action Scheduler's unique zero sentinel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int              $action_id    Positive action ID, or a non-positive rejection value.
	 * @param   string           $hook         Hook being scheduled.
	 * @param   list<mixed>      $args         Hook arguments.
	 * @param   string           $group        Action group.
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
			} elseif ( ! ( $this->function_exists_probe )( 'as_has_scheduled_action' ) ) {
				$diagnostic_facts = $this->readiness_facts();

				$diagnostic_facts['action_scheduler_functions_exist'] = false;
			} elseif ( \as_has_scheduled_action( $hook, $args, $group ) ) {
				return new Success( true );
			}
		}

		return $this->result_for_action_id(
			$action_id,
			$hook,
			$function_name,
			$diagnostic_facts,
			$failure_cause
		);
	}

	/**
	 * Maps an Action Scheduler action ID into the checked scheduling result.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int                                                                                                        $action_id    Positive action ID, or a non-positive rejection value.
	 * @param   string                                                                                                     $hook         Hook being scheduled.
	 * @param   non-empty-string                                                                                           $function_name Procedural function called.
	 * @param   array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, wp_init_fired: bool}|null $facts         Known diagnostic facts.
	 * @param   non-empty-string|null                                                                                      $failure_cause Known rejection cause.
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
			$cause = \sprintf(
				'%1$s returned negative action ID %2$d; only a positive ID confirms that Action Scheduler persisted the action.',
				$function_name,
				$action_id
			);
		} elseif ( ! $facts['action_scheduler_functions_exist'] ) {
			$cause = 'the Action Scheduler function table is unavailable; load or activate Action Scheduler before retrying.';
		} elseif ( ! $facts['wp_init_fired'] ) {
			$cause = 'WordPress init has not fired; call the scheduling operation after action_scheduler_init instead of before init.';
		} elseif ( ! $facts['action_scheduler_init_fired'] ) {
			$cause = 'action_scheduler_init has not fired; load Action Scheduler early enough to initialize, then retry after that action.';
		} else {
			$cause = \sprintf(
				'the Action Scheduler store rejected the action; inspect the PHP error log for a store or database exception, or a %s filter returning zero.',
				'pre_' . $function_name
			);
		}

		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::ScheduleFailed,
				\sprintf( 'Action Scheduler could not schedule hook "%1$s": %2$s', $hook, $cause ),
				array(
					'hook'                             => $hook,
					'action_scheduler_function'        => $function_name,
					'action_scheduler_functions_exist' => $facts['action_scheduler_functions_exist'],
					'action_scheduler_init_fired'      => $facts['action_scheduler_init_fired'],
					'wp_init_fired'                    => $facts['wp_init_fired'],
				),
			)
		);
	}

	// endregion
}
