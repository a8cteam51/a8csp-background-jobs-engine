<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;

\defined( 'ABSPATH' ) || exit;

/**
 * Retains registered background-work definitions by their stable identities.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class JobRegistry {
	// region FIELDS AND CONSTANTS

	/**
	 * Registration data keyed by stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, array{kind: string, name: string, execution: object, options: JobOptions}>
	 */
	private array $registrations = array();

	// endregion

	// region METHODS

	/**
	 * Registers one definition without replacing an existing identity owner.
	 *
	 * Execution compatibility is established by the resolved kind handler before this data-only
	 * registry is called.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string        $identity   Complete owner-qualified identity.
	 * @param   JobDefinition $definition Definition to register.
	 *
	 * @throws  \InvalidArgumentException      When the identity and definition name disagree, or another kind owns the identity.
	 * @throws  DuplicateRegistrationException When the definition kind already owns the identity.
	 *
	 * @return  void
	 */
	public function register( string $identity, JobDefinition $definition ): void {
		JobIdentity::validate_name( $definition->name );
		$parts = JobIdentity::parts( $identity );
		if ( null === $parts || $definition->name !== $parts[1] ) {
			throw new \InvalidArgumentException( 'Background-work identity must be canonical and end with the definition\'s declared local name.' );
		}

		$kind     = $definition->kind->value;
		$existing = $this->registrations[ $identity ] ?? null;
		if ( null === $existing ) {
			$this->registrations[ $identity ] = array(
				'kind'      => $kind,
				'name'      => $definition->name,
				'execution' => $definition->execution,
				'options'   => $definition->options,
			);

			return;
		}

		if ( $kind === $existing['kind'] ) {
			throw new DuplicateRegistrationException( \sprintf( '%s name is already registered; register each background-work name exactly once.', $kind ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
		}

		// Exception values are diagnostic data, not rendered output.
		throw new \InvalidArgumentException( \sprintf( 'Background-work identity "%1$s" is already registered as a %2$s; it cannot also be registered as a %3$s.', $identity, $existing['kind'], $kind ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the execution object registered under a stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified work identity.
	 *
	 * @return  object|null
	 */
	public function execution( string $identity ): ?object {
		return $this->registrations[ $identity ]['execution'] ?? null;
	}

	/**
	 * Returns the policy declaration registered under a stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified work identity.
	 *
	 * @return  JobOptions|null
	 */
	public function options( string $identity ): ?JobOptions {
		return $this->registrations[ $identity ]['options'] ?? null;
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
}
