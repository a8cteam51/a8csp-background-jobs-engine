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

A **Job** is one named unit of background work. A `JobDefinition` composes its name, kind, execution object, and policy declaration. Standard execution objects implement `JobExecutionInterface`; Chunked Job execution objects implement the standalone `ChunkedJobExecutionInterface` role to split work into independently processed chunks. `JobDefinition::closure()` provides a closure-backed standard Job with engine-default policy. A **Schedule** dispatches registered work on a fixed recurrence. Every piece of work belongs to a **scope** (your plugin slug); the engine composes `{scope}:{name}` into one identity so plugins using distinct scopes do not collide. A scope is a single-writer partition: exactly one plugin declares the schedules for a given scope, and a sync call for that scope is authoritative over every registration inside it.

Consumers use four connected surfaces:

- **The Engine handle** — `a8csp_bgje( $scope )` returns a scope-bound `Engine` with `jobs()`, `schedules()`, and `runs()` portals to capability managers.
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

Action Scheduler is optional and preferred when ready; when absent, the engine runs on WP-Cron alone.

## When to call the engine

`a8csp_bgje( $scope )` constructs a handle lazily and infallibly. Capability-manager verb calls belong on the WordPress `init` hook or later. Invalid scopes and unavailable engine state return `WP_Error` from the first verb instead of failing handle construction. The request that activates the engine stays dormant until the next request.

Register work and synchronize schedules from `init` **on every request**: registration is per-request, and schedule synchronization treats the supplied schedules as the scope's complete declaration. Prefer `init` priority `2` or later so Action Scheduler's `init:1` store initialization has run; synchronizing before it routes that request's occurrences to WP-Cron.

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

An unanchored schedule first runs one interval after synchronization. An anchored schedule uses the first strictly future point on its UTC phase grid. There is no first-run timestamp field. Omitting a schedule from the next complete declaration removes it; synchronizing with no schedules removes all schedules for the scope without cancelling admitted runs. If the plugin is inactive, `wp a8csp-bgje schedules remove my-plugin --yes` performs the same schedule convergence.

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
		if ( ErrorCode::OverlapHeld->value === $run->get_error_code() ) {
			return;
		}

		error_log( $run->get_error_message() );
		return;
	}

	update_user_meta( $user_id, 'my_plugin_last_digest_run', (string) $run->id );
}
```

`JobDefinition::closure()` deliberately accepts no options and uses every engine default. Use a `JobExecutionInterface` object with `JobDefinition::job()` when the work needs explicit `JobOptions`. `a8csp_bgje_dispatch_job()` dispatches immediately; `a8csp_bgje_dispatch_job_at()` accepts an absolute Unix timestamp. A successful return proves admission, not completion. Keep arguments small and portable: pass identifying keys rather than bulk data.

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
```

Chunks run one at a time with a short pause between them. `ChunkedRunContextInterface` also exposes `prepend_chunk()`, `get_run_id()`, and `get_start_args()`. A failed chunked job starts a fresh run from its original arguments through `runs()->retry_failed()`.

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

	// Cancelled: an operator or scope code withdrew the run before execution.
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

The identity-specific `a8csp_bgje/failed/{identity}` hook fires before the generic `a8csp_bgje/failed` hook; both receive the same `RunFailure` object. Crash-recovery replay can reconstruct an equivalent value. Throw `NonRetryableException` from `handle()`, `generate_queue()`, or `process_chunk()` to fail permanently without consuming the remaining automatic attempts.

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

The engine retains up to 20 failed runs per scope-qualified identity for manual retry and evicts the oldest entry past that limit. A retry that successfully starts a fresh run attempts to remove its retained source entry; a failed removal is logged. Retention is best-effort: a retention write failure is logged rather than made fatal.

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

This table is the canonical public PHP type index. Every listed type is marked `@api` and is part of the SemVer-bound public ABI; any other autoloadable engine type is internal unless this README explicitly documents it as public.

| Type | Public shape |
| --- | --- |
| `Engine` | Scope-bound readonly handle returned by `a8csp_bgje()` with `jobs()`, `schedules()`, and `runs()` portals. |
| `Jobs` | Scope-bound readonly manager for registration, immediate dispatch, and absolute-time dispatch. |
| `Schedules` | Scope-bound readonly manager for schedule synchronization and immediate dispatch. |
| `Runs` | Scope-bound readonly manager for run inspection, retry, and cancellation. |
| `JobDefinition` | Final readonly registration declaration with public `string $name`, `JobKind $kind`, `KindExecutionInterface $execution`, and `JobOptions $options`. Its non-public constructor is exposed through `job()`, `chunked_job()`, `closure()`, and `for_kind()`. The closure constructor always applies engine-default policy. |
| `JobKind` | Final readonly kind key with public `string $value`, built-in `job()` and `chunked_job()` constructors, and `from( string $value )` for a grammar-valid key. It carries no execution contract. |
| `JobOptions` | Final readonly policy declaration constructed with optional named parameters `?int $max_runtime`, `?RetryPolicy $retry`, `?OverlapPolicy $overlap`, and `?\Closure $overlap_key`; each null selects the engine default. `max_runtime` accepts positive seconds, and declarations above 21,600 seconds (6 hours) remain valid while effective execution credit is clamped to that ceiling. |
| `KindExecutionInterface` | Empty marker shared by the standard and chunked execution roles so a kind-agnostic declaration can require execution membership while registration resolves the kind-specific role. |
| `JobExecutionInterface` | Standard execution role extending `KindExecutionInterface` and requiring only `handle( array $start_args, RunContextInterface $context ): void`. |
| `ChunkedJobExecutionInterface` | Standalone chunked execution role extending `KindExecutionInterface` and requiring only `generate_queue( array $start_args, RunContextInterface $context ): iterable` and `process_chunk( array $chunk_args, ChunkedRunContextInterface $context ): void`; it does not extend `JobExecutionInterface`. |
| `Schedule` | Readonly schedule declaration constructed from `name`, `recurrence`, target `job`, `args`, `catch_up`, and `priority`; only `name`, `recurrence`, and `job` are required, and the rest default to an empty array, `CatchUpPolicy::RunOnce`, and null. |
| `Recurrence` | Readonly fixed-interval recurrence created with `every( int $seconds )` or `every_anchored( int $seconds, int $anchor )`; an anchor is reduced modulo the interval. |
| `Run` | Readonly snapshot with `string $identity`, `RunId $id`, and `RunStatus $status`. |
| `RunId` | Final readonly stringable wrapper for a canonical run identifier; `from( string )` requires canonical input, `tryFrom( string )` returns null for another shape, and string casting returns the wire value. |
| `RunFailure` | Readonly value with `string $identity`, `RunId $run_id`, `int $attempts`, `RunFailureStage $stage`, `ErrorCode $code`, `string $summary`, and generic diagnostic payload `?array $details`. |
| `RetryPolicy` | Readonly value constructed from `max_attempts`, `base_delay`, `multiplier`, and `max_delay`; defaults are 3, `MINUTE_IN_SECONDS`, 2, and `HOUR_IN_SECONDS`. `max_attempts` includes the initial attempt. Attempts, base delay, and multiplier are at least 1, and maximum delay is at least the base delay. It exposes `delay_ceiling_for_attempt( int $attempt ): int`. |
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
| `ErrorCode` | `InvalidArgument = 'invalid_argument'`, `AlreadyRegistered = 'already_registered'`, `EngineUnavailable = 'engine_unavailable'`, `UnknownJob = 'unknown_job'`, `UnknownSchedule = 'unknown_schedule'`, `OverlapHeld = 'overlap_held'`, `PayloadRejected = 'payload_rejected'`, `BackendUnavailable = 'backend_unavailable'`, `BackendRejected = 'backend_rejected'`, `StorageFailed = 'storage_failed'`, `RunNotRetained = 'run_not_retained'`, `RunNotCancellable = 'run_not_cancellable'`, `UnsupportedOperation = 'unsupported_operation'`, `ExecutionFailed = 'execution_failed'` |

Minor releases may add cases; consumers treat unknown backing values as generic failures for `ErrorCode` and as generic non-terminal or terminal states, as appropriate, for `RunStatus`.

`RunFailureStage` is a final, interned, open string-backed value. `from( string )` wraps a lowercase snake key with at most one dot qualifier and throws `\ValueError` for malformed input; `tryFrom( string )` returns null instead. Engine stages are available through `execution()`, `queue_generation()`, `crash_reclamation()`, and `scheduling()`. Grammar-valid third-party stages such as `acme.export_sync` remain intact.

`JobKind::from()`, `RunId::from()`, and `RunFailureStage::from()` throw `\ValueError` for malformed strings. `\ValueError` extends `\Error`, not `\Exception`; use the corresponding `tryFrom()` parser when malformed consumer input should return null.

## Authoring work

### Definitions and kinds

Every registration supplies one immutable `JobDefinition`. The typed constructors are:

- `JobDefinition::job( string $name, JobExecutionInterface $execution, ?JobOptions $options = null )`
- `JobDefinition::chunked_job( string $name, ChunkedJobExecutionInterface $execution, ?JobOptions $options = null )`
- `JobDefinition::closure( string $name, \Closure $handler )`
- `JobDefinition::for_kind( string $name, JobKind $kind, KindExecutionInterface $execution, ?JobOptions $options = null )`

The `job()` and `chunked_job()` constructors bind the built-in kind to its typed execution role. The closure handler receives `(array $start_args, RunContextInterface $context)` and always uses engine defaults; the adapter that implements its execution role is internal. `for_kind()` is the generic registration-data primitive. Only engine-installed kinds register successfully, and consumer code cannot install or implement kind handlers.

`JobKind::job()` and `JobKind::chunked_job()` return the installed built-in keys. `JobKind::from()` wraps a grammar-valid key matching `[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?`; it does not prove that a handler is installed. Passing an object that does not implement `KindExecutionInterface` to `for_kind()` fails definition construction with `\TypeError`. Registration of an uninstalled kind returns `WP_Error` with `invalid_argument` and names the kind. A marker-implementing object of the wrong role reaches registration, where the resolved handler returns `WP_Error` with `invalid_argument` and names both the kind and its expected execution interface.

### Job execution and policy

`JobExecutionInterface` requires exactly `handle( array $start_args, RunContextInterface $context ): void`. The definition supplies the name and policy, so the execution role carries no naming, policy, or lifecycle-reaction methods. A normal return succeeds. A throwable fails the attempt and follows the retry policy, except `NonRetryableException`, which fails permanently.

`JobOptions` carries four independent optional policies: `max_runtime`, `retry`, `overlap`, and `overlap_key`. Null selects the engine default for that field. When set, `max_runtime` must be a positive number of seconds. Declarations above 21,600 seconds (6 hours) are accepted and clamped to that effective execution-credit ceiling rather than rejected. The defaults are a 300-second execution-invocation ceiling, a `RetryPolicy` with 3 maximum attempts, a 60-second base delay, multiplier 2, and 3,600-second maximum delay, `OverlapPolicy::Reject`, and a null overlap-key resolver. A null resolver uses the canonical argument hash. The `a8csp_bgje/retry_policy` filter receives the resolved policy before `a8csp_bgje/retry_policy/{identity}` applies the work-specific result.

A handler that exceeds its credited window becomes eligible for crash reclamation, and a reclaimed run can overlap its replacement, so handlers remain idempotent.

### Chunked Job and chunked run context

`ChunkedJobExecutionInterface` is a standalone role and does not extend `JobExecutionInterface`. It requires exactly:

- `generate_queue( array $start_args, RunContextInterface $context ): iterable`
- `process_chunk( array $chunk_args, ChunkedRunContextInterface $context ): void`

The effective `JobOptions::$max_runtime` ceiling, including its 21,600-second (6-hour) clamp, applies independently to one `generate_queue()` or `process_chunk()` invocation, not the whole run. The engine materializes the initial iterable before execution; the complete queue is capped at 1,048,576 bytes and each chunk at 8,192 bytes.

Queue mutations commit only after a normal `process_chunk()` return and are discarded when it throws. An executing-state process death terminally fails the run as `RunFailureStage::crash_reclamation()`, preserving the in-flight chunk in `RunFailure::$details['failed_chunk']`; `runs()->retry_failed()` starts a fresh run from the original arguments. Automatic redelivery covers non-executing pending, scheduled-retry, and continuation states.

### Schedule

`schedules()->sync( Schedule ...$schedules )` receives the scope's complete declaration. Each `Schedule` combines a target job with a `Recurrence`, arguments, a `CatchUpPolicy`, and an advisory priority. Calling `sync()` without arguments removes every schedule declared by that scope.

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

Schedule-driven jobs and chunked job chunks MUST be idempotent. The overlap guard reduces double-fire to the crash-and-reclaim residual; it cannot eliminate it. Backend redelivery, and a reclaimed run reviving after its stale lock is taken, can execute the same logical occurrence more than once. Terminal lifecycle hooks (`completed`, `failed`, `cancelled`, `superseded`) share the same at-least-once crash window between an external effect and its persisted completion marker — durable under Action Scheduler, best-effort under WP-Cron. The `started` hook is inline and non-durable, so a crash between admission and hook delivery can lose it.

## Admission, overlap, and catch-up policies

Each definition resolves one `OverlapPolicy` for imperative and scheduled admission. `JobOptions::$overlap_key`, when present, receives the start arguments and derives an opaque 1-to-64-byte collision identity; `null` uses the canonical argument hash. A resolver that throws or returns a non-string fails the run through the ordinary failure path (`failed` hook, retention, and log) instead of escaping, and surfaces as `execution_failed` at the imperative boundary. Matching is confined to the scope-qualified identity. Failed-run retry preserves `Allow`; `Reject` and `Replace` retry with `Reject`. Catch-up independently determines what happens when a scheduled delivery is late beyond its grace window.

| Overlap | `run_once` catch-up (default) | `skip` catch-up |
| --- | --- | --- |
| `Allow` | Dispatches one due or make-up run even while matching work runs. | Drops a beyond-grace occurrence; otherwise dispatches even while matching work runs. |
| `Reject` (default) | Attempts one due or make-up run, recorded as skipped while a fresh matching lock is held. | Drops a beyond-grace occurrence; otherwise dispatches only when no fresh matching lock is held. |
| `Replace` | Dispatches one due or make-up run; transfers a held matching lock to the new run. | Drops a beyond-grace occurrence; otherwise dispatches and transfers a held matching lock. |

An occurrence is a misfire only when observed strictly after `next_due + grace`; grace defaults to one interval and is filterable. `run_once` attempts one make-up occurrence and realigns; `skip` drops it, realigns, and emits the misfire-skipped hooks.

## Hooks and filters

Lifecycle reactions are hooks-only. Every event with an identity fires its identity-specific hook first and its generic hook second. Generic lifecycle hooks prepend the identity except `failed`, whose specific and generic variants receive the same self-identifying `RunFailure` object. Terminal hooks (`completed`, `failed`, `cancelled`, and `superseded`) are durable under Action Scheduler and best-effort under WP-Cron; `started` is inline and non-durable. An ordinary job's `started` hook fires on admission before backend delivery is scheduled, so a throwing listener fails the dispatch closed; a chunked job fires `started` after its generated queue is durably persisted and before continuation delivery is scheduled.

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
| `a8csp_bgje/lock_staleness` · `a8csp_bgje/lock_staleness/{identity}` | Generic: `(int $seconds, string $identity)` · specific: `(int $seconds)`; return a positive lock window, defaulting to 900 and at least twice the continue delay. |
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

**current beta behavior**

Priority is an integer from 0 through 255. The implemented precedence is: explicit dispatch argument > schedule value > engine default 10. Action Scheduler honors the resolved value; WP-Cron accepts and ignores it. Keeping the field in the common API permits transparent backend failover.

An omitted schedule priority is stored as `null`. It currently resolves to the same backend value as an explicit `10`, but the declarations have different fingerprints. Changing an existing declaration from omitted priority to explicit `10` therefore unschedules and recreates its backend occurrence.

**reserved precedence contract**

A reserved precedence contract is not implemented on this beta tree: specific priority filter > generic priority filter > explicit dispatch argument > schedule value > kind default > engine default 10. The two filter rungs and the kind-default rung do not exist yet.

## Scale ceilings

The engine is designed for a handful of plugins with tens of jobs and schedules each. Stay within these ranges for beta; each has a documented path to raise later:

- **Schedules per scope:** low tens. Each scope's registrations live in one option row that every occurrence rewrites, so co-firing hundreds of schedules for one scope adds contention. Schedule synchronization reads the backend occurrence census in one bulk query per sync; eliminating that census entirely for an unchanged declaration is planned for a later minor.
- **Chunked Job chunk count:** thousands is fine; the queue is byte-capped (1 MiB) but chunk *count* is not, and admission serializes the growing queue, so tens of thousands of tiny chunks is expensive. Prefer fewer, larger chunks or paginate a parent chunked job.
- **`history_size` filter:** the default 30 is generous; there is no hard maximum, so a very large value grows the per-identity history row.
- **Action Scheduler group rows:** the engine creates one AS group per run to enable per-run cancellation cleanup, and Action Scheduler does not garbage-collect groups. At millions of lifetime runs this table grows; plan periodic housekeeping for very high-volume, long-lived installs.

## Testing consumer code

Do not redefine `a8csp_bgje()` or the `a8csp_bgje_*()` aliases; the engine declares them unconditionally, so a test redefinition fatals. Keep application code testable by placing engine capability calls behind an application-owned interface or callable and fake that boundary in unit tests. Exercise the public models and functions in WordPress integration tests. Types under `src/Boundary/` cross engine-layer boundaries and remain internal engine seams, not consumer injection contracts.

## Multisite

Network activation is supported; each site runs its own isolated engine state, bound to the request site when the engine graph is built. A storage operation after `switch_to_blog()` throws instead of writing through a graph built for another site — so enter each site through a fresh request or execution context and operate there. Network uninstall applies the same per-site option-retention policy and unschedules WP-Cron events on every site; when a site's `actionscheduler_actions` table and initialized public API are available, it marks pending engine actions as canceled without requiring the other three Action Scheduler tables.

## Uninstall

Uninstall removes operational engine options and unschedules WP-Cron events per site, but preserves `a8csp_bgje_failed_runs_*` and `a8csp_bgje_run_history_*` option rows by default for post-uninstall diagnosis. When the `actionscheduler_actions` table and initialized public API are available, Action Scheduler marks pending actions for the engine's delivery hooks as canceled rather than deleting them. In-progress, complete, failed, already-canceled, and newly canceled action rows survive, as do all `actionscheduler_logs` rows and orphaned `actionscheduler_claims` and `actionscheduler_groups` rows. Define `A8CSP_BGJE_REMOVE_DIAGNOSTICS_ON_UNINSTALL` to the literal boolean `true` before deleting the plugin to remove those diagnostic rows too; an undefined constant or any other value preserves them, and `wp a8csp-bgje reset` is deliberately exempt and still deletes every engine option row, including both diagnostic families.

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
| List execution-overlap locks | `wp a8csp-bgje locks list [--format=<table\|json\|csv\|yaml>]` |
| Repair a malformed execution-overlap lock | `wp a8csp-bgje locks repair <identity> [--args-hash=<hash>] [--yes]` |
| List schedules | `wp a8csp-bgje schedules list [--scope=<scope>] [--format=<format>]` |
| Remove every schedule in one scope | `wp a8csp-bgje schedules remove <scope> [--yes]` |
| Destroy all engine state (development reset) | `wp a8csp-bgje reset [--yes]` |

Every `<identity>` is a composed `{scope}:{name}`; PHP calls take the scope-local name while the CLI takes the full identity. `failed-runs list`, `runs list`, and `schedules list` accept `table`, `csv`, `json`, `count`, or `yaml`; `locks list` accepts `table`, `json`, `csv`, or `yaml` (default `table`). `runs list` includes recent history only in `table`, `json`, and `yaml`, and its `count` is the bounded live count. Maintenance preserves malformed lock rows and logs redacted correlation for review; `locks repair` fences matching Running rows before exact-deleting the reviewed malformed generation and prompts unless `--yes`. `reset` permanently deletes every engine option row and pending backend action, including the maintenance registration the next boot recreates; it prompts unless `--yes`. `schedules remove` converges a scope's schedules to empty without cancelling existing runs and errors on a scope with no persisted registry row.

## Releasing

Releases are cut by pushing a version tag. The release workflow fails closed unless the plugin header, `package.json`, and the newest `CHANGELOG.md` entry all agree with the tag, then builds and smoke-tests the distribution ZIP; prereleases publish outside the stable update channel. Publication also requires green trunk-push Quality and Tests runs at the exact tagged commit.

`CHANGELOG.md` is generated from the fragments in `changelog/` by `composer changelog:write`. The first release and each prerelease entry pass their version explicitly:

```sh
composer changelog:write -- --use-version=1.0.0-beta.1
```
