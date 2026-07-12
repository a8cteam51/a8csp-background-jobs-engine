<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Backends;

use A8C\SpecialProjects\BackgroundTasksEngine\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Scheduling\SchedulingErrorReason;

\defined( 'ABSPATH' ) || exit;

/**
 * Scheduling backend over Action Scheduler.
 *
 * Action Scheduler is commonly bundled by a consumer rather than activated as a standalone
 * plugin. Readiness therefore requires its complete procedural table and the signal that its data
 * store has initialized. Every procedural call remains guarded because load order can change
 * between requests and consumers can supply partial or competing copies of the library.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ActionSchedulerBackend implements BackendInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * Procedural functions required by this backend.
	 *
	 * @var list<non-empty-string>
	 */
	private const REQUIRED_FUNCTIONS = array(
		'as_enqueue_async_action',
		'as_schedule_recurring_action',
		'as_schedule_single_action',
		'as_unschedule_all_actions',
		'as_has_scheduled_action',
		'as_next_scheduled_action',
	);

	/**
	 * Predicate backing {@see self::is_ready()}.
	 *
	 * @var \Closure(): bool
	 */
	private \Closure $readiness_probe;

	/**
	 * Predicate reporting whether one runtime function exists.
	 *
	 * @var \Closure(string): bool
	 */
	private \Closure $function_exists_probe;

	/**
	 * Predicate reporting how many times one WordPress action fired.
	 *
	 * @var \Closure(string): int
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
	public function __construct(
		?callable $readiness_probe = null,
		?callable $function_exists_probe = null,
		?callable $did_action_probe = null,
	) {
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
					SchedulingErrorReason::InvalidInterval,
					'Action Scheduler requires recurring intervals of at least one second.',
					array( 'interval' => $interval ),
				)
			);
		}

		$function_failure = $this->missing_function_failure( 'as_has_scheduled_action' );
		if ( null !== $function_failure ) {
			return $function_failure;
		}

		if ( \as_has_scheduled_action( $hook, $args, $group ) ) {
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
			false,
			$priority
		);

		return $this->result_for_action_id( $action_id, $hook, 'as_schedule_recurring_action' );
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

		$function_failure = $this->missing_function_failure( 'as_has_scheduled_action' );
		if ( null !== $function_failure ) {
			return $function_failure;
		}

		if ( \as_has_scheduled_action( $hook, $args, $group ) ) {
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
	 * A unique enqueue delegates atomic deduplication to Action Scheduler. Its identity includes
	 * arguments and group, and pending or in-progress matches both block insertion. A blocked insert
	 * returns the same zero used for failures, so the matching action is confirmed before accepting
	 * the zero as a successful no-op.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function enqueue_async( string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): AbstractResult {
		if ( ! $this->is_ready() ) {
			return $this->backend_not_ready( $this->readiness_facts() );
		}

		$function_failure = $this->missing_function_failure( 'as_enqueue_async_action' );
		if ( null !== $function_failure ) {
			return $function_failure;
		}

		$action_id        = \as_enqueue_async_action( $hook, $args, $group, $unique, $priority );
		$diagnostic_facts = null;
		if ( 0 === $action_id && $unique ) {
			if ( ! \function_exists( 'as_has_scheduled_action' ) ) {
				$diagnostic_facts = $this->readiness_facts();

				$diagnostic_facts['action_scheduler_functions_exist'] = false;
			} elseif ( \as_has_scheduled_action( $hook, $args, $group ) ) {
				return new Success( true );
			}
		}

		return $this->result_for_action_id( $action_id, $hook, 'as_enqueue_async_action', $diagnostic_facts );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Action Scheduler cancels pending actions but cannot recall an action already running. The
	 * postcondition therefore checks whether a matching pending or in-progress action remains rather
	 * than treating the void cancellation call itself as proof of success.
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

		if ( \as_has_scheduled_action( $hook, $args, $group ) ) {
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

		return new Failure(
			new SchedulingError(
				SchedulingErrorReason::BackendNotReady,
				'Action Scheduler is not ready; load or activate Action Scheduler, then call this scheduling operation after action_scheduler_init fires.',
				$context,
			)
		);
	}

	/**
	 * Maps an Action Scheduler action ID into the checked scheduling result.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int                                                                                                        $action_id    Action ID, or zero on rejection.
	 * @param   string                                                                                                     $hook         Hook being scheduled.
	 * @param   non-empty-string                                                                                           $function_name Procedural function called.
	 * @param   array{action_scheduler_functions_exist: bool, action_scheduler_init_fired: bool, wp_init_fired: bool}|null $facts         Known diagnostic facts.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function result_for_action_id( int $action_id, string $hook, string $function_name, ?array $facts = null ): AbstractResult {
		if ( 0 !== $action_id ) {
			return new Success( true );
		}

		$facts ??= $this->readiness_facts();

		if ( ! $facts['action_scheduler_functions_exist'] ) {
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
