<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;

\defined( 'ABSPATH' ) || exit;

/**
 * Scheduling operations shared by every backend.
 *
 * Mutations expose expected backend failures as result data, while queries return scalar state.
 * Group and priority support remain backend capabilities so the facade can route the same request
 * through multiple implementations.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface BackendInterface {
	/**
	 * Schedules a recurring hook.
	 *
	 * Identity is the hook plus serialized arguments, plus the group where the backend supports
	 * groups. Scheduling is idempotent: an existing recurring chain with that identity is retained.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $hook                Hook to run.
	 * @param   int         $interval            Positive interval in seconds.
	 * @param   list<mixed> $args                Arguments passed to the hook.
	 * @param   int|null    $first_run_timestamp Unix timestamp of the first run, or null for now.
	 * @param   string      $group               Backend grouping label.
	 * @param   int         $priority            Advisory execution priority.
	 *
	 * @return  AbstractResult<true, SchedulingError> Success carrying true when the hook identity is present or queued on that backend, including a pre-existing occurrence; it does not identify occurrence kind or prove a fresh enqueue.
	 */
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function schedule_recurring( string $hook, int $interval, array $args = array(), ?int $first_run_timestamp = null, string $group = '', int $priority = 10 ): AbstractResult;

	/**
	 * Schedules a hook for one run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $hook      Hook to run.
	 * @param   int         $timestamp Unix timestamp of the run.
	 * @param   list<mixed> $args      Arguments passed to the hook.
	 * @param   string      $group     Backend grouping label.
	 * @param   int         $priority  Advisory execution priority.
	 *
	 * @return  AbstractResult<true, SchedulingError> Success carrying true when the single hook is scheduled.
	 */
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function schedule_single( string $hook, int $timestamp, array $args = array(), string $group = '', int $priority = 10 ): AbstractResult;

	/**
	 * Enqueues a hook to run asynchronously.
	 *
	 * Identity is the hook plus serialized arguments, plus the group where the backend supports
	 * groups. Enqueueing is idempotent; backends that distinguish pending from running actions block
	 * duplicates in both states.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $hook     Hook to run.
	 * @param   list<mixed> $args     Arguments passed to the hook.
	 * @param   string      $group    Backend grouping label.
	 * @param   int         $priority Advisory execution priority.
	 *
	 * @return  AbstractResult<true, SchedulingError> Success carrying true when the hook identity is present or queued on that backend, including a pre-existing occurrence; it does not identify occurrence kind or prove a fresh enqueue.
	 */
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function enqueue_async( string $hook, array $args = array(), string $group = '', int $priority = 10 ): AbstractResult;

	/**
	 * Unschedules every hook matching the supplied identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $hook  Hook to unschedule.
	 * @param   list<mixed> $args  Arguments identifying the scheduled hook.
	 * @param   string      $group Backend grouping label.
	 *
	 * @return  AbstractResult<true, SchedulingError> Success carrying true when every matching hook is confirmed absent from that backend's store.
	 */
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule( string $hook, array $args = array(), string $group = '' ): AbstractResult;

	/**
	 * Unschedules every pending action for the supplied hooks, regardless of arguments or groups.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<non-empty-string> $hooks Hooks to unschedule.
	 *
	 * @return  AbstractResult<int, SchedulingError> Success carries the number of matching pending actions removed.
	 */
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule_hooks( array $hooks ): AbstractResult;

	/**
	 * Returns whether a matching hook is scheduled.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $hook  Hook to query.
	 * @param   list<mixed> $args  Arguments identifying the scheduled hook.
	 * @param   string      $group Backend grouping label.
	 *
	 * @return  bool
	 */
	public function is_scheduled( string $hook, array $args = array(), string $group = '' ): bool;

	/**
	 * Returns the next run for a matching hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $hook  Hook to query.
	 * @param   list<mixed> $args  Arguments identifying the scheduled hook.
	 * @param   string      $group Backend grouping label.
	 *
	 * @return  int|null Unix timestamp of the next run, or null when none exists.
	 */
	public function get_next_scheduled( string $hook, array $args = array(), string $group = '' ): ?int;

	/**
	 * Returns whether the backend can accept requests.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public function is_ready(): bool;

	/**
	 * Returns whether the backend candidate has no runtime implementation to consult.
	 *
	 * @internal Scheduler-facade clearance authority only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public function is_absent(): bool;

	/**
	 * Returns whether the backend adapter exposes calendar cron expressions.
	 *
	 * Readiness remains a separate runtime fact.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public function supports_cron_expressions(): bool;

	/**
	 * Registers per-request backend hooks.
	 *
	 * The facade calls this method on every configured backend each request before, and regardless
	 * of, readiness selection. Persisted schedules keep resolving while another backend is preferred.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function register_hooks(): void;
}
