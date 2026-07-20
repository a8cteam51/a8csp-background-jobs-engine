# A8CSP Background Jobs Engine

**Contributors:** wpspecialprojects
**Tags:**
**Requires at least:** 7.0
**Tested up to:** 7.0
**Requires PHP:** 8.5
**Stable tag:** 1.0.0-beta.1
**License:** GPL v2 or later
**License URI:** <https://www.gnu.org/licenses/gpl-2.0.html>

A background-work engine for WordPress sites: Jobs, Schedules, and Chunked Jobs using Action Scheduler when available, with a documented best-effort WP-Cron fallback.

## What it is

A **Job** is one named unit of background work — a callable, or a `OneOffJobInterface` implementation. A **Chunked Job** is named work split into independently processed chunks. A **Schedule** dispatches a registered Job on a fixed recurrence. Every piece of work belongs to an **owner** (your plugin slug); the engine composes `{owner}:{name}` into one identity so two plugins can never collide.

There are **two ways to drive the engine, over the same core**:

- **The procedural functions** — flat `a8csp_bgje_*()` calls that return `T | WP_Error`. This is the shortest path from Action Scheduler and the surface most examples below use.
- **The owner-bound `Client`** — `a8csp_bgje( $owner )` returns a typed facade; its scheduling and run commands return a sealed `Result` object (`Success` | `Failure<ApiError>`), while registration returns `void` and throws. Use it when you want the typed error model and static exhaustiveness. The functions are a thin translation over this same `Client`.

Delivery uses Action Scheduler when it is ready and falls back to WP-Cron otherwise. At-least-once delivery is guaranteed **only under Action Scheduler**; WP-Cron is best-effort. An occurrence on a temporarily unavailable backend is dormant, not lost.

The engine's own state persists in non-autoloaded `wp_options` rows under the reserved `a8csp_bgje_` prefix, so it adds no weight to ordinary page loads. The dynamic families are `a8csp_bgje_schedule_registrations_{owner}`, `a8csp_bgje_run_{identity}_{run_id}`, `a8csp_bgje_failed_runs_{identity}`, `a8csp_bgje_latest_run_{identity}`, `a8csp_bgje_history_{identity}`, `a8csp_bgje_overlap_lock_{identity}_{args_hash}`, `a8csp_bgje_occurrence_lease_{registration_hash}`, and `a8csp_bgje_cleanup_intent_{registration_hash}`.

## Installation

The canonical install is the plugin ZIP attached to a [GitHub Release](https://github.com/a8cteam51/a8csp-background-tasks-engine/releases). Download it, upload it as a WordPress plugin, and activate it. The release ZIP bundles production Composer dependencies and the translation template, so it needs no Composer step. Installed copies receive release updates through the dashboard like any plugin.

For a source checkout, clone into `wp-content/plugins/a8csp-background-jobs-engine` and install production dependencies:

```sh
cd wp-content/plugins
git clone https://github.com/a8cteam51/a8csp-background-tasks-engine.git a8csp-background-jobs-engine
cd a8csp-background-jobs-engine
composer install --no-dev
```

Action Scheduler is optional and preferred when ready; when absent, the engine runs on WP-Cron alone.

## When to call the engine

Everything is available from the WordPress `init` hook and later — resolving work earlier throws. Register your work and synchronize your schedules from `init` **on every request**: registration is per-request, and schedule synchronization treats the array you pass as the owner's complete declaration. Prefer `init` priority `2` or later so Action Scheduler's `init:1` store initialization has run; synchronizing before it routes that request's occurrences to WP-Cron.

Pass your plugin's lowercase slug as the owner (matching `[a-z0-9][a-z0-9-]*`, at most 32 bytes, never starting with the reserved `a8csp-jobs-engine` prefix). Job, Chunked Job, and Schedule names match `[a-z0-9_-]+` and are at most 64 bytes.

## Quick start

```php
add_action( 'init', 'my_plugin_register_background_work', 2 );

function my_plugin_register_background_work(): void {
	// A Job can be a plain callable — no class required.
	$registered = a8csp_bgje_job_register(
		'my-plugin',
		'refresh-cache',
		static function ( array $args ): void {
			my_plugin_refresh_cache( (int) ( $args['site_id'] ?? 0 ) );
		}
	);
	if ( is_wp_error( $registered ) ) {
		error_log( 'Background job registration failed: ' . $registered->get_error_message() );
		return;
	}

	// Declare this owner's complete recurring-schedule set, every init.
	$synced = a8csp_bgje_schedule_sync(
		'my-plugin',
		array(
			array(
				'name'  => 'hourly-refresh',
				'every' => HOUR_IN_SECONDS,
				'job'  => 'refresh-cache',
				'args'  => array( 'site_id' => get_current_blog_id() ),
			),
		)
	);
	if ( is_wp_error( $synced ) ) {
		error_log( 'Background schedule sync failed: ' . $synced->get_error_message() );
	}
}
```

Every fallible `a8csp_bgje_*()` function returns its value on success or a `WP_Error` on an expected failure, and is marked `#[\NoDiscard]` so ignoring the result is a mistake. A `WP_Error` carries a stable string code (`$error->get_error_code()` — for example `overlap_held`, `unknown_work`, `payload_rejected`), an engine-authored message, and redaction-safe structured data (`$error->get_error_data()`). Calling the engine before `init`, or resolving it while the engine graph is unavailable, throws a `LogicException`; expected operation failures return a `WP_Error`.

## Examples

Five complete, copy-paste-adaptable scenarios. Each assumes the owner slug `my-plugin`.

### 1. A recurring schedule, full lifecycle

Define the job, declare the schedule on every `init`, and remove it cleanly on deactivation.

```php
add_action( 'init', 'my_plugin_boot_schedules', 2 );

function my_plugin_boot_schedules(): void {
	$registered = a8csp_bgje_job_register(
		'my-plugin',
		'prune-transients',
		static function ( array $args ): void {
			my_plugin_prune_expired_transients();
		}
	);
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
		return;
	}

	// The array is the COMPLETE set for this owner: a schedule you omit here
	// is removed. 'catch_up' defaults to 'run_once'.
	$synced = a8csp_bgje_schedule_sync(
		'my-plugin',
		array(
			array(
				'name'     => 'nightly-prune',
				'every'    => DAY_IN_SECONDS,
				'job'     => 'prune-transients',
				'catch_up' => 'run_once',  // 'run_once' | 'skip'
			),
		)
	);
	if ( is_wp_error( $synced ) ) {
		error_log( $synced->get_error_message() );
	}
}

// Deactivation hygiene: converge this owner's schedules to empty so nothing
// keeps firing after your plugin is gone. Existing admitted runs are not
// cancelled here — recurring occurrences stop, in-flight runs finish.
register_deactivation_hook( __FILE__, static function (): void {
	if ( 0 === did_action( 'init' ) && ! doing_action( 'init' ) ) {
		error_log( 'Run: wp background-jobs schedules remove my-plugin --yes' );
		return;
	}

	$removed = a8csp_bgje_schedule_sync( 'my-plugin', array() );
	if ( is_wp_error( $removed ) ) {
		error_log( $removed->get_error_message() );
	}
} );
```

A new schedule first runs at `now + every`; there is no first-run timestamp field. If the client is already inactive when you need to clean up, run `wp background-jobs schedules remove my-plugin`.

### 2. A one-shot job, dispatched asynchronously

Register once from `init`, then enqueue whenever you have work. `delay_seconds` is a **relative** delay, not an absolute timestamp. A successful return is the run ID — proof the work was *accepted*, not that it finished.

```php
add_action( 'init', static function (): void {
	$registered = a8csp_bgje_job_register(
		'my-plugin',
		'email-digest',
		static function ( array $args ): void {
			my_plugin_send_digest( (int) $args['user_id'] );
		}
	);
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
	}
}, 2 );

function my_plugin_queue_digest( int $user_id ): void {
	$run_id = a8csp_bgje_job_enqueue(
		'my-plugin',
		'email-digest',
		array( 'user_id' => $user_id ),
		delay_seconds: 0 // run as soon as a worker picks it up
	);
	if ( is_wp_error( $run_id ) ) {
		if ( 'overlap_held' === $run_id->get_error_code() ) {
			return; // one is already queued or running for these arguments — fine.
		}
		error_log( $run_id->get_error_message() );
		return;
	}

	update_user_meta( $user_id, 'my_plugin_last_digest_run', $run_id );
}
```

The bridge-backed Job uses its arguments as the overlap identity and rejects a second identical enqueue while the first is live. Keep arguments small — pass identifying keys, not bulk data (there is an 8,192-byte encoded-JSON ceiling).

### 3. A chunked job over a chunk queue

A Chunked Job is genuinely multi-step, so it stays a class. Extend `AbstractChunkedJob` and implement `get_name()`, `generate_queue()`, and `process_chunk()`; the terminal callbacks and retry policy have working defaults.

```php
use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\AbstractChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;

final class RecountCommentsChunkedJob extends AbstractChunkedJob {
	public function get_name(): string {
		return 'recount-comments';
	}

	// Queue generation runs once; return one argument array per chunk.
	public function generate_queue( array $start_args ): iterable {
		$post_ids = get_posts(
			array(
				'post_type'   => (string) ( $start_args['post_type'] ?? 'post' ),
				'fields'      => 'ids',
				'numberposts' => -1,
			)
		);
		foreach ( $post_ids as $post_id ) {
			yield array( 'post_id' => (int) $post_id );
		}
	}

	// One chunk. A normal return succeeds it; a throw fails the attempt.
	public function process_chunk( array $chunk_args, ChunkContextInterface $context ): void {
		wp_update_comment_count_now( (int) $chunk_args['post_id'] );

		// Redeliver more work by appending to the run's queue; the append
		// commits only when this chunk returns normally.
		if ( ! empty( $chunk_args['spawn_followup'] ) ) {
			$context->enqueue( array( 'post_id' => (int) $chunk_args['post_id'], 'verify' => true ) );
		}
	}

	// Optional: observe the terminal outcome. Both are at-least-once — make them idempotent.
	public function on_completed( string $run_id, array $start_args ): void {
		delete_transient( 'my_plugin_recount_running' );
	}

	public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void {
		error_log( "Recount {$run_id} failed [{$failure->code->value}]: {$failure->summary}" );
	}

	// Optional: tune retries (defaults are 3 attempts, 60s base, x2, 3600s cap).
	public function get_retry_policy(): RetryPolicy {
		return new RetryPolicy( max_attempts: 5 );
	}
}

add_action( 'init', static function (): void {
	$registered = a8csp_bgje_chunked_job_register( 'my-plugin', new RecountCommentsChunkedJob() );
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
	}
}, 2 );

function my_plugin_start_recount(): void {
	// The bridge-backed Job rejects a matching live run.
	$run_id = a8csp_bgje_chunked_job_start(
		'my-plugin',
		'recount-comments',
		array( 'post_type' => 'post' )
	);
	if ( is_wp_error( $run_id ) ) {
		error_log( $run_id->get_error_message() );
	}
}
```

Chunks run one at a time with a short pause between them (default 60 seconds; filterable — see *Hooks and filters*). To stop retrying permanently from inside a chunk, throw `NonRetryableException`. A failed chunked job can be restarted from its original arguments with `a8csp_bgje_run_retry_failed()`.

### 4. Day-2 operations: inspection and the CLI

From PHP you can look up the last completed run of a name:

```php
$last = a8csp_bgje_run_last_completed( 'my-plugin', 'email-digest' );
if ( is_wp_error( $last ) ) {
	error_log( $last->get_error_message() );
} elseif ( null !== $last ) {
	// $last is the most recent completed run ID within the retained history window.
}
```

`last_completed` covers only the retained history window (30 entries per name by default). For richer inspection, use WP-CLI. Every `<identity>` is the composed `{owner}:{name}`:

```sh
# What is scheduled, and is it healthy?
wp background-jobs schedules list --owner=my-plugin

# Live and recent runs for one job or chunked job.
wp background-jobs runs list my-plugin:recount-comments
wp background-jobs runs list my-plugin:recount-comments --format=json

# What failed, and retry it.
wp background-jobs failed-runs list --owner=my-plugin
wp background-jobs failed-runs retry my-plugin:email-digest <run_id>

# Cancel a specific retained run (needs the run ID).
wp background-jobs runs cancel my-plugin:email-digest <run_id>

# Remove a client's schedules when it is already inactive.
wp background-jobs schedules remove my-plugin --yes
```

`runs list --format=count` and `--format=csv` report only the live rows (the bounded inspected page, up to 20); `table`, `json`, and `yaml` include recent history. Unreadable rows are excluded and counted in a warning on STDERR for every format, so machine-readable STDOUT stays parseable. `wp background-jobs reset` destroys **all** engine state — it is a development reset, not an operational tool.

### 5. Handling failures

Terminal outcomes fire hooks. The `run_on_failed` / `run_on_completed` helpers wire the correct per-identity hook for you (they work for both jobs and chunked jobs):

```php
add_action( 'init', static function (): void {
	a8csp_bgje_run_on_failed(
		'my-plugin',
		'email-digest',
		static function ( string $run_id, array $start_args, $failure ): void {
			// $failure is a RunFailure: ->summary is engine-authored and redacted
			// (never your raw exception text), ->code is an ApiErrorCode, and
			// ->attempts / ->stage / ->failed_chunk describe the terminalization.
			error_log( "Digest {$run_id} gave up [{$failure->code->value}]: {$failure->summary}" );
		}
	);

	a8csp_bgje_run_on_completed(
		'my-plugin',
		'email-digest',
		static function ( string $run_id, array $start_args ): void {
			// runs at-least-once across crash recovery — keep it idempotent.
		}
	);
}, 2 );
```

Terminal hooks (and callbacks) are at-least-once and can replay across crash recovery, so make their handlers idempotent. Cancelled and superseded runs fire neither `completed` nor `failed`. For your own logging sink, see *Bring your own PSR-3 logger*.

## The procedural functions

The owner is always the first argument. Every fallible function is `#[\NoDiscard]` and returns `T | WP_Error`.

| Function | Returns |
| --- | --- |
| `a8csp_bgje_job_register( $owner, $name, callable $handler, array $options = [] )` | `true \| WP_Error` |
| `a8csp_bgje_job_enqueue( $owner, $name, array $args = [], int $delay_seconds = 0, int $priority = 10 )` | run ID `string \| WP_Error` |
| `a8csp_bgje_chunked_job_register( $owner, ChunkedJobInterface $chunked_job )` | `true \| WP_Error` |
| `a8csp_bgje_chunked_job_start( $owner, $name, array $start_args = [], int $priority = 10 )` | run ID `string \| WP_Error` |
| `a8csp_bgje_schedule_sync( $owner, array $schedules )` | `true \| WP_Error` |
| `a8csp_bgje_schedule_dispatch( $owner, $name )` | run ID `string \| WP_Error` |
| `a8csp_bgje_run_last_completed( $owner, $name )` | run ID `string \| null \| WP_Error` |
| `a8csp_bgje_run_retry_failed( $owner, $name, $run_id )` | fresh run ID `string \| WP_Error` |
| `a8csp_bgje_run_cancel( $owner, $name, $run_id )` | run ID `string \| WP_Error` |
| `a8csp_bgje_run_on_completed( $owner, $name, callable $listener )` | `void` |
| `a8csp_bgje_run_on_failed( $owner, $name, callable $listener )` | `void` |

`a8csp_bgje_job_register()` accepts `array $options` with `max_runtime` (seconds) and `retry` (a `RetryPolicy`). A schedule entry is `['name' => …, 'every' => seconds, 'job' => …, 'args' => [], 'catch_up' => 'run_once', 'priority' => 10]`; only `name`, `every`, and `job` are required, and `every` must be an integer number of seconds.

## The Client (the typed spine)

The functions delegate to an owner-bound `Client` whose scheduling and run commands return a sealed `Result` instead of a `WP_Error` (registration returns `void` and throws). Reach for it when you want the typed error model and the engine's `ApiErrorCode` enum:

```php
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiErrorCode;

$client = a8csp_bgje( 'my-plugin' );
$result = $client->jobs()->enqueue( 'email-digest', array( 'user_id' => 7 ) );
if ( $result->is_failure() ) {
	if ( ApiErrorCode::OverlapHeld === $result->error->code ) {
		return;
	}
	error_log( $result->error->message );
	return;
}
$run_id = $result->value;
```

`Client` exposes `jobs()`, `chunked_jobs()`, `schedules()`, and `runs()`. On this surface a Job can also be a full `OneOffJobInterface`; the callable adapter used by the functions is the public `Api\Job\CallableJob` (`new CallableJob( $name, \Closure::fromCallable( $handler ) )`), so you can register a closure on the Client too. The result variants `Api\Result\Success` and `Api\Result\Failure` (a failure carrying an `Api\Error\ApiError`) are part of the SemVer surface; the functions keep them out of your code by translating to `WP_Error`.

## The work contracts

### Job

A minimal Job is a callable `fn ( array $args ): void`. Register it with `a8csp_bgje_job_register()`. For a full class, implement `OneOffJobInterface`:

```php
interface OneOffJobInterface extends JobInterface {
	public function handle( array $args ): void;
}
```

`JobInterface` declares the stable name, callback runtime, retry policy, `overlap_policy()`, and argument-aware `overlap_key()`. `AbstractJob` (and `CallableJob`) defaults to `OverlapPolicy::Reject`, a canonical argument hash, and the shared 300-second runtime; the engine caps the credited window at six hours. A handler that exceeds its window becomes eligible for crash reclamation, and a reclaimed run can overlap its replacement — so handlers must be idempotent. A normal return succeeds; a thrown exception fails the attempt; throw `NonRetryableException` to skip the remaining retries.

### Chunked Job and chunked job context

```php
interface ChunkedJobInterface extends JobInterface {
	public function generate_queue( array $start_args ): iterable;

	public function process_chunk( array $chunk_args, ChunkContextInterface $context ): void;

	public function on_completed( string $run_id, array $start_args ): void;

	public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void;
}

interface ChunkContextInterface {
	public function enqueue( array $chunk_args ): void;   // append to the queue

	public function prepend( array $chunk_args ): void;   // prepend to the queue

	public function get_run_id(): string;

	public function get_start_args(): array;
}
```

`AbstractChunkedJob` supplies the runtime default, the retry policy, and no-op terminal callbacks, so a minimal Chunked Job implements only `get_name()`, `generate_queue()`, and `process_chunk()`. The runtime ceiling applies independently to one `generate_queue()` or `process_chunk()` call, not the whole run. Queue mutations from a chunk are transactional: they commit only when the chunk returns normally and are discarded when it throws, so retrying cannot duplicate queued work. `generate_queue()` returns an `iterable`, but the engine materializes it fully before execution — the complete queue is capped at 1,048,576 bytes and each chunk at 8,192 bytes.

Chunk execution is not automatically redelivered after an *executing-state* crash: a process death between durable admission and the queue-advancement CAS terminally fails the run as a `CrashReclaim`, preserving the in-flight chunk in the failure record; `a8csp_bgje_run_retry_failed()` starts a fresh run from the original arguments. Automatic redelivery covers only non-executing states (pending, scheduled retry, continue). Terminal callbacks are at-least-once while the Chunked Job stays registered — durable under Action Scheduler, best-effort under WP-Cron. Cancelled and superseded runs invoke neither callback.

### Schedule

Schedules are declared as spec-arrays to `a8csp_bgje_schedule_sync()`; on the Client surface they are `Api\Schedule\Schedule` value objects:

```php
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Schedule;

$schedule = new Schedule(
	name: 'hourly-refresh',
	recurrence: Recurrence::every( HOUR_IN_SECONDS ),
	job: 'refresh-cache',
	args: array(),
	catch_up: CatchUpPolicy::RunOnce,
	priority: 10,
);
$result = $client->schedules()->sync( array( $schedule ) );
```

Use `Recurrence::every( $seconds )` for a fixed interval. On the procedural surface the same schedule is `['name' => 'hourly-refresh', 'every' => HOUR_IN_SECONDS, 'job' => 'refresh-cache']`; catch-up accepts `'run_once'|'skip'`. The target Job supplies overlap behavior for both imperative and scheduled runs.

## Migrating from Action Scheduler

Register a Job for each former action hook, then call the procedural functions from `init` or later.

| Action Scheduler | Engine |
| --- | --- |
| `as_enqueue_async_action( $hook, $args, $group )` | `a8csp_bgje_job_enqueue( 'my-plugin', 'name', $args )` |
| `as_schedule_single_action( $ts, $hook, $args, $group )` | `a8csp_bgje_job_enqueue( 'my-plugin', 'name', $args, delay_seconds: max( 0, $ts - time() ) )` — a **relative** delay, not a timestamp. |
| `as_schedule_recurring_action( $ts, $interval, $hook, $args, $group )` | Include `['name' => 'name', 'every' => $interval, 'job' => 'name', 'args' => $args]` in the complete array passed to `a8csp_bgje_schedule_sync( 'my-plugin', [...] )`. No first-run timestamp. |
| `as_unschedule_action( $hook, $args, $group )` | Omit that schedule from the next complete `a8csp_bgje_schedule_sync()` array. |
| `as_unschedule_all_actions( … )` | `a8csp_bgje_schedule_sync( 'my-plugin', [] )` removes every schedule this owner declares. |
| `as_next_scheduled_action( … )` | No public next-due query. Treat the array you pass to a successful `sync()` as the source of truth. |
| `as_has_scheduled_action( … )` | No public pending/running boolean query. |

The key difference is ownership: Action Scheduler's `$group` defaults to `''`, leaving work ownerless and easy to clear by accident. The engine requires the owner up front and composes it into every identity. And where repeated `as_enqueue_async_action()` calls admit duplicates, `a8csp_bgje_job_enqueue()` rejects matching live work by default.

## Idempotency invariant

Schedule-driven jobs and chunked job chunks MUST be idempotent. The overlap guard reduces double-fire to the crash-and-reclaim residual; it cannot eliminate it. Backend redelivery, and a reclaimed run reviving after its stale lock is taken, can execute the same logical occurrence more than once. Terminal callbacks and terminal lifecycle hooks (`completed`, `failed`, `cancelled`, `superseded`) share the same at-least-once crash window between an external effect and its persisted completion marker — durable under Action Scheduler, best-effort under WP-Cron. The `started` hook is inline and non-durable, so a crash between admission and hook delivery can lose it.

## Admission, overlap, and catch-up policies

Each Job declares one overlap policy for imperative and scheduled admission. Its `overlap_key()` can derive an opaque 1-to-64-byte collision identity from the start arguments; `null` uses the canonical argument hash. Matching is scoped to the owner-qualified identity. Failed-run retry always uses `reject`, regardless of the declared invariant. Catch-up independently determines what happens when a scheduled delivery is late beyond its grace window.

| Overlap | `run_once` catch-up (default) | `skip` catch-up |
| --- | --- | --- |
| `allow` | Dispatches one due or make-up run even while matching work runs. | Drops a beyond-grace occurrence; otherwise dispatches even while matching work runs. |
| `reject` (default) | Attempts one due or make-up run, recorded as skipped while a fresh matching lock is held. | Drops a beyond-grace occurrence; otherwise dispatches only when no fresh matching lock is held. |
| `replace` | Dispatches one due or make-up run; transfers a held matching lock to the new run. | Drops a beyond-grace occurrence; otherwise dispatches and transfers a held matching lock. |

An occurrence is a misfire only when observed strictly after `next_due + grace`; grace defaults to one interval and is filterable. `run_once` attempts one make-up occurrence and realigns; `skip` drops it, realigns, and emits the misfire-skipped hooks.

## Hooks and filters

Detailed parameter contracts are documented inline at each fire site under `src/`. For each lifecycle pair, the identity-specific hook fires first and the generic companion follows with the identity prepended. Every `{identity}` and generic `$identity` is the complete `{owner}:{name}`.

| Event | Identity-specific and generic hooks |
| --- | --- |
| Started | `a8csp_jobs_engine/started/{identity}`: `($run_id, $start_args)` · `a8csp_jobs_engine/started`: `($identity, $run_id, $start_args)` |
| Completed | `a8csp_jobs_engine/completed/{identity}`: `($run_id, $start_args)` · generic prepends `$identity` |
| Failed | `a8csp_jobs_engine/failed/{identity}`: `($run_id, $start_args, RunFailure $failure)` · generic prepends `$identity` |
| Cancelled | `a8csp_jobs_engine/cancelled/{identity}`: `($run_id, $start_args)` · generic prepends `$identity` |
| Retry scheduled | `a8csp_jobs_engine/retry_scheduled/{identity}`: `($run_id, $start_args, $attempt, $delay)` · generic prepends `$identity` |
| Superseded | `a8csp_jobs_engine/superseded/{identity}`: `($run_id, $start_args)` · generic prepends `$identity` |
| Misfire skipped | `a8csp_jobs_engine/misfire_skipped/{identity}`: `($owner, $due_at, $observed_at)` · generic prepends `$identity` |
| Log | `a8csp_jobs_engine/log`: `($level, $message, $context)` (no generic companion) |

A `started` or `retry_scheduled` listener that throws terminally fails the admitted run with `execution_failed`; the run stays retained for retry. Do not hook the engine's internal delivery actions (`start_chunked_job`, `continue_chunked_job`, `run_job`, `run_chunk`, `cleanup_chunked_job`, `schedule_due`).

| Filter | Input and required return |
| --- | --- |
| `a8csp_jobs_engine/queue/{identity}` | `($queue, $start_args, $run_id)` returns the complete list of chunk argument arrays. |
| `a8csp_jobs_engine/continue_delay` | `($delay, $identity, $run_id)` returns a non-negative integer delay in seconds; default 60. It fires for **both Jobs and Chunked Jobs** because it also sets each run's lock-staleness floor at twice the delay. |
| `a8csp_jobs_engine/lock_staleness/{identity}` | `($seconds)` returns a positive integer lock window; default 900, at least twice the continue delay. |
| `a8csp_jobs_engine/history_size` | `($size)` returns a positive integer per-buffer history cap; default 30. |
| `a8csp_jobs_engine/retry_policy/{identity}` | `(RetryPolicy $policy)` returns a `RetryPolicy`; a foreign return leaves the contract policy in effect. |
| `a8csp_jobs_engine/misfire_grace/{identity}` | `($grace, $owner, $identity)` returns a non-negative integer grace in seconds; default one interval. |
| `a8csp_jobs_engine/log_to_error_log` | `($enabled)` returns whether to register the default PHP error-log sink; default `true`. |

## Bring your own PSR-3 logger

The engine writes `a8csp_jobs_engine/log` events to PHP's error log by default. To route them to your own `Psr\Log\LoggerInterface`, disable the default sink and attach a three-argument listener:

```php
use Psr\Log\LoggerInterface;

/** @var LoggerInterface $logger */
add_filter( 'a8csp_jobs_engine/log_to_error_log', static fn ( bool $enabled ): bool => false );
add_action(
	'a8csp_jobs_engine/log',
	static function ( string $level, string $message, array $context ) use ( $logger ): void {
		$logger->log( $level, $message, $context );
	},
	10,
	3
);
```

Top-level context throwables arrive pre-redacted as `{class, code, file, trace_hash}` arrays; listeners never receive raw exception objects or messages.

## Priority is advisory

Priority is an integer from 0 through 255, default 10. Action Scheduler honors it; WP-Cron accepts and ignores it. Keeping the field in the common API permits transparent backend failover.

## Scale ceilings

The engine is designed for a handful of plugins with tens of jobs and schedules each. Stay within these ranges for beta; each has a documented path to raise later:

- **Schedules per owner:** low tens. Each owner's registrations live in one option row that every occurrence rewrites, so co-firing hundreds of schedules for one owner adds contention. Schedule synchronization reads the backend occurrence census in one bulk query per sync; eliminating that census entirely for an unchanged declaration is planned for a later minor.
- **Chunked Job chunk count:** thousands is fine; the queue is byte-capped (1 MiB) but chunk *count* is not, and admission serializes the growing queue, so tens of thousands of tiny chunks is expensive. Prefer fewer, larger chunks or paginate a parent chunked job.
- **`history_size` filter:** the default 30 is generous; there is no hard maximum, so a very large value grows the per-identity history row.
- **Action Scheduler group rows:** the engine creates one AS group per run to enable per-run cancellation cleanup, and Action Scheduler does not garbage-collect groups. At millions of lifetime runs this table grows; plan periodic housekeeping for very high-volume, long-lived installs.

## Testing your client

The public facade constructors accept an owner string and a small engine port (`JobsEngineInterface`, `ChunkedJobsEngineInterface`, `SchedulesEngineInterface`, `RunsEngineInterface`). A test implements the port (or a partial fake) and constructs `Jobs`, `ChunkedJobs`, `Schedules`, or `Runs` directly, without booting a backend:

```php
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ApiError;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\OneOffJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\Jobs;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\JobsEngineInterface;

$engine = new class() implements JobsEngineInterface {
	public array $calls = array();

	public function register_job( string $identity, OneOffJobInterface $job ): void {}

	/** @return AbstractResult<string, ApiError> */
	public function enqueue( string $identity, array $args, int $delay, int $priority ): AbstractResult {
		$this->calls[] = array( $identity, $args, $delay, $priority );

		return new Success( 'run-test' );
	}
};

$jobs  = new Jobs( 'my-plugin', $engine );
$result = $jobs->enqueue( 'refresh', array( 'site_id' => 7 ), delay: 30 );

assert( $result->is_success() && 'run-test' === $result->value );
```

Do not stub `a8csp_bgje()` or the `a8csp_bgje_*()` functions — the engine declares them unconditionally, so a test redefinition fatals. Code that resolves its own client instead accepts a `Client` (or a `fn ( string $owner ): Client` resolver defaulting to `a8csp_bgje()`) and injects the fake facade set through that seam.

## Multisite

Network activation is supported; each site runs its own isolated engine state, bound to the request site when the engine graph is built. A storage operation after `switch_to_blog()` throws instead of writing through a graph built for another site — so enter each site through a fresh request or execution context and operate there. Network uninstall sweeps every site's engine options and pending backend work; Action Scheduler cleanup requires its complete four-table schema and leaves incomplete or migrated stores untouched.

## WP-CLI

The command root is `wp background-jobs`, exposing three action-taking subcommands — `schedules`, `runs`, `failed-runs` — plus the leaf subcommand `reset`.

| Operation | Synopsis |
| --- | --- |
| List failed runs | `wp background-jobs failed-runs list [--owner=<owner>] [--format=<format>]` |
| Retry a failed run | `wp background-jobs failed-runs retry <identity> <run_id>` |
| Purge failed runs for one identity | `wp background-jobs failed-runs purge <identity>` |
| Purge every failed-run store | `wp background-jobs failed-runs purge --all` |
| Cancel a retained run | `wp background-jobs runs cancel <identity> <run_id>` |
| List runs and recent history | `wp background-jobs runs list <identity> [--format=<format>]` |
| List schedules | `wp background-jobs schedules list [--owner=<owner>] [--format=<format>]` |
| Remove every schedule owned by one client | `wp background-jobs schedules remove <owner> [--yes]` |
| Destroy all engine state (development reset) | `wp background-jobs reset [--yes]` |

Every `<identity>` is a composed `{owner}:{name}`; note that PHP calls take the owner-local name while the CLI takes the full identity. List commands accept `table`, `csv`, `json`, `count`, or `yaml` (default `table`); `runs list` includes recent history only in `table`, `json`, and `yaml`, and its `count` is the bounded live count. `reset` permanently deletes every engine option row and pending backend action, including the maintenance registration the next boot recreates; it prompts unless `--yes`. `schedules remove` converges an owner's schedules to empty without cancelling existing runs and errors on an owner with no persisted registry row.

## Releasing

Releases are cut by pushing a version tag. The release workflow fails closed unless the plugin header, `package.json`, and the newest `CHANGELOG.md` entry all agree with the tag, then builds and smoke-tests the distribution ZIP; prereleases publish outside the stable update channel. Publication also requires green trunk-push Quality and Tests runs at the exact tagged commit.

`CHANGELOG.md` is generated from the fragments in `changelog/` by `composer changelog:write`. The first release and each prerelease entry pass their version explicitly:

```sh
composer changelog:write -- --use-version=1.0.0-beta.1
```
