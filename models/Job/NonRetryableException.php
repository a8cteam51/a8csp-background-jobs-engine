<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job;

\defined( 'ABSPATH' ) || exit;

/**
 * Client-ready permanent job failure without a custom exception hierarchy.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
class NonRetryableException extends \RuntimeException {}
