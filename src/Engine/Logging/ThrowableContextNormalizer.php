<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Logging;

\defined( 'ABSPATH' ) || exit;

/**
 * Projects top-level throwable context into message-free correlation fields.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ThrowableContextNormalizer {
	// region FIELDS AND CONSTANTS

	/**
	 * Hexadecimal characters retained from one exception trace digest.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int TRACE_HASH_LENGTH = 16;

	// endregion

	// region METHODS

	/**
	 * Returns context with every top-level throwable projected to redacted fields.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $context Structured context.
	 *
	 * @return  array<array-key, mixed>
	 */
	public static function normalize( array $context ): array {
		$normalized = array();
		foreach ( $context as $key => $value ) {
			$normalized[ $key ] = $value instanceof \Throwable ? self::project( $value ) : $value;
		}

		return $normalized;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns message-free exception fields for host-log correlation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Throwable $throwable Caught exception or error.
	 *
	 * @return  array{class: string, code: int|string, file: string, trace_hash: string}
	 */
	public static function project( \Throwable $throwable ): array {
		$code = $throwable->getCode();
		if ( ! \is_int( $code ) ) {
			$code = \get_debug_type( $code );
		}

		return array(
			'class'      => \get_debug_type( $throwable ),
			'code'       => $code,
			'file'       => \basename( $throwable->getFile() ) . ':' . $throwable->getLine(),
			'trace_hash' => \substr( \hash( 'sha256', $throwable->getTraceAsString() ), 0, self::TRACE_HASH_LENGTH ),
		);
	}

	// endregion
}
