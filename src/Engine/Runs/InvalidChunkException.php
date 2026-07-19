<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs;

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
	public function __construct( string $message = self::MESSAGE ) {
		parent::__construct( '' === $message ? self::MESSAGE : $message );
	}

	// endregion
}
