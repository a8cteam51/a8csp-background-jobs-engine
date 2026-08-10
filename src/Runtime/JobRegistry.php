<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\DuplicateRegistrationException;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;

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
	 * @var     array<string, JobDefinition>
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
			$this->registrations[ $key ] = $definition;

			return;
		}

		if ( $kind === $existing->kind->value ) {
			throw new DuplicateRegistrationException( \sprintf( 'Background-work identity "%1$s" is already registered as a %2$s; register each background-work name exactly once.', (string) $identity, $kind ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
		}

		// Exception values are diagnostic data, not rendered output.
		throw new \InvalidArgumentException( \sprintf( 'Background-work identity "%1$s" is already registered as a %2$s; it cannot also be registered as a %3$s.', (string) $identity, $existing->kind->value, $kind ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the registered definition only when its kind agrees with the caller.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified work identity.
	 * @param   string   $kind     Expected kind key.
	 *
	 * @return  JobDefinition|null
	 */
	public function definition_for_kind( Identity $identity, string $kind ): ?JobDefinition {
		$definition = $this->registrations[ (string) $identity ] ?? null;

		return $kind === $definition?->kind->value ? $definition : null;
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
		return ( $this->registrations[ (string) $identity ] ?? null )?->kind->value;
	}

	// endregion
}
