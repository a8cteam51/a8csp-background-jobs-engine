<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\DuplicateRegistrationException;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;

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
	 * @var     array<string, array{kind: string, execution: object, options: JobOptions}>
	 */
	private array $registrations = array();

	// endregion

	// region METHODS

	/**
	 * Registers one definition without replacing an existing identity scope.
	 *
	 * Execution compatibility is established by the resolved kind handler before this data-only
	 * registry is called.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity      $identity   Complete scope-qualified identity.
	 * @param   JobDefinition $definition Definition to register.
	 *
	 * @throws  \InvalidArgumentException      When the identity and definition name disagree, or another kind owns the identity.
	 * @throws  DuplicateRegistrationException When the definition kind already owns the identity.
	 *
	 * @return  void
	 */
	public function register( Identity $identity, JobDefinition $definition ): void {
		Identity::validate_name( $definition->name );
		if ( $definition->name !== $identity->name() ) {
			throw new \InvalidArgumentException( 'Background-work identity must be canonical and end with the definition\'s declared local name.' );
		}

		$key      = (string) $identity;
		$kind     = $definition->kind->value;
		$existing = $this->registrations[ $key ] ?? null;
		if ( null === $existing ) {
			$this->registrations[ $key ] = array(
				'kind'      => $kind,
				'execution' => $definition->execution,
				'options'   => $definition->options,
			);

			return;
		}

		if ( $kind === $existing['kind'] ) {
			throw new DuplicateRegistrationException( \sprintf( 'Background-work identity "%1$s" is already registered as a %2$s; register each background-work name exactly once.', (string) $identity, $kind ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
		}

		// Exception values are diagnostic data, not rendered output.
		throw new \InvalidArgumentException( \sprintf( 'Background-work identity "%1$s" is already registered as a %2$s; it cannot also be registered as a %3$s.', (string) $identity, $existing['kind'], $kind ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the execution object registered under a stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified work identity.
	 *
	 * @return  object|null
	 */
	public function execution( Identity $identity ): ?object {
		return $this->registrations[ (string) $identity ]['execution'] ?? null;
	}

	/**
	 * Returns the policy declaration registered under a stable identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified work identity.
	 *
	 * @return  JobOptions|null
	 */
	public function options( Identity $identity ): ?JobOptions {
		return $this->registrations[ (string) $identity ]['options'] ?? null;
	}

	/**
	 * Returns the policy declaration for untrusted scheduler-wire identity bytes when its kind matches.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Raw scheduler-wire identity bytes.
	 * @param   string $kind     Persisted kind key.
	 *
	 * @return  JobOptions|null
	 */
	public function raw_options( string $identity, string $kind ): ?JobOptions {
		$registration = $this->registrations[ $identity ] ?? null;

		return ( $registration['kind'] ?? null ) === $kind ? $registration['options'] : null;
	}

	/**
	 * Returns the registered kind for one complete identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified identity.
	 *
	 * @return  string|null
	 */
	public function kind( Identity $identity ): ?string {
		return $this->registrations[ (string) $identity ]['kind'] ?? null;
	}

	// endregion
}
