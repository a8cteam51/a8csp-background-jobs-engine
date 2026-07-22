<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkedJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\AbstractJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobInterface;
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
	 * Registered work contracts and their kind keys, keyed by stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, array{kind: string, contract: JobInterface}>
	 */
	private array $registrations = array();

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
		$this->register(
			$identity,
			$job->get_name(),
			'job',
			$job,
			'Job identity must be canonical and end with the job\'s declared local name.',
			'Job name is already registered; register each job name exactly once.'
		);
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
		$this->register(
			$identity,
			$chunked_job->get_name(),
			'chunked_job',
			$chunked_job,
			'Chunked Job identity must be canonical and end with the chunked job\'s declared local name.',
			'Chunked Job name is already registered; register each chunked job name exactly once.'
		);
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
		$contract = $this->contract_for_kind( $identity, 'job' );

		return $contract instanceof AbstractJob ? $contract : null;
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
		$contract = $this->contract_for_kind( $identity, 'chunked_job' );

		return $contract instanceof ChunkedJobInterface ? $contract : null;
	}

	/**
	 * Returns the work contract registered under a stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified work identity.
	 *
	 * @return  JobInterface|null
	 */
	public function contract( string $identity ): ?JobInterface {
		return $this->registrations[ $identity ]['contract'] ?? null;
	}

	/**
	 * Returns the registered kind for one complete identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified identity.
	 *
	 * @return  string|null
	 */
	public function kind( string $identity ): ?string {
		return $this->registrations[ $identity ]['kind'] ?? null;
	}

	// endregion

	// region HELPERS

	/**
	 * Registers one validated contract without replacing an existing identity owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string       $identity          Complete owner-qualified identity.
	 * @param   string       $name              Declared local work name.
	 * @param   string       $kind              Incoming registration channel.
	 * @param   JobInterface $contract          Work contract.
	 * @param   string       $identity_message  Non-canonical identity diagnostic.
	 * @param   string       $duplicate_message Same-kind duplicate diagnostic.
	 *
	 * @throws  \InvalidArgumentException      When the identity is non-canonical or the other kind owns it.
	 * @throws  DuplicateRegistrationException When the same kind already owns the identity.
	 *
	 * @return  void
	 */
	private function register(
		string $identity,
		string $name,
		string $kind,
		JobInterface $contract,
		string $identity_message,
		string $duplicate_message,
	): void {
		JobIdentity::validate_name( $name );
		$parts = JobIdentity::parts( $identity );
		if ( null === $parts || $name !== $parts[1] ) {
			throw new \InvalidArgumentException( $identity_message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
		}

		$existing = $this->registrations[ $identity ] ?? null;
		if ( null === $existing ) {
			$this->registrations[ $identity ] = array(
				'kind'     => $kind,
				'contract' => $contract,
			);

			return;
		}

		if ( $kind === $existing['kind'] ) {
			throw new DuplicateRegistrationException( $duplicate_message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
		}

		// Exception values are diagnostic data, not rendered output.
		throw new \InvalidArgumentException( \sprintf( 'Background-work identity "%1$s" is already registered as a %2$s; it cannot also be registered as a %3$s.', $identity, $existing['kind'], $kind ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Returns a registered contract only when its registration channel matches.
	 *
	 * The stored kind, rather than implemented interfaces, keeps a contract implementing both public
	 * work interfaces visible only through the channel that registered it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified identity.
	 * @param   string $kind     Expected registration channel.
	 *
	 * @return  JobInterface|null
	 */
	private function contract_for_kind( string $identity, string $kind ): ?JobInterface {
		$registration = $this->registrations[ $identity ] ?? null;
		if ( null === $registration || $kind !== $registration['kind'] ) {
			return null;
		}

		return $registration['contract'];
	}

	// endregion
}
