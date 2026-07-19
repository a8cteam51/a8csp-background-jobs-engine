<?php declare( strict_types=1 );

/**
 * Infection reflects the production classes inside its own process while enriching their AST;
 * without ABSPATH, the file guards exit that process before any mutant is generated.
 *
 * @since   1.0.0
 * @version 1.0.0
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

if ( ! \defined( 'ABSPATH' ) ) {
	\define( 'ABSPATH', __DIR__ . '/' );
}
