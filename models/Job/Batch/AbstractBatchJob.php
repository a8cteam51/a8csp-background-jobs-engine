<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job\Batch;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefaults;

\defined( 'ABSPATH' ) || exit;

/**
 * Shared base for the batch job kind.
 *
 * No engine backend dispatches batch runs; `Jobs::register()` rejects the kind as
 * `invalid_argument`.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class AbstractBatchJob implements BatchJobInterface {
	use JobDefaults;
}
