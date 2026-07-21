<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Logging;

use A8C\SpecialProjects\BackgroundJobsEngine\Internal\PortableArguments;

\defined( 'ABSPATH' ) || exit;

/**
 * Provides the engine's bare-install error-log fallback.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ErrorLogSink {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum array levels retained from one context value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_CONTEXT_ARRAY_DEPTH = 8;

	// endregion

	// region METHODS

	/**
	 * Registers the bare-install handler for the log channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public static function register(): void {
		/**
		 * Filters whether engine log events are written to PHP's configured error log.
		 *
		 * @since   1.0.0
		 * @version 1.0.0
		 *
		 * @param   bool $log_to_error_log Whether to register the default error-log handler.
		 */
		$log_to_error_log = \apply_filters( 'a8csp_jobs_engine/log_to_error_log', true );
		if ( false === $log_to_error_log ) {
			return;
		}

		\add_action( 'a8csp_jobs_engine/log', array( self::class, 'log' ), 10, 3 );
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
			'a8csp-background-jobs-engine.' . $level . ': ' . $message,
			array(
				"\r" => '\\r',
				"\n" => '\\n',
			)
		);

		if ( array() !== $context ) {
			try {
				$encoded_context = \wp_json_encode( self::normalize_context( $context ), \JSON_THROW_ON_ERROR, self::MAX_CONTEXT_ARRAY_DEPTH + 1 );
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
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The bare-install fallback writes engine events to PHP's configured error log.
			\error_log( $line );
		} catch ( \Throwable ) {
			// The channel of last resort has no further fallback, so a failing error_log ends the attempt silently.
			return;
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Retains bounded JSON-safe context, projecting a raw reserved exception value when invoked directly.
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
			if ( 'exception' === $key && $value instanceof \Throwable ) {
				$normalized[ $key ] = ThrowableContextNormalizer::project( $value );
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
	// endregion
}
