<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api;

\defined( 'ABSPATH' ) || exit;

/**
 * Enforces shared command and schedule admission boundaries.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class AdmissionValidator {
	// region FIELDS AND CONSTANTS

	/**
	 * Highest scheduler priority accepted by admission contracts.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const MAX_PRIORITY = 255;

	// endregion

	// region METHODS

	/**
	 * Asserts that a scheduler priority fits the supported byte range.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int    $priority Priority to validate.
	 * @param   string $context  Concept-specific exception context.
	 *
	 * @throws  \InvalidArgumentException When the priority is outside the supported range.
	 *
	 * @return  void
	 */
	public static function assert_priority( int $priority, string $context ): void {
		if ( 0 <= $priority && self::MAX_PRIORITY >= $priority ) {
			return;
		}

		// Exception values are diagnostic data, not rendered output.
		throw new \InvalidArgumentException( \sprintf( '%1$s priority %2$d is invalid; pass a value from 0 through %3$d.', $context, $priority, self::MAX_PRIORITY ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Asserts that arguments form a portable JSON-encodable tree.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args    Arguments to validate.
	 * @param   string                  $context Concept-specific exception context.
	 *
	 * @throws  \InvalidArgumentException When the arguments are not portable and JSON-encodable.
	 *
	 * @return  void
	 */
	public static function assert_portable_args( array $args, string $context ): void {
		try {
			$encoded_args = \wp_json_encode( $args, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION );
		} catch ( \JsonException ) {
			$encoded_args = false;
		}

		if ( \is_string( $encoded_args ) && PortableArguments::is_valid( $args ) ) {
			return;
		}

		// Exception values are diagnostic data, not rendered output.
		throw new \InvalidArgumentException( \sprintf( '%s arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.', $context ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	// endregion
}
