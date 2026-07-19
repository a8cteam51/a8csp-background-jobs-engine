<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage;

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
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Malformed persisted rows must decode without emitting PHP warnings.
		\set_error_handler( static fn (): bool => true );

		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WordPress options use PHP serialization and class construction is disabled here.
			return \unserialize( $raw, array( 'allowed_classes' => false ) );
		} catch ( \Throwable ) {
			return null;
		} finally {
			\restore_error_handler();
		}
	}

	// endregion
}
