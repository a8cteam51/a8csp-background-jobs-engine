# Tests

The test rig defines three PHPUnit suites, exposes five local run configurations, and adds a separate mutation-testing job.

## Suite matrix

- **Unit** (`tests/Unit/`) — the `Unit` suite runs without WordPress or wp-env. Plain PHPUnit
  `TestCase` tests use recording WordPress stubs where needed, including
  `tests/Unit/wp-hook-stubs.php` for the real `Plugin::boot()` path. CI runs it on PHP 8.5 and 8.6.
- **Integration** (`tests/Integration/`) — the complete `Integration` suite runs against a
  supported WordPress version with Action Scheduler active on port 8890. The command runs
  `tests/complete-as-migration.php` before PHPUnit so the Action Scheduler store is on its
  completed migration schema. CI runs this configuration against WordPress 7.0 and nightly.
- **Degraded** — the `Integration` suite filtered to `--group=degraded` runs on port 8892 with
  Action Scheduler absent, exercising the WP-Cron-only path. The command refuses to start PHPUnit
  if the Action Scheduler class or enqueue function is present.
- **Requirements** (`tests/Integration/RequirementsCheckTest.php`) — the separate `Requirements`
  suite runs on port 8891 against the below-floor WordPress fixture and verifies that the
  requirements gate degrades gracefully instead of fataling.
- **Multisite** — the `Integration` suite filtered to `--group=multisite` runs on port 8894. The
  command converts the fixture to multisite when needed, network-activates Action Scheduler and
  the engine, runs `tests/complete-as-migration.php`, and then starts PHPUnit.

## Running the configurations

Each `composer test:*` verb starts its own environment first — a container already running serves
the mount set it was created with, and `start` is what replaces it when the resolved config moved.
Stopping afterwards is still worth it: the four environments claim the same ports as other
repositories built from the same template.

Unit requires no wp-env instance:

```sh
composer test:unit
```

Integration:

```sh
composer test:integration
npm run wp-env:tests:stop
```

Degraded:

```sh
composer test:degraded
npm run wp-env:degraded:stop
```

Requirements:

```sh
composer test:requirements
npm run wp-env:belowfloor:stop
```

Multisite:

```sh
composer test:multisite
npm run wp-env:multisite:stop
```

## Ports

| Environment  | Config                    | Port |
| ------------ | ------------------------- | ---- |
| Integration  | `.wp-env.tests.json`      | 8890 |
| Requirements | `.wp-env.belowfloor.json` | 8891 |
| Degraded     | `.wp-env.degraded.json`   | 8892 |
| Dev          | `.wp-env.json`            | 8893 |
| Multisite    | `.wp-env.multisite.json`  | 8894 |

## Why plain `TestCase`, not `WP_UnitTestCase`

WordPress core's PHPUnit scaffold supports PHPUnit through version 9; open ticket
[#62004](https://core.trac.wordpress.org/ticket/62004) tracks compatibility work for PHPUnit 11
and later. This rig runs PHPUnit 13 directly against plain `TestCase` inside wp-env, without
depending on `WP_UnitTestCase` or core's PHPUnit compatibility range.

That trade gives up `$this->factory` fixture helpers, `go_to()` routing simulation, and
`WP_UnitTestCase`'s per-test transaction rollback. The WordPress-backed configurations create
their explicit fixtures through public WordPress APIs and need to observe persistence, boot-time
side effects, backend delivery, and uninstall behavior. Automatic transaction rollback would mask
the storage behavior those tests are written to verify.

## Mutation testing (CI-first)

`composer test:unit:mutation` runs Infection against the Unit suite's source. It sits outside the
default `composer quality-check` target (only `quality-check:all` pulls it in) and does not gate
pull requests — it runs on its own weekly schedule in CI (`.github/workflows/tests-mutation.yml`),
since mutation testing is slow. Local runs on macOS are unreliable: a race in Infection's
coverage-XML tmpdir handling can produce zero generated mutants or a hang, independent of anything
in this repo's own configuration. Treat the CI job, not a local run, as authoritative for mutation
results.
