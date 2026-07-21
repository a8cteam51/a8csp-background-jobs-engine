<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefaults;

\defined( 'ABSPATH' ) || exit;

/**
 * Base for background work split into independently processed chunks.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class AbstractChunkedJob implements ChunkedJobInterface {
	use JobDefaults;
}
