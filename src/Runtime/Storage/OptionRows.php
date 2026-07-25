<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use wpdb;

\defined( 'ABSPATH' ) || exit;

/**
 * Performs authoritative raw SQL and cache I/O for fenced option rows.
 *
 * Each instance is bound to the current site because WordPress rebinds wpdb's per-site table
 * properties during a blog switch.
 *
 * @internal
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
		private wpdb $wpdb,
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
	 * @return  RowWriteOutcome Exact insert classification.
	 */
	public function insert_if_absent( string $key, string $raw ): RowWriteOutcome {
		$this->assert_site();
		$wpdb = $this->wpdb;

		$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'off') /* LOCK */", $wpdb->options, $key, $raw ) ?? '' );
		$this->purge_cache( $key );

		return match ( $result ) {
			1       => RowWriteOutcome::Won,
			0       => RowWriteOutcome::Lost,
			default => RowWriteOutcome::WriteFailed,
		};
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

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT `option_value` FROM %i WHERE `option_name` = %s LIMIT 1', $wpdb->options, $key ), \ARRAY_A );
		if ( $this->last_read_failed() ) {
			return new Failure(
				new EngineError(
					'Authoritative option-row read failed; repair WordPress option reads and retry.',
					reason: EngineErrorReason::StorageFailure,
					context: array(
						'option_name'   => $key,
						'storage_error' => $wpdb->last_error,
					),
				)
			);
		}
		if ( ! \is_array( $row ) || ! \is_string( $row['option_value'] ?? null ) ) {
			return new Success( null );
		}

		return new Success( $row['option_value'] );
	}

	/**
	 * Reads a bounded set of exact raw option values in one authoritative query.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string> $keys Option names.
	 *
	 * @throws  \LogicException When the current site differs from the bound site.
	 *
	 * @return  AbstractResult<array<string, string>, EngineError>
	 */
	#[\NoDiscard( 'an authoritative read outcome must be handled, not dropped' )]
	public function read_many( array $keys ): AbstractResult {
		$this->assert_site();
		if ( array() === $keys ) {
			return new Success( array() );
		}

		$wpdb         = $this->wpdb;
		$placeholders = \implode( ', ', \array_fill( 0, \count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN-list placeholders are array_fill()-built literals; every option name binds through prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT `option_name`, `option_value` FROM %i WHERE `option_name` IN (' . $placeholders . ')', $wpdb->options, ...$keys ), \ARRAY_A );
		if ( $this->last_read_failed() ) {
			return new Failure( new EngineError( 'Authoritative option-row read failed; repair WordPress option reads and retry.', reason: EngineErrorReason::StorageFailure, context: array( 'storage_error' => $wpdb->last_error ), ) );
		}

		$requested = \array_fill_keys( $keys, true );
		$selected  = array();
		foreach ( $rows ?? array() as $row ) {
			$option_name  = $row['option_name'] ?? null;
			$option_value = $row['option_value'] ?? null;
			if ( \is_string( $option_name ) && \is_string( $option_value ) && isset( $requested[ $option_name ] ) ) {
				$selected[ $option_name ] = $option_value;
			}
		}

		return new Success( $selected );
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
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s ORDER BY `option_name` ASC', $wpdb->options, $wpdb->esc_like( $prefix ) . '%' ) );
		if ( $this->last_read_failed() ) {
			return new Failure( new EngineError( 'Authoritative option-name read failed; repair WordPress option reads and retry.', reason: EngineErrorReason::StorageFailure, context: array( 'storage_error' => $wpdb->last_error ), ) );
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
	 * Returns one bounded option-name page strictly after an optional bytewise cursor.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $prefix     Literal option-name prefix.
	 * @param   string|null $after_name Exclusive option-name cursor, or null for the prefix start.
	 * @param   int         $limit      Positive maximum number of names returned.
	 *
	 * @throws  \InvalidArgumentException When the limit is non-positive.
	 * @throws  \LogicException           When the current site differs from the bound site.
	 *
	 * @return  AbstractResult<array{names: list<string>, next_cursor: string|null, scanned: int}, EngineError>
	 */
	#[\NoDiscard( 'an authoritative read outcome must be handled, not dropped' )]
	public function option_names_after( string $prefix, ?string $after_name, int $limit ): AbstractResult {
		if ( 1 > $limit ) {
			throw new \InvalidArgumentException( 'An option-name cursor page requires a positive limit.' );
		}

		$this->assert_site();
		$wpdb       = $this->wpdb;
		$pattern    = $wpdb->esc_like( $prefix ) . '%';
		$candidates = null === $after_name
			? $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s ORDER BY BINARY `option_name` ASC LIMIT %d', $wpdb->options, $pattern, $limit ) )
			: $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s AND BINARY `option_name` > BINARY %s ORDER BY BINARY `option_name` ASC LIMIT %d', $wpdb->options, $pattern, $after_name, $limit ) );
		if ( $this->last_read_failed() ) {
			return new Failure( new EngineError( 'Authoritative option-name read failed; repair WordPress option reads and retry.', reason: EngineErrorReason::StorageFailure, context: array( 'storage_error' => $wpdb->last_error ), ) );
		}

		$scanned     = \count( $candidates );
		$next_cursor = null;
		if ( $scanned === $limit ) {
			$next_cursor = $candidates[ $scanned - 1 ] ?? null;
			if ( ! \is_string( $next_cursor ) || ( null !== $after_name && 0 >= \strcmp( $next_cursor, $after_name ) ) ) {
				return new Failure( new EngineError( 'Authoritative option-name read failed; repair WordPress option reads and retry.', reason: EngineErrorReason::StorageFailure, context: array( 'storage_error' => $wpdb->last_error ), ) );
			}
		}

		$typed = array();
		foreach ( $candidates as $name ) {
			if ( \is_string( $name ) && \str_starts_with( $name, $prefix ) && ( null === $after_name || 0 < \strcmp( $name, $after_name ) ) ) {
				$typed[] = $name;
			}
		}

		return new Success(
			array(
				'names'       => $typed,
				'next_cursor' => $next_cursor,
				'scanned'     => $scanned,
			)
		);
	}

	/**
	 * Returns one bounded page and the complete accepted count for an exact option-name byte length.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                 $prefix       Literal option-name prefix.
	 * @param   int                    $total_length Required complete option-name byte length.
	 * @param   int                    $limit        Positive maximum number of names returned.
	 * @param   callable(string): bool $is_valid     Complete-name validity predicate.
	 *
	 * @throws  \InvalidArgumentException When the length or limit is invalid.
	 * @throws  \LogicException           When the current site differs from the bound site.
	 *
	 * @return  array{names: list<string>, total: int}|null Null when either authoritative read fails.
	 */
	public function option_names_page( string $prefix, int $total_length, int $limit, callable $is_valid ): ?array {
		if ( \strlen( $prefix ) > $total_length || 1 > $limit ) {
			throw new \InvalidArgumentException( 'An option-name page requires a complete length at least as long as its prefix and a positive limit.' );
		}

		$this->assert_site();
		$wpdb     = $this->wpdb;
		$pattern  = $wpdb->esc_like( $prefix ) . '%';
		$accepted = array();
		$total    = 0;
		$cursor   = null;

		do {
			$candidates = null === $cursor
				? $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s AND LENGTH(`option_name`) = %d ORDER BY BINARY `option_name` ASC LIMIT %d', $wpdb->options, $pattern, $total_length, $limit ) )
				: $wpdb->get_col( $wpdb->prepare( 'SELECT `option_name` FROM %i WHERE `option_name` LIKE %s AND LENGTH(`option_name`) = %d AND BINARY `option_name` > BINARY %s ORDER BY BINARY `option_name` ASC LIMIT %d', $wpdb->options, $pattern, $total_length, $cursor, $limit ) );
			if ( $this->last_read_failed() ) {
				return null;
			}

			$candidate_count = \count( $candidates );
			if ( 0 === $candidate_count ) {
				break;
			}

			$next_cursor = $candidates[ $candidate_count - 1 ] ?? null;
			if (
				! \is_string( $next_cursor )
				|| ( null !== $cursor && 0 >= \strcmp( $next_cursor, $cursor ) )
			) {
				return null;
			}

			foreach ( $candidates as $name ) {
				if (
					! \is_string( $name )
					|| \strlen( $name ) !== $total_length
					|| ! \str_starts_with( $name, $prefix )
					|| ! $is_valid( $name )
				) {
					continue;
				}

				++$total;
				if ( $total <= $limit ) {
					$accepted[] = $name;
				}
			}

			$cursor = $next_cursor;
		} while ( $candidate_count === $limit );

		return array(
			'names' => $accepted,
			'total' => $total,
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
	 * @return  RowWriteOutcome Exact replacement classification.
	 */
	public function compare_and_swap( string $key, string $expected_raw, string $replacement_raw ): RowWriteOutcome {
		$this->assert_site();
		$wpdb = $this->wpdb;

		$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `option_value` = %s WHERE `option_name` = %s AND BINARY `option_value` = BINARY %s', $wpdb->options, $replacement_raw, $key, $expected_raw ) ?? '' );
		$this->purge_cache( $key );
		if ( 1 === $result ) {
			return RowWriteOutcome::Won;
		}

		if ( 0 !== $result ) {
			return RowWriteOutcome::WriteFailed;
		}

		if ( $expected_raw !== $replacement_raw ) {
			return RowWriteOutcome::Lost;
		}

		// MySQL reports zero for an unchanged update, so the raw row distinguishes success from a lost CAS.
		$selected = $this->read( $key );
		if ( $selected->is_failure() ) {
			return RowWriteOutcome::WriteFailed;
		}

		return $replacement_raw === $selected->value
			? RowWriteOutcome::Won
			: RowWriteOutcome::Lost;
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
	 * @return  RowDeleteOutcome Exact delete classification.
	 */
	public function delete_if_value_matches( string $key, string $expected_raw ): RowDeleteOutcome {
		$this->assert_site();
		$wpdb = $this->wpdb;

		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `option_name` = %s AND BINARY `option_value` = BINARY %s', $wpdb->options, $key, $expected_raw ) ?? '' );
		$this->purge_cache( $key );

		return match ( $result ) {
			1       => RowDeleteOutcome::Deleted,
			0       => RowDeleteOutcome::ValueMismatch,
			default => RowDeleteOutcome::DeleteFailed,
		};
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

		throw new \LogicException( 'Do not reuse OptionRows after switch_to_blog(); construct a new site-bound instance after switching.' );
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
