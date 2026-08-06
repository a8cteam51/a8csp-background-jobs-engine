# Architecture

This maps the plugin's major components, boot sequence, and load-bearing machinery.

## The component model

The plugin is a list of components; a component is a class with a static `should_load()` gate, an
`initialize()` readiness phase, and a `register_hooks()` attachment phase; the boot is a few
foreach loops you can read — gate and construct, initialize all, then register all hooks, so
every surviving component is initialized before any hook can fire.

## The map

- `a8csp-background-jobs-engine.php` defines the plugin header and constants, requires
  `functions-bootstrap.php`, and wires the self-updater, the requirements gate, and the
  `plugins_loaded` boot. A request that activates the engine stays dormant until
  the next request.
- `functions-bootstrap.php` provides the GitHub release updater, plugin metadata,
  version compatibility checks, the requirements gate, and its admin-notice reporter; both root
  bootstrap files stay parsable below the plugin's PHP floor, and CI lints them against the older
  PHP versions.
- `functions.php` provides the scope-bound front door `a8csp_bgje( string $scope ): Engine`, the
  composition-root accessor `a8csp_bgje_plugin(): Plugin`, and a deterministic loader for the
  procedural facade files. Handle and manager construction is lazy. The runtime graph is assembled
  and published during the `plugins_loaded` boot; `init` is the supported consumer invocation
  boundary.
- `includes/` groups the procedural facade by concept: `job.php` provides background-work
  registration plus kind-agnostic immediate and absolute-time dispatch, `schedule.php` provides
  schedule synchronization and dispatch, and `run.php` provides run inspection, retry, and
  cancellation.
- `portals/` holds the public `Engine`, `Jobs`, `Schedules`, and `Runs` services; `src/` root holds
  the bootstrapping mechanism: `src/ComponentInterface.php` is the one contract,
  `src/ComponentCollection.php` the shared gated collection, `src/AbstractComponent.php` the
  optional defaults-only base, and `src/Plugin.php` the internal composition root — the one file to
  edit when wiring a top-level component into `COMPONENTS`. The main bootstrap registers the
  request-local `Plugin` instance's `boot()` method; components boot in registration order behind a
  non-retryable latch.
- `models/` holds the representation layer in the editorial `Error/`, `Job/`, `Run/`, and
  `Schedule/` subdirectories; every model type declares the
  `A8C\SpecialProjects\BackgroundJobsEngine` root namespace. `JobDefinition` composes a name,
  `JobKind`, a `KindExecutionInterface` execution object, and `JobOptions`; standard and chunked
  behavior implement `JobExecutionInterface` and `ChunkedJobExecutionInterface`, which share that
  marker but no member. Execution callbacks depend on `RunContextInterface` or
  `ChunkedRunContextInterface`; `RunContext` is the final standard implementation. `Schedule`,
  `Recurrence`, and `CatchUpPolicy` form the typed schedule declaration consumed by the public
  `Schedules` service.
- The README's [Releasing](../README.md#releasing) section is the canonical SemVer contract for the
  consumer surface; this document does not redefine it.
  `src/Boundary/` contains engine-owned values that cross layer boundaries; the rest of the engine
  graph is likewise `@internal`.
- `src/Runtime/` is the engine capability tree: `Component.php` assembles and publishes the
  request-local object graph, while `ScopeOperations.php` exposes its scope-bound verb surface to
  the public portals; `Runs/Dispatcher.php`, `Schedules/ScheduleOperations.php`, `Inspection.php`,
  and `JobRegistry.php` are the root collaborators. `JobRegistry.php` keys registrations by full
  identity and retains each complete `JobDefinition`. The single kind-handler registry resolves a
  definition's kind; the resolved internal handler validates its execution role and owns invocation.
  Only engine-installed kinds are accepted, and the handler SPI is internal.
  `Backends/` (Action Scheduler preferred, WP-Cron fallback), `Schedules/`
  (schedule registry, sync orchestration, occurrence delivery, leases, and cleanup convergence),
  `Locks/` (CAS-fenced execution-overlap storage, the single overlap-identity authority that admission,
  retry, and inspection all resolve through, persisted-lane inspection, and exact malformed-orphan
  reclamation), `Runs/` (admission, delivery claiming, kind handlers, the run and failed-run stores, and
  the terminal transitions and effects that follow them), `Storage/` (option-row stores with CAS
  fencing), `Maintenance/` (bounded sweeps on an hourly recurrence), `Logging/`, and `Error/` each
  own one sub-capability.
- `src/Runtime/Runs/` is the largest subtree, and one run's fate crosses five of its files in a fixed
  order: `Dispatcher.php` admits work and resolves overlap, `RunTransitions.php` owns every terminal
  compare-and-swap, `FailureLifecycle.php` classifies a failure and chooses retry or terminalization,
  `LifecycleEffects.php` publishes the hooks and history a terminal state owes, and
  `RunReconciliation.php` repairs runs whose delivery or liveness stopped. Admission, delivery,
  terminalization, and effects are separate because each is a distinct compare-and-swap that can lose
  to a rival independently.
- `src/CLI/` registers the `wp a8csp-bgje` command surface, including redacted persisted-lock
  inspection gated on WP-CLI.
- `languages/` contains the POT generated from the plugin's strings; the release workflow
  regenerates it so archives always ship current strings.
- `uninstall.php` loads root `footprint.php`, whose pure-data manifest records option prefixes,
  fixed transient keys, and delivery hooks. Runtime-suffixed option names cannot be enumerated as
  fixed keys, so the `a8csp_bgje_` prefix sweep is the complete ownership boundary. Per site,
  uninstall preserves failed-run and run-history diagnostics by default, deletes other eligible
  options, unschedules WP-Cron events, and marks pending Action Scheduler actions as canceled when
  its actions table and initialized public API are available. The
  `A8CSP_BGJE_REMOVE_DIAGNOSTICS_ON_UNINSTALL` constant includes diagnostics in the sweep only when
  its value is literal `true`; network uninstall applies the policy in bounded site batches.
- `tests/` contains the automated test suite; see `tests/README.md` for the suite matrix and
  local workflow.
- `.github/workflows/` includes quality, test, audit, CodeQL, workflow-checks, mutation, and
  release automation; the release pipeline builds, smoke-tests the artifact through the shared
  reusable workflow, and publishes prereleases off the stable update channel.

## Runtime directory admission

The map's one-sub-capability ownership is the admission criterion for the directories directly
beneath `src/Runtime/`. Each names the capability it owns; generic catch-alls — support, utilities,
helpers, registries, and contracts — are not admitted, because they carry no admission test and
accumulate whatever has no other home. A seam or adapter that is not itself a sub-capability sits at
the root of `src/Runtime/` rather than in a bucket of its own, as the clock and randomization
adapters do. `Error/` holds internal failure values, reason vocabularies, and the mapper; excludes
exceptions and public error-code models.

## Naming conventions

The dispatch timing axis uses `_at` names for absolute Unix timestamps, including `$run_at` and
`$fire_at`; the backend boundary mirrors Action Scheduler's `timestamp` and `first_run_timestamp`
vocabulary. Duration names including `Recurrence::every()`, `max_runtime`, `base_delay`, and
`max_delay` remain unsuffixed.

A scope is the consumer slug, a name is local to that scope, and their canonical identity is
composed as `{scope}:{name}`.

Every interface ends in `Interface`; every abstract class begins with `Abstract`.

## Delivery and degradation

The engine writes through the first ready backend in preference order and reads or clears across
every ready backend. Action Scheduler becomes ready when its procedural API is available and
`action_scheduler_init` has fired; writes before that point route to WP-Cron. Terminal
lifecycle-hook delivery is at-least-once under Action Scheduler and best-effort under WP-Cron.

A schedule chain is a recurring tick on `a8csp_bgje/internal/schedule_due`. The tick performs
admission and carries the engine-owned priority 0. Each admitted occurrence creates a separate
`a8csp_bgje/internal/deliver` row that runs the job, and the consumer's priority reaches that row
alone. Action Scheduler honors these priorities; WP-Cron ignores them.

Schedule synchronization is authoritative for one scope's complete declaration. A declaration
whose fingerprint matches its registration enters the occurrence census. Priority is outside the
fingerprint, so a priority-only declaration edit preserves an already-converged tick. The recurring
chain stores no priority; occurrence admission reads the current request's declaration. Exactly
one tick is the unchanged fast path, a missing tick is recreated, and duplicated ticks are cleared
and recreated. Action Scheduler obtains the count with one identity-scoped query per matching
declaration and one hydration per occurrence that query matched; WP-Cron buckets the requested
identities from one cron snapshot.

Active-run rows admit at most 1,000,000 persisted serialization bytes. Kind-owned state, including
a chunk queue, receives 983,616 bytes after the 16,384-byte row-envelope reserve; terminal rows are
exempt from both byte ceilings so a byte-budget rejection does not wedge their overlap locks. An
oversized complete row returns a payload rejection at admission. An uncaught chunk-context
portability or size rejection counts the failing invocation once, terminalizes the run, and leaves
the remaining retry allowance unused.

Overlap locks, occurrence leases, and run generations use option-row compare-and-swap fences for
concurrency control. The overlap guard serializes concurrent runs rather than deduplicating an
occurrence. A delivery-state write failure, or a process death between creating the delivery row
and persisting the occurrence state, leaves the occurrence due. A run that finishes before
redelivery reaches admission executes twice for that occurrence under every overlap policy;
`Allow` gives every dispatch a distinct overlap lane and removes cross-run exclusion.

Run reconciliation crash-fails a `Running`, executing row when a schema-invalid lock hides the lock
heartbeat and the run-row heartbeat is stale. Delivery ownership stamps both rows from one credited
timestamp, so run-row freshness is authoritative in that case. A stale non-executing run with no
pending-action descriptor follows the same recovery path; fresh rows remain preserved.

Maintenance reconciles stale parseable locks against retained run state. For a schema-invalid lock,
it reads active-run values for that identity in bounded batches and retains a compact projection of
unreadable state and Running argument lanes. It preserves the lock when a Running run has the same
arguments hash. An unreadable active-run row conservatively preserves every malformed lane for the
identity until a complete run pass removes it. Without a matching Running run or unreadable row,
maintenance exact-deletes only the malformed generation it inspected. A changed or absent generation
loses that fence silently; an authoritative delete failure preserves the row and warns the operator.
Malformed-lock diagnostics contain only the raw length and truncated SHA-256 correlation, never the
persisted bytes.

Consumer hooks use the `a8csp_bgje/` namespace. Hooks whose operation has an identity publish
generic and identity-specific variants: actions fire the specific hook before the generic hook,
while filters apply the generic hook before the specific hook so the specific return is
authoritative. This includes the failed lifecycle action and the retry-policy, queue,
lock-staleness, misfire-grace, and continuation-delay filters. Hooks without an identity remain
global. Action Scheduler and WP-Cron deliveries use the private
`a8csp_bgje/internal/deliver` and `a8csp_bgje/internal/schedule_due` hooks.

## Multisite

Options are per-site, so network uninstall pages through site IDs, switches into each site,
runs the per-site cleanup, and restores the prior site. The requirements gate reports through
`all_admin_notices` on site and network admin screens alike. The request-global graph deliberately
fails loud across `switch_to_blog()`; per-site operation happens through each site's own requests.
