<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowDeleteOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowWriteOutcome;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Owns execution-overlap locks stored as WordPress options.
 *
 * A claim mutates only an absent row. Existing parseable rows are returned as exact snapshots so
 * the admission coordinator can fence the incumbent before transferring that same lock generation.
 * Unreadable or malformed selections are indeterminate and remain unchanged. Maintenance owns stale
 * deletion independently.
 *
 * LockWindows resolves the 15-minute default, lock-staleness filter, and
 * twice-the-continue-delay floor; this guard enforces lock mechanics with the supplied window.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class OverlapGuard {
	// region FIELDS AND CONSTANTS

	/**
	 * Prefix for execution-overlap lock option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string OPTION_PREFIX = 'a8csp_bgje_overlap_lock_';

	/**
	 * Prefix length retained from a raw-value digest in operator diagnostics.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int RAW_SHA256_LENGTH = 16;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ClockInterface  $clock        Timestamp source.
	 * @param   LoggerInterface $logger       Log event sink.
	 * @param   OptionRows      $rows         Authoritative raw lock-row I/O.
	 * @param   LockWindows     $lock_windows Filterable liveness policy for the run holding a lock.
	 */
	public function __construct(
		private ClockInterface $clock,
		private LoggerInterface $logger,
		private OptionRows $rows,
		private LockWindows $lock_windows,
	) {}

	// endregion

	// region METHODS

	/**
	 * Claims an absent lock or selects an existing parseable row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash Stable single-flight identity.
	 * @param   string   $run_id    Claiming run identifier.
	 *
	 * @return  LockClaimResult Typed selection carrying the generation it decided under, with an exact contended snapshot when one was read.
	 */
	public function claim( Identity $identity, string $args_hash, string $run_id ): LockClaimResult {
		$key      = $this->option_name( $identity, $args_hash );
		$now      = $this->clock->now()->getTimestamp();
		$new_lock = self::new_lock( $run_id, $now );

		if ( RowWriteOutcome::Won === $this->rows->insert_if_absent( $key, self::serialize( $new_lock ) ) ) {
			return LockClaimResult::claimed( $now );
		}

		$selected = $this->rows->read( $key );
		if ( $selected->is_failure() ) {
			return LockClaimResult::indeterminate();
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return LockClaimResult::indeterminate();
		}

		$lock = self::parse( $raw );
		if ( null === $lock ) {
			return LockClaimResult::indeterminate();
		}

		// Liveness is the incumbent's own policy: resolving the window from the contender would judge a healthy
		// incumbent against a window it never declared. Resolving it applies consumer filters, so the age this
		// decision uses is read afterwards.
		$staleness_window = $this->lock_windows->lock_staleness( $identity, $lock['run_id'] );
		$now              = $this->clock->now()->getTimestamp();

		return LockClaimResult::contended( $lock['run_id'], $raw, self::is_stale( $lock, $now, $staleness_window ), $now );
	}

	/**
	 * Replaces the currently selected lock only while its exact row is unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity              Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash             Stable single-flight identity.
	 * @param   string   $expected_owner_run_id Owner parsed from the selected row.
	 * @param   string   $expected_raw          Exact selected row bytes.
	 * @param   string   $replacement_run_id    Replacement owner.
	 * @param   int|null $at                    Admission timestamp shared with the replacement run row, or null to read the
	 *                                          clock. The successor's first delivery presents its run-row heartbeat as this
	 *                                          lock's expected generation.
	 *
	 * @return  LockTransferOutcome Ownership classification after the transfer attempt.
	 */
	public function replace( Identity $identity, string $args_hash, string $expected_owner_run_id, string $expected_raw, string $replacement_run_id, ?int $at = null ): LockTransferOutcome {
		$expected_lock = self::parse( $expected_raw );
		if ( null === $expected_lock || $expected_owner_run_id !== $expected_lock['run_id'] ) {
			return LockTransferOutcome::Lost;
		}

		$now = $at ?? $this->clock->now()->getTimestamp();

		return match ( $this->rows->compare_and_swap( $this->option_name( $identity, $args_hash ), $expected_raw, self::serialize( self::new_lock( $replacement_run_id, $now ) ) ) ) {
			RowWriteOutcome::Won         => LockTransferOutcome::Transferred,
			RowWriteOutcome::Lost        => LockTransferOutcome::Lost,
			RowWriteOutcome::WriteFailed => LockTransferOutcome::Indeterminate,
		};
	}

	/**
	 * Refreshes liveness only while the run still owns the exact selected lock row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity              Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash             Stable single-flight identity.
	 * @param   string   $run_id                Owning run identifier.
	 * @param   int|null $at                    Liveness timestamp, or null to use the current clock time. A future value marks
	 *                                          expected execution work or retry fire as the run's legitimate sign of life.
	 * @param   int|null $expected_heartbeat_at Expected heartbeat for one delivery generation, or null to accept any owned generation.
	 *
	 * @return  HeartbeatOutcome Ownership classification after the heartbeat attempt.
	 */
	#[\NoDiscard( 'a lock-heartbeat outcome must be handled, not dropped' )]
	public function heartbeat( Identity $identity, string $args_hash, string $run_id, ?int $at = null, ?int $expected_heartbeat_at = null ): HeartbeatOutcome {
		$key      = $this->option_name( $identity, $args_hash );
		$selected = $this->rows->read( $key );
		if ( $selected->is_failure() ) {
			$this->logger->warning(
				'Execution-overlap lock heartbeat could not read the authoritative lock row; ownership is indeterminate and the caller aborts without a terminal claim.',
				array(
					'key'       => $key,
					'identity'  => (string) $identity,
					'args_hash' => $args_hash,
					'run_id'    => $run_id,
				)
			);

			return HeartbeatOutcome::Indeterminate;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return HeartbeatOutcome::Lost;
		}

		$lock = self::parse( $raw );
		if ( null === $lock || $run_id !== $lock['run_id'] ) {
			return HeartbeatOutcome::Lost;
		}
		if ( null !== $expected_heartbeat_at && $expected_heartbeat_at !== $lock['heartbeat_at'] ) {
			return HeartbeatOutcome::GenerationMismatch;
		}

		$lock['heartbeat_at'] = $at ?? $this->clock->now()->getTimestamp();

		$write = $this->rows->compare_and_swap( $key, $raw, self::serialize( $lock ) );
		if ( RowWriteOutcome::Won === $write ) {
			return HeartbeatOutcome::Owned;
		}
		if ( RowWriteOutcome::WriteFailed === $write ) {
			$this->logger->warning(
				'Execution-overlap lock heartbeat could not write the authoritative lock row; ownership is indeterminate and the caller aborts without a terminal claim.',
				array(
					'key'       => $key,
					'identity'  => (string) $identity,
					'args_hash' => $args_hash,
					'run_id'    => $run_id,
				)
			);

			return HeartbeatOutcome::Indeterminate;
		}

		// Ownership moved after selection, so execution cannot continue under this lock.
		return null !== $expected_heartbeat_at ? HeartbeatOutcome::GenerationMismatch : HeartbeatOutcome::Lost;
	}

	/**
	 * Deletes a lock only while the terminating run owns the exact selected row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash Stable single-flight identity.
	 * @param   string   $run_id    Owning run identifier.
	 *
	 * @return  bool Whether this run confirmed a clean release of, or absence of ownership over, the selected lock generation.
	 */
	public function release( Identity $identity, string $args_hash, string $run_id ): bool {
		$key      = $this->option_name( $identity, $args_hash );
		$selected = $this->rows->read( $key );
		if ( $selected->is_failure() ) {
			$this->logger->warning(
				'Execution-overlap lock release could not read the lock row; the staleness sweep reclaims the leaked key.',
				array(
					'key'       => $key,
					'identity'  => (string) $identity,
					'args_hash' => $args_hash,
					'run_id'    => $run_id,
				)
			);

			return false;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return true;
		}

		$lock = self::parse( $raw );
		if ( null === $lock || $run_id !== $lock['run_id'] ) {
			return true;
		}

		return RowDeleteOutcome::Deleted === $this->rows->delete_if_value_matches( $key, $raw );
	}

	/**
	 * Returns one exact raw lock snapshot and its validated schema for maintenance.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash Stable single-flight identity.
	 *
	 * @return  AbstractResult<array{raw: string, lock: array{run_id: string, heartbeat_at: int}|null}|null, EngineError>
	 */
	#[\NoDiscard( 'a persisted-lock read outcome must be handled, not dropped' )]
	public function inspect_persisted_lock( Identity $identity, string $args_hash ): AbstractResult {
		$selected = $this->rows->read( $this->option_name( $identity, $args_hash ) );
		if ( $selected->is_failure() ) {
			return $selected;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return new Success( null );
		}

		return new Success(
			array(
				'raw'  => $raw,
				'lock' => self::parse( $raw ),
			)
		);
	}

	/**
	 * Reclaims a malformed lock only while its exact inspected row is unchanged.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity     Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash    Stable single-flight identity.
	 * @param   string   $expected_raw Exact inspected malformed row value.
	 *
	 * @return  RowDeleteOutcome Exact malformed-row delete classification.
	 */
	public function reclaim_malformed_lock( Identity $identity, string $args_hash, string $expected_raw ): RowDeleteOutcome {
		return $this->rows->delete_if_value_matches( $this->option_name( $identity, $args_hash ), $expected_raw );
	}

	/**
	 * Parses a canonical work identity and argument hash from one overlap-lock option name.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Complete option name.
	 *
	 * @return  array{identity: Identity, args_hash: string}|null
	 */
	public static function identity_from_option_name( string $option_name ): ?array {
		$matched = \preg_match( '/\A' . \preg_quote( self::OPTION_PREFIX, '/' ) . '(?<identity>.+)_(?<args_hash>[a-f0-9]{64})\z/D', $option_name, $matches );
		if ( 1 !== $matched ) {
			return null;
		}

		$identity = Identity::tryFrom( $matches['identity'] );
		if ( null === $identity ) {
			return null;
		}

		return array(
			'identity'  => $identity,
			'args_hash' => $matches['args_hash'],
		);
	}

	/**
	 * Returns redacted correlation for one exact persisted raw value.
	 *
	 * @internal Operator diagnostics only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $raw Exact persisted option value.
	 *
	 * @return  array{raw_length: int, raw_sha256: string}
	 */
	public static function raw_correlation( string $raw ): array {
		return array(
			'raw_length' => \strlen( $raw ),
			'raw_sha256' => \substr( \hash( 'sha256', $raw ), 0, self::RAW_SHA256_LENGTH ),
		);
	}

	/**
	 * Deletes a stale owned lock after rechecking its exact row and staleness boundary.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity         Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash        Stable single-flight identity.
	 * @param   string   $run_id           Expected lock owner.
	 * @param   int      $staleness_window Resolved staleness window in seconds.
	 *
	 * @return  bool Whether the exact stale row was deleted.
	 */
	public function delete_stale_owned_lock( Identity $identity, string $args_hash, string $run_id, int $staleness_window ): bool {
		$inspected = $this->inspect_persisted_lock( $identity, $args_hash );
		if ( $inspected->is_failure() ) {
			return false;
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot || null === $snapshot['lock'] ) {
			return false;
		}

		$lock = $snapshot['lock'];
		if (
			$run_id !== $lock['run_id']
			|| ! self::is_stale( $lock, $this->clock->now()->getTimestamp(), $staleness_window )
		) {
			return false;
		}

		return $this->delete_persisted_lock( $identity, $args_hash, $snapshot['raw'] );
	}

	/**
	 * Fences a running run when its owned lock is missing, transferred, or can be stale-deleted exactly.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity         Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash        Stable single-flight identity.
	 * @param   string   $run_id           Expected lock owner.
	 * @param   int      $staleness_window Resolved staleness window in seconds.
	 *
	 * @return  MaintenanceFenceOutcome Typed ownership classification.
	 */
	public function fence_abandoned_run( Identity $identity, string $args_hash, string $run_id, int $staleness_window ): MaintenanceFenceOutcome {
		$inspected = $this->inspect_persisted_lock( $identity, $args_hash );
		if ( $inspected->is_failure() ) {
			return MaintenanceFenceOutcome::Indeterminate;
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot ) {
			return MaintenanceFenceOutcome::Abandoned;
		}

		$lock = $snapshot['lock'];
		if ( null === $lock ) {
			return MaintenanceFenceOutcome::Malformed;
		}

		if ( $run_id !== $lock['run_id'] ) {
			return MaintenanceFenceOutcome::Transferred;
		}

		if ( ! self::is_stale( $lock, $this->clock->now()->getTimestamp(), $staleness_window ) ) {
			return MaintenanceFenceOutcome::Owned;
		}

		return $this->delete_persisted_lock( $identity, $args_hash, $snapshot['raw'] )
			? MaintenanceFenceOutcome::Abandoned
			: MaintenanceFenceOutcome::Indeterminate;
	}

	/**
	 * Classifies run ownership without deleting a stale lock needed by a redelivered action.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash Stable single-flight identity.
	 * @param   string   $run_id    Expected lock owner.
	 *
	 * @return  MaintenanceFenceOutcome Typed ownership classification.
	 */
	public function classify_run_fence( Identity $identity, string $args_hash, string $run_id ): MaintenanceFenceOutcome {
		$inspected = $this->inspect_persisted_lock( $identity, $args_hash );
		if ( $inspected->is_failure() ) {
			return MaintenanceFenceOutcome::Indeterminate;
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot ) {
			return MaintenanceFenceOutcome::Abandoned;
		}

		$lock = $snapshot['lock'];
		if ( null === $lock ) {
			return MaintenanceFenceOutcome::Malformed;
		}

		return $run_id === $lock['run_id']
			? MaintenanceFenceOutcome::Owned
			: MaintenanceFenceOutcome::Transferred;
	}

	/**
	 * Prepares the exact lock generation expected by a redelivered action.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity     Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash    Stable single-flight identity.
	 * @param   string   $run_id       Expected lock owner.
	 * @param   int      $heartbeat_at Delivery-generation heartbeat.
	 * @param   int      $staleness    Resolved lock-staleness window.
	 *
	 * @return  RedeliveryFenceOutcome Typed readiness after the preparation attempt.
	 */
	public function prepare_run_redelivery_fence( Identity $identity, string $args_hash, string $run_id, int $heartbeat_at, int $staleness ): RedeliveryFenceOutcome {
		$key         = $this->option_name( $identity, $args_hash );
		$replacement = array(
			'run_id'       => $run_id,
			'heartbeat_at' => $heartbeat_at,
		);
		$inspected   = $this->inspect_persisted_lock( $identity, $args_hash );
		if ( $inspected->is_failure() ) {
			return RedeliveryFenceOutcome::Indeterminate;
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot ) {
			$write = $this->rows->insert_if_absent( $key, self::serialize( $replacement ) );
			if ( RowWriteOutcome::Won === $write ) {
				return RedeliveryFenceOutcome::Ready;
			}
			if ( RowWriteOutcome::WriteFailed === $write ) {
				return RedeliveryFenceOutcome::Indeterminate;
			}

			return $this->classify_redelivery_fence( $identity, $args_hash, $run_id, $heartbeat_at, $staleness );
		}

		$lock = $snapshot['lock'];
		if ( null === $lock ) {
			$write = $this->rows->compare_and_swap( $key, $snapshot['raw'], self::serialize( $replacement ) );
			if ( RowWriteOutcome::Won === $write ) {
				return RedeliveryFenceOutcome::Ready;
			}
			if ( RowWriteOutcome::WriteFailed === $write ) {
				return RedeliveryFenceOutcome::Indeterminate;
			}

			return $this->classify_redelivery_fence( $identity, $args_hash, $run_id, $heartbeat_at, $staleness );
		}
		if ( $run_id !== $lock['run_id'] ) {
			return RedeliveryFenceOutcome::Transferred;
		}
		if ( $heartbeat_at === $lock['heartbeat_at'] ) {
			return RedeliveryFenceOutcome::Ready;
		}
		if ( ! self::is_stale( $lock, $this->clock->now()->getTimestamp(), $staleness ) ) {
			return RedeliveryFenceOutcome::Live;
		}

		$write = $this->rows->compare_and_swap( $key, $snapshot['raw'], self::serialize( $replacement ) );
		if ( RowWriteOutcome::Won === $write ) {
			return RedeliveryFenceOutcome::Ready;
		}
		if ( RowWriteOutcome::WriteFailed === $write ) {
			return RedeliveryFenceOutcome::Indeterminate;
		}

		return $this->classify_redelivery_fence( $identity, $args_hash, $run_id, $heartbeat_at, $staleness );
	}

	// endregion

	// region HELPERS

	/**
	 * Reclassifies a redelivery fence after an exact lock write loses its race.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity     Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash    Stable single-flight identity.
	 * @param   string   $run_id       Expected lock owner.
	 * @param   int      $heartbeat_at Delivery-generation heartbeat.
	 * @param   int      $staleness    Resolved lock-staleness window.
	 *
	 * @return  RedeliveryFenceOutcome Typed readiness after the lost write.
	 */
	private function classify_redelivery_fence( Identity $identity, string $args_hash, string $run_id, int $heartbeat_at, int $staleness ): RedeliveryFenceOutcome {
		$inspected = $this->inspect_persisted_lock( $identity, $args_hash );
		if ( $inspected->is_failure() ) {
			return RedeliveryFenceOutcome::Indeterminate;
		}

		$snapshot = $inspected->value;
		if ( null === $snapshot || null === $snapshot['lock'] ) {
			return RedeliveryFenceOutcome::Indeterminate;
		}

		$lock = $snapshot['lock'];
		if ( $run_id !== $lock['run_id'] ) {
			return RedeliveryFenceOutcome::Transferred;
		}
		if ( $heartbeat_at === $lock['heartbeat_at'] ) {
			return RedeliveryFenceOutcome::Ready;
		}

		return self::is_stale( $lock, $this->clock->now()->getTimestamp(), $staleness )
			? RedeliveryFenceOutcome::Indeterminate
			: RedeliveryFenceOutcome::Live;
	}

	/**
	 * Returns the execution-overlap option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash Stable single-flight identity.
	 *
	 * @return  string
	 */
	private function option_name( Identity $identity, string $args_hash ): string {
		return self::OPTION_PREFIX . (string) $identity . '_' . $args_hash;
	}

	/**
	 * Returns a newly claimed lock row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Claiming run identifier.
	 * @param   int    $now    Initial heartbeat timestamp.
	 *
	 * @return  array{run_id: string, heartbeat_at: int}
	 */
	private static function new_lock( string $run_id, int $now ): array {
		return array(
			'run_id'       => $run_id,
			'heartbeat_at' => $now,
		);
	}

	/**
	 * Returns a lock row's exact persisted representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{run_id: string, heartbeat_at: int} $row Complete lock row.
	 *
	 * @throws  \LogicException When WordPress does not serialize the row to a string.
	 *
	 * @return  string
	 */
	private static function serialize( array $row ): string {
		$value = \maybe_serialize( $row );
		if ( ! \is_string( $value ) ) {
			throw new \LogicException( 'WordPress must serialize an execution-overlap lock row to a string.' );
		}

		return $value;
	}

	/**
	 * Parses the required persisted lock fields into the canonical row shape.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $raw Exact persisted option value.
	 *
	 * @return  array{run_id: string, heartbeat_at: int}|null
	 */
	private static function parse( string $raw ): ?array {
		$value = RawOptionDecoder::decode( $raw );
		if (
			! \is_array( $value )
			|| ! \is_string( $value['run_id'] ?? null )
			|| ! \is_int( $value['heartbeat_at'] ?? null )
		) {
			return null;
		}

		return array(
			'run_id'       => $value['run_id'],
			'heartbeat_at' => $value['heartbeat_at'],
		);
	}

	/**
	 * Returns whether the heartbeat age is strictly greater than the supplied window.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{run_id: string, heartbeat_at: int} $lock             Lock row.
	 * @param   int                                      $now              Current timestamp.
	 * @param   int                                      $staleness_window Staleness window in seconds.
	 *
	 * @return  bool
	 */
	private static function is_stale( array $lock, int $now, int $staleness_window ): bool {
		return $now - $lock['heartbeat_at'] > $staleness_window;
	}

	/**
	 * Deletes one inspected lock only while its exact raw row is unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity     Complete scope-qualified job or chunked job identity.
	 * @param   string   $args_hash    Stable single-flight identity.
	 * @param   string   $expected_raw Exact inspected row value.
	 *
	 * @return  bool Whether the inspected row was deleted.
	 */
	private function delete_persisted_lock( Identity $identity, string $args_hash, string $expected_raw ): bool {
		return RowDeleteOutcome::Deleted === $this->rows->delete_if_value_matches( $this->option_name( $identity, $args_hash ), $expected_raw );
	}

	// endregion
}
