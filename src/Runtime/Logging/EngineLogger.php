<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Logging;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

\defined( 'ABSPATH' ) || exit;

/**
 * Emits engine log events and provides the bare-install error-log fallback.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class EngineLogger extends AbstractLogger {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum array levels retained from one context value in the default sink.
	 *
	 * It matches ThrowableContextNormalizer's traversal bound; subscribers and the host log share one nesting budget.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_CONTEXT_ARRAY_DEPTH = 8;

	/**
	 * PSR-3 severities ordered from most to least severe.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, int>
	 */
	private const array LOG_LEVEL_PRIORITIES = array(
		LogLevel::EMERGENCY => 0,
		LogLevel::ALERT     => 1,
		LogLevel::CRITICAL  => 2,
		LogLevel::ERROR     => 3,
		LogLevel::WARNING   => 4,
		LogLevel::NOTICE    => 5,
		LogLevel::INFO      => 6,
		LogLevel::DEBUG     => 7,
	);

	// endregion

	// region INHERITED METHODS

	/**
	 * Writes the default record and then publishes an interpolated log event.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed                   $level   The log level.
	 * @param   string|\Stringable      $message The log message.
	 * @param   array<array-key, mixed> $context The structured context.
	 *
	 * @throws  InvalidArgumentException When the log level cannot be represented as a string.
	 *
	 * @return  void
	 */
	#[\Override]
	public function log( mixed $level, string|\Stringable $message, array $context = array() ): void {
		if ( ! \is_scalar( $level ) && ! $level instanceof \Stringable ) {
			throw new InvalidArgumentException( 'Use a scalar or Stringable PSR-3 log level.' );
		}

		// A rendering-safe placeholder lets the containment breadcrumb name the level even when the Stringable cast itself throws.
		$rendered_level = $level instanceof \Stringable ? '<unrenderable:' . \get_debug_type( $level ) . '>' : (string) $level;

		$replacements = array();
		foreach ( $context as $key => $value ) {
			if ( $value instanceof \Throwable || ( ! \is_scalar( $value ) && ! $value instanceof \Stringable ) ) {
				continue;
			}

			try {
				$replacements[ '{' . $key . '}' ] = (string) $value;
			} catch ( \Throwable ) {
				// A log call must never throw because one context placeholder cannot be rendered.
				continue;
			}
		}

		try {
			$rendered_level   = (string) $level;
			$rendered_message = \strtr( (string) $message, $replacements );
			$rendered_context = ThrowableContextNormalizer::normalize( $context );

			/**
			 * Filters whether this engine log event is written to PHP's configured error log.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   bool $log_to_error_log Whether to write this event to the default error-log sink.
			 */
			try {
				$log_to_error_log = \apply_filters( 'a8csp_bgje/log_to_error_log', true );
			} catch ( \Throwable ) {
				// A failing consumer gate falls back to the enabled engine sink so third-party code cannot suppress the event.
				$log_to_error_log = true;
			}

			if ( false !== $log_to_error_log ) {
				/**
				 * Filters the least severe PSR-3 level written to PHP's configured error log.
				 *
				 * @since   1.0.0
				 * @version 1.0.0
				 *
				 * @param   string $error_log_level The default sink's least severe included level.
				 */
				try {
					$error_log_level = \apply_filters( 'a8csp_bgje/error_log_level', LogLevel::WARNING );
				} catch ( \Throwable ) {
					// A failing consumer floor falls back to warning so third-party code cannot suppress warning-or-higher records.
					$error_log_level = LogLevel::WARNING;
				}

				if ( self::should_write_to_error_log( $rendered_level, $error_log_level ) ) {
					self::write_to_error_log( $rendered_level, $rendered_message, $rendered_context );
				}
			}

			// The engine-owned record precedes external subscribers because WordPress action dispatch does not contain callback exceptions.
			/**
			 * Fires when the engine emits a log event.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $level   The log level.
			 * @param   string                  $message The interpolated log message.
			 * @param   array<array-key, mixed> $context The structured context.
			 */
			\do_action( 'a8csp_bgje/log', $rendered_level, $rendered_message, $rendered_context );
		} catch ( \Throwable $throwable ) {
			try {
				$breadcrumb = \strtr(
					\sprintf( 'a8csp-background-jobs-engine: log dispatch failed [hook=%s] [level=%s] [exception=%s]', 'a8csp_bgje/log', $rendered_level, \get_debug_type( $throwable ) ),
					array(
						"\0" => '\\0',
						"\r" => '\\r',
						"\n" => '\\n',
					)
				);

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The fallback bypasses the failed log hook to prevent recursion.
				\error_log( $breadcrumb );
			} catch ( \Throwable ) {
				// A log call must never throw because its fallback channel is unavailable.
				return;
			}
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Returns whether the default sink includes one rendered level.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $level           Rendered event level.
	 * @param   mixed  $error_log_level Filtered sink floor.
	 *
	 * @return  bool
	 */
	private static function should_write_to_error_log( string $level, mixed $error_log_level ): bool {
		if ( ! isset( self::LOG_LEVEL_PRIORITIES[ $level ] ) ) {
			return true;
		}

		if ( ! \is_string( $error_log_level ) || ! isset( self::LOG_LEVEL_PRIORITIES[ $error_log_level ] ) ) {
			$error_log_level = LogLevel::WARNING;
		}

		return self::LOG_LEVEL_PRIORITIES[ $level ] <= self::LOG_LEVEL_PRIORITIES[ $error_log_level ];
	}

	/**
	 * Writes a rendered event to PHP's configured error log.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $level   Rendered event level.
	 * @param   string                  $message Rendered event message.
	 * @param   array<array-key, mixed> $context Redacted event context.
	 *
	 * @return  void
	 */
	private static function write_to_error_log( string $level, string $message, array $context ): void {
		$line = \strtr(
			'a8csp-background-jobs-engine.' . $level . ': ' . $message,
			array(
				"\0" => '\\0',
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

	/**
	 * Retains bounded JSON-safe context for the default transport.
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
