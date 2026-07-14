<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage;

\defined( 'ABSPATH' ) || exit;

/**
 * Decodes raw option rows without constructing serialized classes.
 *
 * @internal Engine storage only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class RawOptionDecoder {
	// region METHODS

	/**
	 * Returns the decoded persisted value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $raw Exact persisted option value.
	 *
	 * @return  mixed
	 */
	public static function decode( string $raw ): mixed {
		\call_user_func( 'set_error_handler', static fn (): bool => true );

		try {
			return \call_user_func( 'unserialize', $raw, array( 'allowed_classes' => false ) );
		} catch ( \Throwable ) {
			return null;
		} finally {
			\call_user_func( 'restore_error_handler' );
		}
	}

	// endregion
}
