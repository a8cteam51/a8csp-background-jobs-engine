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

A Batch is named work split into independently processed chunks. A consumer registers a `BatchInterface`; the engine persists the queue, retries each failed chunk independently, and invokes one terminal callback after success or failure. Cancelled and superseded Batches invoke neither terminal callback.

Consumers use the same API with either scheduling backend. An occurrence on a temporarily unavailable backend is dormant, not lost; writes can use another ready backend, and the dormant occurrence becomes visible when its backend recovers.

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

## Quick start

In a consumer plugin under its own namespace, register the Task and Batch implementations and synchronize the owner's complete schedule declaration from `init`:

```php
namespace Acme\BackgroundTasks;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\OverlapPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Schedule;

final class BackgroundTasksRegistration {
	private const OWNER = 'acme-background-work';

	private const SCHEDULE_NAME = 'site-health-ping';

	public static function register(): void {
		$engine = \a8csp_bgte_engine();
		if ( null === $engine ) {
			\error_log( 'The background tasks engine is unavailable.' );
			return;
		}

		$engine->tasks()->register( new SiteHealthPingTask() );
		$engine->batches()->register( new CommentCountRecountBatch() );

		$synced = $engine->schedules()->sync(
			self::OWNER,
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
			\error_log( 'Background schedule sync failed: ' . $synced->error->message );
		}
	}
}

\add_action( 'init', array( BackgroundTasksRegistration::class, 'register' ) );
```

`SiteHealthPingTask` and `CommentCountRecountBatch` are consumer-owned implementations of the contracts below. See the tested version of this example in [`DemoConsumer`](tests/Support/Fixtures/DemoConsumer.php), [`SiteHealthPingTask`](tests/Support/Fixtures/SiteHealthPingTask.php), and [`CommentCountRecountBatch`](tests/Support/Fixtures/CommentCountRecountBatch.php).

Schedule synchronization treats the passed array as the owner's complete declaration, so call it on every `init`. After `init` registers the contracts, enqueue the Task or start the Batch through the procedural API:

```php
$task_result = \a8csp_bgte_enqueue_task(
	SiteHealthPingTask::NAME,
	array( 'transient' => 'acme_site_health_snapshot' )
);

$batch_result = \a8csp_bgte_start_batch(
	CommentCountRecountBatch::NAME,
	array( 'post_type' => 'post' )
);
```

Scheduling, retry, and cancellation methods return `Success` or `Failure`. A successful scheduling result means the work was accepted, not that its handler completed. Branch with `is_success()` or `is_failure()`, then read the narrowed result's `value` or `error` property.

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

`BatchInterface` generates initial chunks, processes one chunk at a time, receives one terminal callback after success or failure, and supplies the retry policy used independently for each failed chunk. Cancellation and supersession invoke neither callback.

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

Use `Recurrence::every( $seconds )` for fixed-interval schedule synchronization. `Recurrence::cron( $expression )` represents a calendar expression, but synchronization returns a failure because the recurring backend port supports fixed intervals only.

## Idempotency invariant

Schedule-driven tasks MUST be idempotent. The overlap guard reduces double-fire to the crash-and-reclaim residual; it cannot eliminate it. Backend redelivery and a reclaimed run that revives after its stale lock is taken can execute the same logical occurrence more than once. The demo Task converges repeated deliveries by overwriting one stable consumer transient instead of appending a record or repeating an external command.

## Overlap and catch-up policies

Overlap applies to a matching task name and argument identity. Catch-up determines what happens when a delivery is late beyond its grace window.

| Overlap policy | `RunOnce` catch-up (default) | `Skip` catch-up |
| --- | --- | --- |
| `Allow` | Dispatches one due or catch-up run even while matching work is active. | Drops a beyond-grace occurrence; otherwise dispatches even while matching work is active. |
| `Skip` (default) | Attempts one due or catch-up run and drops it while a fresh matching lock is held. | Drops a beyond-grace occurrence; otherwise dispatches only when no fresh matching lock is held. |
| `Replace` | Dispatches one due or catch-up run; if matching work holds the lock, transfers it to the new run. | Drops a beyond-grace occurrence; otherwise dispatches and transfers a held matching lock to the new run. |

`Allow` gives each run an independent overlap identity. `Skip` leaves the active run in place. `Replace` transfers a held matching lock, or acquires or reclaims it when no matching lock is held. An incumbent already inside a callback reaches its next fencing boundary rather than being interrupted mid-callback.

An occurrence becomes due at `next_due`. It is a misfire only when observed strictly after `next_due + grace`; equality is still within grace. Grace defaults to one interval and is filterable through `a8csp_background_tasks/misfire_grace/{schedule}`. `RunOnce` attempts one make-up occurrence and realigns the recurrence without replaying every missed interval. `Skip` drops the occurrence, realigns the recurrence, and emits the misfired hooks.

## Hooks and filters

For each lifecycle pair, the name-specific hook fires first and the generic companion follows with the name prepended.

| Event | Name-specific hook and payload | Generic hook and payload |
| --- | --- | --- |
| Started | `a8csp_background_tasks/started/{name}`: `($run_id, $start_args)` | `a8csp_background_tasks/started`: `($name, $run_id, $start_args)` |
| Completed | `a8csp_background_tasks/completed/{name}`: `($run_id, $start_args)` | `a8csp_background_tasks/completed`: `($name, $run_id, $start_args)` |
| Failed | `a8csp_background_tasks/failed/{name}`: `($run_id, $start_args, EngineError $error)` | `a8csp_background_tasks/failed`: `($name, $run_id, $start_args, EngineError $error)` |
| Cancelled | `a8csp_background_tasks/cancelled/{name}`: `($run_id, $start_args)` | `a8csp_background_tasks/cancelled`: `($name, $run_id, $start_args)` |
| Retrying | `a8csp_background_tasks/retrying/{name}`: `($run_id, $start_args, $attempt, $delay)` | `a8csp_background_tasks/retrying`: `($name, $run_id, $start_args, $attempt, $delay)` |
| Superseded | `a8csp_background_tasks/superseded/{name}`: `($run_id, $start_args)` | `a8csp_background_tasks/superseded`: `($name, $run_id, $start_args)` |
| Misfired | `a8csp_background_tasks/misfired/{schedule}`: `($owner, $due_at, $observed_at)` | `a8csp_background_tasks/misfired`: `($schedule, $owner, $due_at, $observed_at)` |
| Log | `a8csp_background_tasks/log`: `($level, $message, $context)` | No generic companion. |

Run IDs, names, owners, and log fields are strings; attempt, delay, and misfire timestamps are integers; argument and log-context payloads are arrays. `EngineError` is the persisted terminal failure value. Misfired hooks fire only when `CatchUpPolicy::Skip` drops a beyond-grace occurrence.

Consumers do not hook the engine's internal delivery actions: `a8csp_background_tasks/start`, `a8csp_background_tasks/continue`, `a8csp_background_tasks/run`, `a8csp_background_tasks/cleanup`, or `a8csp_background_tasks/schedule_due`.

| Filter | Input and required return |
| --- | --- |
| `a8csp_background_tasks/queue/{batch}` | `($queue, $start_args, $run_id)` returns the complete list of chunk argument arrays. |
| `a8csp_background_tasks/continue_delay` | `($delay, $name, $run_id)` returns a non-negative delay in seconds; the default is 60. It receives Task names as well as Batch names because the value also feeds every run's lock-staleness floor. |
| `a8csp_background_tasks/lock_staleness/{name}` | `($seconds)` returns a positive lock window; the default is 900 and the effective value is at least twice the continue delay. |
| `a8csp_background_tasks/history_size` | `($size)` returns a positive per-buffer history cap; the default is 30. |
| `a8csp_background_tasks/retry_policy/{name}` | `(RetryPolicy $policy)` returns a `RetryPolicy`; a foreign return leaves the contract policy in effect. |
| `a8csp_background_tasks/misfire_grace/{schedule}` | `($grace, $owner, $schedule)` returns a non-negative grace in seconds; the default is one interval. |

## Bring your own PSR-3 logger

The default `Utilities\Logging\ErrorLogSink` listener writes `a8csp_background_tasks/log` events to PHP's configured error log. Given your own `Psr\Log\LoggerInterface` instance in `$logger`, remove that listener after the engine boots and attach a three-argument listener:

```php
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Logging\ErrorLogSink;
use Psr\Log\LoggerInterface;

/** @var LoggerInterface $logger */
\add_action(
	'plugins_loaded',
	static function () use ( $logger ): void {
		\remove_action(
			'a8csp_background_tasks/log',
			array( ErrorLogSink::class, 'log' ),
			10
		);
		\add_action(
			'a8csp_background_tasks/log',
			static function ( string $level, string $message, array $context ) use ( $logger ): void {
				$logger->log( $level, $message, $context );
			},
			10,
			3
		);
	},
	20
);
```

## Priority is advisory

Priority is an integer from 0 through 255 and defaults to 10. Action Scheduler receives it; WP-Cron accepts and ignores it because its event store has no priority dimension. Keeping the field in the common API permits transparent backend failover.

## Owner-scoped schedule synchronization

`$engine->schedules()->sync( $owner, $schedules )` converges one owner's complete declaration. Synchronization targets only engine-owned `a8csp_background_tasks/schedule_due` occurrences identified by owner and schedule name, so it does not mutate foreign WP-Cron events or Action Scheduler actions. Orphan detection is scoped to the supplied owner and cannot remove another consumer's schedules.

Use a stable owner slug and pass every schedule owned by that consumer on every `init`. Passing an empty array removes only that owner's registry branch and occurrences on ready backends. An occurrence dormant on an unavailable backend outlives the registration and self-removes when that backend delivers it.

## Keep action arguments small

Public start and enqueue arguments are validated as JSON-encodable scalar trees and persisted in run state. The initial Task or Batch delivery carries only the engine envelope of name, run ID, and sequence. The 8,000-byte JSON ceiling applies to each backend action payload. A Batch chunk action also includes one chunk's arguments, so every chunk must fit with the envelope. An oversized or unencodable payload fails with a corrective message naming the hook:

> Scheduling hook "&lt;hook&gt;" has arguments that cannot be JSON-encoded within the 8000-byte limit; pass identifying keys and load bulk data from storage inside the handler.

Bulk data belongs in storage that the Task or Batch reads by key. Pass identifying keys in action arguments. The tested Task carries its transient key. The tested Batch carries a `post_type` key, queries post IDs during queue generation, and puts one ID in each chunk.

## Failure, retry, and cancellation

A failed Task invocation or Batch chunk retries under its `RetryPolicy`, using bounded exponential delays with full jitter. The defaults are 3 attempts in total, including the first, a 60-second base delay, a multiplier of 2, and a 3,600-second delay cap. Batch retry counts reset for each chunk. Throw an exception implementing `NonRetryableExceptionInterface` to bypass the remaining attempts for a permanent failure.

After the final attempt, the engine writes the terminal failure to the per-name failed store. It invokes the Batch failure callback where applicable, followed by the failed hooks. Start a fresh run from the original arguments with `$engine->retry_failed( $name, $run_id )`, `a8csp_bgte_retry_failed_run( $name, $run_id )`, or `wp background-tasks failed retry <name> <run_id>`. Retry belongs directly on `Engine`, not on the Task or Batch API. A successful result carries the fresh run ID and means the work was scheduled; lifecycle hooks report its eventual outcome.

Cancel a retained run with `$engine->cancel( $name, $run_id )`, `a8csp_bgte_cancel_run( $name, $run_id )`, or `wp background-tasks cancel <name> <run_id>`. Pending work, retry backoff, and a Batch waiting between chunks are cancellable. Cancellation is refused while an admitted lifecycle action is executing, whether it is in engine orchestration or a consumer callback. A Batch with no chunks left and cleanup pending is materially complete and is also refused. Cancelling a run does not remove its originating recurring Schedule.

Cancellation records the terminal outcome before it attempts to clear pending backend deliveries, so delivery cleanup is best effort. A ready Action Scheduler backend can clear the per-run group. WP-Cron cannot identify a group-only clear, so one pending event may survive, reach the engine admission hook, and be discarded without invoking consumer work. Cancelled hooks fire, and a cancelled Batch invokes neither `on_success()` nor `on_failure()`.

## WP-CLI

The canonical command root is `wp background-tasks`; there is no alias.

| Operation | Effective synopsis |
| --- | --- |
| List failed runs | `wp background-tasks failed list [--format=<format>]` |
| Retry a failed run | `wp background-tasks failed retry <name> <run_id>` |
| Purge failed runs for one name | `wp background-tasks failed purge <name>` |
| Purge every discovered failed-run store | `wp background-tasks failed purge --all` |
| Cancel a retained run | `wp background-tasks cancel <name> <run_id>` |
| List schedules | `wp background-tasks schedules list [--owner=<owner>] [--format=<format>]` |
| List runs and recent history | `wp background-tasks runs list <name> [--format=<format>]` |

The list commands accept `table`, `csv`, `json`, `count`, or `yaml`; the default is `table`. Examples matching the command help are:

```sh
wp background-tasks failed list
wp background-tasks failed list --format=json
wp background-tasks failed retry email-digest 00000000000000000001-0000000000000000001
wp background-tasks failed purge email-digest
wp background-tasks failed purge --all
wp background-tasks cancel email-digest 00000000000000000001-0000000000000000001
wp background-tasks schedules list
wp background-tasks schedules list --owner=consumer-plugin --format=json
wp background-tasks runs list email-digest
wp background-tasks runs list email-digest --format=json
```

`schedules list` reports persisted registrations and state visible through ready backends. When registrations are listed in table format, the command adds a note if a present backend is not ready and may hold dormant occurrences.

`runs list` table output separates live runs from bounded recent history. A waiting live run has a backend delivery or retry pending; an executing run has an admitted lifecycle action in progress, which may be engine orchestration or a consumer callback. For a Batch, the queue count retains the current chunk until that chunk returns normally. A stale heartbeat on an executing row identifies work that maintenance can reclaim, and `failed store` marks a failure available to `failed retry`.
