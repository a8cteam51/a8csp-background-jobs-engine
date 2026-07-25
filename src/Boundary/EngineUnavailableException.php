<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Boundary;

\defined( 'ABSPATH' ) || exit;

/**
 * Identifies an operations request outside the booted engine graph's lifetime.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class EngineUnavailableException extends \LogicException {}
