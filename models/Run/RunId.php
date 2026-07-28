<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Immutable public wrapper for one canonical run identifier.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RunId implements \Stringable {
	// region FIELDS AND CONSTANTS

	/** Mirrors `Runtime\Runs\RunIdentity::LENGTH`. */
	private const int LENGTH = 40;

	/** Mirrors `Runtime\Runs\RunIdentity::TIME_DIGITS` and `Runtime\Runs\RunIdentity::RANDOM_DIGITS`. */
	private const string PATTERN = '/\A[0-9]{20}-[0-9]{19}\z/D';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $value Canonical wire value.
	 */
	private function __construct(
		private string $value,
	) {}

	/**
	 * Returns the canonical wire value.
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

	// region METHODS

	/**
	 * Wraps one canonical run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $value Run identifier candidate.
	 *
	 * @throws  \ValueError When the candidate is not canonical.
	 *
	 * @return  self
	 */
	public static function from( string $value ): self {
		$id = self::tryFrom( $value );
		if ( null === $id ) {
			throw new \ValueError( 'Run identifier must match the canonical shape "%020d-%019d": 20 decimal timestamp digits, a dash, and 19 decimal random digits.' );
		}

		return $id;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the backed-enum API.
	/**
	 * Wraps one canonical run identifier, or returns null for another shape.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $value Run identifier candidate.
	 *
	 * @return  self|null
	 */
	public static function tryFrom( string $value ): ?self {
		if ( self::LENGTH !== \strlen( $value ) || 1 !== \preg_match( self::PATTERN, $value ) ) {
			return null;
		}

		return new self( $value );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// endregion
}
