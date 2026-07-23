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
- `functions.php` provides the owner-bound front door `a8csp_bgje( string $owner ): Engine`, the
  composition-root accessor `a8csp_bgje_plugin(): Plugin`, and a deterministic loader for the
  procedural facade files; handle and manager construction is lazy, while capability readiness
  starts at `init`.
- `includes/` groups the procedural facade by concept: `job.php` provides background-work
  registration and kind-agnostic dispatch, `schedule.php` provides schedule synchronization and
  dispatch, and `run.php` provides run inspection, retry, and cancellation.
- `portals/` holds the public `Engine`, `Jobs`, `Schedules`, and `Runs` services; `src/` root holds
  the bootstrapping mechanism: `src/ComponentInterface.php` is the one contract,
  `src/ComponentCollection.php` the shared gated collection, `src/AbstractComponent.php` the
  optional defaults-only base, and `src/Plugin.php` the internal composition root — the one file to
  edit when wiring a top-level component into `COMPONENTS`. The main bootstrap registers the
  request-local `Plugin` instance's `boot()` method; components boot in registration order behind a
  non-retryable latch.
- `models/` holds the public representation under `Error\`, `Job\`, `Run\`, and `Schedule\`.
  `Job\JobDefinition` composes a name, `Job\JobKind`, execution object, and `Job\JobOptions`;
  standard and chunked behavior implement `Job\JobExecution` and the standalone
  `Job\Chunked\ChunkedJobExecution` role. `Schedule\Schedule`, `Schedule\Recurrence`, and
  `Schedule\CatchUpPolicy` form the typed schedule declaration consumed by the public `Schedules`
  service.
- The root services, `models/`, `a8csp_bgje()`, and the verb-noun procedural aliases form the SemVer-bound
  consumer surface: the owner-scoped `Engine` handle and capability managers plus job definitions,
  execution roles, policy, contexts, and input and returned value types.
  `src/Boundary/` contains engine-owned values that cross layer boundaries; the rest of the engine
  graph is likewise `@internal`.
- `src/Runtime/` is the engine capability tree: `Component.php` assembles and publishes the
  request-local object graph, while `OwnerOperations.php` exposes its owner-bound verb surface to
  the public portals; `EngineFacade.php`, `Inspection.php`, and `JobRegistry.php` are the root
  collaborators. `JobRegistry.php` retains each definition's kind key, name, execution object, and
  options. The single kind-handler registry resolves a definition's kind; the resolved
  internal handler validates its execution role and owns invocation. Only engine-installed kinds are
  accepted, and the handler SPI is internal. `Backends/` (Action Scheduler preferred, WP-Cron
  fallback), `Schedules/`
  (schedule registry, sync orchestration, occurrence delivery, leases, and cleanup convergence),
  `Locks/`, `Runs/`, `Storage/` (option-row stores with CAS fencing), `Maintenance/` (bounded sweeps
  on an hourly recurrence), `Logging/`, and `Error/` each own one sub-capability.
- `src/CLI/` registers the `wp background-jobs` command surface, gated on WP-CLI.
- `languages/` contains the POT generated from the plugin's strings; the release workflow
  regenerates it so archives always ship current strings.
- `uninstall.php` carries the persisted footprint inline — the `a8csp_bgje_` prefix sweep is the
  complete ownership boundary, runtime-suffixed option families make a fixed-key manifest
  impossible — and sweeps options, WP-Cron events, and Action Scheduler rows per site, in
  bounded batches across a network.
- `tests/` contains the automated test suite; see `tests/README.md` for the suite matrix and
  local workflow.
- `.github/workflows/` includes quality, test, audit, CodeQL, workflow-checks, mutation, and
  release automation; the release pipeline builds, smoke-tests the artifact through the shared
  reusable workflow, and publishes prereleases off the stable update channel.

## Delivery and degradation

The engine writes through the first ready backend in preference order, with Action Scheduler
before WP-Cron; WP-Cron provides the documented best-effort fallback. Delivery is at-least-once
for terminal lifecycle hooks. Overlap locks, occurrence leases, and run generations use
option-row compare-and-swap fences for concurrency control.

## Multisite

Options are per-site, so network uninstall pages through site IDs, switches into each site,
runs the per-site cleanup, and restores the prior site. The requirements gate reports through
`all_admin_notices` on site and network admin screens alike. The request-global graph deliberately
fails loud across `switch_to_blog()`; per-site operation happens through each site's own requests.
