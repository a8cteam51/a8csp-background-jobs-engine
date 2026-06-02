# A8CSP Background Tasks

A8CSP Background Tasks is a WordPress plugin that provides a small framework for
running long-running work in queued chunks. It defines background task classes,
stores task run state in WordPress options, and schedules task runs through
Action Scheduler when that plugin is active or through WP-Cron otherwise.

## Repository Structure

- `a8csp-background-tasks.php` is the WordPress plugin bootstrap and metadata
  file.
- `src/Plugin.php` coordinates task lifecycle events: start, continue, process,
  cleanup, and stop.
- `models/abstract-background-task.php` defines the base class for custom tasks.
- `models/class-call-user-func-task.php` provides a generic callback task.
- `adapters/` contains scheduler adapters for Action Scheduler and WP-Cron.
- `includes/` contains helper functions for queues, task run IDs, retries,
  timestamps, and logging.
- `languages/` contains the translation template.
- `tests/` contains Codeception integration and end-to-end suites.

## WordPress Surface

The plugin metadata requires WordPress 6.7 or later and PHP 8.3. The Composer
package also requires PHP 8.3 or later and the JSON extension.

Custom task classes extend `A8CSP_Abstract_Background_Task`, implement
`get_name()` and `process()`, and may override `generate_queue()` and
`cleanup()`. Task instances register themselves through the
`a8csp/background_tasks` filter.

The runtime uses these hooks:

- `a8csp/background_tasks/start`
- `a8csp/background_tasks/continue`
- `a8csp/background_tasks/process`
- `a8csp/background_tasks/cleanup`
- `a8csp/background_tasks/process/$task_name`
- `a8csp/background_tasks/cleanup/$task_name`

Queues can be filtered with `a8csp/background_tasks/queue/$task_name` and
`a8csp/background_tasks/queue`. Task run state is stored in WordPress options
with the `a8csp_bg-task_` prefix. `A8CSP_BGT_MAX_RUN_IDS` controls how many run
IDs are retained and defaults to `30`; `A8CSP_BGT_MAX_RETRIES` controls retry
attempts and defaults to `3`.

The current source does not register custom post types, taxonomies, REST routes,
shortcodes, blocks, or WP-CLI commands.

> Note: the tracked bootstrap currently returns immediately after loading
> `vendor/autoload.php`. The initialization block that includes `functions.php`
> and hooks `Plugin::initialize()` is present below that return, but is not
> reached until the early return is removed.

## Requirements

- PHP 8.3+
- Composer
- Node.js 22+ and npm 10+
- Docker, for `wp-env` development and tests
- Selenium with Chromium, for end-to-end tests

Install dependencies from the repository root:

```sh
npm install
composer run-script packages-install
```

## Local Development

The repository includes a `.wp-env.json` file that runs WordPress with PHP 8.3,
mounts this plugin, and maps the repository into the test environment as
`project`.

Start the WordPress environment:

```sh
npm run wp-env:start
```

Stop it when finished:

```sh
npm run wp-env:stop
```

For the Codeception suites, copy the default environment file and adjust values
only when your local ports, database, or ChromeDriver settings differ:

```sh
cp tests/.dist.env tests/.env
```

## Tests

The test workflow expects Docker host networking to be enabled and a Selenium
container named `selenium-chromium` to be available:

```sh
docker run -d --shm-size="2g" --net=host --name="selenium-chromium" selenium/standalone-chromium:latest
```

Create the end-to-end database fixture:

```sh
npm run wp-env:start
npm run tests:export-db
```

Run all tests:

```sh
npm run tests:run
```

Individual suites can be run with:

```sh
npm run tests:run:integration
npm run tests:run:end-to-end
```

## Quality Checks

Run the PHP checks:

```sh
composer run-script lint:php
```

Run the package-level checks:

```sh
npm run lint
```

The README markdown check is defined separately:

```sh
npm run lint:readme-md
```

GitHub Actions run Composer validation and `composer run-script lint:php` on
pushes to `trunk` and `develop`. The syntax workflow checks PHP files on PHP
8.3 and also keeps compatibility coverage for the bootstrap path on older PHP
versions.

## Usage Example

```php
class MyExampleTask extends A8CSP_Abstract_Background_Task {
	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public static function get_name(): string {
		return 'my_example_task';
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public static function generate_queue( array $run_args ): array {
		$queue = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$queue[] = array( $i, wp_rand( 0, $i ) );
		}

		return $queue;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public static function process( array $chunk, string $run_id ): void {
		if ( wp_rand( 0, 10 ) > 5 ) {
			throw new RuntimeException( 'Random exception' );
		}

		error_log( wp_json_encode( $chunk ) );
	}
}

MyExampleTask::get_instance();

$run_args          = array();
$my_task_scheduler = MyExampleTask::get_scheduler();

if ( ! $my_task_scheduler::has_task_run( MyExampleTask::get_name(), $run_args ) ) {
	$next_daily_timestamp = a8csp_bgt_get_date_timestamp( 'today 1PM' ) + 24 * HOUR_IN_SECONDS;
	$my_task_scheduler::schedule_recurring_task_run( MyExampleTask::get_name(), $next_daily_timestamp, DAY_IN_SECONDS, $run_args );
}
```

For one-off callback work, use the generic callback task:

```php
A8CSP_Call_User_Func_Task::register(
	static function ( int $post_id ): void {
		clean_post_cache( $post_id );
	},
	array( 123 )
);
```

## Studio by WordPress.com Setup

The `wp-env` flow above is the current Docker-based path for local development
and tests. For a manual macOS setup with Studio by WordPress.com, use MariaDB and
create a development database and a test database:

```sh
mariadb -e "CREATE DATABASE IF NOT EXISTS a8csp_background_tasks"
mariadb -e "CREATE USER IF NOT EXISTS 'wpcom_studio'@'localhost' IDENTIFIED BY '<your secret password>'"
mariadb -e "GRANT ALL PRIVILEGES ON a8csp_background_tasks.* TO 'wpcom_studio'@'localhost'"
mariadb -e "CREATE DATABASE IF NOT EXISTS a8csp_background_tasks_tests"
mariadb -e "GRANT ALL PRIVILEGES ON a8csp_background_tasks_tests.* TO 'wpcom_studio'@'localhost'"
```

Create a Studio site named `A8CSP Background Tasks` and configure it with:

- `DB_NAME`: `a8csp_background_tasks`
- `DB_USER`: `wpcom_studio`
- `DB_PASSWORD`: your local password
- `DB_HOST`: `127.0.0.1`
- `DB_CHARSET`: `utf8mb4`
- `DB_COLLATE`: `utf8mb4_unicode_520_ci`

Use `admin@example.com` as the site admin email. If the repository is not cloned
inside the site's `wp-content/plugins` directory, keep your checkout synced to
`wp-content/plugins/a8csp-background-tasks`.

Export a database fixture for end-to-end tests when needed:

```sh
mysqldump -u wpcom_studio -p a8csp_background_tasks > ./tests/Support/Data/dump.sql
```

## Maintenance Notes

- Keep generated dependency directories out of commits: `vendor/`,
  `node_modules/`, `.wp-env.override.json`, `codeception.yml`, and test output
  directories are ignored.
- `composer.lock` and `package-lock.json` are tracked and should be updated with
  dependency changes.
- `readme.txt` contains WordPress.org plugin metadata and should be reviewed
  before packaging because it still contains scaffold placeholder content.
- The tracked `LICENSE` file is GPL-3.0. Composer and npm metadata declare
  `GPL-2.0-or-later`, while the plugin header declares GPL v3 or later.
