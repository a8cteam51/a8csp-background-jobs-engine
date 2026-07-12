<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Provides the engine's always-on log channel.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class Log implements Component {
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
		\add_action( 'a8csp/background_tasks/log', array( self::class, 'log' ), 10, 3 );
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
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON exceptions keep encoding failures on the original log line.
				$encoded_context = \json_encode( $context, \JSON_THROW_ON_ERROR );
				$line           .= ' ' . $encoded_context;
			} catch ( \Throwable ) {
				$line .= ' [context JSON encoding failed: use only JSON-encodable values]';
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A bare install deliberately routes the engine's only log channel to PHP's configured error log.
		\error_log( $line );
	}

	// endregion
}
