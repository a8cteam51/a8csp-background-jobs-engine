<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Internal\Result;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error\ErrorInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Failed variant of {@see AbstractResult}, carrying an expected error as data.
 *
 * After a failed predicate branch, clients read {@see self::$error} directly without an
 * additional type check. Exceptions remain reserved for unexpected infrastructure failures and
 * programmer errors rather than expected domain outcomes.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @template TError of ErrorInterface
 * @extends AbstractResult<never, TError>
 */
final readonly class Failure extends AbstractResult {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   TError $error Error carried by the failure.
	 *
	 * @throws  \LogicException When the supplied value does not implement the error contract.
	 */
	public function __construct(
		public mixed $error,
	) {
		if ( ! $this->error instanceof ErrorInterface ) {
			throw new \LogicException( 'A failed result requires an error implementing ErrorInterface.' );
		}
	}

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
		return false;
	}

	// endregion
}
