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

A **Job** is one named unit of background work, authored as a callable or a `Job\AbstractJob` subclass. A **Chunked Job** extends `Job\Chunked\AbstractChunkedJob` to split named work into independently processed chunks. A **Schedule** dispatches registered work on a fixed recurrence. Every piece of work belongs to an **owner** (your plugin slug); the engine composes `{owner}:{name}` into one identity so plugins using distinct owner slugs do not collide.

Consumers use four connected surfaces:

- **The Engine handle** — `a8csp_bgje( $owner )` returns an owner-bound `Engine` with `jobs()`, `schedules()`, and `runs()` portals to capability managers.
- **The public models** — extend `Job\AbstractJob` or `Job\Chunked\AbstractChunkedJob`; work receives `Job\RunContext` or `Job\Chunked\ChunkContext`; run-producing commands return `Run\Run` snapshots, terminal failures use `Run\RunFailure`, and verb failures return `WP_Error`.
- **The procedural aliases** — ten thin `a8csp_bgje_*()` functions take `$owner` first and mirror the capability-manager verbs.
- **The lifecycle hooks** — observe runs through the `a8csp_jobs_engine/*` actions.

The data boundary is deliberate: payloads the engine hands to consumer code are typed objects such as `Run\Run`, `Job\RunContext`, `Job\Chunked\ChunkContext`, and `Run\RunFailure`; configuration supplied to the engine uses arrays and scalars, including callable options and schedule specifications.

Delivery uses Action Scheduler when it is ready and falls back to WP-Cron otherwise. At-least-once delivery is guaranteed **only under Action Scheduler**; WP-Cron is best-effort. An occurrence on a temporarily unavailable backend is dormant, not lost.

The engine's state persists in non-autoloaded `wp_options` rows under the reserved `a8csp_bgje_` prefix, so it adds no weight to ordinary page loads.

## Installation

The canonical install is the plugin ZIP attached to a [GitHub Release](https://github.com/a8cteam51/a8csp-background-jobs-engine/releases). Download it, upload it as a WordPress plugin, and activate it. The release ZIP bundles production Composer dependencies and the translation template, so it needs no Composer step. Installed copies receive release updates through the dashboard like any plugin.

For a source checkout, clone into `wp-content/plugins/a8csp-background-jobs-engine` and install production dependencies:

```sh
cd wp-content/plugins
git clone https://github.com/a8cteam51/a8csp-background-jobs-engine.git a8csp-background-jobs-engine
cd a8csp-background-jobs-engine
composer install --no-dev
```

Action Scheduler is optional and preferred when ready; when absent, the engine runs on WP-Cron alone.

## When to call the engine

`a8csp_bgje( $owner )` constructs a handle lazily and infallibly. Capability-manager verb calls belong on the WordPress `init` hook or later. Invalid owners and unavailable engine state return `WP_Error` from the first verb instead of failing handle construction. The request that activates the engine stays dormant until the next request.

Register work and synchronize schedules from `init` **on every request**: registration is per-request, and schedule synchronization treats the supplied array as the owner's complete declaration. Prefer `init` priority `2` or later so Action Scheduler's `init:1` store initialization has run; synchronizing before it routes that request's occurrences to WP-Cron.

Pass your plugin's lowercase slug as the owner (matching `[a-z0-9][a-z0-9-]*`, at most 32 bytes, never starting with the reserved `a8csp-jobs-engine` prefix). Job, Chunked Job, and Schedule names match `[a-z0-9_-]+` and are at most 64 bytes.

## Quick start

```php
use A8C\SpecialProjects\BackgroundJobsEngine\Job\AbstractJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;

final class RefreshCache extends AbstractJob {
	public function get_name(): string {
		return 'refresh-cache';
	}

	public function handle( array $args, RunContext $context ): void {
		my_plugin_refresh_cache(
			(int) ( $args['site_id'] ?? 0 ),
			$context->get_run_id()
		);
	}
}

add_action( 'init', static function (): void {
	$bg = a8csp_bgje( 'my-plugin' );

	$registered = $bg->jobs()->register( new RefreshCache() );
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
	}
}, 2 );

function my_plugin_queue_cache_refresh( int $site_id ): void {
	$bg  = a8csp_bgje( 'my-plugin' );
	$run = $bg->jobs()->enqueue( 'refresh-cache', array( 'site_id' => $site_id ) );
	if ( is_wp_error( $run ) ) {
		error_log( $run->get_error_message() );
		return;
	}

	update_option( 'my_plugin_last_cache_run', $run->run_id );
}
```

Each successful enqueue returns an immutable `Run\Run` snapshot with `identity`, `run_id`, and `status`. Automatic attempts for one admitted run keep the same run ID. Every capability-manager verb returns its success value or `WP_Error` for an expected validation, registration, readiness, or engine failure. Errors have a stable string code and an engine-authored message, and may carry redaction-safe structured context.

## Examples

Five complete, copy-paste-adaptable scenarios follow. Each uses the owner slug `my-plugin`.

### 1. A recurring schedule, full lifecycle

Define the job and synchronize the owner's complete schedule declaration on every `init`.

```php
use A8C\SpecialProjects\BackgroundJobsEngine\Job\AbstractJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;

final class PruneTransients extends AbstractJob {
	public function get_name(): string {
		return 'prune-transients';
	}

	public function handle( array $args, RunContext $context ): void {
		my_plugin_prune_expired_transients();
	}
}

add_action( 'init', static function (): void {
	$bg = a8csp_bgje( 'my-plugin' );

	$registered = $bg->jobs()->register( new PruneTransients() );
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
		return;
	}

	$synced = $bg->schedules()->sync(
		array(
			array(
				'name'     => 'nightly-prune',
				'every'    => DAY_IN_SECONDS,
				'job'      => 'prune-transients',
				'catch_up' => 'run_once',
			),
		)
	);
	if ( is_wp_error( $synced ) ) {
		error_log( $synced->get_error_message() );
	}
}, 2 );

register_deactivation_hook( __FILE__, static function (): void {
	if ( 0 === did_action( 'init' ) && ! doing_action( 'init' ) ) {
		error_log( 'Run: wp background-jobs schedules remove my-plugin --yes' );
		return;
	}

	$removed = a8csp_bgje_sync_schedules( 'my-plugin', array() );
	if ( is_wp_error( $removed ) ) {
		error_log( $removed->get_error_message() );
	}
} );
```

An unanchored schedule first runs one interval after synchronization. An anchored schedule uses the first strictly future point on its UTC phase grid. There is no first-run timestamp field. Omitting a schedule from the next complete declaration removes it; synchronizing an empty array removes all schedules for the owner without cancelling admitted runs. If the plugin is inactive, `wp background-jobs schedules remove my-plugin --yes` performs the same schedule convergence.

### 2. A one-shot callable, dispatched asynchronously

`jobs()->register_callable()` is the class-free authoring form. Its handler receives the invocation arguments and a typed `Job\RunContext`.

```php
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;

add_action( 'init', static function (): void {
	$registered = a8csp_bgje_register_callable(
		'my-plugin',
		'email-digest',
		static function ( array $args, RunContext $context ): void {
			my_plugin_send_digest(
				(int) $args['user_id'],
				$context->get_run_id()
			);
		},
		array(
			'max_runtime' => 120,
			'retry'       => array(
				'max_attempts' => 5,
				'base_delay'   => 30,
				'multiplier'   => 2,
				'max_delay'    => 600,
			),
			'overlap'     => 'reject',
			'overlap_key' => static fn ( array $args ): ?string => isset( $args['user_id'] )
				? 'user:' . (string) $args['user_id']
				: null,
			'on_failed'   => static function ( string $run_id, array $args, RunFailure $failure ): void {
				error_log( sprintf( 'Digest %s failed [%s]: %s', $run_id, $failure->code->value, $failure->summary ) );
			},
		)
	);
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
	}
}, 2 );

function my_plugin_queue_digest( int $user_id ): void {
	$run = a8csp_bgje_enqueue(
		'my-plugin',
		'email-digest',
		array( 'user_id' => $user_id ),
		delay_seconds: 0
	);
	if ( is_wp_error( $run ) ) {
		if ( 'overlap_held' === $run->get_error_code() ) {
			return;
		}

		error_log( $run->get_error_message() );
		return;
	}

	update_user_meta( $user_id, 'my_plugin_last_digest_run', $run->run_id );
}
```

`delay_seconds` is a relative delay, not an absolute timestamp. A successful return proves admission, not completion. Keep arguments small and portable: pass identifying keys rather than bulk data.

### 3. A chunked job over a chunk queue

A Chunked Job implements queue generation and one-chunk processing. Queue mutations through `Job\Chunked\ChunkContext` commit only when `process_chunk()` returns normally.

```php
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\AbstractChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;

final class RecountComments extends AbstractChunkedJob {
	public function get_name(): string {
		return 'recount-comments';
	}

	public function generate_queue( array $start_args, RunContext $context ): iterable {
		set_transient( 'my_plugin_recount_running', $context->get_run_id() );

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

	public function process_chunk( array $chunk_args, ChunkContext $context ): void {
		wp_update_comment_count_now( (int) $chunk_args['post_id'] );

		if ( ! empty( $chunk_args['spawn_followup'] ) ) {
			$context->enqueue(
				array(
					'post_id' => (int) $chunk_args['post_id'],
					'verify'  => true,
				)
			);
		}
	}

	public function get_retry_policy(): RetryPolicy {
		return new RetryPolicy(
			max_attempts: 5,
			base_delay: MINUTE_IN_SECONDS,
			multiplier: 2,
			max_delay: HOUR_IN_SECONDS
		);
	}

	public function on_completed( string $run_id, array $start_args, ?string $previous_completed_run_id ): void {
		delete_transient( 'my_plugin_recount_running' );
	}

	public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void {
		error_log( sprintf( 'Recount %s failed [%s]: %s', $run_id, $failure->code->value, $failure->summary ) );
	}
}

add_action( 'init', static function (): void {
	$registered = a8csp_bgje_register( 'my-plugin', new RecountComments() );
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
	}
}, 2 );

function my_plugin_start_recount(): void {
	$run = a8csp_bgje_start(
		'my-plugin',
		'recount-comments',
		array( 'post_type' => 'post' )
	);
	if ( is_wp_error( $run ) ) {
		error_log( $run->get_error_message() );
		return;
	}

	update_option( 'my_plugin_last_recount_run', $run->run_id );
}
```

Chunks run one at a time with a short pause between them. `Job\Chunked\ChunkContext` also exposes `prepend()`, `get_run_id()`, and `get_start_args()`. A failed chunked job starts a fresh run from its original arguments through `runs()->retry_failed()`.

### 4. Day-2 operations: inspection, retry, cancellation, and the CLI

```php
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;

function my_plugin_review_digest_run( string $run_id ): void {
	$bg  = a8csp_bgje( 'my-plugin' );
	$run = $bg->runs()->inspect( 'email-digest', $run_id );
	if ( is_wp_error( $run ) ) {
		error_log( $run->get_error_message() );
		return;
	}

	if ( RunStatus::Failed === $run->status ) {
		$retry = $bg->runs()->retry_failed( 'email-digest', $run->run_id );
		if ( is_wp_error( $retry ) ) {
			error_log( $retry->get_error_message() );
		}
	}

	$last = $bg->runs()->last_completed( 'email-digest' );
	if ( is_wp_error( $last ) ) {
		error_log( $last->get_error_message() );
	} elseif ( null !== $last ) {
		update_option( 'my_plugin_last_completed_digest', $last->run_id );
	}
}

function my_plugin_cancel_digest( string $run_id ): void {
	$cancelled = a8csp_bgje_cancel_run( 'my-plugin', 'email-digest', $run_id );
	if ( is_wp_error( $cancelled ) ) {
		error_log( $cancelled->get_error_message() );
	}
}
```

`runs()->inspect()` returns a retained `Run\Run` or `WP_Error`; absence is `run_not_retained`. `runs()->last_completed()` adds `null` when no completion is present in retained history. After a manual retry admits a fresh run, the engine attempts to remove the retained source entry and logs a cleanup failure.

Every CLI `<identity>` is the composed `{owner}:{name}`:

```sh
# What is scheduled, and is it healthy?
wp background-jobs schedules list --owner=my-plugin

# Live and recent runs for one job or chunked job.
wp background-jobs runs list my-plugin:recount-comments
wp background-jobs runs list my-plugin:recount-comments --format=json

# What failed, and retry it.
wp background-jobs failed-runs list --owner=my-plugin
wp background-jobs failed-runs retry my-plugin:email-digest <run_id>

# Cancel a specific retained run.
wp background-jobs runs cancel my-plugin:email-digest <run_id>

# Remove an inactive plugin's schedules.
wp background-jobs schedules remove my-plugin --yes
```

`runs list --format=count` and `--format=csv` report only the live rows (the bounded inspected page, up to 20); `table`, `json`, and `yaml` include recent history. Unreadable rows are excluded and counted in a warning on STDERR for every format, so machine-readable STDOUT stays parseable. `wp background-jobs reset` destroys **all** engine state; it is a development reset, not an operational tool.

### 5. Handling failures

Within one terminal-effect delivery, `on_failed()` and the generic `a8csp_jobs_engine/failed` hook receive the same `Run\RunFailure` object. Crash-recovery replay can reconstruct an equivalent value. A listener for one item filters on `$failure->identity`. Throw `Job\NonRetryableException` from `handle()`, `generate_queue()`, or `process_chunk()` to fail permanently without consuming the remaining automatic attempts.

```php
use A8C\SpecialProjects\BackgroundJobsEngine\Job\AbstractJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;

final class PublishWebhook extends AbstractJob {
	public function get_name(): string {
		return 'publish-webhook';
	}

	public function handle( array $args, RunContext $context ): void {
		if ( my_plugin_webhook_is_revoked( (string) $args['endpoint_id'] ) ) {
			throw new NonRetryableException( 'The endpoint is permanently unavailable.' );
		}

		my_plugin_publish_webhook( (string) $args['endpoint_id'], $context->get_run_id() );
	}

	public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void {
		error_log( sprintf( 'Webhook %s failed [%s]: %s', $run_id, $failure->code->value, $failure->summary ) );
	}
}

add_action( 'init', static function (): void {
	$registered = a8csp_bgje_register( 'my-plugin', new PublishWebhook() );
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
	}

	add_action(
		'a8csp_jobs_engine/failed',
		static function ( RunFailure $failure ): void {
			if ( 'my-plugin:publish-webhook' !== $failure->identity ) {
				return;
			}

			error_log( sprintf( 'Run %s consumed %d attempt(s).', $failure->run_id, $failure->attempts ) );
		},
		10,
		1
	);
}, 2 );
```

The failure summary is engine-authored and redacted; it never contains raw exception text. Terminal callbacks and hooks can replay across crash recovery, so handlers use the run ID to converge repeated delivery. Their delivery is durable under Action Scheduler and best-effort under WP-Cron. Cancelled and superseded runs invoke neither `on_completed()` nor `on_failed()`.

The engine retains up to 20 failed runs per owner-qualified identity for manual retry and evicts the oldest entry past that limit. A retry that successfully starts a fresh run attempts to remove its retained source entry; a failed removal is logged. Retention is best-effort: a retention write failure is logged rather than made fatal.

## The procedural functions

`a8csp_bgje( string $owner ): Engine` returns the lazy owner-bound handle. Each thin alias below takes `$owner` first, invokes the matching capability-manager verb, and returns the same shape.

| Capability-manager verb | Procedural alias | Returns |
| --- | --- | --- |
| `jobs()->register( Job\JobInterface $job )` | `a8csp_bgje_register( string $owner, Job\JobInterface $job )` | `true \| WP_Error` |
| `jobs()->register_callable( string $name, callable $handler, array $options = [] )` | `a8csp_bgje_register_callable( string $owner, string $name, callable $handler, array $options = [] )` | `true \| WP_Error` |
| `jobs()->enqueue( string $name, array $args = [], int $delay_seconds = 0, int $priority = 10 )` | `a8csp_bgje_enqueue( string $owner, string $name, array $args = [], int $delay_seconds = 0, int $priority = 10 )` | `Run\Run \| WP_Error` |
| `jobs()->start( string $name, array $start_args = [], int $priority = 10 )` | `a8csp_bgje_start( string $owner, string $name, array $start_args = [], int $priority = 10 )` | `Run\Run \| WP_Error` |
| `schedules()->sync( array $schedules )` | `a8csp_bgje_sync_schedules( string $owner, array $schedules )` | `true \| WP_Error` |
| `schedules()->dispatch( string $name )` | `a8csp_bgje_dispatch_schedule( string $owner, string $name )` | `Run\Run \| WP_Error` |
| `runs()->inspect( string $name, string $run_id )` | `a8csp_bgje_inspect_run( string $owner, string $name, string $run_id )` | `Run\Run \| WP_Error` |
| `runs()->last_completed( string $name )` | `a8csp_bgje_last_completed_run( string $owner, string $name )` | `Run\Run \| null \| WP_Error` |
| `runs()->retry_failed( string $name, string $run_id )` | `a8csp_bgje_retry_failed_run( string $owner, string $name, string $run_id )` | `Run\Run \| WP_Error` |
| `runs()->cancel( string $name, string $run_id )` | `a8csp_bgje_cancel_run( string $owner, string $name, string $run_id )` | `Run\Run \| WP_Error` |

The callable handler receives `(array $args, Job\RunContext $context)`. Its options are:

- `max_runtime`: integer callback-runtime ceiling in seconds.
- `retry`: a partial array containing integer `max_attempts`, `base_delay`, `multiplier`, and `max_delay` fields.
- `overlap`: `'allow'`, `'reject'`, or `'replace'`.
- `overlap_key`: `callable(array $args): ?string`.
- `on_completed`: `callable(string $run_id, array $args, ?string $previous_completed_run_id): void`.
- `on_failed`: `callable(string $run_id, array $args, Run\RunFailure $failure): void`.

A schedule entry requires `name`, `every`, and `job`. Optional fields default to `'anchor' => null`, `'args' => []`, `'catch_up' => 'run_once'`, and `'priority' => 10`; catch-up also accepts `'skip'`. `every` is a positive integer number of seconds. `anchor` is a non-negative UTC phase offset reduced modulo `every`; it is not site-local or calendar time.

## Public values and contexts

All public type names below are relative to the `A8C\SpecialProjects\BackgroundJobsEngine` namespace.

| Type | Public shape |
| --- | --- |
| `Engine` | Owner-bound readonly handle returned by `a8csp_bgje()` with `jobs()`, `schedules()`, and `runs()` portals. |
| `Jobs` | Owner-bound readonly manager for registration and job dispatch. |
| `Schedules` | Owner-bound readonly manager for schedule synchronization and immediate dispatch. |
| `Runs` | Owner-bound readonly manager for run inspection, retry, and cancellation. |
| `Run\Run` | Readonly snapshot with `string $identity`, `string $run_id`, and `Run\RunStatus $status`. |
| `Run\RunFailure` | Readonly value with `string $identity`, `string $run_id`, `int $attempts`, `Run\RunFailureStage $stage`, `Error\ErrorCode $code`, `string $summary`, and `?array $failed_chunk`. |
| `Job\RetryPolicy` | Readonly value constructed from `max_attempts`, `base_delay`, `multiplier`, and `max_delay`; defaults are 3, `MINUTE_IN_SECONDS`, 2, and `HOUR_IN_SECONDS`. `max_attempts` includes the initial attempt. Attempts, base delay, and multiplier are at least 1, and maximum delay is at least the base delay. It exposes `delay_ceiling_for_attempt( int $attempt ): int`. |
| `Job\RunContext` | `get_run_id(): string` and `get_start_args(): array`. |
| `Job\Chunked\ChunkContext` | Extends `Job\RunContext` with `enqueue( array $chunk_args ): void` and `prepend( array $chunk_args ): void`. |
| `Job\NonRetryableException` | Runtime exception that marks client work as permanently failed. |

The backed enums are:

| Enum | Cases and backing values |
| --- | --- |
| `Run\RunStatus` | `Running = 'running'`, `Completed = 'completed'`, `Failed = 'failed'`, `Cancelled = 'cancelled'`, `Superseded = 'superseded'` |
| `Job\OverlapPolicy` | `Allow = 'allow'`, `Reject = 'reject'`, `Replace = 'replace'` |
| `Run\RunFailureStage` | `Execution = 'execution'`, `QueueGeneration = 'queue_generation'`, `CrashReclaim = 'crash_reclaim'`, `Scheduling = 'scheduling'` |
| `Error\ErrorCode` | `EngineUnavailable = 'engine_unavailable'`, `UnknownWork = 'unknown_work'`, `UnknownSchedule = 'unknown_schedule'`, `OverlapHeld = 'overlap_held'`, `PayloadRejected = 'payload_rejected'`, `BackendUnavailable = 'backend_unavailable'`, `BackendRejected = 'backend_rejected'`, `StorageFailure = 'storage_failure'`, `RunNotRetained = 'run_not_retained'`, `RunNotCancellable = 'run_not_cancellable'`, `UnsupportedOperation = 'unsupported_operation'`, `ExecutionFailed = 'execution_failed'` |

`invalid_argument` and `already_registered` are additional `WP_Error` codes at the facade boundary; they are not `Error\ErrorCode` cases.

## The work contracts

### Job

A class-authored Job extends `Job\AbstractJob` and implements:

- `get_name(): string`
- `handle( array $args, Job\RunContext $context ): void`

The `Job\JobDefaults` trait supplies overridable defaults for `max_callback_runtime(): int`, `overlap_policy(): Job\OverlapPolicy`, `overlap_key( array $start_args ): ?string`, `get_retry_policy(): Job\RetryPolicy`, `on_completed( string $run_id, array $start_args, ?string $previous_completed_run_id ): void`, and `on_failed( string $run_id, array $start_args, Run\RunFailure $failure ): void`. The defaults are a 300-second callback ceiling, `Job\OverlapPolicy::Reject`, a null overlap key, a fresh default `Job\RetryPolicy`, and no-op terminal callbacks.

The default overlap policy is `Job\OverlapPolicy::Reject`; a null overlap key uses the canonical argument hash. A normal `handle()` return succeeds. A throwable fails the attempt and follows the retry policy, except `Job\NonRetryableException`, which fails permanently. A handler that exceeds its credited window becomes eligible for crash reclamation, and a reclaimed run can overlap its replacement, so handlers remain idempotent.

### Chunked Job and chunk context

A class-authored Chunked Job extends `Job\Chunked\AbstractChunkedJob` and implements:

- `get_name(): string`
- `generate_queue( array $start_args, Job\RunContext $context ): iterable`
- `process_chunk( array $chunk_args, Job\Chunked\ChunkContext $context ): void`

It uses `Job\JobDefaults` for the same policy and terminal-callback methods as `Job\AbstractJob`. The runtime ceiling applies independently to one `generate_queue()` or `process_chunk()` invocation, not the whole run. The engine materializes the initial iterable before execution; the complete queue is capped at 1,048,576 bytes and each chunk at 8,192 bytes.

Queue mutations commit only after a normal `process_chunk()` return and are discarded when it throws. An executing-state process death terminally fails the run as `Run\RunFailureStage::CrashReclaim`, preserving the in-flight chunk in `Run\RunFailure::$failed_chunk`; `runs()->retry_failed()` starts a fresh run from the original arguments. Automatic redelivery covers non-executing pending, scheduled-retry, and continuation states.

### Schedule

Schedules are complete specification arrays passed to `schedules()->sync()`. Each entry has this configuration shape:

`[ 'name' => string, 'every' => int, 'job' => string, 'anchor' => ?int, 'args' => array, 'catch_up' => 'run_once'|'skip', 'priority' => int ]`

Only `name`, `every`, and `job` are required. An unanchored schedule first runs one interval after synchronization. An anchored schedule first runs at the strictly future Unix timestamp whose phase matches `anchor mod every`, then stays on that grid. The target work supplies overlap behavior for imperative and scheduled runs.

## Migrating from Action Scheduler

Register work for each former action hook, then use an owner-bound handle or its aliases from `init` or later.

| Action Scheduler | Engine |
| --- | --- |
| `as_enqueue_async_action( $hook, $args, $group )` | `a8csp_bgje_enqueue( 'my-plugin', 'name', $args )` |
| `as_schedule_single_action( $ts, $hook, $args, $group )` | `a8csp_bgje_enqueue( 'my-plugin', 'name', $args, delay_seconds: max( 0, $ts - time() ) )` — a **relative** delay, not a timestamp. |
| `as_schedule_recurring_action( $ts, $interval, $hook, $args, $group )` | Include `[ 'name' => 'name', 'every' => $interval, 'anchor' => $ts, 'job' => 'name', 'args' => $args ]` in the complete array passed to `a8csp_bgje_sync_schedules( 'my-plugin', [...] )`. The anchor preserves the fixed UTC phase modulo the interval, not the exact first timestamp or site-local time. |
| `as_unschedule_action( $hook, $args, $group )` | Omit that schedule from the next complete `schedules()->sync()` array. |
| `as_unschedule_all_actions( … )` | `a8csp_bgje_sync_schedules( 'my-plugin', [] )` removes every schedule this owner declares. |
| `as_next_scheduled_action( … )` | No public next-due query. Treat the array passed to a successful `schedules()->sync()` as the source of truth. |
| `as_has_scheduled_action( … )` | No public pending/running boolean query. |

The key difference is ownership: Action Scheduler's `$group` defaults to `''`, leaving work ownerless and easy to clear by accident. The engine requires the owner up front and composes it into every identity. Repeated `jobs()->enqueue()` calls reject matching live work under the default overlap policy.

## Idempotency invariant

Schedule-driven jobs and chunked job chunks MUST be idempotent. The overlap guard reduces double-fire to the crash-and-reclaim residual; it cannot eliminate it. Backend redelivery, and a reclaimed run reviving after its stale lock is taken, can execute the same logical occurrence more than once. Terminal callbacks and terminal lifecycle hooks (`completed`, `failed`, `cancelled`, `superseded`) share the same at-least-once crash window between an external effect and its persisted completion marker — durable under Action Scheduler, best-effort under WP-Cron. The `started` hook is inline and non-durable, so a crash between admission and hook delivery can lose it.

## Admission, overlap, and catch-up policies

Each Job declares one `Job\OverlapPolicy` for imperative and scheduled admission. Its `overlap_key()` can derive an opaque 1-to-64-byte collision identity from the start arguments; `null` uses the canonical argument hash. Matching is scoped to the owner-qualified identity. Failed-run retry preserves `Allow`; `Reject` and `Replace` retry with `Reject`. Catch-up independently determines what happens when a scheduled delivery is late beyond its grace window.

| Overlap | `run_once` catch-up (default) | `skip` catch-up |
| --- | --- | --- |
| `Allow` | Dispatches one due or make-up run even while matching work runs. | Drops a beyond-grace occurrence; otherwise dispatches even while matching work runs. |
| `Reject` (default) | Attempts one due or make-up run, recorded as skipped while a fresh matching lock is held. | Drops a beyond-grace occurrence; otherwise dispatches only when no fresh matching lock is held. |
| `Replace` | Dispatches one due or make-up run; transfers a held matching lock to the new run. | Drops a beyond-grace occurrence; otherwise dispatches and transfers a held matching lock. |

An occurrence is a misfire only when observed strictly after `next_due + grace`; grace defaults to one interval and is filterable. `run_once` attempts one make-up occurrence and realigns; `skip` drops it, realigns, and emits the misfire-skipped hooks.

## Hooks and filters

The `started`, `completed`, `cancelled`, `superseded`, and `retry_scheduled` events fire the identity-specific hook first and the generic hook second with the identity prepended. The `failed` event is the exception: it uses only `a8csp_jobs_engine/failed` and dispatches one `Run\RunFailure` argument. Terminal hooks (`completed`, `failed`, `cancelled`, and `superseded`) are durable under Action Scheduler and best-effort under WP-Cron; `started` is inline and non-durable.

| Event | Hooks and arguments |
| --- | --- |
| Started | `a8csp_jobs_engine/started/{identity}`: `(string $run_id, array $start_args)` · `a8csp_jobs_engine/started`: `(string $identity, string $run_id, array $start_args)` |
| Completed | `a8csp_jobs_engine/completed/{identity}`: `(string $run_id, array $start_args, ?string $previous_completed_run_id)` · generic prepends `$identity` |
| Failed | `a8csp_jobs_engine/failed`: `(Run\RunFailure $failure)`; filter per work item on `$failure->identity` |
| Cancelled | `a8csp_jobs_engine/cancelled/{identity}`: `(string $run_id, array $start_args)` · generic prepends `string $identity` |
| Superseded | `a8csp_jobs_engine/superseded/{identity}`: `(string $run_id, array $start_args)` · generic prepends `string $identity` |
| Retry scheduled | `a8csp_jobs_engine/retry_scheduled/{identity}`: `(string $run_id, array $start_args, int $attempt, int $delay)` · generic prepends `string $identity`; attempt is the one-indexed failed-attempt count and delay is the chosen delay in seconds |
| Misfire skipped | `a8csp_jobs_engine/misfire_skipped/{schedule_identity}`: `(string $owner, int $due_at, int $observed_at)` · generic prepends `string $schedule_identity` |
| Log | `a8csp_jobs_engine/log`: `(string $level, string $message, array $context)` |

A `started` or `retry_scheduled` listener that throws terminally fails the admitted run with `execution_failed`; when retention succeeds, the failed run is available for manual retry. Do not hook the engine's internal delivery actions.

| Filter | Input and required return |
| --- | --- |
| `a8csp_jobs_engine/retry_policy/{identity}` | `(Job\RetryPolicy $policy): Job\RetryPolicy`; a foreign return leaves the contract policy in effect. |
| `a8csp_jobs_engine/queue/{identity}` | `(array $queue, array $start_args, string $run_id): array`; return an array list containing the complete set of chunk argument arrays. |
| `a8csp_jobs_engine/misfire_grace/{schedule_identity}` | `(int $interval, string $owner, string $schedule_identity): int`; return a non-negative grace in seconds, defaulting to one interval. |
| `a8csp_jobs_engine/continue_delay` | `(int $delay, string $identity, string $run_id): int`; return a non-negative delay in seconds, defaulting to 60. It also floors lock staleness at twice the delay. |
| `a8csp_jobs_engine/lock_staleness/{identity}` | `(int $seconds): int`; return a positive lock window, defaulting to 900 and at least twice the continue delay. |
| `a8csp_jobs_engine/history_size` | `(int $size): int`; return a positive per-buffer history cap, defaulting to 30. |
| `a8csp_jobs_engine/log_to_error_log` | `(bool $enabled): bool`; return whether to register the default PHP error-log sink, defaulting to `true`. |

## Bring your own PSR-3 logger

The engine writes `a8csp_jobs_engine/log` events to PHP's error log by default. To route them to a `Psr\Log\LoggerInterface`, disable the default sink and attach a three-argument listener:

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

Do not redefine `a8csp_bgje()` or the `a8csp_bgje_*()` aliases; the engine declares them unconditionally, so a test redefinition fatals. Keep application code testable by placing engine capability calls behind an application-owned interface or callable and fake that boundary in unit tests. Exercise the public models and functions in WordPress integration tests. Types under `src/Internal/` are internal engine seams, not consumer injection contracts.

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
| Remove every schedule owned by one plugin | `wp background-jobs schedules remove <owner> [--yes]` |
| Destroy all engine state (development reset) | `wp background-jobs reset [--yes]` |

Every `<identity>` is a composed `{owner}:{name}`; PHP calls take the owner-local name while the CLI takes the full identity. List commands accept `table`, `csv`, `json`, `count`, or `yaml` (default `table`); `runs list` includes recent history only in `table`, `json`, and `yaml`, and its `count` is the bounded live count. `reset` permanently deletes every engine option row and pending backend action, including the maintenance registration the next boot recreates; it prompts unless `--yes`. `schedules remove` converges an owner's schedules to empty without cancelling existing runs and errors on an owner with no persisted registry row.

## Releasing

Releases are cut by pushing a version tag. The release workflow fails closed unless the plugin header, `package.json`, and the newest `CHANGELOG.md` entry all agree with the tag, then builds and smoke-tests the distribution ZIP; prereleases publish outside the stable update channel. Publication also requires green trunk-push Quality and Tests runs at the exact tagged commit.

`CHANGELOG.md` is generated from the fragments in `changelog/` by `composer changelog:write`. The first release and each prerelease entry pass their version explicitly:

```sh
composer changelog:write -- --use-version=1.0.0-beta.1
```
