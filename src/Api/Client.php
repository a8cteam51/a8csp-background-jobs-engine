<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkedJobs;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Run\Runs;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Schedules;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\Jobs;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\JobIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Owner-bound entry point for supported background-work operations.
 *
 * Deterministic contract violations detectable before side effects, including invalid or reserved
 * identities, priority or delay bounds, non-portable arguments, and cross-kind registration, throw
 * {@see \InvalidArgumentException}. A valid command refused by current registration, lock, backend,
 * storage, or run state returns `Failure<ApiError>`.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Client {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $owner     Client plugin owner.
	 * @param   Jobs        $jobs     Owner-bound job facade.
	 * @param   ChunkedJobs $chunked_jobs   Owner-bound chunked job facade.
	 * @param   Schedules   $schedules Owner-bound schedule facade.
	 * @param   Runs        $runs      Owner-bound run facade.
	 *
	 * @throws  \InvalidArgumentException When the owner violates the client-owner contract.
	 */
	public function __construct(
		private string $owner,
		private Jobs $jobs,
		private ChunkedJobs $chunked_jobs,
		private Schedules $schedules,
		private Runs $runs,
	) {
		JobIdentity::validate_owner( $this->owner );
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the owner-bound job facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Jobs
	 */
	public function jobs(): Jobs {
		return $this->jobs;
	}

	/**
	 * Returns the owner-bound chunked job facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  ChunkedJobs
	 */
	public function chunked_jobs(): ChunkedJobs {
		return $this->chunked_jobs;
	}

	/**
	 * Returns the owner-bound schedule facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Schedules
	 */
	public function schedules(): Schedules {
		return $this->schedules;
	}

	/**
	 * Returns the owner-bound run facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Runs
	 */
	public function runs(): Runs {
		return $this->runs;
	}

	// endregion
}
