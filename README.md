# A8CSP Background Tasks Engine

**Contributors:** wpspecialprojects
**Tags:**
**Requires at least:** 7.0
**Tested up to:** 7.0
**Requires PHP:** 8.5
**Stable tag:** 1.0.0
**License:** GPL v2 or later
**License URI:** <https://www.gnu.org/licenses/gpl-2.0.html>

A background-work engine for WordPress sites: Tasks, Schedules, and Batches on pluggable scheduling backends.

## What it is

A Task is one named unit of background work. A consumer registers a `TaskInterface` instance and dispatches it with arguments through the engine facade or the procedural API.

A Schedule is an owner-scoped declaration that dispatches a registered Task on a fixed recurrence. The declaration includes the task arguments, overlap policy, catch-up policy, and advisory priority.

A Batch is named work split into independently processed chunks. A consumer registers a `BatchInterface`; the engine persists the queue, retries each failed chunk independently, and invokes one terminal callback after success or failure.

The facade prefers Action Scheduler and uses WP-Cron as its baseline backend. Consumers use the same API with either backend. An occurrence on a temporarily unavailable backend is dormant, not lost; new writes can use another ready backend, and the dormant occurrence becomes visible again when its backend recovers.

## Quick start

The executable fixture contains the complete [`SiteHealthPingTask`](tests/Support/Fixtures/SiteHealthPingTask.php) and [`CommentCountRecountBatch`](tests/Support/Fixtures/CommentCountRecountBatch.php) implementations. Its consumer entry point registers both contracts and synchronizes one hourly schedule from `init`:

```php
namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\Fixtures;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Schedule;

final readonly class DemoConsumer {
	public const OWNER = 'a8csp-bgte-demo';

	public const SCHEDULE_NAME = 'site-health-ping';

	public const LOG_HOOK = 'a8csp_bgte_demo/log';

	public function __construct(
		private int $site_health_interval = \HOUR_IN_SECONDS
	) {
		if ( 1 > $this->site_health_interval ) {
			throw new \InvalidArgumentException(
				'The demo site-health interval must be positive; pass at least one second.'
			);
		}
	}

	public function boot(): void {
		\add_action( 'init', array( $this, 'register_background_work' ) );
	}

	public function register_background_work(): void {
		$engine = \a8csp_bgte_engine();
		if ( null === $engine ) {
			\do_action(
				self::LOG_HOOK,
				'error',
				'The demo consumer could not register because the background tasks engine is unavailable.',
				array()
			);

			return;
		}

		$engine->tasks()->register( new SiteHealthPingTask() );
		$engine->batches()->register( new CommentCountRecountBatch() );

		$synced = $engine->schedules()->sync(
			self::OWNER,
			array(
				new Schedule(
					self::SCHEDULE_NAME,
					Recurrence::every( $this->site_health_interval ),
					SiteHealthPingTask::NAME,
					array( 'transient' => SiteHealthPingTask::SNAPSHOT_TRANSIENT ),
					OverlapPolicy::Skip,
					CatchUpPolicy::RunOnce,
					10
				),
			)
		);
		if ( $synced->is_failure() ) {
			\do_action(
				self::LOG_HOOK,
				'error',
				'The demo consumer could not synchronize its site-health schedule.',
				array( 'error' => $synced->error->message )
			);
		}
	}
}

( new DemoConsumer() )->boot();
```

Schedule synchronization treats the passed array as the owner's complete declaration, so call it on every `init`. After `init` registers the contracts, enqueue the Task or start the Batch through the procedural API:

```php
$task_result = a8csp_bgte_enqueue_task(
	SiteHealthPingTask::NAME,
	array( 'transient' => SiteHealthPingTask::SNAPSHOT_TRANSIENT )
);

$batch_result = a8csp_bgte_start_batch(
	CommentCountRecountBatch::NAME,
	array( 'post_type' => 'post' )
);
```

Every mutation that schedules work returns `Success` or `Failure`. A successful result means the work was scheduled, not that its handler completed. Branch with `is_success()` or `is_failure()`, then read the narrowed result's `value` or `error` property.

## The three contracts

### Task

`TaskInterface` defines a stable name, a handler, and a retry policy. A normal handler return succeeds; a thrown exception fails the attempt.

```php
interface TaskInterface {
	public function get_name(): string;

	public function handle( array $args ): void;

	public function get_retry_policy(): RetryPolicy;
}
```

### Batch and batch context

`BatchInterface` generates initial chunks, processes one chunk at a time, receives one terminal callback, and supplies the retry policy used independently for each failed chunk.

```php
interface BatchInterface {
	public function get_name(): string;

	public function generate_queue( array $start_args ): iterable;

	public function process_chunk(
		array $chunk_args,
		BatchContextInterface $context
	): void;

	public function on_success( string $run_id, array $start_args ): void;

	public function on_failure(
		string $run_id,
		array $start_args,
		EngineError $error
	): void;

	public function get_retry_policy(): RetryPolicy;
}
```

`BatchContextInterface` exposes only the current run. Queue mutations are transactional within the chunk attempt: they take effect after a normal return and are discarded when the attempt throws.

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

Use `Recurrence::every( $seconds )` for v1 schedule synchronization. `Recurrence::cron( $expression )` can represent a calendar expression, but v1 synchronization returns a failure because the recurring backend port currently supports fixed intervals only.

## Idempotency invariant

Schedule-driven tasks MUST be idempotent. The overlap guard reduces double-fire to the crash-and-reclaim residual; it cannot eliminate it. Backend redelivery and a reclaimed run that revives after its stale lock is taken can execute the same logical occurrence more than once. The demo Task converges repeated deliveries by overwriting one stable consumer transient instead of appending a record or repeating an external command.

## Overlap and catch-up policies

Overlap applies to a matching task name and argument identity. Catch-up determines what happens when a delivery is late beyond its grace window.

| Overlap policy | `RunOnce` catch-up (default) | `Skip` catch-up |
| --- | --- | --- |
| `Allow` | Dispatches one due or catch-up run even while matching work is active. | Drops a beyond-grace occurrence; otherwise dispatches even while matching work is active. |
| `Skip` (default) | Attempts one due or catch-up run and drops it while a fresh matching lock is held. | Drops a beyond-grace occurrence; otherwise dispatches only when no fresh matching lock is held. |
| `Replace` | Dispatches one due or catch-up run and transfers the matching lock to it. | Drops a beyond-grace occurrence; otherwise dispatches and transfers the matching lock to it. |

`Allow` gives each run an independent overlap identity. `Skip` leaves the active run in place. `Replace` transfers ownership; an incumbent already inside a callback reaches its next fencing boundary rather than being interrupted mid-callback.

An occurrence becomes due at `next_due`. It is a misfire only when observed strictly after `next_due + grace`; equality is still within grace. Grace defaults to one interval and is filterable through `a8csp_background_tasks/misfire_grace/{schedule}`. `RunOnce` attempts one make-up occurrence and realigns the recurrence without replaying every missed interval. `Skip` drops the occurrence, realigns the recurrence, and emits the misfired hooks.

## Hooks and filters

For each lifecycle pair, the dynamic hook fires first and the generic companion follows with the name prepended.

| Event | Dynamic hook and payload | Generic hook and payload |
| --- | --- | --- |
| Started | `started/{name}`: `($run_id, $start_args)` | `started`: `($name, $run_id, $start_args)` |
| Completed | `completed/{name}`: `($run_id, $start_args)` | `completed`: `($name, $run_id, $start_args)` |
| Failed | `failed/{name}`: `($run_id, $start_args, EngineError $error)` | `failed`: `($name, $run_id, $start_args, EngineError $error)` |
| Retrying | `retrying/{name}`: `($run_id, $start_args, $attempt, $delay)` | `retrying`: `($name, $run_id, $start_args, $attempt, $delay)` |
| Superseded | `superseded/{name}`: `($run_id, $start_args)` | `superseded`: `($name, $run_id, $start_args)` |
| Misfired | `misfired/{schedule}`: `($owner, $due_at, $observed_at)` | `misfired`: `($schedule, $owner, $due_at, $observed_at)` |
| Log | `log`: `($level, $message, $context)` | No generic companion. |

All hook names above use the `a8csp_background_tasks/` prefix. Run IDs, names, owners, and log fields are strings; attempt, delay, and misfire timestamps are integers; argument and log-context payloads are arrays. `EngineError` is the persisted terminal failure value. Misfired hooks fire only when `CatchUpPolicy::Skip` drops a beyond-grace occurrence.

Consumers never hook the engine's internal delivery actions: `start`, `continue`, `run`, `cleanup`, or `schedule_due`.

| Filter | Input and required return |
| --- | --- |
| `queue/{batch}` | `($queue, $start_args, $run_id)` returns the complete list of chunk argument arrays. |
| `continue_delay` | `($delay, $name, $run_id)` returns a non-negative delay in seconds; the default is 60. It receives Task names as well as Batch names: the value spaces Batch chunk continuations and also feeds every run's lock-staleness floor, so scope callbacks by `$name`. |
| `lock_staleness/{name}` | `($seconds)` returns a positive lock window; the default is 900 and the effective value is at least twice the continue delay. |
| `history_size` | `($size)` returns a positive per-buffer history cap; the default is 30. |
| `retry_policy/{name}` | `(RetryPolicy $policy)` returns a `RetryPolicy`; a foreign return leaves the contract policy in effect. |
| `misfire_grace/{schedule}` | `($grace, $owner, $schedule)` returns a non-negative grace in seconds; the default is one interval. |

Filter names also use the `a8csp_background_tasks/` prefix.

## Priority is advisory

Priority is an integer from 0 through 255, defaults to 10, and sorts lower values first where the backend supports ordering. Action Scheduler honors it. WP-Cron ignores it. The facade still accepts the field for WP-Cron because rejecting an advisory dimension would break transparent backend failover.

## Owner-scoped schedule synchronization

`$engine->schedules()->sync( $owner, $schedules )` converges one owner's complete declaration. Synchronization only ever mutates engine-owned hooks; foreign WP-Cron events and Action Scheduler actions are structurally unreachable. Orphan detection is scoped to the supplied owner, so it can never see or remove another consumer's schedules as orphan candidates.

Use a stable owner slug and pass every schedule owned by that consumer on every `init`. Passing an empty array removes only that owner's registry branch and occurrences on ready backends. An occurrence dormant on an unavailable backend outlives the registration and self-removes when that backend later delivers it.

## Keep action arguments small

Public start and enqueue arguments are validated as JSON-encodable scalar trees and persisted in the run state; the scheduled backend action carries only the engine's envelope (name, run ID, sequence). The 8,000-byte JSON ceiling applies to each backend action payload — for a Batch that is one chunk's arguments plus that envelope, so every chunk must fit within it. An oversized or unencodable payload fails with a corrective message naming the hook:

> Scheduling hook "&lt;hook&gt;" has arguments that cannot be JSON-encoded within the 8000-byte limit; pass identifying keys and load bulk data from storage inside the handler.

Bulk data goes in storage that the task or batch reads by key. Actions carry identifying keys only. The demo Task carries its transient key. The demo Batch carries a `post_type` key, queries post IDs during queue generation, and puts one ID in each chunk.

## WP-CLI

The canonical command is `wp background-tasks`; there is no alias. Failed runs can be listed, retried, or purged:

```sh
wp background-tasks failed list --format=table
wp background-tasks failed retry a8csp-bgte-demo-site-health-ping 00000000000000000001-0000000000000000001
wp background-tasks failed purge a8csp-bgte-demo-site-health-ping
```

Use `wp background-tasks failed purge --all` to purge every retained failed-run store.

## Failure lifecycle in brief

A failed Task invocation or Batch chunk retries under its `RetryPolicy`, using bounded exponential delays with full jitter. The defaults are 3 attempts in total (including the first), a 60-second base delay, a multiplier of 2, and a 3,600-second delay cap. Batch retry counts reset for each chunk. Throw an exception implementing `NonRetryableExceptionInterface` to bypass the remaining attempts for a permanent failure.

After the final attempt, the engine writes the terminal failure to the per-name failed store. It then invokes the Batch failure callback where applicable, followed by the failed hooks. Start a fresh run from the original arguments with `$engine->retry_failed( $name, $run_id )`, `a8csp_bgte_retry_failed_run( $name, $run_id )`, or `wp background-tasks failed retry <name> <run-id>`. A successful retry result means the new run was scheduled; lifecycle hooks report its eventual outcome.
