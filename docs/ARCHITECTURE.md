# Architecture

This is the map of the plugin: what each file owns, how the pieces boot, and where the
load-bearing machinery lives. It ships with the repository so the map survives for every future
maintainer.

## The component model

The plugin is a list of components; a component is a class with a static `should_load()` gate, an
`initialize()` readiness phase, and a `register_hooks()` attachment phase; the boot is a few
foreach loops you can read — gate and construct, initialize all, then register all hooks, so
every surviving component is initialized before any hook can fire.

## The map

- `a8csp-background-tasks-engine.php` defines the plugin header and constants, requires
  `functions-bootstrap.php`, and wires the self-updater, the requirements gate, and the
  `plugins_loaded` priority-zero boot. A request that activates the engine stays dormant until
  the next request.
- `functions-bootstrap.php` provides the GitHub release updater, plugin metadata,
  version compatibility checks, the requirements gate, and its admin-notice reporter; both root
  bootstrap files stay parsable below the plugin's PHP floor, and CI lints them against the older
  PHP versions.
- `functions.php` provides the construction-only composition-root accessor and the consumer
  front door `a8csp_bgte( string $owner ): Consumer`, available from `init` or later.
- `src/` root holds only the bootstrapping mechanism: `src/ComponentInterface.php` is the one
  contract, `src/ComponentCollection.php` the shared gated collection, `src/AbstractComponent.php`
  the optional defaults-only base, and `src/Plugin.php` the composition root — the one file to
  edit when wiring a top-level component into `COMPONENTS`; they boot in registration order
  behind a non-retryable latch.
- `src/Api/` is the entire public consumer surface (SemVer-bound): the owner-scoped `Consumer`
  facades, the `Result` monad and error values, `WorkIdentity` (owner ≤32, name ≤64, composed
  ≤97 bytes), `PortableArguments`, the policy enums, and the Task/Batch/Run/Schedule contracts.
  Everything outside `src/Api/` is `@internal`.
- `src/Engine/` is the engine capability tree: `Component.php` assembles and publishes the
  request-local object graph; `EngineFacade.php`, `Inspection.php`, and `WorkRegistry.php` are the
  root collaborators; `Backends/` (Action Scheduler preferred, WP-Cron fallback), `Occurrences/`
  (schedule registry, sync orchestration, occurrence delivery, leases, and cleanup convergence),
  `Locks/`, `Runs/`, `Storage/` (option-row stores with CAS fencing), `Maintenance/` (bounded sweeps
  on an hourly recurrence), `Logging/`, and `Error/` each own one sub-capability.
- `src/CLI/` registers the `wp background-tasks` command surface, gated on WP-CLI.
- `languages/` contains the POT generated from the plugin's strings; the release workflow
  regenerates it so archives always ship current strings.
- `uninstall.php` carries the persisted footprint inline — the `a8csp_bgte_` prefix sweep is the
  complete ownership boundary, runtime-suffixed option families make a fixed-key manifest
  impossible — and sweeps options, WP-Cron events, and Action Scheduler rows per site, in
  bounded batches across a network.
- `tests/` contains the automated test suite; see `tests/README.md` for the suite matrix and
  local workflow.
- `.github/workflows/` contains the quality, test, audit, mutation, and release workflows; the
  release pipeline builds, smoke-tests the artifact through the shared reusable workflow, and
  publishes prereleases off the stable update channel.

## Delivery and degradation

The engine prefers Action Scheduler and falls back to WP-Cron with documented best-effort
semantics; the degraded CI environment proves the WP-Cron-only path with Action Scheduler
absent. Delivery is at-least-once for terminal lifecycle hooks; concurrency control rides
option-row CAS fences (overlap locks, occurrence leases, run generations) proven by the
suite's pinned concurrency evidence.

## Multisite

Options are per-site, so the uninstall sweep visits every site of a network in bounded batches
while the requirements gate reports through `all_admin_notices` on site and network admin
screens alike. The request-global graph deliberately fails loud across `switch_to_blog()`;
per-site operation happens through each site's own requests. The multisite wp-env fixture
converts itself into a network and `composer test:multisite` proves the network sweep against
it.
