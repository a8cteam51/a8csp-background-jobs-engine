<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging;

\defined( 'ABSPATH' ) || exit;

/**
 * Projects throwable values encountered during a bounded array traversal into message-free correlation fields.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ThrowableContextNormalizer {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum array levels traversed while redacting one context value.
	 *
	 * Redaction traverses arbitrary consumer-shaped context, so this bounds stack growth and the
	 * work a reference-sharing graph can multiply out. It sits deliberately above EngineLogger's
	 * smaller transport bound, which stays the authority on what the host-log record retains: an
	 * equal budget would truncate every over-deep value here first and leave that narrower bound
	 * unable to reject anything.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_TRAVERSAL_DEPTH = 16;

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
	 * Returns context with encountered throwable array elements projected and over-deep array subtrees replaced.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $context Structured context.
	 *
	 * @return  array<array-key, mixed>
	 */
	public static function normalize( array $context ): array {
		$active_reference_ids = array();

		return self::normalize_array( $context, $active_reference_ids, self::MAX_TRAVERSAL_DEPTH );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns a recursively normalized array with cyclic reference edges and over-deep subtrees made finite.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $values               Values to normalize.
	 * @param   array<string, true>     $active_reference_ids Reference containers on the current traversal path.
	 * @param   int                     $remaining_depth      Array levels still permitted.
	 *
	 * @return  array<array-key, mixed>
	 */
	private static function normalize_array( array $values, array &$active_reference_ids, int $remaining_depth ): array {
		$normalized = array();
		foreach ( $values as $key => $value ) {
			if ( $value instanceof \Throwable ) {
				$normalized[ $key ] = self::project( $value );
				continue;
			}

			if ( ! \is_array( $value ) ) {
				$normalized[ $key ] = $value;
				continue;
			}

			if ( 1 > $remaining_depth ) {
				$normalized[ $key ] = \get_debug_type( $value );
				continue;
			}

			$reference = \ReflectionReference::fromArrayElement( $values, $key );
			if ( null === $reference ) {
				$normalized[ $key ] = self::normalize_array( $value, $active_reference_ids, $remaining_depth - 1 );
				continue;
			}

			$reference_id = $reference->getId();
			if ( isset( $active_reference_ids[ $reference_id ] ) ) {
				// Cutting only the recursive edge keeps the payload finite while the active frame still redacts every reachable value.
				$normalized[ $key ] = array();
				continue;
			}

			$active_reference_ids[ $reference_id ] = true;
			try {
				$normalized[ $key ] = self::normalize_array( $value, $active_reference_ids, $remaining_depth - 1 );
			} finally {
				unset( $active_reference_ids[ $reference_id ] );
			}
		}

		return $normalized;
	}

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
