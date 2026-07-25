<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Boundary;

\defined( 'ABSPATH' ) || exit;

/**
 * Identifies a same-kind registration collision at the engine boundary.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class DuplicateRegistrationException extends \LogicException {}
