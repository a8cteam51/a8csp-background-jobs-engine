<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

\defined( 'ABSPATH' ) || exit;

/**
 * Identifies engine-authored validation failures at the client chunked-job-context boundary.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class InvalidChunkException extends \InvalidArgumentException {
	// region FIELDS AND CONSTANTS

	/**
	 * Stable validation detail safe for operator-facing failure retention.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string MESSAGE = 'Chunked Job chunk arguments must contain only null, scalar, or nested array values.';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $message Stable engine-authored validation detail.
	 */
	private function __construct( string $message ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Private visibility restricts construction to engine-authored factories.
		parent::__construct( $message );
	}

	// endregion

	// region METHODS

	/**
	 * Creates the stable portable-payload validation failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function non_portable(): self {
		return new self( self::MESSAGE );
	}

	/**
	 * Creates a stable chunk-size validation failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $actual Actual encoded JSON byte count.
	 * @param   int $limit  Maximum encoded JSON byte count.
	 *
	 * @return  self
	 */
	public static function chunk_too_large( int $actual, int $limit ): self {
		return new self( \sprintf( 'Chunked Job chunk arguments contain %1$d JSON bytes; the limit is %2$d bytes.', $actual, $limit ) );
	}

	/**
	 * Creates a stable queue-size validation failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $actual Actual persisted serialization byte count.
	 * @param   int $limit  Maximum persisted serialization byte count.
	 *
	 * @return  self
	 */
	public static function queue_too_large( int $actual, int $limit ): self {
		return new self( \sprintf( 'Chunked Job queue contains %1$d persisted serialization bytes; the limit is %2$d bytes.', $actual, $limit ) );
	}

	// endregion
}
