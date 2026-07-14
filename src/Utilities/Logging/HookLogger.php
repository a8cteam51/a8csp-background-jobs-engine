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
		\do_action(
			'a8csp_background_tasks/log',
			(string) $level,
			\strtr( (string) $message, $replacements ),
			$context
		);
	}

	// endregion
}
