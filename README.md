# A8CSP Background Tasks

## Setting up a dev environment using Studio by WordPress.com on MacOS

1) Make sure MariaDB is running on your machine.
	* Follow the instructions at https://mariadb.com/kb/en/installing-mariadb-on-macos-using-homebrew/
	* Test by opening a terminal and running `mariadb` (no arguments)

1) Create a new database for the plugin:
	* mariadb -e "CREATE DATABASE IF NOT EXISTS a8csp_background_tasks"
    * mariadb -e "CREATE USER IF NOT EXISTS 'wpcom_studio'@'localhost' IDENTIFIED BY '<your secret password>'"
	* mariadb -e "GRANT ALL PRIVILEGES ON a8csp_background_tasks.* TO 'wpcom_studio'@'localhost'"

1) Create a new database for the automated tests:
	* mariadb -e "CREATE DATABASE IF NOT EXISTS a8csp_background_tasks_tests"
	* mariadb -e "GRANT ALL PRIVILEGES ON a8csp_background_tasks_tests.* TO 'wpcom_studio'@'localhost'"

1) Create a new site in *Studio by WordPress.com* and configure it to use the database you created in the previous step.
	* Suggested site name: `A8CSP Background Tasks`
    * https://developer.wordpress.com/docs/developer-tools/studio/#use-studio-with-mysql-server
    * Suggested `wp-config.php` constants are:
      * `DB_NAME`: `a8csp_background_tasks`
      * `DB_USER`: `wpcom_studio`
      * `DB_PASSWORD`: `<your secret password>`
      * `DB_HOST`: `127.0.0.1`
      * `DB_CHARSET`: `utf8mb4`
      * `DB_COLLATE`: `utf8mb4_unicode_520_ci`

1) Clone the repository and install the dependencies:
	* `npm install`
	* `composer run-script install`

1) Finish configuring the site using the username and password provided by Studio by WordPress.com.
    * Use `admin@example.com` as the site admin email.

1) Export the database to create your E2E tests fixture.
	* `cd <path to the plugin>`
    * `mysqldump -u wpcom_studio -p a8csp_background_tasks > ./tests/Support/Data/dump.sql`

1) Copy the `tests/.dist.env` file to `tests/.env` and update all the values to match your local environment.

1) Install the plugin on the site.
	* If you cloned it inside the `wp-content/plugins` directory, you should be done.
    * If you cloned it somewhere else, ensure that you keep your dev directory in sync with the site's `wp-content/plugins/a8csp-background-tasks` directory. For example, through PhpStorm's Local Deployment feature.

1) Install selenium-server and chromedriver to run the E2E tests.
    * https://formulae.brew.sh/formula/selenium-server
	* https://formulae.brew.sh/cask/chromedriver
    * Test that it's working by running `selenium-server info` and `chromedriver --version`, respectively.
    * If you encounter the error `Apple could not verify “chromedriver” is free of malware that may harm your Mac or compromise your privacy.`:
      * Run `which chromedriver` to get the path to the binary.
      * Run `xattr -d com.apple.quarantine <path to chromedriver>` to remove the quarantine attribute.
    * Your `chromedriver` version should match the version of your Chrome browser.

1) Start the selenium server:
   	* In a new terminal tab, run `selenium-server standalone --port 4444`
    * If you use a different port, update the `CHROMEDRIVER_PORT` variable inside the `tests/.env` file accordingly.

1) Test that everything is working by running the automated tests:
	* `composer run-script test`

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
} MyExampleTask::get_instance();

$my_task_scheduler = MyExampleTask::get_instance()::get_scheduler();
if ( ! $my_task_scheduler::has_task_run( MyExampleTask::get_name() ) ) {
	$next_daily_timestamp = a8csp_bgt_get_date_timestamp( 'today 1PM' ) + 24 * HOUR_IN_SECONDS;
	$my_task_scheduler::schedule_recurring_task_run( MyExampleTask::get_name(), $next_daily_timestamp, DAY_IN_SECONDS, array() );
}
```
