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

A **Job** is one named unit of background work, authored as a callable or an `A8CSP_Job` subclass. A **Chunked Job** extends `A8CSP_ChunkedJob` to split named work into independently processed chunks. A **Schedule** dispatches a registered Job on a fixed recurrence. Every piece of work belongs to an **owner** (your plugin slug); the engine composes `{owner}:{name}` into one identity so two plugins can never collide.

Consumers use three connected surfaces:

- **The global models** — extend `A8CSP_Job` or `A8CSP_ChunkedJob`; chunk handlers receive `A8CSP_ChunkContext`.
- **The procedural functions** — call `a8csp_bgje_*()` to register, enqueue, schedule, inspect, cancel, and retry. Fallible calls return `T | WP_Error`.
- **The lifecycle hooks** — observe runs through the `a8csp_jobs_engine/*` actions.

The namespaced `Api\` facades and their `Result` monad are the engine's internal typed spine. The procedural functions adapt that spine to the supported consumer boundary above.

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

Everything is available from the WordPress `init` hook and later — resolving work earlier throws. The request that activates the engine is the exception: it stays dormant until the next request. Register your work and synchronize your schedules from `init` **on every request**: registration is per-request, and schedule synchronization treats the array you pass as the owner's complete declaration. Prefer `init` priority `2` or later so Action Scheduler's `init:1` store initialization has run; synchronizing before it routes that request's occurrences to WP-Cron.

Pass your plugin's lowercase slug as the owner (matching `[a-z0-9][a-z0-9-]*`, at most 32 bytes, never starting with the reserved `a8csp-jobs-engine` prefix). Job, Chunked Job, and Schedule names match `[a-z0-9_-]+` and are at most 64 bytes.

## Quick start

```php
add_action( 'init', 'my_plugin_register_background_work', 2 );

function my_plugin_register_background_work(): void {
	// A Job can be a plain callable — no class required.
	$registered = a8csp_bgje_job_register(
		'my-plugin',
		'refresh-cache',
		static function ( array $args, string $run_id ): void {
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
				'name'   => 'hourly-refresh',
				'every'  => HOUR_IN_SECONDS,
				'anchor' => 0, // Align to the UTC Unix-epoch phase, not site-local time.
				'job'    => 'refresh-cache',
				'args'   => array( 'site_id' => get_current_blog_id() ),
			),
		)
	);
	if ( is_wp_error( $synced ) ) {
		error_log( 'Background schedule sync failed: ' . $synced->get_error_message() );
	}
}
```

The callable receives the invocation arguments and the engine-assigned run ID; automatic attempts for one admitted run keep that ID. The optional integer `anchor` fixes the recurrence to a UTC phase offset modulo `every`, so `0` aligns to Unix-epoch interval boundaries rather than a site-local clock.

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
		static function ( array $args, string $run_id ): void {
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

An unanchored new schedule first runs at `now + every`; an anchored schedule uses the first strictly future point on its UTC phase grid. There is no first-run timestamp field. If the client is already inactive when you need to clean up, run `wp background-jobs schedules remove my-plugin`.

### 2. A one-shot job, dispatched asynchronously

Register once from `init`, then enqueue whenever you have work. `delay_seconds` is a **relative** delay, not an absolute timestamp. A successful return is the run ID — proof the work was *accepted*, not that it finished.

```php
add_action( 'init', static function (): void {
	$registered = a8csp_bgje_job_register(
		'my-plugin',
		'email-digest',
		static function ( array $args, string $run_id ): void {
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

A Chunked Job is genuinely multi-step, so it stays a class. Extend `A8CSP_ChunkedJob` and implement `get_name()`, `generate_queue()`, and `process_chunk()`; terminal callbacks, retry, overlap, and callback-runtime methods have working defaults.

```php
final class RecountCommentsChunkedJob extends \A8CSP_ChunkedJob {
	public function get_name(): string {
		return 'recount-comments';
	}

	// Queue generation receives the stable run ID and returns one argument array per chunk.
	public function generate_queue( array $start_args, string $run_id ): iterable {
		set_transient( 'my_plugin_recount_running', $run_id );

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
	public function process_chunk( array $chunk_args, \A8CSP_ChunkContext $context ): void {
		wp_update_comment_count_now( (int) $chunk_args['post_id'] );

		// Redeliver more work by appending to the run's queue; the append
		// commits only when this chunk returns normally.
		if ( ! empty( $chunk_args['spawn_followup'] ) ) {
			$context->enqueue( array( 'post_id' => (int) $chunk_args['post_id'], 'verify' => true ) );
		}
	}

	// Optional: observe the terminal outcome. Both are at-least-once — make them idempotent.
	public function on_completed( string $run_id, array $start_args, ?string $previous_completed_run_id ): void {
		delete_transient( 'my_plugin_recount_running' );
	}

	public function on_failed( string $run_id, array $start_args, array $failure ): void {
		error_log( sprintf( 'Recount %s failed [%s]: %s', $run_id, $failure['code'], $failure['summary'] ) );
	}

	// Optional: tune retries (defaults are 3 attempts, 60s base, x2, 3600s cap).
	public function retry(): array {
		return array( 'max_attempts' => 5 );
	}
}

add_action( 'init', static function (): void {
	$registered = a8csp_bgje_chunked_job_register( 'my-plugin', new RecountCommentsChunkedJob() );
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
	}
}, 2 );

function my_plugin_start_recount(): void {
	// The default overlap policy rejects a matching live run.
	$run_id = a8csp_bgje_chunked_job_start(
		'my-plugin',
		'recount-comments',
		array( 'post_type' => 'post' ),
		10
	);
	if ( is_wp_error( $run_id ) ) {
		error_log( $run_id->get_error_message() );
	}
}
```

Chunks run one at a time with a short pause between them (default 60 seconds; filterable — see *Hooks and filters*). `A8CSP_ChunkContext` also exposes `prepend()`, `get_run_id()`, and `get_start_args()`. A failed chunked job can be restarted from its original arguments with `a8csp_bgje_run_retry_failed()`.

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

Jobs and chunked jobs expose terminal outcomes through raw per-identity actions. Register listeners directly with `add_action()` on the `a8csp_jobs_engine/failed/{identity}` and `a8csp_jobs_engine/completed/{identity}` hooks. The `failed` hook passes a `RunFailure` object as its third argument, while the `completed` hook passes the predecessor run ID.

```php
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;

add_action( 'init', static function (): void {
	add_action(
		'a8csp_jobs_engine/failed/my-plugin:email-digest',
		static function ( string $run_id, array $start_args, RunFailure $failure ): void {
			// The RunFailure object carries identity, run_id, attempts, stage, code, summary, and failed_chunk.
			// The summary is engine-authored and redacted, never raw exception text.
			error_log( sprintf( 'Digest %s gave up [%s]: %s', $run_id, $failure->code->value, $failure->summary ) );
		},
		10,
		3
	);

	add_action(
		'a8csp_jobs_engine/completed/my-plugin:email-digest',
		static function ( string $run_id, array $start_args, ?string $previous_completed_run_id ): void {
			// Runs at-least-once across crash recovery — keep it idempotent.
		},
		10,
		3
	);
}, 2 );
```

Terminal hooks (and callbacks) are at-least-once and can replay across crash recovery, so make their handlers idempotent. The completion listener's third argument is the predecessor captured when this run completes, or `null` for the first retained completion; it remains stable across replay. Cancelled and superseded runs fire neither `completed` nor `failed`.

The engine retains up to 20 failed runs per owner-qualified identity for manual retry and evicts the oldest entry past that limit. A retry that successfully starts a fresh run consumes and removes its retained source entry, making normal retry one-shot. Retention is best-effort: a retention write failure is logged rather than made fatal. For your own logging sink, see *Bring your own PSR-3 logger*.

## The procedural functions

The owner is always the first argument. Every fallible function is `#[\NoDiscard]` and returns `T | WP_Error`.

| Function | Returns |
| --- | --- |
| `a8csp_bgje_job_register( $owner, $name, callable $handler, array $options = [] )` | `true \| WP_Error` |
| `a8csp_bgje_job_register_object( $owner, \A8CSP_Job $job )` | `true \| WP_Error` |
| `a8csp_bgje_job_enqueue( $owner, $name, array $args = [], int $delay_seconds = 0, int $priority = 10 )` | run ID `string \| WP_Error` |
| `a8csp_bgje_chunked_job_register( $owner, \A8CSP_ChunkedJob $job )` | `true \| WP_Error` |
| `a8csp_bgje_chunked_job_start( $owner, $name, array $start_args = [], int $priority = 10 )` | run ID `string \| WP_Error` |
| `a8csp_bgje_schedule_sync( $owner, array $schedules )` | `true \| WP_Error` |
| `a8csp_bgje_schedule_dispatch( $owner, $name )` | run ID `string \| WP_Error` |
| `a8csp_bgje_run_last_completed( $owner, $name )` | run ID `string \| null \| WP_Error` |
| `a8csp_bgje_run_retry_failed( $owner, $name, $run_id )` | fresh run ID `string \| WP_Error` |
| `a8csp_bgje_run_cancel( $owner, $name, $run_id )` | run ID `string \| WP_Error` |

The callable registration handler receives `(array $args, string $run_id)`. Its `$options` accept `max_runtime` (int seconds); `retry` (an array of integer `max_attempts`, `base_delay`, `multiplier`, and `max_delay` values); `overlap` (`'allow'`, `'reject'`, or `'replace'`); `overlap_key` (`callable(array $args): ?string`); `on_completed` (`callable(string $run_id, array $args, ?string $previous_completed_run_id): void`); and `on_failed` (`callable(string $run_id, array $args, array $failure): void`). The function wraps these values in an anonymous `A8CSP_Job`.

A schedule entry is `['name' => …, 'every' => seconds, 'job' => …, 'args' => [], 'catch_up' => 'run_once', 'priority' => 10, 'anchor' => null]`. Only `name`, `every`, and `job` are required. `every` is a positive integer number of seconds; an optional non-negative integer `anchor` selects a fixed UTC phase modulo that interval.

## The internal typed spine

The namespaced `Client`, the `Jobs`/`ChunkedJobs`/`Schedules`/`Runs` facades, and the `Result` monad form the engine's internal spine: they connect the bridge to the engine and offer no backward-compatibility guarantee, so consumer code does not call them directly — it uses the `a8csp_bgje_*()` functions (which translate expected failures to `WP_Error`) and the global `A8CSP_*` authoring models.

Two `Api\` symbols are meant for consumers: throw `A8C\SpecialProjects\BackgroundJobsEngine\Api\NonRetryableException` from `handle()` or `process_chunk()` to fail a run permanently, skipping the remaining retries. (The `RunFailure` and `RetryPolicy` objects that the raw `failed` hook and the `retry_policy` filter hand out are documented under Hooks and filters.)

## The work contracts

### Job

A class-authored Job extends `A8CSP_Job`, supplies a stable `get_name()`, and implements `handle( array $args, string $run_id ): void`. The run ID identifies one admitted run across its automatic attempts. Register the object with `a8csp_bgje_job_register_object()`; use `a8csp_bgje_job_register()` for the callable form.

```php
final class RefreshCacheJob extends \A8CSP_Job {
	public function get_name(): string {
		return 'refresh-cache';
	}

	public function handle( array $args, string $run_id ): void {
		my_plugin_refresh_cache( (int) ( $args['site_id'] ?? 0 ), $run_id );
	}

	public function max_callback_runtime(): int {
		return 120;
	}

	public function retry(): array {
		return array(
			'max_attempts' => 5,
			'base_delay'   => 30,
			'multiplier'   => 2,
			'max_delay'    => 600,
		);
	}

	public function overlap_policy(): string {
		return 'reject';
	}

	public function overlap_key( array $start_args ): ?string {
		return isset( $start_args['site_id'] ) ? 'site:' . (string) $start_args['site_id'] : null;
	}

	public function on_completed( string $run_id, array $args, ?string $previous_completed_run_id ): void {
		delete_transient( 'my_plugin_refresh_pending' );
	}

	public function on_failed( string $run_id, array $args, array $failure ): void {
		error_log( sprintf( 'Refresh %s failed [%s]: %s', $run_id, $failure['code'], $failure['summary'] ) );
	}
}

add_action( 'init', static function (): void {
	$registered = a8csp_bgje_job_register_object( 'my-plugin', new RefreshCacheJob() );
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
	}
}, 2 );
```

The overridable `retry()` method returns an array of integer `max_attempts`, `base_delay`, `multiplier`, and `max_delay` values. A `max_callback_runtime()` return of `<= 0`, or a thrown declaration, is normalized to the 300-second default; values above six hours are capped at six hours. `overlap_policy()` returns `'allow'`, `'reject'` (the default), or `'replace'`; `overlap_key( array $start_args ): ?string` can replace the canonical argument hash with an opaque 1-to-64-byte key.

`on_completed( string $run_id, array $args, ?string $previous_completed_run_id ): void` receives the previous completed run captured when this run finishes, or `null`. `on_failed( string $run_id, array $args, array $failure ): void` receives the public failure array. Both callbacks are at-least-once and fire only while the Job stays registered, so make them idempotent. A normal `handle()` return succeeds; a throw fails the attempt and is retried under the job's retry policy, while throwing `A8C\SpecialProjects\BackgroundJobsEngine\Api\NonRetryableException` fails the run permanently with no further retry. A handler that exceeds its credited window becomes eligible for crash reclamation, and a reclaimed run can overlap its replacement, so handlers must also be idempotent.

### Chunked Job and chunked job context

A class-authored Chunked Job extends `A8CSP_ChunkedJob` and implements `get_name()`, `generate_queue( array $start_args, string $run_id ): iterable`, and `process_chunk( array $chunk_args, A8CSP_ChunkContext $context ): void`. Register it with `a8csp_bgje_chunked_job_register( $owner, $job )` and start it with `a8csp_bgje_chunked_job_start( $owner, $name, $start_args, $priority )`; Example 3 shows the complete shape.

`A8CSP_ChunkContext` exposes `enqueue( array $chunk_args ): void`, `prepend( array $chunk_args ): void`, `get_run_id(): string`, and `get_start_args(): array`. Queue mutations commit only when `process_chunk()` returns normally and are discarded when it throws. The inherited `retry()`, `max_callback_runtime()`, `overlap_policy()`, `overlap_key()`, `on_completed()`, and `on_failed()` methods have the same public shapes and normalization rules as `A8CSP_Job`. The runtime ceiling applies independently to one `generate_queue()` or `process_chunk()` call, not the whole run. The engine materializes the initial iterable before execution; the complete queue is capped at 1,048,576 bytes and each chunk at 8,192 bytes.

Chunk execution is not automatically redelivered after an *executing-state* crash: a process death between durable admission and the queue-advancement CAS terminally fails the run as a `CrashReclaim`, preserving the in-flight chunk in the failure record; `a8csp_bgje_run_retry_failed()` starts a fresh run from the original arguments. Automatic redelivery covers only non-executing states (pending, scheduled retry, continue). Terminal callbacks are at-least-once while the Chunked Job stays registered — durable under Action Scheduler, best-effort under WP-Cron. Cancelled and superseded runs invoke neither callback.

### Schedule

Schedules are complete spec arrays passed to `a8csp_bgje_schedule_sync()`. The optional `anchor` is a fixed non-negative UTC phase offset and is reduced modulo `every`; it does not represent site-local or calendar time.

```php
$synced = a8csp_bgje_schedule_sync(
	'my-plugin',
	array(
		array(
			'name'     => 'hourly-refresh',
			'every'    => HOUR_IN_SECONDS,
			'anchor'   => 0,
			'job'      => 'refresh-cache',
			'args'     => array(),
			'catch_up' => 'run_once',
			'priority' => 10,
		),
	)
);
if ( is_wp_error( $synced ) ) {
	error_log( $synced->get_error_message() );
}
```

An unanchored schedule first runs at `now + every`. An anchored schedule first runs at the strictly future Unix timestamp whose phase matches `anchor mod every`, then stays on that grid. Catch-up accepts `'run_once'|'skip'`; the target Job supplies overlap behavior for both imperative and scheduled runs. Inside the internal typed spine, these forms are represented by `Recurrence::every( int $seconds )` and `Recurrence::every_anchored( int $seconds, int $anchor )`.

## Migrating from Action Scheduler

Register a Job for each former action hook, then call the procedural functions from `init` or later.

| Action Scheduler | Engine |
| --- | --- |
| `as_enqueue_async_action( $hook, $args, $group )` | `a8csp_bgje_job_enqueue( 'my-plugin', 'name', $args )` |
| `as_schedule_single_action( $ts, $hook, $args, $group )` | `a8csp_bgje_job_enqueue( 'my-plugin', 'name', $args, delay_seconds: max( 0, $ts - time() ) )` — a **relative** delay, not a timestamp. |
| `as_schedule_recurring_action( $ts, $interval, $hook, $args, $group )` | Include `['name' => 'name', 'every' => $interval, 'anchor' => $ts, 'job' => 'name', 'args' => $args]` in the complete array passed to `a8csp_bgje_schedule_sync( 'my-plugin', [...] )`. The anchor preserves the fixed UTC phase modulo the interval, not the exact first timestamp or a site-local time. |
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
| Completed | `a8csp_jobs_engine/completed/{identity}`: `($run_id, $start_args, $previous_completed_run_id)` · generic prepends `$identity` |
| Failed | `a8csp_jobs_engine/failed/{identity}`: `($run_id, $start_args, RunFailure $failure)` · generic prepends `$identity` |
| Cancelled | `a8csp_jobs_engine/cancelled/{identity}`: `($run_id, $start_args)` · generic prepends `$identity` |
| Retry scheduled | `a8csp_jobs_engine/retry_scheduled/{identity}`: `($run_id, $start_args, $attempt, $delay)` · generic prepends `$identity` |
| Superseded | `a8csp_jobs_engine/superseded/{identity}`: `($run_id, $start_args)` · generic prepends `$identity` |
| Misfire skipped | `a8csp_jobs_engine/misfire_skipped/{identity}`: `($owner, $due_at, $observed_at)` · generic prepends `$identity` |
| Log | `a8csp_jobs_engine/log`: `($level, $message, $context)` (no generic companion) |

A `started` or `retry_scheduled` listener that throws terminally fails the admitted run with `execution_failed`; the run stays retained for retry. Do not hook the engine's internal delivery actions (`start_chunked_job`, `continue_chunked_job`, `run_job`, `cleanup_chunked_job`, `schedule_due`).

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

## Testing consumer code

Do not redefine `a8csp_bgje()` or the `a8csp_bgje_*()` functions; the engine declares them unconditionally, so a test redefinition fatals. Keep application code testable by placing procedural calls behind an application-owned interface or callable and fake that boundary in unit tests. Exercise the real global models and functions in WordPress integration tests. The ports and facade constructors under `src/Api/` are internal engine seams, not consumer injection contracts.

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
