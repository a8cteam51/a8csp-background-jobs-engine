<?php declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Returns the hash of the provided arguments.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   array $args The arguments to hash.
 *
 * @return  string
 */
function a8csp_bgt_hash_task_args( array $args ): string {
	return hash( 'md5', wp_json_encode( $args ) );
}

/**
 * Returns the UNIX timestamp for the provided date.
 *
 * @param   string            $date     The date to convert to a timestamp.
 * @param   DateTimeZone|null $timezone Optional. The timezone to use. Default is the site's timezone.
 *
 * @return  integer|null
 */
function a8csp_bgt_get_date_timestamp( string $date = 'now', ?DateTimeZone $timezone = null ): ?int {
	try {
		$timezone ??= new DateTimeZone( wp_timezone_string() );
		return ( new DateTime( $date, $timezone ) )->getTimestamp();
	} catch ( Exception $exception ) {
		a8csp_bgt_log_error( $exception->getMessage(), __FUNCTION__ );
		return null;
	}
}

/**
 * Logs a generic error message to the error log.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string     $message The error message.
 * @param   string     $context The context in which the error occurred.
 * @param   array|null $extra   Additional information to log.
 * @param   string     $type    The type of error.
 *
 * @return  void
 * @noinspection ForgottenDebugOutputInspection
 */
function a8csp_bgt_log_error( string $message, string $context, ?array $extra = null, string $type = 'Generic' ): void {
	$message = "❌ $type Error ($context): $message" . ( ! is_null( $extra ) ? ' - ' . wp_json_encode( $extra ) : '' );
	error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
