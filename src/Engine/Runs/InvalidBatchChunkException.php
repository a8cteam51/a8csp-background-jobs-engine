<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs;

\defined( 'ABSPATH' ) || exit;

/**
 * Identifies engine-authored validation failures at the consumer batch-context boundary.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class InvalidBatchChunkException extends \InvalidArgumentException {
	// region FIELDS AND CONSTANTS

	/**
	 * Stable validation detail safe for operator-facing failure retention.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string MESSAGE = 'Batch chunk arguments must contain only null, scalar, or nested array values.';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function __construct() {
		parent::__construct( self::MESSAGE );
	}

	// endregion
}
