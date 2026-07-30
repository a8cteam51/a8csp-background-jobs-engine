<?php declare( strict_types=1 );
/**
 * PHPStan needs representative plugin constants because WordPress is not loaded during analysis.
 *
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

\define( 'A8CSP_BGJE_BASENAME', 'a8csp-background-jobs-engine/a8csp-background-jobs-engine.php' );
\define( 'A8CSP_BGJE_DIR_PATH', '/var/www/html/wp-content/plugins/a8csp-background-jobs-engine/' );

// A database drop-in constructs wpdb itself, so it reads the credentials wp-config.php defines. The
// values are irrelevant to analysis; only their type and existence are.
\define( 'DB_USER', 'username' );
\define( 'DB_PASSWORD', 'password' );
\define( 'DB_NAME', 'database' );
\define( 'DB_HOST', 'localhost' );
