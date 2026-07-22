<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Internal\Error;

use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;

\defined( 'ABSPATH' ) || exit;

/**
 * Client-visible failure returned when a background-work operation cannot complete.
 *
 * The message is engine-authored and excludes raw client exception text. Context contains only
 * structured detail safe for client diagnostics.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ApiError implements ErrorInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ErrorCode            $code    Stable machine-readable classification.
	 * @param   string               $message Engine-authored corrective detail.
	 * @param   array<string, mixed> $context Structured redaction-safe detail.
	 */
	public function __construct(
		public ErrorCode $code,
		public string $message,
		public array $context = array(),
	) {}

	// endregion
}
