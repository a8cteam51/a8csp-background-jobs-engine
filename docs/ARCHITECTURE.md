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
  `plugins_loaded` priority-zero boot. A request that activates the engine stays dormant until
  the next request.
- `functions-bootstrap.php` provides the GitHub release updater, plugin metadata,
  version compatibility checks, the requirements gate, and its admin-notice reporter; both root
  bootstrap files stay parsable below the plugin's PHP floor, and CI lints them against the older
  PHP versions.
- `functions.php` provides the construction-only composition-root accessor and the owner-bound
  front door `a8csp_bgje( string $owner ): Engine`; handle construction is lazy, while verb
  readiness starts at `init`.
- `src/` root holds only the bootstrapping mechanism: `src/ComponentInterface.php` is the one
  contract, `src/ComponentCollection.php` the shared gated collection, `src/AbstractComponent.php`
  the optional defaults-only base, and `src/Plugin.php` the composition root — the one file to
  edit when wiring a top-level component into `COMPONENTS`; they boot in registration order
  behind a non-retryable latch.
- `models/`, `a8csp_bgje()`, and the verb-mirror aliases form the SemVer-bound consumer surface:
  the owner-scoped `Engine` handle, authoring bases, contexts, and returned value types.
  `src/Api/` contains the internal capability facades and contracts; the rest of the engine graph
  is likewise `@internal`.
- `src/Engine/` is the engine capability tree: `Component.php` assembles and publishes the
  request-local object graph; `EngineFacade.php`, `Inspection.php`, and `JobRegistry.php` are the
  root collaborators; `Backends/` (Action Scheduler preferred, WP-Cron fallback), `Occurrences/`
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
