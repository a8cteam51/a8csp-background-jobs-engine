<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job;

\defined( 'ABSPATH' ) || exit;

/**
 * Identifies an engine-owned background-work kind.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class JobKind {
	// region FIELDS AND CONSTANTS

	/**
	 * Lexical grammar shared by installed and prospective kind keys.
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
	 * @param   string $value Opaque grammar-valid kind key.
	 */
	private function __construct(
		public string $value,
	) {}

	// endregion

	// region NAMED CONSTRUCTORS

	/**
	 * Returns the standard job kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function job(): self {
		return new self( 'job' );
	}

	/**
	 * Returns the chunked job kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function chunked_job(): self {
		return new self( 'chunked_job' );
	}

	/**
	 * Wraps one grammar-valid engine kind key.
	 *
	 * Only engine-installed kinds can be registered today. This constructor exposes the public
	 * registration data path so a future engine-installed kind remains a leaf addition; it does not
	 * install a handler or expose the internal handler SPI.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $value Opaque kind key.
	 *
	 * @throws  \InvalidArgumentException When the key violates the kind grammar.
	 *
	 * @return  self
	 */
	public static function from( string $value ): self {
		if ( 1 !== \preg_match( self::PATTERN, $value ) ) {
			throw new \InvalidArgumentException( 'Job kind must match [a-z][a-z0-9_]*(.[a-z][a-z0-9_]*)?.' );
		}

		return new self( $value );
	}

	// endregion
}
