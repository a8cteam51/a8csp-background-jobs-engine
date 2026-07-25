<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Marks an object as the execution role of an engine-owned job kind.
 *
 * The roles share no member, so the only thing a kind-agnostic declaration can require is
 * membership. Whether an object implements the role its kind expects stays a registration decision.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface KindExecutionInterface {}
