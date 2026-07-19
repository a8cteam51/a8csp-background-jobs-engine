<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api\Result;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\ErrorInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Sealed-type base for an operation outcome: either a {@see Success} or a {@see Failure}.
 *
 * Clients branch with {@see self::is_success()} or {@see self::is_failure()}, then read the
 * narrowed variant's public payload. Expected failures remain data in a result; unexpected
 * infrastructure failures and programmer errors remain exceptions.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @template-covariant TValue
 * @template-covariant TError of ErrorInterface
 *
 * @phpstan-sealed Success|Failure
 */
abstract readonly class AbstractResult {
	// region METHODS

	/**
	 * Returns whether the result is a success.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 *
	 * @phpstan-assert-if-true Success<TValue> $this
	 * @phpstan-assert-if-false Failure<TError> $this
	 */
	abstract public function is_success(): bool;

	/**
	 * Returns whether the result is a failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 *
	 * @phpstan-assert-if-true Failure<TError> $this
	 * @phpstan-assert-if-false Success<TValue> $this
	 */
	public function is_failure(): bool {
		return ! $this->is_success();
	}

	// endregion
}
