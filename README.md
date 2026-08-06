# A8CSP Background Jobs Engine

**Contributors:** wpspecialprojects
**Tags:**
**Requires at least:** 7.0
**Tested up to:** 7.0
**Requires PHP:** 8.5
**Stable tag:** 1.0.0-beta.2
**License:** GPL v2 or later
**License URI:** <https://www.gnu.org/licenses/gpl-2.0.html>

A background-work engine for WordPress sites: Jobs, Schedules, and Chunked Jobs using Action Scheduler when available, with a documented best-effort WP-Cron fallback.

## What it is

A **Job** is one named unit of background work. A `JobDefinition` composes its name, kind, execution object, and policy declaration. Standard execution objects implement `JobExecutionInterface`; Chunked Job execution objects implement the standalone `ChunkedJobExecutionInterface` role to split work into independently processed chunks. `JobDefinition::closure()` provides a closure-backed standard Job, with an optional `JobOptions` declaration. A **Schedule** dispatches registered work on a fixed recurrence. Every piece of work belongs to a **scope** (your plugin slug); the engine composes `{scope}:{name}` into one identity so plugins using distinct scopes do not collide. A scope is a single-writer partition: exactly one plugin declares the schedules for a given scope, and a sync call for that scope is authoritative over every registration inside it.

Consumers use four connected surfaces:

- **The Engine handle** — `a8csp_bgje( $scope )` returns a scope-bound `Engine` with `jobs()`, `schedules()`, and `runs()` capability managers.
- **The public models and execution roles** — compose work with `JobDefinition`, `JobKind`, and `JobOptions`; implement `JobExecutionInterface` or `ChunkedJobExecutionInterface`; declare schedules with `Schedule`, `Recurrence`, and `CatchUpPolicy`; callbacks depend on `RunContextInterface` or `ChunkedRunContextInterface`; run-producing commands return `Run` snapshots, terminal failures use `RunFailure`, and verb failures return `WP_Error`.
- **The procedural aliases** — nine verb-noun `a8csp_bgje_*()` functions take `$scope` first, accept the same `JobDefinition` registration value as the Jobs manager and the same variadic `Schedule` values as the Schedules manager, and invoke the capability-manager verbs.
- **The lifecycle hooks** — observe runs through the `a8csp_bgje/*` actions.

The data boundary is deliberate: capability managers accept typed definition, policy, and schedule values, and payloads the engine hands to consumer code are typed objects such as `Run`, `RunContext`, and `RunFailure`; execution callbacks depend on `RunContextInterface` or `ChunkedRunContextInterface`. The procedural aliases accept the same typed definition and schedule values as their capability-manager counterparts.

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

Action Scheduler is optional and preferred when ready; when absent, the engine runs on WP-Cron alone. **Action Scheduler 4.1.0 is the supported floor.** Chunked Jobs schedule each successor as a unique action that differs from the running one only in its sequence argument, and Action Scheduler made unique scheduling args-aware in 4.0.0; that is the functional requirement. The floor sits above it because 4.1.0 hardened deserialization of stored schedule data, and the engine declines to drive an elected copy below that. An initialized copy below 4.1.0 is treated as unusable rather than trusted: the engine runs on WP-Cron and flags the elected copy as unsupported in its scheduling diagnostics. Action Scheduler elects the highest version among every bundled copy on the site, so the deciding version is not necessarily the one shipped beside any single plugin.

## Depending on the engine

The engine is a separate plugin, and `a8csp_bgje()` is a plain function: if the engine is deactivated, every consumer call is a fatal error on `init`. Declare the dependency in your plugin header so WordPress enforces it:

```php
/**
 * Plugin Name: My Plugin
 * Requires Plugins: a8csp-background-jobs-engine
 */
```

WordPress refuses to activate your plugin without the engine and blocks deactivating the engine while you depend on it. If the dependency is genuinely optional, guard instead of declaring it:

```php
if ( function_exists( 'a8csp_bgje' ) ) {
	a8csp_bgje( 'my-plugin' )->jobs()->register( $definition );
}
```

Never define `a8csp_bgje()` yourself as a fallback; a second definition of the same name is a fatal error the moment both plugins load.

## When to call the engine

`a8csp_bgje( $scope )` constructs a handle lazily and infallibly. Capability-manager verb calls belong on the WordPress `init` hook or later. Invalid scopes and unavailable engine state return `WP_Error` from the first verb instead of failing handle construction. The request that activates the engine stays dormant until the next request.

Register work and synchronize schedules from `init` **on every request**: registration is per-request, and schedule synchronization treats the supplied schedules as the scope's complete declaration. Prefer `init` priority `2` or later so Action Scheduler's `init:1` store initialization has run; synchronizing before it routes that request's occurrences to WP-Cron.

Register unconditionally. A delivery that arrives on a request where its definition was never registered fails that run terminally with `unknown_job` and consumes no automatic attempt, because the engine cannot distinguish work you removed from work you forgot to declare. Guarding registration behind `is_admin()`, a page check, or a capability check is the usual way this happens, and it fails intermittently rather than consistently: background deliveries run in whichever request context the backend chooses, including admin-ajax.

Pass your plugin's lowercase slug as the scope (matching `[a-z0-9][a-z0-9-]*`, at most 32 bytes, never starting with the reserved `a8csp-bgje` prefix). Job, Chunked Job, and Schedule names match `[a-z0-9_-]+` and are at most 64 bytes.

## Quick start

```php
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\JobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContextInterface;

final class RefreshCacheExecution implements JobExecutionInterface {
	public const string NAME = 'refresh-cache';

	public function handle( array $start_args, RunContextInterface $context ): void {
		my_plugin_refresh_cache(
			(int) ( $start_args['site_id'] ?? 0 ),
			(string) $context->get_run_id()
		);
	}
}

add_action( 'init', static function (): void {
	$bg = a8csp_bgje( 'my-plugin' );

	$registered = $bg->jobs()->register(
		JobDefinition::job( RefreshCacheExecution::NAME, new RefreshCacheExecution() )
	);
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
	}
}, 2 );

function my_plugin_queue_cache_refresh( int $site_id ): void {
	$bg  = a8csp_bgje( 'my-plugin' );
	$run = $bg->jobs()->dispatch( RefreshCacheExecution::NAME, array( 'site_id' => $site_id ) );
	if ( is_wp_error( $run ) ) {
		error_log( $run->get_error_message() );
		return;
	}

	update_option( 'my_plugin_last_cache_run', (string) $run->id );
}
```

The execution class's public `NAME` constant co-locates its stable scope-local name with its behavior. Definitions, imperative dispatches, and schedules reference the same constant.

Each successful dispatch returns an immutable `Run` snapshot with `identity`, `id` (a `RunId` value), and `status`. Automatic attempts for one admitted run keep the same run ID. Every capability-manager verb returns its success value or `WP_Error` for an expected validation, registration, readiness, or engine failure. Errors have a stable string code and an engine-authored message, and may carry redaction-safe structured context.

## Examples

Five complete, copy-paste-adaptable scenarios follow. Each uses the scope slug `my-plugin`.

### 1. A recurring schedule, full lifecycle

Define the job and synchronize the scope's complete schedule declaration on every `init`.

```php
use A8C\SpecialProjects\BackgroundJobsEngine\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\JobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;

final class PruneTransientsExecution implements JobExecutionInterface {
	public const string NAME = 'prune-transients';

	public function handle( array $start_args, RunContextInterface $context ): void {
		my_plugin_prune_expired_transients();
	}
}

add_action( 'init', static function (): void {
	$bg = a8csp_bgje( 'my-plugin' );

	$registered = $bg->jobs()->register(
		JobDefinition::job( PruneTransientsExecution::NAME, new PruneTransientsExecution() )
	);
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
		return;
	}

	$synced = $bg->schedules()->sync(
		new Schedule(
			'nightly-prune',
			Recurrence::every( DAY_IN_SECONDS ),
			PruneTransientsExecution::NAME,
			catch_up: CatchUpPolicy::RunOnce,
		),
	);
	if ( is_wp_error( $synced ) ) {
		error_log( $synced->get_error_message() );
	}
}, 2 );

register_deactivation_hook( __FILE__, static function (): void {
	$removed = a8csp_bgje_sync_schedules( 'my-plugin' );
	if ( is_wp_error( $removed ) ) {
		error_log( $removed->get_error_message() );
	}
} );
```

An unanchored schedule first runs one interval after synchronization. An anchored schedule uses the first strictly future point on its UTC phase grid. There is no first-run timestamp field. Omitting a schedule from the next complete declaration removes it; synchronizing with no schedules removes all schedules for the scope without cancelling admitted runs. Under WP-Cron, removal reaches events in WordPress's own `cron` option; a replacement cron store installed through the `pre_*` cron filters keeps its own events, and the engine reports removal as complete without seeing them. If the plugin is inactive, `wp a8csp-bgje schedules remove my-plugin --yes` performs the same schedule convergence.

### 2. A one-shot callable, dispatched asynchronously

`JobDefinition::closure()` is the class-free authoring form. Its handler receives the invocation arguments and a typed `RunContextInterface`.

```php
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContextInterface;

add_action( 'init', static function (): void {
	$registered = a8csp_bgje_register_job(
		'my-plugin',
		JobDefinition::closure(
			'email-digest',
			static function ( array $start_args, RunContextInterface $context ): void {
				my_plugin_send_digest(
					(int) $start_args['user_id'],
					(string) $context->get_run_id()
				);
			}
		)
	);
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
	}
}, 2 );

function my_plugin_queue_digest( int $user_id ): void {
	$run = a8csp_bgje_dispatch_job(
		'my-plugin',
		'email-digest',
		array( 'user_id' => $user_id )
	);
	if ( is_wp_error( $run ) ) {
		// A run already owns this lane and is doing the work, so there is nothing to queue.
		if ( ErrorCode::OverlapHeld->value === $run->get_error_code() ) {
			return;
		}

		error_log( $run->get_error_message() );
		return;
	}

	update_user_meta( $user_id, 'my_plugin_last_digest_run', (string) $run->id );
}
```

`JobDefinition::closure()` accepts the same optional `JobOptions` as `JobDefinition::job()`, so a closure-backed Job declares retry, overlap and runtime policy without an execution class. `a8csp_bgje_dispatch_job()` dispatches immediately; `a8csp_bgje_dispatch_job_at()` accepts an absolute Unix timestamp. A successful return proves admission, not completion. Keep arguments small and portable: pass identifying keys rather than bulk data.

### 3. A chunked job over a chunk queue

A Chunked Job implements queue generation and one-chunk processing. Queue mutations through `ChunkedRunContextInterface` commit only when `process_chunk()` returns normally.

```php
use A8C\SpecialProjects\BackgroundJobsEngine\ChunkedJobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\ChunkedRunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;

final class RecountCommentsExecution implements ChunkedJobExecutionInterface {
	public const string NAME = 'recount-comments';

	public function generate_queue( array $start_args, RunContextInterface $context ): iterable {
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

	public function process_chunk( array $chunk_args, ChunkedRunContextInterface $context ): void {
		wp_update_comment_count_now( (int) $chunk_args['post_id'] );

		if ( ! empty( $chunk_args['spawn_followup'] ) ) {
			$context->append_chunk(
				array(
					'post_id' => (int) $chunk_args['post_id'],
					'verify'  => true,
				)
			);
		}
	}
}

add_action( 'init', static function (): void {
	$registered = a8csp_bgje_register_job(
		'my-plugin',
		JobDefinition::chunked_job(
			RecountCommentsExecution::NAME,
			new RecountCommentsExecution(),
			new JobOptions(
				max_runtime: 300,
				retry: new RetryPolicy(
					max_attempts: 5,
					base_delay: MINUTE_IN_SECONDS,
					multiplier: 2,
					max_delay: HOUR_IN_SECONDS
				),
				overlap: OverlapPolicy::Reject,
				overlap_key: static fn ( array $args ): ?string => is_string( $args['post_type'] ?? null )
					? $args['post_type']
					: null,
			)
		)
	);
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
	}
}, 2 );

function my_plugin_dispatch_recount(): void {
	$run = a8csp_bgje_dispatch_job(
		'my-plugin',
		RecountCommentsExecution::NAME,
		array( 'post_type' => 'post' )
	);
	if ( is_wp_error( $run ) ) {
		error_log( $run->get_error_message() );
		return;
	}

	update_option( 'my_plugin_last_recount_run', (string) $run->id );
}

add_action(
	'a8csp_bgje/completed/my-plugin:recount-comments',
	static function ( RunId $run_id, array $start_args ): void {
		my_plugin_cleanup_recount( (string) $run_id, $start_args );
	},
	10,
	2
);
```

Chunks run one at a time with a short pause between them. `ChunkedRunContextInterface` also exposes `prepend_chunk()`, `get_run_id()`, and `get_start_args()`. A failed chunked job starts a fresh run from its retained arguments and retained priority through `runs()->retry_failed()`; the retry replays both values directly instead of resolving priority again. A retained entry without a priority field uses engine default 10.

`ChunkedJobExecutionInterface` defines queue generation and chunk processing; post-queue work belongs on the completed hook above, whose payload and delivery guarantees are covered in [Reacting to run lifecycles](#4-reacting-to-run-lifecycles).

### 4. Reacting to run lifecycles

Lifecycle reactions are hooks-only: the engine pushes every outcome, so consumers listen instead of
polling. Every admitted run ends in exactly one of four terminal states — completed, failed,
cancelled, or superseded — and each fires its identity-specific hook first, then its generic hook.

```php
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;

add_action( 'init', static function (): void {
	// Completed: the payload carries the run's RunId and, when one exists, the previously
	// completed run — enough to keep a scope-side pointer without polling.
	add_action(
		'a8csp_bgje/completed/my-plugin:email-digest',
		static function ( RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ): void {
			update_option( 'my_plugin_last_completed_digest', (string) $run_id );
		},
		10,
		3
	);

	// Failed: both variants receive the same self-identifying RunFailure. See "Handling
	// failures" for the retry-policy side of this hook.
	add_action(
		'a8csp_bgje/failed/my-plugin:email-digest',
		static function ( RunFailure $failure ): void {
			my_plugin_alert_on_digest_failure( (string) $failure->run_id, $failure->summary );
		},
		10,
		1
	);

	// Cancelled: an operator or scope code withdrew the run while it was not executing.
	add_action(
		'a8csp_bgje/cancelled/my-plugin:email-digest',
		static function ( RunId $run_id, array $start_args ): void {
			my_plugin_release_digest_reservation( (string) $run_id );
		},
		10,
		2
	);

	// Superseded: a Replace-policy dispatch displaced this run; the replacement carries its
	// own lifecycle, so clean up anything keyed to the displaced run's id.
	add_action(
		'a8csp_bgje/superseded/my-plugin:email-digest',
		static function ( RunId $run_id, array $start_args ): void {
			my_plugin_discard_partial_digest( (string) $run_id );
		},
		10,
		2
	);
}, 2 );
```

Terminal hooks are durable under Action Scheduler and best-effort under WP-Cron, and crash-recovery
replay can deliver them more than once — key every reaction to the run ID so repeated delivery
converges. The generic variants (`a8csp_bgje/completed`, …) prepend `string $identity` (except
`failed`, whose payload already self-identifies) and serve one listener across every identity.

Acting on runs, rather than reacting, stays imperative: `runs()->cancel()` (or
`a8csp_bgje_cancel_run()`) withdraws a retained run, `runs()->retry_failed()` starts a fresh run
from a retained failure, and `runs()->inspect()` / `runs()->last_completed()` answer point-in-time
questions from tooling — inspection returns `run_not_retained` for an absent run, and
`last_completed()` returns `null` outside the retained history window. Day-2 operator workflows
live in the CLI — see "WP-CLI".

### 5. Handling failures

The identity-specific `a8csp_bgje/failed/{identity}` hook fires before the generic `a8csp_bgje/failed` hook; both receive the same `RunFailure` object. Crash-recovery replay can reconstruct an equivalent value. Throw `NonRetryableException` from `handle()`, `generate_queue()`, or `process_chunk()` to fail permanently without consuming the remaining automatic attempts. An uncaught portability or size rejection is also deterministic: the failing invocation counts once, the engine schedules no automatic retry, and the remaining allowance stays unused. A queue that crosses the byte limit by more than its index envelope is refused by the chunked run context as the mutation is made; one that crosses it only within that envelope is refused when the attempt commits, and reports `payload_rejected` at the scheduling stage.

```php
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\JobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;

final class PublishWebhookExecution implements JobExecutionInterface {
	public const string NAME = 'publish-webhook';

	public function handle( array $start_args, RunContextInterface $context ): void {
		if ( my_plugin_webhook_is_revoked( (string) $start_args['endpoint_id'] ) ) {
			throw new NonRetryableException( 'The endpoint is permanently unavailable.' );
		}

		my_plugin_publish_webhook( (string) $start_args['endpoint_id'], (string) $context->get_run_id() );
	}
}

add_action( 'init', static function (): void {
	$registered = a8csp_bgje_register_job(
		'my-plugin',
		JobDefinition::job( PublishWebhookExecution::NAME, new PublishWebhookExecution() )
	);
	if ( is_wp_error( $registered ) ) {
		error_log( $registered->get_error_message() );
	}

	add_action(
		'a8csp_bgje/failed/my-plugin:' . PublishWebhookExecution::NAME,
		static function ( RunFailure $failure ): void {
			error_log( sprintf( 'Run %s consumed %d attempt(s).', $failure->run_id, $failure->attempts ) );
		},
		10,
		1
	);
}, 2 );
```

The failure summary is engine-authored and redacted; it never contains raw exception text. Terminal hooks can replay across crash recovery, so listeners use the run ID to converge repeated delivery. Their delivery is durable under Action Scheduler and best-effort under WP-Cron. Completed, failed, cancelled, and superseded reactions use their lifecycle hooks exclusively.

The engine retains up to 20 failed runs per scope-qualified identity for manual retry, subject also to a 1,000,000-byte ceiling on the complete serialized retention row. It evicts oldest entries first until both bounds hold. If a new entry cannot fit even by itself, the engine rejects that entry instead of retaining it and leaves the existing row intact. A retry that successfully starts a fresh run attempts to remove its retained source entry; a failed removal is logged. Retention is best-effort: a retention write failure is logged rather than made fatal.

## The procedural functions

`a8csp_bgje( string $scope ): Engine` returns the lazy scope-bound handle. Each alias below takes `$scope` first, converts wire run identifiers to their public values where needed, invokes the matching capability-manager verb, and returns the same shape.

The procedural facade is grouped by concept: `includes/job.php` provides background-work registration plus kind-agnostic immediate and absolute-time dispatch, `includes/schedule.php` provides schedule synchronization and dispatch, and `includes/run.php` provides run inspection, retry, and cancellation.

| Capability-manager verb | Procedural alias | Returns |
| --- | --- | --- |
| `jobs()->register( JobDefinition $definition )` | `a8csp_bgje_register_job( string $scope, JobDefinition $definition )` | `true \| WP_Error` |
| `jobs()->dispatch( string $name, array $start_args = array(), ?int $priority = null )` | `a8csp_bgje_dispatch_job( string $scope, string $name, array $start_args = array(), ?int $priority = null )` | `Run \| WP_Error` |
| `jobs()->dispatch_at( string $name, int $run_at, array $start_args = array(), ?int $priority = null )` | `a8csp_bgje_dispatch_job_at( string $scope, string $name, int $run_at, array $start_args = array(), ?int $priority = null )` | `Run \| WP_Error` |
| `schedules()->sync( Schedule ...$schedules )` | `a8csp_bgje_sync_schedules( string $scope, Schedule ...$schedules )` | `true \| WP_Error` |
| `schedules()->dispatch( string $name )` | `a8csp_bgje_dispatch_schedule( string $scope, string $name )` | `Run \| WP_Error` |
| `runs()->inspect( string $name, RunId $run_id )` | `a8csp_bgje_inspect_run( string $scope, string $name, string $run_id )` | `Run \| WP_Error` |
| `runs()->last_completed( string $name )` | `a8csp_bgje_last_completed_run( string $scope, string $name )` | `Run \| null \| WP_Error` |
| `runs()->retry_failed( string $name, RunId $run_id )` | `a8csp_bgje_retry_failed_run( string $scope, string $name, string $run_id )` | `Run \| WP_Error` |
| `runs()->cancel( string $name, RunId $run_id )` | `a8csp_bgje_cancel_run( string $scope, string $name, string $run_id )` | `Run \| WP_Error` |

Registration is typed on both surfaces. Compose a `JobDefinition` through `job()`, `chunked_job()`, `closure()`, or the engine-kind primitive `for_kind()`, then pass that value unchanged to the manager or procedural function.

Dispatch resolves the registered kind before admission. Priority resolution and schedule-fingerprint behavior are documented under [Priority is advisory](#priority-is-advisory).

## Public models, roles, and contexts

Imports are optional; public types can also be referenced fully qualified:

```php
$run_id = \A8C\SpecialProjects\BackgroundJobsEngine\RunId::tryFrom( $persisted_run_id );
```

All public type names below are relative to the `A8C\SpecialProjects\BackgroundJobsEngine` namespace.

This table is the public PHP type index. Every listed type is marked `@api` and belongs to the PHP API tier of the [canonical SemVer contract](#releasing); any other autoloadable engine type is internal unless this README explicitly documents it as public.

| Type | Public shape |
| --- | --- |
| `Engine` | Scope-bound readonly handle returned by `a8csp_bgje()` with its `jobs()`, `schedules()`, and `runs()` capability managers. |
| `Jobs` | Scope-bound readonly manager for registration, immediate dispatch, and absolute-time dispatch. |
| `Schedules` | Scope-bound readonly manager for schedule synchronization and immediate dispatch. |
| `Runs` | Scope-bound readonly manager for run inspection, retry, and cancellation. |
| `JobDefinition` | Final readonly registration declaration with public `string $name`, `JobKind $kind`, `KindExecutionInterface $execution`, and `JobOptions $options`. Its non-public constructor is exposed through `job()`, `chunked_job()`, `closure()`, and `for_kind()`, each taking an optional `JobOptions`. |
| `JobKind` | Final readonly kind key with public `string $value`, built-in `job()` and `chunked_job()` constructors, and `from( string $value )` for a grammar-valid key. It carries no execution contract. |
| `JobOptions` | Final readonly policy declaration constructed with optional named parameters `?int $max_runtime`, `?RetryPolicy $retry`, `?OverlapPolicy $overlap`, `?\Closure $overlap_key`, and `?int $priority`. Null uses the documented default for each policy except priority, where it defers to the priority resolution ladder ending at engine default 10. `max_runtime` supplies per-invocation crash-reclamation credit, not an execution limit. Construction stores `max_runtime` and priority without validating their declared bounds: `jobs()->register()` rejects an invalid `max_runtime` or job-default priority, `schedules()->sync()` rejects an invalid schedule priority, and `jobs()->dispatch()` rejects an invalid explicit priority. The [consumer limits](#consumer-limits) give the exact bounds and effective clamp. |
| `KindExecutionInterface` | Empty marker shared by the standard and chunked execution roles so a kind-agnostic declaration can require execution membership while registration resolves the kind-specific role. |
| `JobExecutionInterface` | Standard execution role extending `KindExecutionInterface` and requiring only `handle( array $start_args, RunContextInterface $context ): void`. |
| `ChunkedJobExecutionInterface` | Standalone chunked execution role extending `KindExecutionInterface` and requiring only `generate_queue( array $start_args, RunContextInterface $context ): iterable` and `process_chunk( array $chunk_args, ChunkedRunContextInterface $context ): void`; it does not extend `JobExecutionInterface`. |
| `Schedule` | Readonly schedule declaration constructed from `name`, `recurrence`, target `job`, `args`, `catch_up`, and `priority`; only `name`, `recurrence`, and `job` are required, and the rest default to an empty array, `CatchUpPolicy::RunOnce`, and null. |
| `Recurrence` | Readonly fixed-interval recurrence created with `every( int $seconds )` or `every_anchored( int $seconds, int $anchor )`; an anchor is reduced modulo the interval. |
| `Run` | Readonly snapshot with `string $identity`, `RunId $id`, and `RunStatus $status`. |
| `RunId` | Final readonly stringable wrapper for a canonical run identifier; `from( string )` requires canonical input, `tryFrom( string )` returns null for another shape, and string casting returns the wire value. |
| `RunFailure` | Readonly value with `string $identity`, `RunId $run_id`, `int $attempts`, `RunFailureStage $stage`, `ErrorCode $code`, `string $summary`, and generic diagnostic payload `?array $details`. |
| `RunFailureStage` | Final readonly interned open string-backed stage carried by `RunFailure::$stage`; its grammar, engine stages, and third-party keys are described below this table. |
| `RetryPolicy` | Readonly value constructed from `max_attempts`, `base_delay`, `multiplier`, and `max_delay`; defaults are 3, `MINUTE_IN_SECONDS`, 2, and `HOUR_IN_SECONDS`. `max_attempts` includes the initial attempt. It bounds consecutive failures at one point of a run rather than failures across the whole run: a successful chunk or queue generation clears the consumed attempts, so a chunked job allows this many consecutive failures at each chunk. Attempts, base delay, and multiplier are at least 1, and maximum delay is at least the base delay. It exposes `delay_ceiling_for_attempt( int $attempt ): int`. |
| `RunContextInterface` | `get_run_id(): RunId` and `get_start_args(): array`. |
| `RunContext` | Final context constructed with `RunId $run_id` and `array $start_args`; implements `RunContextInterface`. |
| `ChunkedRunContextInterface` | Extends `RunContextInterface` with `append_chunk( array $chunk_args ): void` and `prepend_chunk( array $chunk_args ): void`. |
| `NonRetryableException` | Runtime exception that marks client work as permanently failed. |

The engine supplies the only implementation of `ChunkedRunContextInterface`; consumers must not implement it, and methods may be added in minor versions.

`JobKind::from()` validates kind-key grammar but does not install a kind. Only engine-installed kinds can be registered. The generic definition path is registration data, while kind handlers and their SPI stay internal.

The backed enums are:

| Enum | Cases and backing values |
| --- | --- |
| `RunStatus` | `Running = 'running'`, `Completed = 'completed'`, `Failed = 'failed'`, `Cancelled = 'cancelled'`, `Superseded = 'superseded'` |
| `OverlapPolicy` | `Allow = 'allow'`, `Reject = 'reject'`, `Replace = 'replace'` |
| `CatchUpPolicy` | `RunOnce = 'run_once'`, `Skip = 'skip'` |
| `ErrorCode` | `InvalidArgument = 'invalid_argument'`, `AlreadyRegistered = 'already_registered'`, `EngineUnavailable = 'engine_unavailable'`, `UnknownJob = 'unknown_job'`, `UnknownSchedule = 'unknown_schedule'`, `OverlapHeld = 'overlap_held'`, `AdmissionConflict = 'admission_conflict'`, `PayloadRejected = 'payload_rejected'`, `BackendUnavailable = 'backend_unavailable'`, `BackendRejected = 'backend_rejected'`, `StorageFailed = 'storage_failed'`, `RunNotRetained = 'run_not_retained'`, `RunNotCancellable = 'run_not_cancellable'`, `UnsupportedOperation = 'unsupported_operation'`, `ExecutionFailed = 'execution_failed'` |

Consumers treat unknown backing values as generic failures for `ErrorCode` and as generic non-terminal or terminal states, as appropriate, for `RunStatus`.

`RunStatus` has no pending case. `Running` means admitted and not yet terminal, so a run dispatched for a future time reports `running` from admission onward, including while it waits for that time to arrive.

`RunFailureStage` is a final, interned, open string-backed value. `from( string )` wraps a lowercase snake key with at most one dot qualifier and throws `\ValueError` for malformed input; `tryFrom( string )` returns null instead. Engine stages are available through `execution()`, `queue_generation()`, `crash_reclamation()`, and `scheduling()`. Grammar-valid third-party stages such as `acme.export_sync` remain intact.

`JobKind::from()`, `RunId::from()`, and `RunFailureStage::from()` throw `\ValueError` for malformed strings. `\ValueError` extends `\Error`, not `\Exception`; use the corresponding `tryFrom()` parser when malformed consumer input should return null.

## Authoring work

### Definitions and kinds

Every registration supplies one immutable `JobDefinition`. The typed constructors are:

- `JobDefinition::job( string $name, JobExecutionInterface $execution, ?JobOptions $options = null )`
- `JobDefinition::chunked_job( string $name, ChunkedJobExecutionInterface $execution, ?JobOptions $options = null )`
- `JobDefinition::closure( string $name, \Closure $handler, ?JobOptions $options = null )`
- `JobDefinition::for_kind( string $name, JobKind $kind, KindExecutionInterface $execution, ?JobOptions $options = null )`

The `job()` and `chunked_job()` constructors bind the built-in kind to its typed execution role. The closure handler receives `(array $start_args, RunContextInterface $context)`; the adapter that implements its execution role is internal. `for_kind()` is the generic registration-data primitive. Only engine-installed kinds register successfully, and consumer code cannot install or implement kind handlers.

`JobKind::job()` and `JobKind::chunked_job()` return the installed built-in keys. `JobKind::from()` wraps a grammar-valid key matching `[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?`; it does not prove that a handler is installed. Passing an object that does not implement `KindExecutionInterface` to `for_kind()` fails definition construction with `\TypeError`. Registration of an uninstalled kind returns `WP_Error` with `invalid_argument` and names the kind. A marker-implementing object of the wrong role reaches registration, where the resolved handler returns `WP_Error` with `invalid_argument` and names both the kind and its expected execution interface.

### Job execution and policy

`JobExecutionInterface` requires exactly `handle( array $start_args, RunContextInterface $context ): void`. The definition supplies the name and policy, so the execution role carries no naming, policy, or lifecycle-reaction methods. A normal return succeeds. A handler that catches its own failure and returns normally therefore records no failed attempt and leaves the retry budget untouched. Only a throwable that escapes the handler fails the attempt and follows the retry policy, except `NonRetryableException`, which fails permanently.

`JobOptions` carries five independent optional policies: `max_runtime`, `retry`, `overlap`, `overlap_key`, and `priority`. Null uses the documented default for each policy except priority, where it leaves the other resolution rungs operative. `max_runtime` supplies the per-invocation crash-reclamation credit: the engine credits the handler for this duration before its heartbeat begins aging through a separately resolved lock-staleness window. It never interrupts the handler. `jobs()->register()` rejects a non-null value below one second, so `0` is invalid rather than unlimited. Null selects 300 seconds, and declarations above 21,600 seconds (6 hours) remain valid but clamp to that effective credit. An unlimited credit would leave a crashed run's valid lock unreclaimable: under the default `OverlapPolicy::Reject`, every later matching dispatch would be refused because maintenance could never classify the valid lock as stale.

Maintenance also crash-fails a persisted `Running`, executing run when schema-invalid lock bytes hide its lock heartbeat and its run-row heartbeat is stale. Delivery ownership stamps the lock and run row from the same credited timestamp, so the run-row heartbeat is valid liveness evidence in that case. A stale non-executing run with no pending-action descriptor follows the same recovery path; a fresh row remains preserved. The later lock phase reads active-run values in bounded batches, retains only unreadable-row and Running-lane liveness, and exact-deletes an inspected malformed generation only when no Running row occupies its lane. An unreadable active-run row preserves every malformed lane for that identity. A changed or absent generation loses the exact-delete fence silently, while an option-delete failure emits a redacted operator warning.

While a non-executing row is fresh and preserved, `wp a8csp-bgje runs cancel` can resolve it only when the identity has a live executable registration matching the persisted kind; otherwise cancellation returns `unknown_job`. Once the heartbeat is stale, maintenance can crash-reclaim the engine-installed `job` and `chunked_job` kinds without that consumer registration and then reclaim the orphaned malformed lock.

Neither `JobOptions` nor `Schedule` validates priority during construction. `jobs()->register()` rejects an out-of-range job default, `schedules()->sync()` rejects an out-of-range schedule value, and `jobs()->dispatch()` rejects an out-of-range explicit argument. The remaining defaults are a `RetryPolicy` with 3 maximum attempts, a 60-second base delay, multiplier 2, and 3,600-second maximum delay, `OverlapPolicy::Reject`, and a null overlap-key resolver. The `priority` field defaults to null; the resolution ladder ends at engine default 10. A null resolver uses the canonical argument hash. The `a8csp_bgje/retry_policy` filter receives the resolved policy before `a8csp_bgje/retry_policy/{identity}` applies the work-specific result.

Expiry of the credit and lock-staleness window does not interrupt a handler. Crash reconciliation can then reclaim the run and admit replacement work that overlaps it, so handlers remain idempotent.

### Chunked Job and chunked run context

`ChunkedJobExecutionInterface` is a standalone role and does not extend `JobExecutionInterface`. It requires exactly:

- `generate_queue( array $start_args, RunContextInterface $context ): iterable`
- `process_chunk( array $chunk_args, ChunkedRunContextInterface $context ): void`

The effective `JobOptions::$max_runtime` ceiling, including its 21,600-second (6-hour) clamp, applies independently to one `generate_queue()` or `process_chunk()` invocation, not the whole run. The engine materializes the initial iterable before execution. Each chunk accepts at most 8,192 JSON bytes. The persisted queue accepts at most 983,616 serialization bytes: the 1,000,000-byte complete active-run row ceiling minus a 16,384-byte row-envelope reserve. A complete active-run row above 1,000,000 persisted serialization bytes is refused with `payload_rejected`. That check covers every write while the run is active, not admission alone, so a queue within its own ceiling can still be refused once the rest of the row is counted with it.

Queue mutations commit only after a normal `process_chunk()` return and are discarded when it throws. An executing-state process death terminally fails the run as `RunFailureStage::crash_reclamation()`, preserving the in-flight chunk in `RunFailure::$details['failed_chunk']`; `runs()->retry_failed()` starts a fresh run from the retained arguments and retained priority without resolving priority again. A retained entry without a priority field uses engine default 10. Automatic redelivery covers non-executing pending, scheduled-retry, and continuation states.

### Schedule

`schedules()->sync( Schedule ...$schedules )` receives the scope's complete declaration. Each `Schedule` combines a target job with a `Recurrence`, arguments, a `CatchUpPolicy`, and an advisory priority. Calling `sync()` without arguments removes every schedule declared by that scope.

Each schedule chain has two stages. The recurring backend row is the tick on `a8csp_bgje/internal/schedule_due`, and it performs admission only. Each admitted occurrence creates a separate delivery row on `a8csp_bgje/internal/deliver`, which runs the job. The tick carries the engine-owned priority 0, while the consumer's priority reaches the delivery row alone. This applies the consumer's priority once and keeps admission ahead of the work it admits. Action Scheduler honors these priorities; WP-Cron ignores them.

`Recurrence::every()` creates an unanchored fixed interval. `Recurrence::every_anchored()` creates an interval aligned to a non-negative UTC Unix-epoch phase. Both recurrence constructors require a positive interval in seconds. An unanchored schedule first runs one interval after synchronization. An anchored schedule first runs at the strictly future Unix timestamp whose phase matches `anchor mod interval`, then stays on that grid. The target work supplies overlap behavior for imperative and scheduled runs.

The procedural `a8csp_bgje_sync_schedules()` alias accepts the same `Schedule` values variadically.

## Migrating from Action Scheduler

Register work for each former action hook, then use a scope-bound handle or its aliases from `init` or later.

| Action Scheduler | Engine |
| --- | --- |
| `as_enqueue_async_action( $hook, $args, $group )` | `a8csp_bgje_dispatch_job( 'my-plugin', 'name', $args )` |
| `as_schedule_single_action( $ts, $hook, $args, $group )` | `a8csp_bgje_dispatch_job_at( 'my-plugin', 'name', $ts, $args )` — `$ts` remains an absolute Unix timestamp. |
| `as_schedule_recurring_action( $ts, $interval, $hook, $args, $group )` | Include `new Schedule( 'name', Recurrence::every_anchored( $interval, $ts ), 'name', $args )` in the complete declaration passed variadically to `a8csp_bgje_sync_schedules( 'my-plugin', ... )`. The anchor preserves the fixed UTC phase modulo the interval, not the exact first timestamp or site-local time. |
| `as_unschedule_action( $hook, $args, $group )` | Omit that schedule from the next complete declaration passed to `a8csp_bgje_sync_schedules()`. |
| `as_unschedule_all_actions( … )` | `a8csp_bgje_sync_schedules( 'my-plugin' )` removes every schedule this scope declares. |
| `as_next_scheduled_action( … )` | No public next-due query. Treat the declaration passed to a successful `a8csp_bgje_sync_schedules()` call as the source of truth. |
| `as_has_scheduled_action( … )` | No public pending/running boolean query. |

The key difference is partitioning: Action Scheduler's `$group` defaults to `''`, leaving work unpartitioned and easy to clear by accident. The engine requires the scope up front and composes it into every identity. Repeated `jobs()->dispatch()` calls reject matching live work under the default overlap policy.

## Idempotency invariant

Schedule-driven jobs and chunked job chunks MUST be idempotent. The overlap guard serializes concurrent runs rather than deduplicating an occurrence, so a job that finishes before its redelivery arrives runs twice for one occurrence under every overlap policy. Two paths redeliver a schedule occurrence: a delivery-state write that fails, and a process death between creating the delivery row and running its state-persistence callback. The process-death path is silent and unlabelled because nothing distinguishes its redelivery from a genuinely due occurrence.

`OverlapPolicy::Allow` gives each dispatch its own overlap lane, so it removes even the concurrent protection: the overlap guard cannot suppress a duplicate occurrence admission at all. A reclaimed run reviving after its stale lock is taken can also execute the same logical work more than once. Terminal lifecycle hooks (`completed`, `failed`, `cancelled`, `superseded`) share the same crash window between an external effect and its persisted completion marker — durable under Action Scheduler, best-effort under WP-Cron. The `started` hook is inline and non-durable, so a crash between admission and hook delivery can lose it.

## Admission, overlap, and catch-up policies

Each definition resolves one `OverlapPolicy` for imperative and scheduled admission. `JobOptions::$overlap_key`, when present, receives the start arguments and derives an opaque 1-to-64-byte collision identity; `null` uses the canonical argument hash. A resolver that throws or returns a value other than string or null surfaces as `execution_failed` instead of escaping; an empty or oversized string produces `payload_rejected`. Imperative dispatch rejects an `execution_failed` resolver result before creating a run. A recurring occurrence consumes that result as a terminal run, fires the `failed` hook, attempts retention, and logs the outcome. Failed-run retry returns `execution_failed` and keeps its source entry retained. Matching is confined to the scope-qualified identity. Failed-run retry preserves `Allow`; `Reject` and `Replace` retry with `Reject`. `overlap_held` and `admission_conflict` are different answers. `overlap_held` means a run owns the lane and is doing the work, so skipping is correct. `admission_conflict` means admission lost a race to a rival dispatch and admitted nothing. **Dispatch admits again on your behalf when that happens**, up to three attempts in total, because a lost attempt leaves the lane exactly as it found it and never reaches your handler; an attempt that follows also takes custody of replaying a supersession the losing attempt could not restore. Where dispatch re-admits on your behalf, `admission_conflict` therefore reaches you only when a lane stayed contended across every attempt, which makes it worth logging rather than retrying by hand. Re-admission is logged at debug level. Scheduled occurrence delivery admits once, because the scheduler redelivers a due occurrence on its own. Manual schedule dispatch also admits once, and nothing redelivers it, so that caller owns the retry; it also reports `admission_conflict` before any admission is attempted when another delivery holds that schedule's occurrence decision. Catch-up independently determines what happens when a scheduled delivery is late beyond its grace window.

| Overlap | `run_once` catch-up (default) | `skip` catch-up |
| --- | --- | --- |
| `Allow` | Dispatches one due or make-up run even while matching work runs. | Drops a beyond-grace occurrence; otherwise dispatches even while matching work runs. |
| `Reject` (default) | Attempts one due or make-up run, recorded as skipped while a fresh matching lock is held. | Drops a beyond-grace occurrence; otherwise dispatches only when no fresh matching lock is held. |
| `Replace` | Dispatches one due or make-up run; transfers a held matching lock to the new run. | Drops a beyond-grace occurrence; otherwise dispatches and transfers a held matching lock. |

An occurrence is a misfire only when observed strictly after `next_due + grace`; grace defaults to one interval and is filterable. `run_once` attempts one make-up occurrence and realigns; `skip` drops it, realigns, and emits the misfire-skipped hooks.

## Hooks and filters

Lifecycle reactions are hooks-only. Every event with an identity fires its identity-specific hook first and its generic hook second. Generic lifecycle hooks prepend the identity except `failed`, whose specific and generic variants receive the same self-identifying `RunFailure` object. Terminal hooks (`completed`, `failed`, `cancelled`, and `superseded`) are durable under Action Scheduler and best-effort under WP-Cron; `started` is inline and non-durable. An ordinary job's `started` hook fires on admission before backend delivery is scheduled, so a throwing listener fails the dispatch closed; a chunked job fires `started` after its generated queue is durably persisted and before continuation delivery is scheduled. Fail-closed depends on the terminal write landing: if storage cannot confirm it, the dispatch reports `storage_failed` rather than `execution_failed`, and that run may still be delivered once stale-state maintenance reaches it. Treat that code as "inspect before compensating".

| Event | Hooks and arguments |
| --- | --- |
| Started | `a8csp_bgje/started/{identity}`: `(RunId $run_id, array $start_args)` · `a8csp_bgje/started`: `(string $identity, RunId $run_id, array $start_args)` |
| Completed | `a8csp_bgje/completed/{identity}`: `(RunId $run_id, array $start_args, ?RunId $previous_completed_run_id)` · generic prepends `$identity` |
| Failed | `a8csp_bgje/failed/{identity}` · `a8csp_bgje/failed`: `(RunFailure $failure)` |
| Cancelled | `a8csp_bgje/cancelled/{identity}`: `(RunId $run_id, array $start_args)` · generic prepends `string $identity` |
| Superseded | `a8csp_bgje/superseded/{identity}`: `(RunId $run_id, array $start_args)` · generic prepends `string $identity` |
| Retry scheduled | `a8csp_bgje/retry_scheduled/{identity}`: `(RunId $run_id, array $start_args, int $attempt, int $delay)` · generic prepends `string $identity`; attempt is the one-indexed failed-attempt count and delay is the chosen delay in seconds |
| Misfire skipped | `a8csp_bgje/misfire_skipped/{schedule_identity}`: `(string $scope, int $due_at, int $observed_at)` · generic prepends `string $schedule_identity` |
| Log | `a8csp_bgje/log`: `(string $level, string $message, array $context)` |

A `started` or `retry_scheduled` listener that throws terminally fails the admitted run with `execution_failed`; when retention succeeds, the failed run is available for manual retry. Do not hook the engine's internal delivery actions.

Filters with an identity apply the generic hook first and the identity-specific hook second. Each result feeds the next hook, so the identity-specific return is authoritative. Filters without an identity remain global.

| Filter | Input and required return |
| --- | --- |
| `a8csp_bgje/retry_policy` · `a8csp_bgje/retry_policy/{identity}` | Generic: `(RetryPolicy $policy, string $identity)` · specific: `(RetryPolicy $policy)`; return a `RetryPolicy`. A foreign final return leaves the resolved definition/default policy in effect. |
| `a8csp_bgje/queue` · `a8csp_bgje/queue/{identity}` | Generic: `(array $queue, string $identity, array $start_args, string $run_id)` · specific: `(array $queue, array $start_args, string $run_id)`; return an array list containing the complete set of chunk argument arrays. |
| `a8csp_bgje/misfire_grace` · `a8csp_bgje/misfire_grace/{schedule_identity}` | Both: `(int $grace, string $scope, string $schedule_identity)`; return a non-negative grace in seconds, defaulting to one interval. |
| `a8csp_bgje/continue_delay` · `a8csp_bgje/continue_delay/{identity}` | Generic: `(int $delay, string $identity, string $run_id)` · specific: `(int $delay, string $run_id)`; return a non-negative delay in seconds, defaulting to 60. It also floors lock staleness at twice the delay. |
| `a8csp_bgje/lock_staleness` · `a8csp_bgje/lock_staleness/{identity}` | Generic: `(int $seconds, string $identity)` · specific: `(int $seconds)`; return a positive lock window, defaulting to 900 and at least twice the continue delay. Consulted when a lock is already held, to grade the run holding it; an uncontended dispatch has no incumbent to grade and does not apply it. |
| `a8csp_bgje/history_size` | `(int $size): int`; return a positive per-buffer history cap, defaulting to 30. |
| `a8csp_bgje/log_to_error_log` | `(bool $enabled): bool`; return whether the current event is written to the default PHP error-log sink, defaulting to `true`. Evaluated for every event. |
| `a8csp_bgje/error_log_level` | `(string $minimum_level): string`; return the least severe recognized PSR-3 level written to the default PHP error-log sink, defaulting to `warning`. An unrecognized return falls back to `warning`; this gates only the sink and never `a8csp_bgje/log`. |

## Bring your own PSR-3 logger

The engine publishes every log event to `a8csp_bgje/log` and writes warning-and-above events to PHP's error log by default; `notice`, `info`, and `debug` still reach hook listeners. To route every event to a `Psr\Log\LoggerInterface`, disable the default sink and attach a three-argument listener:

```php
use Psr\Log\LoggerInterface;

/** @var LoggerInterface $logger */
add_filter( 'a8csp_bgje/log_to_error_log', static fn ( bool $enabled ): bool => false );
add_action(
	'a8csp_bgje/log',
	static function ( string $level, string $message, array $context ) use ( $logger ): void {
		$logger->log( $level, $message, $context );
	},
	10,
	3
);
```

Raw throwable values held directly in context arrays never reach listeners at any depth: encountered values arrive as redacted `{class, code, file, trace_hash}` arrays, and over-deep array subtrees are replaced wholesale.

## Priority is advisory

Priority uses the range in [Consumer limits](#consumer-limits). The resolution ladder is: explicit dispatch argument > schedule value > job default > engine default 10. The first two rungs belong to imperative and scheduled admission respectively, so one resolution evaluates only its applicable rung. Manual `retry_failed()` is the exception: it replays the retained failed run's admitted priority without resolving the ladder again; a retained entry without a priority field uses engine default 10. Action Scheduler honors the resolved value; WP-Cron accepts and ignores it. Keeping the field in the common API permits transparent backend failover.

Priority is absent from the schedule fingerprint, so an omitted value and an explicit `10` hash identically. For an already-converged chain with exactly one tick, a priority-only declaration edit performs no scheduling-backend write and preserves the recurring chain, its next-due anchor, and its misfire and overlap counters. A successful sync also resets the undeclared-occurrence counters the engine keeps for registrations that earlier syncs stopped declaring. The recurring chain stores no priority; occurrence admission reads `Schedule::$priority` from the current request's declaration. Under the per-request declaration contract, the edited value governs the next admitted occurrence's delivery. The unchanged-fingerprint census still repairs a missing or duplicated chain, and a chain firing at a cadence the declaration no longer asks for: scheduling retains an existing chain rather than rewriting it, so a chain created against a superseded declaration would otherwise keep its own cadence while every fingerprint and count agreed.

## Consumer limits

This table covers engine-enforced identity, payload, scheduling-admission, storage, and retention ceilings that consumers can hit, plus filterable history retention. Constructor grammars and configurable policy budgets remain with their public model documentation. The values and behavior in this table are part of the [SemVer-bound consumer surface](#releasing). The owning symbols are implementation sources of truth, not additional public PHP API.

| Consumer contract | Limit | Source of truth | Filterable |
| --- | --- | --- | --- |
| Scope | 1–32 bytes matching `[a-z0-9][a-z0-9-]*`; the `a8csp-bgje` prefix is reserved for the engine. | `Boundary\Identity::SCOPE_MAX_BYTES` and `Boundary\Identity::ENGINE_SCOPE` | No |
| Job, Chunked Job, and Schedule local name | 1–64 bytes matching `[a-z0-9_-]+`. | `Boundary\Identity::NAME_MAX_BYTES` | No |
| Dispatched start arguments and declared schedule arguments | A portable tree of scalars, null, and arrays, at most 512 array levels and 8,192 encoded JSON bytes. `jobs()->dispatch()`, `jobs()->dispatch_at()`, or `schedules()->sync()` returns `WP_Error` with `payload_rejected` for byte overflow; `Schedule` construction still rejects a snapshot that is not portable or JSON-encodable. | `Boundary\PortableArguments::MAX_ARGUMENTS_JSON_DEPTH` and `Runtime\ScopeOperations::MAX_ARGUMENTS_BYTES` | No |
| Priority | 0–255 inclusive. Null exists only on input surfaces and defers resolution; a backend always receives a resolved integer. | `Runtime\Runs\Dispatcher::MAX_PRIORITY`; the minimum is the admission invariant `0`. | No |
| `max_runtime` crash-reclamation credit | A non-null declaration is at least 1 second; null selects 300 seconds; declarations above 21,600 seconds remain valid but clamp to 21,600 seconds. Actual reclamation also waits for the separately resolved lock-staleness window and a reconciliation or admission check. | `Runtime\ScopeOperations::register()` owns the literal minimum; `Runtime\Locks\LockWindows::DEFAULT_EXECUTION_LEASE` and `Runtime\Locks\LockWindows::MAX_EXECUTION_LEASE` own the default and clamp. | No. The credit itself is not filterable; the later staleness window is. `a8csp_bgje/lock_staleness` sets that window, and `a8csp_bgje/continue_delay` floors it at twice the delay, so either one moves the reclamation deadline. |
| Custom overlap key | 1–64 bytes when the resolver returns a non-null key. | `Runtime\Locks\OverlapIdentity::MAX_OVERLAP_KEY_BYTES` | No |
| Absolute `dispatch_at()` timestamp | At most 253,402,300,799. | `Runtime\Runs\Dispatcher::MAX_RUN_AT` | No |
| One chunk's arguments | A portable tree of scalars, null, and arrays, at most 512 array levels and 8,192 encoded JSON bytes. | `Boundary\PortableArguments::MAX_ARGUMENTS_JSON_DEPTH` and `Runtime\Runs\ChunkedRunContext::MAX_CHUNK_BYTES` | No |
| Complete chunked-job queue | At most 983,616 PHP-serialized bytes. | `Runtime\Runs\Stores\RunStore::MAX_KIND_STATE_BYTES` | No; only the initial generated queue contents are filterable through `a8csp_bgje/queue` and its identity-specific form. |
| Complete active-run row | At most 1,000,000 PHP-serialized bytes while active. | `Runtime\Runs\Stores\RunStore::MAX_ROW_BYTES` | No |
| Failed-run retained count | At most 20 entries per scope-qualified work identity. | `Runtime\Runs\Stores\FailedRunStore::ENTRY_LIMIT` | No |
| Complete failed-run retention row | At most 1,000,000 PHP-serialized bytes per scope-qualified work identity. | `Runtime\Runs\Stores\RunStore::MAX_ROW_BYTES`, enforced for this row by `Runtime\Runs\Stores\FailedRunStore::MAX_ROW_BYTES`, which derives from it | No |
| Run history | Default 30 entries in each history buffer; no hard maximum. | `Runtime\Runs\Stores\RunHistory::DEFAULT_SIZE` | Yes: `a8csp_bgje/history_size` accepts a positive integer. |

## Scale ceilings

The engine is designed for a handful of plugins with tens of jobs and schedules each. Its supported operating envelope is:

- **Schedules per scope:** low tens. Each scope's registrations live in one option row that every occurrence rewrites, so co-firing hundreds of schedules for one scope adds contention. For declarations whose fingerprints match, synchronization censuses every ready backend. Action Scheduler issues one identity-scoped occurrence query per declaration, while WP-Cron buckets the requested identities from one cron snapshot. The aggregate count lets the unchanged-declaration fast path distinguish exactly one tick from a missing or duplicated chain.
- **Chunked Job chunk count:** thousands is fine; the queue is capped at 983,616 persisted serialization bytes, but chunk *count* is not. Each chunk is measured once as it is generated and each context mutation measures only its own chunk, so the queue cost is linear in chunk count. Prefer fewer, larger chunks anyway: every chunk is a separate scheduled delivery, and that overhead dominates.
- **`history_size` filter:** the default 30 is generous; there is no hard maximum, so a very large value grows the per-identity history row.
- **Action Scheduler group rows:** the engine creates one AS group per scope-qualified Job or Chunked Job identity. Action Scheduler does not garbage-collect groups, so retired identities leave rows behind, but lifetime run count does not increase this table.

Action Scheduler exposes the site-global `action_scheduler_queue_runner_batch_size`, `action_scheduler_queue_runner_concurrent_batches`, and `action_scheduler_queue_runner_time_limit` queue-runner filters. The engine filters none of them: every Action Scheduler consumer, including WooCommerce, shares those settings, so changing them also changes third-party throughput. Queue-runner tuning belongs to the site; use Action Scheduler's [performance guidance](https://actionscheduler.org/perf/) and [high-volume reference plugin](https://github.com/woocommerce/action-scheduler-high-volume) as references.

## Testing consumer code

Do not redefine `a8csp_bgje()` or the `a8csp_bgje_*()` aliases; the engine declares them unconditionally, so a test redefinition fatals. Keep application code testable by placing engine capability calls behind an application-owned interface or callable and fake that boundary in unit tests. Exercise the public models and functions in WordPress integration tests. Types under `src/Boundary/` cross engine-layer boundaries and remain internal engine seams, not consumer injection contracts.

## Multisite

Network activation is supported; each site runs its own isolated engine state, bound to the request site when the engine graph is built. A storage operation after `switch_to_blog()` throws instead of writing through a graph built for another site — so enter each site through a fresh request or execution context and operate there. Network uninstall applies the same per-site option-retention policy and unschedules WP-Cron events on every site; when a site's `actionscheduler_actions` table and initialized public API are available, it marks pending engine actions as canceled without requiring the other three Action Scheduler tables.

## Uninstall

Uninstall removes operational engine options and unschedules WP-Cron events per site, but preserves `a8csp_bgje_failed_runs_*` and `a8csp_bgje_run_history_*` option rows by default for post-uninstall diagnosis. When the `actionscheduler_actions` table and initialized public API are available, Action Scheduler marks pending actions for the engine's delivery hooks as canceled rather than deleting them. In-progress, complete, failed, already-canceled, and newly canceled action rows survive, as do all `actionscheduler_logs` rows and orphaned `actionscheduler_claims` and `actionscheduler_groups` rows. Define `A8CSP_BGJE_REMOVE_DIAGNOSTICS_ON_UNINSTALL` to the literal boolean `true` before deleting the plugin to remove those diagnostic rows too; an undefined constant or any other value preserves them, and `wp a8csp-bgje reset` is deliberately exempt and still deletes every engine runtime option row, including both diagnostic families.

## WP-CLI

The command root is `wp a8csp-bgje`, exposing four action-taking subcommands — `schedules`, `runs`, `failed-runs`, `locks` — plus the leaf subcommand `reset`.

| Operation | Synopsis |
| --- | --- |
| List failed runs | `wp a8csp-bgje failed-runs list [--scope=<scope>] [--format=<format>]` |
| Retry a failed run | `wp a8csp-bgje failed-runs retry <identity> <run_id>` |
| Purge failed runs for one identity | `wp a8csp-bgje failed-runs purge <identity>` |
| Purge every failed-run store | `wp a8csp-bgje failed-runs purge --all` |
| Cancel a retained run | `wp a8csp-bgje runs cancel <identity> <run_id>` |
| List runs and recent history | `wp a8csp-bgje runs list <identity> [--format=<format>]` |
| List execution-overlap locks | `wp a8csp-bgje locks list [--format=<format>]` |
| List schedules | `wp a8csp-bgje schedules list [--scope=<scope>] [--format=<format>]` |
| Remove every schedule in one scope | `wp a8csp-bgje schedules remove <scope> [--yes]` |
| Destroy all engine state (development reset) | `wp a8csp-bgje reset [--yes]` |

Every `<identity>` is a composed `{scope}:{name}`; PHP calls take the scope-local name while the CLI takes the full identity. Every list subcommand accepts `table`, `csv`, `json`, `count`, or `yaml` (default `table`). `runs list` includes recent history only in `table`, `json`, and `yaml`, and its `count` is the bounded live count. `reset` permanently deletes every engine runtime option row and pending backend action, including the maintenance registration the next boot recreates; it prompts unless `--yes`. It leaves the release updater's cached lookup alone, which belongs to the update mechanism rather than to background work and expires on its own. `schedules remove` converges a scope's schedules to empty without cancelling existing runs and errors on a scope with no persisted registry row.

## Releasing

The canonical SemVer contract is tiered:

| Tier | Contract |
| --- | --- |
| PHP API | The types in the [public type index](#public-models-roles-and-contexts), `a8csp_bgje()`, and the verb-noun procedural aliases form the bound PHP surface. An incompatible change to an existing name, signature, or documented behavior is breaking, subject to an explicitly documented additive exception such as `ChunkedRunContextInterface`. |
| Hooks and filters | The documented consumer actions and filters form the bound event surface. Minor releases may add hooks and filters. Changing an existing hook's arguments, or a filter's required return, is breaking. |
| Enums | Public enum cases form an additive vocabulary. Minor releases may add enum cases. Consumers must treat an unknown enum case as a generic value rather than assume the listed cases are exhaustive: a generic failure for `ErrorCode`, and a generic terminal or non-terminal state, as appropriate, for `RunStatus`. |
| Consumer limits | The values and behaviors in [Consumer limits](#consumer-limits) form the bound limit surface. An incompatible change is breaking. |

Everything else is internal unless this README explicitly documents it as public.

Releases are cut by pushing a version tag. The release workflow fails closed unless the plugin header, `package.json`, and the newest `CHANGELOG.md` entry all agree with the tag, then builds and smoke-tests the distribution ZIP; prereleases publish outside the stable update channel. Publication also requires green trunk-push Quality and Tests runs at the exact tagged commit.

`CHANGELOG.md` is generated from the fragments in `changelog/` by `composer changelog:write`. The first release and each prerelease entry pass their version explicitly:

```sh
composer changelog:write -- --use-version=1.0.0-beta.2
```
