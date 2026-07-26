<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Boundary;

\defined( 'ABSPATH' ) || exit;

/**
 * Wraps one canonical scope-qualified background-work identity.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class Identity implements \Stringable {
	// region FIELDS AND CONSTANTS

	/**
	 * Client-scope ceiling paired with `NAME_MAX_BYTES` so the longest composed identity leaves the
	 * 24-byte overlap-lock prefix, separator, and 64-byte single-flight hash inside WordPress's
	 * 191-character `option_name` boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int SCOPE_MAX_BYTES = 32;

	/**
	 * Local-name ceiling paired with `SCOPE_MAX_BYTES` under the same WordPress `option_name` limit.
	 *
	 * `Schedule::MAX_NAME_BYTES` mirrors this boundary-owned limit because the frozen
	 * public model keeps its constant private.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int NAME_MAX_BYTES = 64;

	/**
	 * Longest `{scope}:{name}` identity admitted by the component ceilings.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int IDENTITY_MAX_BYTES = self::SCOPE_MAX_BYTES + 1 + self::NAME_MAX_BYTES;

	/**
	 * Scope namespace retained exclusively for engine work.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string ENGINE_SCOPE = 'a8csp-bgje';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $value Complete scope-qualified background-work identity.
	 */
	private function __construct(
		private readonly string $value,
	) {}

	/**
	 * Returns the complete scope-qualified background-work identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function __toString(): string {
		return $this->value;
	}

	// endregion

	// region FACTORY METHODS

	/**
	 * Composes one canonical background-work identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope                 Client or engine scope.
	 * @param   string $name                  Scope-local work name.
	 * @param   bool   $allow_engine_reserved Whether the engine-reserved namespace is accepted.
	 *
	 * @throws  \InvalidArgumentException When the scope or name violates the canonical grammar.
	 *
	 * @return  self
	 */
	public static function compose( string $scope, string $name, bool $allow_engine_reserved = false ): self {
		self::validate_scope( $scope, $allow_engine_reserved );
		self::validate_name( $name );

		$identity = $scope . ':' . $name;
		if ( self::IDENTITY_MAX_BYTES < \strlen( $identity ) ) {
			throw new \InvalidArgumentException( \sprintf( 'Background-work identity must be at most %d bytes; shorten the scope or name.', self::IDENTITY_MAX_BYTES ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return new self( $identity );
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the backed-enum API.
	/**
	 * Wraps one canonical background-work identity, or returns null for another shape.
	 *
	 * Reserved engine identities are valid persisted identities even though clients cannot claim
	 * their scope prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete `{scope}:{name}` identity.
	 *
	 * @return  self|null
	 */
	public static function tryFrom( string $identity ): ?self {
		if ( 1 !== \substr_count( $identity, ':' ) || self::IDENTITY_MAX_BYTES < \strlen( $identity ) ) {
			return null;
		}

		[ $scope, $name ] = \explode( ':', $identity, 2 );
		try {
			self::validate_scope( $scope, true );
			self::validate_name( $name );
		} catch ( \InvalidArgumentException ) {
			return null;
		}

		return new self( $identity );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// endregion

	// region METHODS

	/**
	 * Returns the client or engine scope.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function scope(): string {
		[ $scope ] = \explode( ':', $this->value, 2 );

		return $scope;
	}

	/**
	 * Returns the scope-local work name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function name(): string {
		[ , $name ] = \explode( ':', $this->value, 2 );

		return $name;
	}

	/**
	 * Validates one client or engine scope.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope                 Client or engine scope.
	 * @param   bool   $allow_engine_reserved Whether the engine-reserved namespace is accepted.
	 *
	 * @throws  \InvalidArgumentException When the scope violates the canonical grammar.
	 *
	 * @return  void
	 */
	public static function validate_scope( string $scope, bool $allow_engine_reserved = false ): void {
		if (
			1 !== \preg_match( '/\A[a-z0-9][a-z0-9-]*\z/D', $scope )
			|| self::SCOPE_MAX_BYTES < \strlen( $scope )
		) {
			throw new \InvalidArgumentException( 'Background-work scope is invalid; pass 1 to 32 bytes matching [a-z0-9][a-z0-9-]*.' );
		}

		if ( ! $allow_engine_reserved && \str_starts_with( $scope, self::ENGINE_SCOPE ) ) {
			throw new \InvalidArgumentException( 'Background-work scope uses the engine-reserved "a8csp-bgje" prefix; use the client plugin slug.' );
		}
	}

	/**
	 * Validates one scope-local job, chunked job, or schedule name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Scope-local name.
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
}
