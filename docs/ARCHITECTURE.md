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
  procedural facade files; handle and manager construction is lazy, while capability readiness
  starts at `init`.
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
- The root services, the README's public type index, `a8csp_bgje()`, and the verb-noun procedural
  aliases form the SemVer-bound consumer surface: the per-scope `Engine` handle and capability
  managers plus job definitions, execution roles, policy, contexts, and input and returned value
  types.
  `src/Boundary/` contains engine-owned values that cross layer boundaries; the rest of the engine
  graph is likewise `@internal`.
- `src/Runtime/` is the engine capability tree: `Component.php` assembles and publishes the
  request-local object graph, while `ScopeOperations.php` exposes its scope-bound verb surface to
  the public portals; `EngineFacade.php`, `Inspection.php`, and `JobRegistry.php` are the root
  collaborators. `JobRegistry.php` retains each definition's kind key, name, execution object, and
  options. The single kind-handler registry resolves a definition's kind; the resolved
  internal handler validates its execution role and owns invocation. Only engine-installed kinds are
  accepted, and the handler SPI is internal. `Backends/` (Action Scheduler preferred, WP-Cron
  fallback), `Schedules/`
  (schedule registry, sync orchestration, occurrence delivery, leases, and cleanup convergence),
  `Locks/` (CAS-fenced execution-overlap storage, the single overlap-identity authority admission,
  retry, and inspection all resolve through, persisted-lane inspection, and explicit malformed-lane
  repair), `Runs/`, `Storage/` (option-row stores with CAS fencing), `Maintenance/` (bounded sweeps
  on an hourly recurrence), `Logging/`, and `Error/` each own one sub-capability.
- `src/CLI/` registers the `wp a8csp-bgje` command surface, including the operator-only
  malformed-lock repair boundary, gated on WP-CLI.
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

Every interface ends in `Interface`; every abstract class begins with `Abstract`.

## Delivery and degradation

The engine writes through the first ready backend in preference order, with Action Scheduler
before WP-Cron; WP-Cron provides the documented best-effort fallback. Delivery is at-least-once
for terminal lifecycle hooks. Overlap locks, occurrence leases, and run generations use
option-row compare-and-swap fences for concurrency control.

Maintenance reconciles stale parseable locks against retained run state. It preserves
schema-invalid lock values and logs only their length and truncated SHA-256 correlation so repair
remains an explicit operator action. The WP-CLI repair path first claims `Superseded` through exact
compare-and-swap for every matching `Running` row and only then exact-deletes the selected malformed
lock generation; a lost run or lock fence leaves the lock in place.

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
