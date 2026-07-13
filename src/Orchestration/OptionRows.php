<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Orchestration;

use wpdb;

\defined( 'ABSPATH' ) || exit;

/**
 * Performs authoritative raw SQL and cache I/O for fenced option rows.
 *
 * Each instance is bound to the current site because WordPress rebinds wpdb's per-site table
 * properties during a blog switch.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class OptionRows {
	// region FIELDS AND CONSTANTS

	/**
	 * Site identifier captured when this row seam is constructed.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private int $site_id;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   wpdb $wpdb Site-bound WordPress database connection.
	 */
	public function __construct( private wpdb $wpdb ) {
		$this->site_id = \get_current_blog_id();
	}

	// endregion

	// region METHODS

	/**
	 * Inserts a non-autoloaded raw row only while its option name is absent.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $key Option name.
	 * @param   string $raw Exact persisted value.
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  bool
	 */
	public function insert( string $key, string $raw ): bool {
		$this->assert_site();
		$wpdb = $this->wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'off') /* LOCK */",
				$wpdb->options,
				$key,
				$raw
			) ?? ''
		);
		$this->purge_cache( $key );

		return 1 === $result;
	}

	/**
	 * Selects the exact raw option value directly from the authoritative site table.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $key Option name.
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  string|null
	 */
	public function select( string $key ): ?string {
		$this->assert_site();
		$wpdb = $this->wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT `option_value` FROM %i WHERE `option_name` = %s LIMIT 1',
				$wpdb->options,
				$key
			),
			\ARRAY_A
		);
		if ( ! \is_array( $row ) || ! \is_string( $row['option_value'] ?? null ) ) {
			return null;
		}

		return $row['option_value'];
	}

	/**
	 * Returns whether the immediately preceding authoritative select failed at the database boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public function last_select_failed(): bool {
		return '' !== $this->wpdb->last_error;
	}

	/**
	 * Replaces a row only while its exact raw value still matches.
	 *
	 * An identical value is confirmed by a direct read because MySQL reports zero affected rows for
	 * an unchanged update.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $key             Option name.
	 * @param   string $expected_raw    Exact selected value.
	 * @param   string $replacement_raw Exact replacement value.
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  bool
	 */
	public function replace( string $key, string $expected_raw, string $replacement_raw ): bool {
		$this->assert_site();
		$wpdb = $this->wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET `option_value` = %s WHERE `option_name` = %s AND BINARY `option_value` = BINARY %s',
				$wpdb->options,
				$replacement_raw,
				$key,
				$expected_raw
			) ?? ''
		);
		$this->purge_cache( $key );
		if ( 1 === $result ) {
			return true;
		}

		// MySQL reports zero for an unchanged update, so the raw row distinguishes success from a lost CAS.
		return 0 === $result
			&& $expected_raw === $replacement_raw
			&& $replacement_raw === $this->select( $key );
	}

	/**
	 * Deletes a row only while its exact raw value still matches.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $key          Option name.
	 * @param   string $expected_raw Exact selected value.
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  bool
	 */
	public function delete( string $key, string $expected_raw ): bool {
		$this->assert_site();
		$wpdb = $this->wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE `option_name` = %s AND BINARY `option_value` = BINARY %s',
				$wpdb->options,
				$key,
				$expected_raw
			) ?? ''
		);
		$this->purge_cache( $key );

		return 1 === $result;
	}

	/**
	 * Returns whether the immediately preceding authoritative delete failed at the database boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 */
	public function last_delete_failed(): bool {
		return '' !== $this->wpdb->last_error;
	}

	// endregion

	// region HELPERS

	/**
	 * Throws when a blog switch makes the injected wpdb point at a different site's tables.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  void
	 */
	private function assert_site(): void {
		if ( \get_current_blog_id() === $this->site_id ) {
			return;
		}

		throw new \LogicException(
			'Do not reuse OptionRows after switch_to_blog(); construct a new site-bound instance after switching.'
		);
	}

	/**
	 * Removes stale request and persistent-cache views after a direct table write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $key Option name.
	 *
	 * @return  void
	 */
	private function purge_cache( string $key ): void {
		\wp_cache_delete( $key, 'options' );

		$notoptions = \wp_cache_get( 'notoptions', 'options' );
		if ( \is_array( $notoptions ) && isset( $notoptions[ $key ] ) ) {
			unset( $notoptions[ $key ] );
			\wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	// endregion
}
