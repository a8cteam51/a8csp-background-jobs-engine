<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job\Batch;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Common contract for batch jobs.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
interface BatchJobInterface extends JobInterface {}
