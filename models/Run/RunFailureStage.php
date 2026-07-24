<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Run;

\defined( 'ABSPATH' ) || exit;

/**
 * Stable terminalization stage exposed by a client-visible run failure.
 *
 * Engine and third-party stages share the persisted kind and lifecycle-stage key grammar.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RunFailureStage {
	// region FIELDS AND CONSTANTS

	/**
	 * Lexical grammar for persisted kind and lifecycle stage keys.
	 *
	 * The public-model copy mirrors `Runtime\Runs\Kinds\KindHandlerInterface::KEY_PATTERN` because
	 * models do not import Runtime internals.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const string PATTERN = '/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?\z/';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $value Persisted terminalization stage.
	 */
	private function __construct(
		public string $value,
	) {}

	/**
	 * Prevents copies that would violate identity-stable stage comparisons.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function __clone(): void {}

	// endregion

	// region FACTORIES

	/**
	 * Returns the client-work execution stage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function execution(): self {
		return self::from( 'execution' );
	}

	/**
	 * Returns the chunked-job queue-generation stage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function queue_generation(): self {
		return self::from( 'queue_generation' );
	}

	/**
	 * Returns the crash-reclamation stage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function crash_reclamation(): self {
		return self::from( 'crash_reclamation' );
	}

	/**
	 * Returns the lifecycle-action scheduling stage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function scheduling(): self {
		return self::from( 'scheduling' );
	}

	/**
	 * Wraps one grammar-valid terminalization stage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $value Terminalization stage candidate.
	 *
	 * @throws  \ValueError When the candidate is lexically malformed.
	 *
	 * @return  self
	 */
	public static function from( string $value ): self {
		$stage = self::tryFrom( $value );
		if ( null === $stage ) {
			throw new \ValueError( 'Run failure stage must use lowercase snake segments with at most one dot qualifier.' );
		}

		return $stage;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the backed-enum API.
	/**
	 * Wraps one grammar-valid terminalization stage, or returns null for another shape.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $value Terminalization stage candidate.
	 *
	 * @return  self|null
	 */
	public static function tryFrom( string $value ): ?self {
		if ( 1 !== \preg_match( self::PATTERN, $value ) ) {
			return null;
		}

		/**
		 * Interned instances indexed by their exact persisted value.
		 *
		 * @var array<string, self> $instances
		 */
		static $instances = array();

		// Process-local interning preserves identity comparisons for repeated stage values; comparisons
		// across serialization use ->value.
		return $instances[ $value ] ??= new self( $value );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// endregion
}
