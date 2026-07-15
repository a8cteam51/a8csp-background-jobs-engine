<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\Logging;

use A8C\SpecialProjects\BackgroundTasksEngine\Component;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\PortableArguments;

\defined( 'ABSPATH' ) || exit;

/**
 * Provides the engine's always-on log channel.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ErrorLogSink implements Component {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum array levels retained from one context value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const MAX_CONTEXT_ARRAY_DEPTH = 8;

	/**
	 * Hexadecimal characters retained from one exception trace digest.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const TRACE_HASH_LENGTH = 16;

	// endregion

	// region INHERITED METHODS

	/**
	 * Keeps the channel available on every site.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	#[\Override]
	public function is_needed(): bool {
		return true;
	}

	/**
	 * Registers the bare-install handler for the log channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public function initialize(): void {
		\add_action( 'a8csp_background_tasks/log', array( self::class, 'log' ), 10, 3 );
	}

	// endregion

	// region HOOKS

	/**
	 * Writes a log-channel event to PHP's configured error log.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $level   The log level.
	 * @param   string                  $message The log message.
	 * @param   array<array-key, mixed> $context The structured context.
	 *
	 * @return  void
	 */
	public static function log( string $level, string $message, array $context ): void {
		$line = \strtr(
			'a8csp-background-tasks-engine.' . $level . ': ' . $message,
			array(
				"\r" => '\\r',
				"\n" => '\\n',
			)
		);

		if ( array() !== $context ) {
			try {
				$encoded_context = \wp_json_encode(
					self::normalize_context( $context ),
					\JSON_THROW_ON_ERROR,
					self::MAX_CONTEXT_ARRAY_DEPTH + 1
				);
			} catch ( \Throwable ) {
				$encoded_context = false;
			}

			if ( \is_string( $encoded_context ) ) {
				$line .= ' ' . $encoded_context;
			} else {
				$line .= ' [context JSON encoding failed: use only JSON-encodable values]';
			}
		}

		try {
			\call_user_func( 'error_log', $line );
		} catch ( \Throwable ) {
			// The channel of last resort has no further fallback, so a failing error_log ends the attempt silently.
			return;
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Retains only bounded scalar context and projects the reserved exception value safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $context Structured context.
	 *
	 * @return  array<array-key, mixed>
	 */
	private static function normalize_context( array $context ): array {
		$normalized = array();
		foreach ( $context as $key => $value ) {
			// Only the PSR-3 reserved key receives the structured projection; a throwable under any other key collapses to its type.
			if ( 'exception' === $key && $value instanceof \Throwable ) {
				$normalized[ $key ] = self::normalize_exception( $value );
				continue;
			}

			if (
				null === $value
				|| \is_scalar( $value )
				|| ( \is_array( $value ) && PortableArguments::is_valid( $value, self::MAX_CONTEXT_ARRAY_DEPTH ) )
			) {
				$normalized[ $key ] = $value;
				continue;
			}

			$normalized[ $key ] = \get_debug_type( $value );
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
	private static function normalize_exception( \Throwable $throwable ): array {
		$code = $throwable->getCode();
		if ( ! \is_int( $code ) && ! \is_string( $code ) ) {
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
