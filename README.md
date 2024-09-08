# a8csp-background-tasks

**Contributors:** wpcomspecialprojects
**Tags:**
**Requires at least:** 6.5
**Tested up to:** 6.5
**Requires PHP:** 8.3
**Stable tag:** 1.0.0
**License:** GPLv3 or later
**License URI:** http://www.gnu.org/licenses/gpl-3.0.html



## Description

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed leo ligula, aliquam et sem luctus, placerat facilisis orci. Cras faucibus, odio ac aliquet scelerisque, nisi ligula dignissim nisi, sed tincidunt magna libero vitae dui. Sed varius lectus turpis, fringilla maximus libero posuere nec. Aenean volutpat pharetra sem, et cursus leo sodales quis.

## Installation

This plugin requires WooCommerce 7.4+ to run. If you're running a lower version, please update first. After you made sure that you're running a supported version of WooCommerce, you may install `Team51 Plugin Scaffold` either manually or through your site's plugins page.

### INSTALL FROM WITHIN WORDPRESS

1. Visit the plugins page withing your dashboard and select `Add New`.
1. Search for `Team51 Plugin Scaffold` and click the `Install Now` button.
1. Activate the plugin from within your `Plugins` page.

### INSTALL MANUALLY

1. Download the plugin from https://wordpress.org/plugins/ and unzip the archive.
1. Upload the `a8csp-background-tasks` folder to the `/wp-content/plugins/` directory.
1. Activate the plugin through the `Plugins` menu in WordPress.

## Example

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

### AFTER ACTIVATION

If the minimum required version of WooCommerce is present, you will find a section present in the `Advanced` tab of the WooCommerce `Settings` page. Aliquam dolor sem, convallis malesuada neque sit amet, dictum mattis velit. Vestibulum at pharetra metus. Suspendisse rhoncus libero nisi, sed rhoncus tortor aliquam pretium.

## Frequently Asked Questions

### How can I get help if I'm stuck?

Quisque volutpat tortor id varius pulvinar. Vivamus porttitor, mi non auctor pellentesque, leo purus interdum libero, at aliquam justo lectus sed ligula.

### I have a question that is not listed here

Duis efficitur, sapien ac scelerisque placerat, elit justo tempor nisl, ut feugiat magna orci quis odio.

## Screenshots

### 1. Example screenshot

[missing image]

## Changelog

### 1.0.0 (FIRST RELEASE DATE)

* First official release.
