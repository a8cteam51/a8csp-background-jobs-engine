<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkedJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\AbstractJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Internal\JobIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Retains registered job and chunked job instances by their stable identities.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class JobRegistry {
	// region FIELDS AND CONSTANTS

	/**
	 * Registered jobs keyed by stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, AbstractJob>
	 */
	private array $jobs = array();

	/**
	 * Registered chunked jobs keyed by stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, ChunkedJobInterface>
	 */
	private array $chunked_jobs = array();

	// endregion

	// region METHODS

	/**
	 * Registers one job instance under a unique identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $identity Complete owner-qualified identity.
	 * @param   AbstractJob $job      Job to register.
	 *
	 * @throws  \InvalidArgumentException      When the identity and job name disagree, or a chunked job owns the identity.
	 * @throws  DuplicateRegistrationException When the job identity is already registered.
	 *
	 * @return  void
	 */
	public function register_job( string $identity, AbstractJob $job ): void {
		$this->guard_registration( $identity, $job->get_name(), 'job' );
		$this->jobs[ $identity ] = $job;
	}

	/**
	 * Registers one chunked job instance under a unique identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string              $identity Complete owner-qualified identity.
	 * @param   ChunkedJobInterface $chunked_job    Chunked Job to register.
	 *
	 * @throws  \InvalidArgumentException      When the identity and chunked job name disagree, or a job owns the identity.
	 * @throws  DuplicateRegistrationException When the chunked job identity is already registered.
	 *
	 * @return  void
	 */
	public function register_chunked_job( string $identity, ChunkedJobInterface $chunked_job ): void {
		$this->guard_registration( $identity, $chunked_job->get_name(), 'chunked_job' );
		$this->chunked_jobs[ $identity ] = $chunked_job;
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the job registered under a stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job identity.
	 *
	 * @return  AbstractJob|null
	 */
	public function job( string $identity ): ?AbstractJob {
		return $this->jobs[ $identity ] ?? null;
	}

	/**
	 * Returns the chunked job registered under a stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified chunked job identity.
	 *
	 * @return  ChunkedJobInterface|null
	 */
	public function chunked_job( string $identity ): ?ChunkedJobInterface {
		return $this->chunked_jobs[ $identity ] ?? null;
	}

	/**
	 * Returns the registered kind for one complete identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified identity.
	 *
	 * @return  'chunked_job'|'job'|null
	 */
	public function kind( string $identity ): ?string {
		if ( isset( $this->jobs[ $identity ] ) ) {
			return 'job';
		}

		return isset( $this->chunked_jobs[ $identity ] ) ? 'chunked_job' : null;
	}

	// endregion

	// region HELPERS

	/**
	 * Validates name grammar, identity agreement, and single-registration ownership.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string              $identity Complete owner-qualified identity.
	 * @param   string              $name     Declared local work name.
	 * @param   'chunked_job'|'job' $kind     Incoming registration channel.
	 *
	 * @throws  \InvalidArgumentException      When the identity is non-canonical or the other kind owns it.
	 * @throws  DuplicateRegistrationException When the same kind already owns the identity.
	 *
	 * @return  void
	 */
	private function guard_registration( string $identity, string $name, string $kind ): void {
		JobIdentity::validate_name( $name );
		$parts = JobIdentity::parts( $identity );
		if ( null === $parts || $name !== $parts[1] ) {
			throw new \InvalidArgumentException( 'job' === $kind ? 'Job identity must be canonical and end with the job\'s declared local name.' : 'Chunked Job identity must be canonical and end with the chunked job\'s declared local name.' );
		}

		$existing = $this->kind( $identity );
		if ( $kind === $existing ) {
			throw new DuplicateRegistrationException( 'job' === $kind ? 'Job name is already registered; register each job name exactly once.' : 'Chunked Job name is already registered; register each chunked job name exactly once.' );
		}
		if ( null !== $existing ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Background-work identity "%1$s" is already registered as a %2$s; it cannot also be registered as a %3$s.', $identity, \str_replace( '_', ' ', $existing ), \str_replace( '_', ' ', $kind ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	// endregion
}
