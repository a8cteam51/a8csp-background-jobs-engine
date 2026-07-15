<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * Adapts the internal PSR-3 seam to the engine's public log hook.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class HookLogger extends AbstractLogger {
	// region INHERITED METHODS

	/**
	 * Dispatches a log event after interpolating supported PSR-3 placeholders.
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
			if ( ! \is_scalar( $value ) && ! $value instanceof \Stringable ) {
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
			\do_action( 'a8csp_background_tasks/log', $rendered_level, $rendered_message, $context );
		} catch ( \Throwable $throwable ) {
			try {
				$breadcrumb = \strtr(
					\sprintf(
						'a8csp-background-tasks-engine: log dispatch failed [hook=%s] [level=%s] [exception=%s] %s',
						'a8csp_background_tasks/log',
						$rendered_level,
						\get_debug_type( $throwable ),
						$throwable->getMessage()
					),
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
}
