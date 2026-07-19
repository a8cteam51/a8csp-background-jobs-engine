<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output;

\defined( 'ABSPATH' ) || exit;

/**
 * Defines the machine-readable and operator-readable CLI output formats.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Format {
	// region FIELDS AND CONSTANTS

	/**
	 * Formats accepted by every list command.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     list<string>
	 */
	private const array SUPPORTED = array( 'table', 'csv', 'json', 'count', 'yaml' );

	// endregion

	// region METHODS

	/**
	 * Returns whether a command argument names one supported format.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-assert-if-true string $format
	 *
	 * @param   mixed $format Candidate format.
	 *
	 * @return  bool
	 */
	public static function is_supported( mixed $format ): bool {
		return \is_string( $format ) && \in_array( $format, self::SUPPORTED, true );
	}

	// endregion
}
