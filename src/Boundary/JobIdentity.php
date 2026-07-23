<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Boundary;

\defined( 'ABSPATH' ) || exit;

/**
 * Validates and composes owner-qualified background-work identities.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class JobIdentity {
	// region FIELDS AND CONSTANTS

	/**
	 * Client-owner ceiling paired with `NAME_MAX_BYTES` so the longest composed identity leaves the
	 * 24-byte overlap-lock prefix, separator, and 64-byte single-flight hash inside WordPress's
	 * 191-character `option_name` boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int OWNER_MAX_BYTES = 32;

	/**
	 * Local-name ceiling paired with `OWNER_MAX_BYTES` under the same WordPress `option_name` limit.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int NAME_MAX_BYTES = 64;

	/**
	 * Longest `{owner}:{name}` identity admitted by the component ceilings.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int IDENTITY_MAX_BYTES = self::OWNER_MAX_BYTES + 1 + self::NAME_MAX_BYTES;

	/**
	 * Owner namespace retained exclusively for engine work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string ENGINE_OWNER = 'a8csp-bgje';

	// endregion

	// region METHODS

	/**
	 * Composes one canonical background-work identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner                 Client or engine owner.
	 * @param   string $name                  Owner-local work name.
	 * @param   bool   $allow_engine_reserved Whether the engine-reserved namespace is accepted.
	 *
	 * @throws  \InvalidArgumentException When the owner or name violates the canonical grammar.
	 *
	 * @return  string
	 */
	public static function compose( string $owner, string $name, bool $allow_engine_reserved = false ): string {
		self::validate_owner( $owner, $allow_engine_reserved );
		self::validate_name( $name );

		$identity = $owner . ':' . $name;
		if ( self::IDENTITY_MAX_BYTES < \strlen( $identity ) ) {
			throw new \InvalidArgumentException( \sprintf( 'Background-work identity must be at most %d bytes; shorten the owner or name.', self::IDENTITY_MAX_BYTES ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $identity;
	}

	/**
	 * Validates one client or engine owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner                 Client or engine owner.
	 * @param   bool   $allow_engine_reserved Whether the engine-reserved namespace is accepted.
	 *
	 * @throws  \InvalidArgumentException When the owner violates the canonical grammar.
	 *
	 * @return  void
	 */
	public static function validate_owner( string $owner, bool $allow_engine_reserved = false ): void {
		if (
			1 !== \preg_match( '/\A[a-z0-9][a-z0-9-]*\z/D', $owner )
			|| self::OWNER_MAX_BYTES < \strlen( $owner )
		) {
			throw new \InvalidArgumentException( 'Background-work owner is invalid; pass 1 to 32 bytes matching [a-z0-9][a-z0-9-]*.' );
		}

		if ( ! $allow_engine_reserved && \str_starts_with( $owner, self::ENGINE_OWNER ) ) {
			throw new \InvalidArgumentException( 'Background-work owner uses the engine-reserved "a8csp-bgje" prefix; use the client plugin slug.' );
		}
	}

	/**
	 * Validates one owner-local job, chunked job, or schedule name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local name.
	 *
	 * @throws  \InvalidArgumentException When the name violates the canonical grammar.
	 *
	 * @return  void
	 */
	public static function validate_name( string $name ): void {
		if (
			1 !== \preg_match( '/\A[a-z0-9_-]+\z/D', $name )
			|| self::NAME_MAX_BYTES < \strlen( $name )
		) {
			throw new \InvalidArgumentException( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the validated owner and local name from one complete identity.
	 *
	 * Reserved engine identities are valid persisted identities even though clients cannot claim
	 * their owner prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete `{owner}:{name}` identity.
	 *
	 * @return  array{string, string}|null
	 */
	public static function parts( string $identity ): ?array {
		if ( 1 !== \substr_count( $identity, ':' ) || self::IDENTITY_MAX_BYTES < \strlen( $identity ) ) {
			return null;
		}

		[ $owner, $name ] = \explode( ':', $identity, 2 );
		try {
			self::validate_owner( $owner, true );
			self::validate_name( $name );
		} catch ( \InvalidArgumentException ) {
			return null;
		}

		return array( $owner, $name );
	}

	// endregion
}
