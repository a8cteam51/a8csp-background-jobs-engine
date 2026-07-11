# A8CSP Background Tasks Engine

**Contributors:** wpcomspecialprojects
**Tags:**
**Requires at least:** 7.0
**Tested up to:** 7.0
**Requires PHP:** 8.5
**Stable tag:** 1.0.0
**License:** GPL v2 or later
**License URI:** <https://www.gnu.org/licenses/gpl-2.0.html>

A background-work engine for WordPress sites: Tasks, Schedules, and Batches on pluggable scheduling backends.

## Description

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed leo ligula, aliquam et sem luctus, placerat facilisis orci. Cras faucibus, odio ac aliquet scelerisque, nisi ligula dignissim nisi, sed tincidunt magna libero vitae dui. Sed varius lectus turpis, fringilla maximus libero posuere nec. Aenean volutpat pharetra sem, et cursus leo sodales quis.

## Installation

This plugin boots whether or not WooCommerce is active. The example WooCommerce settings section initializes only when WooCommerce is active and meets the `WC requires at least` version declared in the plugin header. Install `A8CSP Background Tasks Engine` either manually or through your site's plugins page.

### INSTALL FROM WITHIN WORDPRESS

1. Visit the plugins page withing your dashboard and select `Add New`.
1. Search for `A8CSP Background Tasks Engine` and click the `Install Now` button.
1. Activate the plugin from within your `Plugins` page.

### INSTALL MANUALLY

1. Download the plugin from <https://wordpress.org/plugins/> and unzip the archive.
1. Upload the `A8CSP Background Tasks Engine` folder to the `/wp-content/plugins/` directory.
1. Activate the plugin through the `Plugins` menu in WordPress.

### AFTER ACTIVATION

If the minimum required version of WooCommerce is present, you will find a section present in the `Advanced` tab of the WooCommerce `Settings` page. Aliquam dolor sem, convallis malesuada neque sit amet, dictum mattis velit. Vestibulum at pharetra metus. Suspendisse rhoncus libero nisi, sed rhoncus tortor aliquam pretium.

## Watering down to plain WordPress

To convert this plugin from a WooCommerce extension to a plain WordPress plugin, remove the WooCommerce tier:

1. Delete the `src/Integrations/` and `templates/myaccount/` directories.
2. Remove `Integrations\WC_Settings_Section::class` from the `COMPONENTS` list in `src/Plugin.php`.
3. Remove the `Integrations\WC_Settings_Section` option line from the `uninstall.php` footprint.
4. Remove the `wp-plugin/woocommerce` and `php-stubs/woocommerce-stubs` development dependencies from `composer.json`; run `composer update`.
5. Remove the WooCommerce `scanDirectories` entry from `.phpstan.neon`.
6. Remove the `before_woocommerce_init` compatibility block from the plugin entry file, and the `WC requires at least` / `WC tested up to` plugin-header lines.
7. Delete `tests/Integration/PluginBootWithoutWooCommerceTest.php` and `tests/Unit/WCSettingsSectionTest.php`; drop the WooCommerce assertions from `tests/Integration/PluginBootTest.php`.
8. Remove the WooCommerce-less proof section from `tests/README.md`.
9. Run `composer quality-check`. What remains — blocks, settings, the component list, the `includes/` loader, and a live uninstall footprint — is a complete plain WordPress plugin.

**For a plugin that persists nothing:** delete `src/Settings.php`, its `COMPONENTS` entry in `src/Plugin.php`, its option line in the `uninstall.php` footprint, and `includes/settings.php`.

When integrations multiply behind one shared gate, give them a parent component whose
`initialize()` constructs and gates its children — five lines, written the day they're needed.

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
