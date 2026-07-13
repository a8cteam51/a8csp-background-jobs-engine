<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Result;

\defined( 'ABSPATH' ) || exit;

/**
 * Successful variant of {@see AbstractResult}, carrying the operation's return value.
 *
 * After a successful predicate branch, consumers read {@see self::$value} directly without an
 * additional type check. Expected failure outcomes use {@see Failure}; unexpected failures remain
 * exceptions.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @template-covariant TValue
 * @extends AbstractResult<TValue, never>
 */
final readonly class Success extends AbstractResult {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TValue $value Return value carried by the success.
	 */
	public function __construct(
		public mixed $value
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function is_success(): bool {
		return true;
	}

	// endregion
}
