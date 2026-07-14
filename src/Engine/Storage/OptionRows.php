<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Errors\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
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
	public function __construct(
		private wpdb $wpdb
	) {
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
	 * Reads the exact raw option value directly from the authoritative site table.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $key Option name.
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  AbstractResult<string|null, EngineError>
	 */
	#[\NoDiscard( 'an authoritative read outcome must be handled, not dropped' )]
	public function read( string $key ): AbstractResult {
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
		if ( $this->last_read_failed() ) {
			return new Failure( new EngineError( 'Authoritative option-row read failed: ' . $wpdb->last_error ) );
		}
		if ( ! \is_array( $row ) || ! \is_string( $row['option_value'] ?? null ) ) {
			return new Success( null );
		}

		return new Success( $row['option_value'] );
	}

	/**
	 * Returns exact option names under one escaped literal prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $prefix Literal option-name prefix.
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  AbstractResult<list<string>, EngineError>
	 */
	#[\NoDiscard( 'an authoritative read outcome must be handled, not dropped' )]
	public function option_names( string $prefix ): AbstractResult {
		$this->assert_site();
		$wpdb  = $this->wpdb;
		$names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s ORDER BY `option_name` ASC',
				$wpdb->options,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
		if ( $this->last_read_failed() ) {
			return new Failure( new EngineError( 'Authoritative option-name read failed: ' . $wpdb->last_error ) );
		}

		$typed = array();
		foreach ( $names as $name ) {
			if ( \is_string( $name ) && \str_starts_with( $name, $prefix ) ) {
				$typed[] = $name;
			}
		}

		return new Success( $typed );
	}

	/**
	 * Returns one bounded page and the complete candidate count for an exact option-name length.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $prefix       Literal option-name prefix.
	 * @param   int    $total_length Required complete option-name length.
	 * @param   int    $limit        Positive maximum number of names returned.
	 *
	 * @throws  \InvalidArgumentException When the length or limit is invalid.
	 * @throws  \LogicException           When the current site differs from the bound site.
	 *
	 * @return  array{names: list<string>, total: int}|null Null when either authoritative read fails.
	 */
	public function option_names_page( string $prefix, int $total_length, int $limit ): ?array {
		if ( \strlen( $prefix ) > $total_length || 1 > $limit ) {
			throw new \InvalidArgumentException( 'An option-name page requires a complete length at least as long as its prefix and a positive limit.' );
		}

		$this->assert_site();
		$wpdb    = $this->wpdb;
		$pattern = $wpdb->esc_like( $prefix ) . '%';
		$count   = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE `option_name` LIKE %s AND CHAR_LENGTH(`option_name`) = %d',
				$wpdb->options,
				$pattern,
				$total_length
			)
		);
		if (
			$this->last_read_failed()
			|| ! \is_string( $count )
			|| 1 !== \preg_match( '/\A\d+\z/', $count )
		) {
			return null;
		}

		$names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s AND CHAR_LENGTH(`option_name`) = %d ORDER BY `option_name` ASC LIMIT %d',
				$wpdb->options,
				$pattern,
				$total_length,
				$limit
			)
		);
		if ( $this->last_read_failed() ) {
			return null;
		}

		$typed = array();
		foreach ( $names as $name ) {
			if (
				\is_string( $name )
				&& \strlen( $name ) === $total_length
				&& \str_starts_with( $name, $prefix )
			) {
				$typed[] = $name;
			}
		}

		return array(
			'names' => $typed,
			'total' => \max( (int) $count, \count( $typed ) ),
		);
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

		if ( 0 !== $result || $expected_raw !== $replacement_raw ) {
			return false;
		}

		// MySQL reports zero for an unchanged update, so the raw row distinguishes success from a lost CAS.
		$selected = $this->read( $key );
		if ( $selected->is_failure() ) {
			return false;
		}

		return $replacement_raw === $selected->value;
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
	 * Returns whether the immediately preceding authoritative read failed at the database boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  bool
	 *
	 * @phpstan-impure
	 */
	private function last_read_failed(): bool {
		return '' !== $this->wpdb->last_error;
	}

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
