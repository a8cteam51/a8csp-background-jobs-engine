# A8CSP Background Tasks Engine

**Contributors:** wpspecialprojects
**Tags:**
**Requires at least:** 7.0
**Tested up to:** 7.0
**Requires PHP:** 8.5
**Stable tag:** 1.0.0-beta.1
**License:** GPL v2 or later
**License URI:** <https://www.gnu.org/licenses/gpl-2.0.html>

A background-work engine for WordPress sites: Tasks, Schedules, and Batches using Action Scheduler when available, with a documented best-effort WP-Cron fallback.

## What it is

A Task is one named unit of background work. A consumer registers a `TaskInterface` instance and dispatches it through the owner-bound consumer API.

A Schedule is an owner-scoped declaration that dispatches a registered Task on a fixed recurrence. The declaration includes the task arguments, overlap policy, catch-up policy, and advisory priority.

A Batch is named work split into independently processed chunks. A consumer registers a `BatchInterface`; the engine persists the queue, retries each failed chunk independently, and invokes one terminal callback after the run completes or fails. Cancelled and superseded Batches invoke neither terminal callback. Tasks and Batches share one site-global `{owner}:{name}` identity namespace, so one owner cannot register the same local name as both kinds.

Consumers use the same API with either scheduling backend. An occurrence on a temporarily unavailable backend is dormant, not lost; writes can use another ready backend, and the dormant occurrence becomes visible when its backend recovers.

The engine's own state persists in `wp_options` rows under the reserved `a8csp_bgte_` prefix, and no engine row is ever autoloaded, so engine storage adds no weight to ordinary page loads. The dynamic families are `a8csp_bgte_schedule_registrations_{owner}`, `a8csp_bgte_run_{identity}_{run_id}`, `a8csp_bgte_failed_runs_{identity}`, `a8csp_bgte_latest_run_{identity}`, `a8csp_bgte_run_history_{identity}`, `a8csp_bgte_overlap_lock_{identity}_{args_hash}`, `a8csp_bgte_occurrence_lease_{registration_hash}`, and `a8csp_bgte_cleanup_intent_{registration_hash}`.

## Installation

The canonical install is the plugin ZIP attached to a [GitHub Release](https://github.com/a8cteam51/a8csp-background-tasks-engine/releases). Download the ZIP, upload it as a WordPress plugin, and activate it. The release ZIP includes production Composer dependencies and the translation template (`.pot`), so it needs no Composer step.

Installed copies receive release updates through the WordPress dashboard like any plugin.

For a source checkout, clone or extract the repository into `wp-content/plugins/a8csp-background-tasks-engine`, then install production dependencies inside that plugin directory:

```sh
cd wp-content/plugins
git clone https://github.com/a8cteam51/a8csp-background-tasks-engine.git
cd a8csp-background-tasks-engine
composer install --no-dev
```

Action Scheduler is optional and preferred when it is ready; when it is absent, the engine runs on WP-Cron alone.

## Multisite

Network activation is supported, and each site operates its own isolated engine state. Engine storage is bound to the request site when the engine graph is built, so a storage operation after `switch_to_blog()` throws a `LogicException` instead of writing through a graph created for another site.

Per-site pattern: enter each site through a fresh request or execution context, then resolve its consumer and operate there; do not call engine APIs after switching blogs inside an existing context. Network uninstall sweeps the engine's options and pending backend work from every site.

## Quick start

In a consumer plugin under its own namespace, register the Task and Batch implementations and synchronize the owner's complete schedule declaration from `init`:

```php
namespace Acme\BackgroundTasks;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\ExistingRunPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiErrorCode;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;

final class BackgroundTasksRegistration {
	private const OWNER = 'acme-background-work';

	private const SCHEDULE_NAME = 'site-health-ping';

	public static function register(): void {
		$consumer = \a8csp_bgte( self::OWNER );
		$consumer->tasks()->register( new SiteHealthPingTask() );
		$consumer->batches()->register( new CommentCountRecountBatch() );

		$synced = $consumer->schedules()->sync(
			array(
				new Schedule(
					name: self::SCHEDULE_NAME,
					recurrence: Recurrence::every( \HOUR_IN_SECONDS ),
					task: SiteHealthPingTask::NAME,
					args: array( 'transient' => 'acme_site_health_snapshot' ),
					overlap: OverlapPolicy::Skip,
					catch_up: CatchUpPolicy::RunOnce,
					priority: 10
				),
			)
		);
		if ( $synced->is_failure() ) {
			\error_log( 'Background schedule sync failed.' );
		}
	}
}

\add_action( 'init', array( BackgroundTasksRegistration::class, 'register' ) );
```

`SiteHealthPingTask` and `CommentCountRecountBatch` are consumer-owned implementations of the contracts below. See the tested version of this example in [`DemoConsumer`](tests/Support/Fixtures/DemoConsumer.php), [`SiteHealthPingTask`](tests/Support/Fixtures/SiteHealthPingTask.php), and [`CommentCountRecountBatch`](tests/Support/Fixtures/CommentCountRecountBatch.php).

`a8csp_bgte()` is available from inside `init` at any priority, and later. Calling it before `init` throws a `LogicException` directing the caller to an `init` callback or later. Action Scheduler's stores initialize at `init:1`; allowing earlier enqueues would silently divert them to WP-Cron. By `init`, the engine's eager `plugins_loaded:0` boot has run in every standard load path; the request that activates the engine is the one exception — it stays dormant there until the next request. Pass the consumer plugin slug once; owners match `[a-z0-9][a-z0-9-]*`, are at most 32 bytes, and cannot start with the engine-reserved `a8csp-bgte` prefix. Owner exclusivity is a convention, so plugins use their own slug.

Task, Batch, and Schedule local names match `[a-z0-9_-]+` and are at most 64 bytes. The API composes the owner and local name once at the facade boundary. The complete identity is therefore at most 97 bytes and remains inside WordPress's 191-character `option_name` boundary for every derived store key.

Schedule synchronization treats the passed array as the bound owner's complete declaration, so call it on every `init`. Register every Task and Batch from an `init` callback on every request. Run delivery through WP-Cron, Action Scheduler, and WP-CLI begins only after `init` completes, so an `init`-time registration at any priority is always in place before its runs deliver. A run delivered for a name with no registration in that request fails terminally with `UnknownWork`; after registering, retry it with `runs()->retry_failed()`.

After registration, enqueue the Task or start the Batch through an owner-bound consumer:

```php
$consumer = \a8csp_bgte( 'acme-background-work' );

$task_result = $consumer->tasks()->enqueue(
	SiteHealthPingTask::NAME,
	array( 'transient' => 'acme_site_health_snapshot' ),
	dedup_key: 'site-health-snapshot'
);
if ( $task_result->is_failure() ) {
	switch ( $task_result->error->code ) {
		case ApiErrorCode::OverlapHeld:
			$incumbent_run_id = $task_result->error->context['run_id'] ?? null;
			break;
		default:
			\error_log( $task_result->error->message );
	}
}

$batch_result = $consumer->batches()->start(
	CommentCountRecountBatch::NAME,
	array( 'post_type' => 'post' ),
	existing: ExistingRunPolicy::Reject
);
```

A Task deduplication key is an optional opaque byte string scoped to that Task. While a run owns the key, another enqueue with the same key returns `OverlapHeld` even when its arguments differ; after terminal cleanup, the key is reusable. Pass 1 to 64 bytes, or leave it `null` to derive the overlap identity from the Task arguments. The key is hashed before storage and is an in-flight deduplication mechanism, not a durable idempotency record.

`ExistingRunPolicy::Replace` is the default Batch policy: a start with matching arguments takes over a fresh incumbent's overlap lock, and the incumbent stops at its next fence. `ExistingRunPolicy::Reject` instead returns `OverlapHeld` and leaves the incumbent in place.

Task enqueue arguments, Batch start arguments, and Schedule arguments are each capped at 8,192 bytes in their encoded JSON representation; oversized Task and Batch commands return `PayloadRejected`, while an oversized Schedule definition throws `InvalidArgumentException`.

Scheduling, run-inspection, retry, and cancellation methods return `Success` or `Failure<ApiError>`. A successful scheduling result means the work was accepted, not that its handler completed. Branch with `is_success()` or `is_failure()`, then read the narrowed result's `value` or `error` property. Failed results expose a stable `ApiErrorCode` through `$result->error->code`; `context` contains redaction-safe structured details such as the incumbent `run_id` for `OverlapHeld`.

Deterministic contract violations detected before engine side effects throw `InvalidArgumentException`: invalid or reserved identities, priorities outside 0–255, negative task delays, empty or over-64-byte Task deduplication keys, non-portable Task or Batch arguments, and cross-kind registration. Valid commands rejected by registration or runtime state—including unknown work, held locks, backend refusal, and storage failure—return `Failure<ApiError>`.

The supported facade methods are:

| Facade | Methods |
| --- | --- |
| `Consumer` | `tasks()`, `batches()`, `schedules()`, `runs()` |
| `Api\Task\Tasks` | `register(TaskInterface)`, `enqueue(string $name, array $args = [], int $delay = 0, ?string $dedup_key = null, int $priority = 10)` |
| `Api\Batch\Batches` | `register(BatchInterface)`, `start(string $name, array $start_args = [], ExistingRunPolicy $existing = ExistingRunPolicy::Replace, int $priority = 10)` |
| `Api\Schedule\Schedules` | `sync(array $schedules)`, `dispatch_now(string $name)` |
| `Api\Run\Runs` | `last_completed_run_id(string $name)`, `retry_failed(string $name, string $run_id)`, `cancel(string $name, string $run_id)` |

## Migrating from Action Scheduler

Register a Task for each former action hook, then resolve the owner-bound consumer from `init` or later. The examples below assume `$consumer = \a8csp_bgte( 'my-plugin' )`, with `Schedule` and `Recurrence` imported from `Api\Schedule`.

| Action Scheduler call | Engine equivalent |
| --- | --- |
| `as_enqueue_async_action( $hook, $args, $group )` | `\a8csp_bgte( 'my-plugin' )->tasks()->enqueue( 'name', $args )` |
| `as_schedule_single_action( $timestamp, $hook, $args, $group )` | `$consumer->tasks()->enqueue( 'name', $args, delay: \max( 0, $timestamp - \time() ) )`; `enqueue()` accepts a non-negative delay in seconds, not an absolute timestamp. |
| `as_schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args, $group )` | Include `new Schedule( name: 'hourly-refresh', recurrence: Recurrence::every( $interval_in_seconds ), task: 'refresh', args: $args )` in the owner's complete array passed to `$consumer->schedules()->sync( ... )`. `Schedule` has no first-run timestamp field. |
| `as_unschedule_action( $hook, $args, $group )` | Omit the named `Schedule` from the next complete `sync()` declaration. To stop an already admitted run, retain its run ID and call `$consumer->runs()->cancel( 'name', $run_id )`. |
| `as_unschedule_all_actions( $hook, $args, $group )` | Use the same declarative removal for recurring work; `$consumer->schedules()->sync( array() )` removes every Schedule owned by this consumer. Directly enqueued runs require individual `cancel()` calls with known run IDs. |
| `as_next_scheduled_action( $hook, $args, $group )` | There is no public next-due inspection method. `$consumer->runs()->last_completed_run_id( 'name' )` reports only the latest retained completed run and is not a next-scheduled replacement. |
| `as_has_scheduled_action( $hook, $args, $group )` | There is no public pending-or-running boolean query. Treat the complete declaration supplied to a successful `sync()` as the source of truth for recurring schedules. |

Action Scheduler's optional `$group` defaults to `''`, leaving ownership implicit. The engine requires the consumer owner at the front door and composes it into every identity; it refuses the ownerless ambiguity that makes cross-plugin actions easy to query or cancel accidentally.

## Testing your consumer

The public facade constructors accept an owner string and their small engine port: `TasksEngineInterface`, `BatchesEngineInterface`, `SchedulesEngineInterface`, or `RunsEngineInterface`. A consumer test implements the required port, or uses a partial fake, and constructs `Tasks`, `Batches`, `Schedules`, or `Runs` directly without booting a scheduling backend. A complete `Consumer` can be assembled from those four owner-bound facades.

This compact example records a Task enqueue through the real public facade:

```php
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\Tasks;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TasksEngineInterface;

$engine = new class() implements TasksEngineInterface {
	public array $calls = array();

	public function register_task( string $identity, TaskInterface $task ): void {}

	/** @return AbstractResult<string, ApiError> */
	public function enqueue( string $identity, array $args, int $delay, ?string $dedup_key, int $priority ): AbstractResult {
		$this->calls[] = array( $identity, $args, $delay, $dedup_key, $priority );

		return new Success( 'run-test' );
	}
};

$tasks  = new Tasks( 'my-plugin', $engine );
$result = $tasks->enqueue( 'refresh', array( 'site_id' => 7 ), delay: 30 );

\assert( $result->is_success() && 'run-test' === $result->value );
\assert( array( array( 'my-plugin:refresh', array( 'site_id' => 7 ), 30, null, 10 ) ) === $engine->calls );
```

Do not stub `a8csp_bgte()`. The engine's `functions.php` declares it unconditionally, so a test-defined function fatals with a redeclaration error when the engine loads. Code that resolves its consumer internally instead accepts a `Consumer`, or a `fn ( string $owner ): Consumer` resolver that defaults to `a8csp_bgte()`, and tests inject the fake facade set through that seam.

## The three contracts

### Task

`TaskInterface` defines a stable name, a per-invocation runtime ceiling, a handler, and a retry policy. A normal handler return succeeds; a thrown exception fails the attempt.

```php
interface TaskInterface extends WorkInterface {
	public function get_name(): string;

	public function max_callback_runtime(): int;

	public function handle( array $args ): void;

	public function get_retry_policy(): RetryPolicy;
}
```

`WorkInterface` owns the shared 300-second default, which `AbstractTask` supplies automatically. Invalid or non-positive declarations use that default, and the engine caps the credited window at six hours. The engine credits liveness for the bounded window before each `handle()` call; a handler that exceeds it becomes eligible for crash reclamation after the lock-staleness window. A callback that exceeds its credited window can overlap its crash-recovery replacement, which is why handlers must be idempotent.

### Batch and batch context

`BatchInterface` declares a per-invocation runtime ceiling, generates initial chunks, processes one chunk at a time, receives a terminal callback after the run completes or fails, and supplies the retry policy used independently for each failed chunk. Chunk execution is at-least-once: queue advancement persists only after `process_chunk()` returns, so a crash in between redelivers the same chunk, and implementations converge replays through stable business identifiers carried in the chunk arguments. Terminal callbacks are likewise at-least-once across crash recovery — durable under Action Scheduler, best-effort under the WP-Cron fallback, whose sweeps only run while site traffic triggers them: a process can stop after the callback returns but before its completion marker persists, so implementations use the run ID to converge a replay. Cancellation and supersession invoke neither callback.

```php
interface BatchInterface extends WorkInterface {
	public function get_name(): string;

	public function max_callback_runtime(): int;

	public function generate_queue( array $start_args ): iterable;

	public function process_chunk(
		array $chunk_args,
		BatchContextInterface $context
	): void;

	public function on_completed( string $run_id, array $start_args ): void;

	public function on_failed(
		string $run_id,
		array $start_args,
		RunFailure $failure
	): void;

	public function get_retry_policy(): RetryPolicy;
}
```

The batch ceiling applies independently to one `generate_queue()` or `process_chunk()` call, not to the whole run. `WorkInterface` owns the shared 300-second default, which `AbstractBatch` supplies automatically. Direct implementations must declare it; invalid or non-positive declarations use that default, and the engine caps the credited window at six hours.

`RunFailure::$identity` is the complete `{owner}:{name}` work identity. The value also carries the run ID, consumed attempt count, typed `RunFailureStage`, stable `ApiErrorCode`, engine-authored redacted summary, and the failing batch chunk when one exists. Its summary never contains a raw consumer exception message.

`BatchContextInterface` exposes only the current run. Queue mutations are transactional within the chunk attempt: they take effect after a normal return and are discarded when the attempt throws.

Each generated, filtered, enqueued, or prepended chunk is capped at 8,192 bytes in its encoded JSON representation before queue persistence; the 8,000-byte scheduler action envelope can impose a smaller effective limit after its delivery metadata is included.

The complete queue is capped at 1,048,576 bytes in its persisted serialization both when generation materializes it and whenever `BatchContextInterface::enqueue()` or `prepend()` grows it during processing.

```php
interface BatchContextInterface {
	public function enqueue( array $chunk_args ): void;

	public function prepend( array $chunk_args ): void;

	public function get_run_id(): string;

	public function get_start_args(): array;
}
```

### Schedule

`Schedule` is a readonly value object with this constructor shape:

```php
public function __construct(
	public string $name,
	public Recurrence $recurrence,
	public string $task,
	public array $args = array(),
	public OverlapPolicy $overlap = OverlapPolicy::Skip,
	public CatchUpPolicy $catch_up = CatchUpPolicy::RunOnce,
	public int $priority = 10,
)
```

Use `Recurrence::every( $seconds )` for fixed-interval schedule synchronization.

## Idempotency invariant

Schedule-driven tasks and batch chunks MUST be idempotent. The overlap guard reduces double-fire to the crash-and-reclaim residual; it cannot eliminate it. Backend redelivery and a reclaimed run that revives after its stale lock is taken can execute the same logical occurrence more than once. Terminal callbacks and terminal lifecycle hooks (`completed`, `failed`, `cancelled`, and `superseded`) have the same at-least-once crash window between the external effect and its persisted completion marker; replay of that window is durable under Action Scheduler and best-effort under the WP-Cron fallback. The `started` hook is an inline, non-durable notification on the admission or start path, so a crash between durable admission and hook delivery can lose it. A throwing `on_failed()` callback or terminal lifecycle hook remains pending for a later maintenance attempt, so a persistently failing consumer also retains the terminal row until it is fixed. The demo Task converges repeated deliveries by overwriting one stable consumer transient instead of appending a record or repeating an external command.

## Admission overlap and catch-up policies

Direct Task enqueue and Batch start coordinate active runs through a scoped overlap identity. A Task's overlap identity is its explicit deduplication key when provided and otherwise its arguments; a Batch's overlap identity is always its start arguments. Matching is scoped to the owner-qualified Task or Batch identity.

Schedule overlap is configured independently through `OverlapPolicy`. Catch-up determines what happens when a scheduled delivery is late beyond its grace window.

| Overlap policy | `RunOnce` catch-up (default) | `Skip` catch-up |
| --- | --- | --- |
| `Allow` | Dispatches one due or catch-up run even while matching work is active. | Drops a beyond-grace occurrence; otherwise dispatches even while matching work is active. |
| `Skip` (default) | Attempts one due or catch-up run and drops it while a fresh matching lock is held. | Drops a beyond-grace occurrence; otherwise dispatches only when no fresh matching lock is held. |
| `Replace` | Dispatches one due or catch-up run; if matching work holds the lock, transfers it to the new run. | Drops a beyond-grace occurrence; otherwise dispatches and transfers a held matching lock to the new run. |

`Allow` gives each run an independent overlap identity. `Skip` leaves the active run in place. `Replace` transfers a held matching lock, or acquires or reclaims it when no matching lock is held. An incumbent already inside a callback reaches its next fencing boundary rather than being interrupted mid-callback.

An occurrence becomes due at `next_due`. It is a misfire only when observed strictly after `next_due + grace`; equality is still within grace. Grace defaults to one interval and is filterable through `a8csp_background_tasks/misfire_grace/{identity}`. The `{identity}` suffix and `$identity` filter argument are the complete `{owner}:{name}` schedule identity. `RunOnce` attempts one make-up occurrence and realigns the recurrence without replaying every missed interval. `Skip` drops the occurrence, realigns the recurrence, and emits the misfire-skipped hooks.

Each persisted schedule-registration entry records `misfire_skips` for beyond-grace drops under the catch-up `Skip` policy and `overlap_skips` for due occurrences dropped while the target task's overlap lock is held.

## Hooks and filters

For each lifecycle pair, the identity-specific hook fires first and the generic companion follows with the identity prepended. Every `{identity}` suffix and every generic `$identity` payload is the complete `{owner}:{name}` identity. The misfire-skipped hooks likewise receive the complete schedule identity; `$owner` remains a separate argument.

| Event | Identity-specific hook and payload | Generic hook and payload |
| --- | --- | --- |
| Started | `a8csp_background_tasks/started/{identity}`: `($run_id, $start_args)` | `a8csp_background_tasks/started`: `($identity, $run_id, $start_args)` |
| Completed | `a8csp_background_tasks/completed/{identity}`: `($run_id, $start_args)` | `a8csp_background_tasks/completed`: `($identity, $run_id, $start_args)` |
| Failed | `a8csp_background_tasks/failed/{identity}`: `($run_id, $start_args, RunFailure $failure)` | `a8csp_background_tasks/failed`: `($identity, $run_id, $start_args, RunFailure $failure)` |
| Cancelled | `a8csp_background_tasks/cancelled/{identity}`: `($run_id, $start_args)` | `a8csp_background_tasks/cancelled`: `($identity, $run_id, $start_args)` |
| Retry scheduled | `a8csp_background_tasks/retry_scheduled/{identity}`: `($run_id, $start_args, $attempt, $delay)` | `a8csp_background_tasks/retry_scheduled`: `($identity, $run_id, $start_args, $attempt, $delay)` |
| Superseded | `a8csp_background_tasks/superseded/{identity}`: `($run_id, $start_args)` | `a8csp_background_tasks/superseded`: `($identity, $run_id, $start_args)` |
| Misfire skipped | `a8csp_background_tasks/misfire_skipped/{identity}`: `($owner, $due_at, $observed_at)` | `a8csp_background_tasks/misfire_skipped`: `($identity, $owner, $due_at, $observed_at)` |
| Log | `a8csp_background_tasks/log`: `($level, $message, $context)` | No generic companion. |

Run IDs, identities, owners, and log fields are strings; attempt, delay, and misfire timestamps are integers; argument and log-context payloads are arrays. `RunFailure` is the persisted terminal failure value. Misfire-skipped hooks fire only when `CatchUpPolicy::Skip` drops a beyond-grace occurrence.

Consumers do not hook the engine's internal delivery actions: `a8csp_background_tasks/start_batch`, `a8csp_background_tasks/continue_batch`, `a8csp_background_tasks/run_task`, `a8csp_background_tasks/run_chunk`, `a8csp_background_tasks/cleanup_batch`, or `a8csp_background_tasks/schedule_due`.

| Filter | Input and required return |
| --- | --- |
| `a8csp_background_tasks/queue/{identity}` | `($queue, $start_args, $run_id)` returns the complete list of chunk argument arrays. |
| `a8csp_background_tasks/continue_delay` | `($delay, $identity, $run_id)` returns a non-negative delay in seconds; the default is 60. It receives complete Task identities as well as complete Batch identities because the value also sets every run's lock-staleness floor at twice the delay — once twice the delay exceeds the lock-staleness window, raising it extends how long a crashed run waits for reclamation. |
| `a8csp_background_tasks/lock_staleness/{identity}` | `($seconds)` returns a positive lock window; the default is 900 and the effective value is at least twice the continue delay. |
| `a8csp_background_tasks/history_size` | `($size)` returns a positive per-buffer history cap; the default is 30. |
| `a8csp_background_tasks/retry_policy/{identity}` | `(RetryPolicy $policy)` returns a `RetryPolicy`; a foreign return leaves the contract policy in effect. |
| `a8csp_background_tasks/misfire_grace/{identity}` | `($grace, $owner, $identity)` returns a non-negative grace in seconds; the default is one interval. |
| `a8csp_background_tasks/log_to_error_log` | `($enabled)` returns whether to register the default PHP error-log sink; the default is `true`, and returning `false` disables it. |

## Bring your own PSR-3 logger

The engine writes `a8csp_background_tasks/log` events to PHP's configured error log by default. Given your own `Psr\Log\LoggerInterface` instance in `$logger`, disable that sink before the engine boots and attach a three-argument listener:

```php
use Psr\Log\LoggerInterface;

/** @var LoggerInterface $logger */
\add_filter(
	'a8csp_background_tasks/log_to_error_log',
	static fn ( bool $enabled ): bool => false
);
\add_action(
	'a8csp_background_tasks/log',
	static function ( string $level, string $message, array $context ) use ( $logger ): void {
		$logger->log( $level, $message, $context );
	},
	10,
	3
);
```

## Priority is advisory

Priority is an integer from 0 through 255 and defaults to 10. Action Scheduler receives it; WP-Cron accepts and ignores it because its event store has no priority dimension. Keeping the field in the common API permits transparent backend failover.

## Owner-bound schedule synchronization

`$consumer->schedules()->sync( $schedules )` converges the bound owner's complete declaration. No public Schedule method accepts an owner, so a consumer cannot synchronize another consumer's or the engine's schedules. Synchronization targets only engine-owned `a8csp_background_tasks/schedule_due` occurrences identified by the composed schedule identity, so it does not mutate foreign WP-Cron events or Action Scheduler actions.

Use a stable owner slug and pass every schedule owned by that consumer on every `init`. Passing an empty array removes only that owner's registry branch and occurrences on ready backends. An occurrence dormant on an unavailable backend outlives the registration and self-removes when that backend delivers it.

Action Scheduler becomes writable after `action_scheduler_init`, normally during `init` at priority 1. Synchronizing before that action fires routes occurrences to WP-Cron for that request; schedule the consumer callback after Action Scheduler's priority-1 initialization when that backend is required.

## Keep action arguments small

Public start and enqueue arguments are validated as JSON-encodable portable arguments and persisted in run state. The initial Task or Batch delivery carries only the engine envelope of identity, run ID, and sequence. The 8,000-byte JSON ceiling applies to each backend action payload. A Batch chunk action also includes one chunk's arguments, so every chunk must fit with the envelope. An oversized or unencodable payload fails with a corrective message naming the hook:

> Scheduling hook "&lt;hook&gt;" has arguments that cannot be JSON-encoded within the 8000-byte limit; pass identifying keys and load bulk data from storage inside the handler.

Bulk data belongs in storage that the Task or Batch reads by key. Pass identifying keys in action arguments. The tested Task carries its transient key. The tested Batch carries a `post_type` key, queries post IDs during queue generation, and puts one ID in each chunk.

## Run inspection, failure, retry, and cancellation

`$consumer->runs()->last_completed_run_id( $name )` returns the most recently recorded `Completed` run ID for the owner-local Task or Batch name. A successful lookup carries the run ID or `null` when no completed run remains in the retained history window; a failed, cancelled, or superseded run recorded later does not displace a retained completion. The lookup follows terminal recording order and does not re-sort the timestamp-prefixed run IDs.

Each history buffer retains at most the positive `a8csp_background_tasks/history_size` filter value, 30 by default. Once later terminal outcomes evict a completion, the lookup returns `Success(null)` as if that completion were absent. Consumers needing an indefinite checkpoint persist their own pointer from a Batch's `on_completed()` callback or the completed lifecycle hook. Terminal history is recorded after those notifications, so a lookup from either intentionally returns the previous retained completion.

A failed Task invocation or Batch chunk retries under its `RetryPolicy`, using bounded exponential delays with full jitter. The defaults are 3 attempts in total, including the first, a 60-second base delay, a multiplier of 2, and a 3,600-second delay cap. Batch retry counts reset for each chunk. Throw `NonRetryableException`, or another exception implementing `NonRetryableExceptionInterface`, to bypass the remaining attempts for a permanent failure.

After the final attempt, the engine writes the terminal failure to the per-identity failed store. It invokes the Batch `on_failed()` callback where applicable, followed by the failed hooks. Start a fresh run from the original arguments with `$consumer->runs()->retry_failed( $name, $run_id )` or `wp background-tasks failed-runs retry <owner>:<name> <run_id>`. A successful result carries the fresh run ID and means the work was scheduled; lifecycle hooks report its eventual outcome.

Cancel a retained run with `$consumer->runs()->cancel( $name, $run_id )` or `wp background-tasks runs cancel <owner>:<name> <run_id>`. Pending work, retry backoff, and a Batch waiting between chunks are cancellable. Cancellation is refused while an admitted lifecycle action is executing, whether it is in engine orchestration or a consumer callback. A Batch with no chunks left and cleanup pending is materially complete and is also refused. Cancelling a run does not remove its originating recurring Schedule.

Cancellation records the terminal outcome before it attempts to clear pending backend deliveries, so delivery cleanup is best effort. A ready Action Scheduler backend can clear the per-run group. WP-Cron cannot identify a group-only clear, so one pending event may survive, reach the engine admission hook, and be discarded without invoking consumer work. Cancelled hooks fire, and a cancelled Batch invokes neither `on_completed()` nor `on_failed()`.

## WP-CLI

The canonical command root is `wp background-tasks`; there is no alias.

| Operation | Effective synopsis |
| --- | --- |
| List failed runs | `wp background-tasks failed-runs list [--owner=<owner>] [--format=<format>]` |
| Retry a failed run | `wp background-tasks failed-runs retry <identity> <run_id>` |
| Purge failed runs for one identity | `wp background-tasks failed-runs purge <identity>` |
| Purge every discovered failed-run store | `wp background-tasks failed-runs purge --all` |
| Cancel a retained run | `wp background-tasks runs cancel <identity> <run_id>` |
| List schedules | `wp background-tasks schedules list [--owner=<owner>] [--format=<format>]` |
| List runs and recent history | `wp background-tasks runs list <identity> [--format=<format>]` |
| Destroy all engine state (development reset) | `wp background-tasks reset [--yes]` |

Every targeted `<identity>` argument is a composed `{owner}:{name}` identity. Failed-run and schedule list output include an `owner` column, carry the composed identity in an `identity` column, and accept an exact `--owner` filter. The list commands accept `table`, `csv`, `json`, `count`, or `yaml`; the default is `table`. Examples matching the command help are:

```sh
wp background-tasks failed-runs list
wp background-tasks failed-runs list --format=json
wp background-tasks failed-runs list --owner=consumer-plugin
wp background-tasks failed-runs retry consumer-plugin:email-digest 00000000000000000001-0000000000000000001
wp background-tasks failed-runs purge consumer-plugin:email-digest
wp background-tasks failed-runs purge --all
wp background-tasks runs cancel consumer-plugin:email-digest 00000000000000000001-0000000000000000001
wp background-tasks schedules list
wp background-tasks schedules list --owner=consumer-plugin --format=json
wp background-tasks runs list consumer-plugin:email-digest
wp background-tasks runs list consumer-plugin:email-digest --format=json
wp background-tasks reset
wp background-tasks reset --yes
```

`reset` permanently deletes every engine-owned option row and pending backend action. It is a development reset, not an operational cancellation workflow: it destroys in-flight work irrecoverably, including the engine maintenance registration, which the next boot synchronization recreates. The command prompts for confirmation, and `--yes` skips the prompt.

`schedules list` reports `owner`, `identity`, `recurrence`, `next_due`, `last_fired`, `misfire_skips`, `overlap_skips`, `occurrence_visible`, and `lock`. The `occurrence_visible` value reflects state visible through ready backends. When registrations are listed in table format, the command adds a note if a present backend is not ready and may hold dormant occurrences.

`runs list` table output separates live runs from bounded recent history. History rows expose `run_id`, `outcome`, and `failed_store`; the `failed_store` cell renders as `failed store` when the failure is available to `failed-runs retry`, or `—` otherwise. A waiting live run has a backend delivery or retry pending; an executing run has an admitted lifecycle action in progress, which may be engine orchestration or a consumer callback. For a Batch, the queue count retains the current chunk until that chunk returns normally. A stale heartbeat on an executing row identifies work that maintenance can reclaim.

## Releasing

Releases are cut by pushing a version tag. The release workflow fails closed unless the plugin header, `package.json`, and the newest `CHANGELOG.md` entry all agree with the tag, then builds and smoke-tests the distribution ZIP; prereleases publish outside the stable update channel.

`CHANGELOG.md` is generated from the fragments in `changelog/` by `composer changelog:write`, which derives the next version from the newest existing changelog entry and the fragments' significance. The first release starts from an empty changelog, so it must pass its version explicitly:

```sh
composer changelog:write -- --use-version=1.0.0-beta.1
```

Later entries in a prerelease chain also take an explicit version (`--use-version`, or the `--prerelease` suffix option); from the first stable entry onward, a bare `composer changelog:write` suffices.
