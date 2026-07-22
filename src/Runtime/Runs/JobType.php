<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

\defined( 'ABSPATH' ) || exit;

/**
 * Identifies the registered job contract used by an engine run.
 *
 * Backing values are persisted as run kinds and rendered as diagnostic nouns.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
enum JobType: string {
	// region FIELDS AND CONSTANTS

	case Job        = 'Job';
	case ChunkedJob = 'ChunkedJob';

	// endregion

	// region METHODS

	/**
	 * Returns the machine key for this job type.
	 *
	 * @internal Engine formatting only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-return 'job'|'chunked_job'
	 *
	 * @return  string
	 */
	public function machine_key(): string {
		return match ( $this ) {
			self::Job        => 'job',
			self::ChunkedJob => 'chunked_job',
		};
	}

	/**
	 * Returns the human-readable label for this job type.
	 *
	 * @internal Engine formatting only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-return 'job'|'chunked job'
	 *
	 * @return  string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Job        => 'job',
			self::ChunkedJob => 'chunked job',
		};
	}

	// endregion
}
