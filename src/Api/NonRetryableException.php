<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api;

\defined( 'ABSPATH' ) || exit;

/**
 * Client-ready permanent job failure without a custom exception hierarchy.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
class NonRetryableException extends \RuntimeException implements NonRetryableExceptionInterface {}
