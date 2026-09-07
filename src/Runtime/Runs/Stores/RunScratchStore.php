<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowDeleteOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowWriteOutcome;

\defined( 'ABSPATH' ) || exit;

/**
 * Consumer-owned scratch values that live and die with one run.
 *
 * A Chunked Job accumulates across chunks and has nowhere but storage to put what it accumulates.
 * What it puts there belongs to the run rather than to the job, so the engine owns the lifetime: one
 * row per run, dropped wherever the engine drops the run row, however that run ended.
 *
 * The row is its own family rather than a field on the active-run row, because that row is
 * complete-state compare-and-swap: a write from inside a chunk loop would rewrite the persisted
 * queue once per item.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class RunScratchStore {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum bytes accepted for one run's complete serialized scratch row.
	 *
	 * Scratch shares the active-run row's option-table and object-cache substrate, so it accepts the
	 * same complete-row ceiling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int MAX_ROW_BYTES = RunStore::MAX_ROW_BYTES;

	/**
	 * Maximum bytes accepted for one scratch key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	public const int MAX_KEY_BYTES = 64;

	/**
	 * Prefix for per-run scratch option names.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string OPTION_PREFIX = 'a8csp_bgje_run_scratch_';

	/**
	 * Grammar accepted for one scratch key.
	 *
	 * The job and schedule name grammar, so a consumer already knows it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private const string KEY_PATTERN = '/\A[a-z0-9_-]+\z/D';

	/**
	 * Maximum compare-and-swap attempts before a contended write fails safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int UPDATE_ATTEMPTS = 5;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity   $identity Complete scope-qualified job or chunked job identity.
	 * @param   OptionRows $rows     Authoritative raw option-row I/O.
	 */
	public function __construct(
		private Identity $identity,
		private OptionRows $rows,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns whether a candidate satisfies the scratch key grammar and byte ceiling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $key Scratch key candidate.
	 *
	 * @return  bool
	 */
	public static function is_valid_key( string $key ): bool {
		return self::MAX_KEY_BYTES >= \strlen( $key ) && 1 === \preg_match( self::KEY_PATTERN, $key );
	}

	/**
	 * Returns the complete scratch option name for one run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified job or chunked job identity.
	 * @param   string   $run_id   Run identifier.
	 *
	 * @return  string
	 */
	public static function option_name( Identity $identity, string $run_id ): string {
		return self::OPTION_PREFIX . (string) $identity . '_' . $run_id;
	}

	/**
	 * Parses a work identity and run identifier from one scratch option name.
	 *
	 * The sweep needs both to ask whether the run still exists, which is why the name carries them
	 * literally rather than hashed.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Complete option name.
	 *
	 * @return  array{identity: Identity, run_id: string}|null
	 */
	public static function from_option_name( string $option_name ): ?array {
		$matched = \preg_match( '/\A' . \preg_quote( self::OPTION_PREFIX, '/' ) . '(?<identity>.+)_(?<run_id>[0-9]{' . RunIdentity::TIME_DIGITS . '}-[0-9]{' . RunIdentity::RANDOM_DIGITS . '})\z/D', $option_name, $matches );
		if ( 1 !== $matched ) {
			return null;
		}

		$identity = Identity::tryFrom( $matches['identity'] );

		return null === $identity ? null : array(
			'identity' => $identity,
			'run_id'   => $matches['run_id'],
		);
	}

	/**
	 * Returns one run's scratch value, or null when the run has stored nothing under that key.
	 *
	 * Null separates an absent key from one holding an empty array, which is the distinction a
	 * "have I generated this yet" check is made of.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * A failed read is reported rather than folded into the absent answer: a caller treating a
	 * database fault as "not generated yet" would redo the work the scratch exists to avoid.
	 *
	 * @param   string $run_id Run identifier.
	 * @param   string $key    Scratch key.
	 *
	 * @return  AbstractResult<array<array-key, mixed>|null, EngineError>
	 */
	#[\NoDiscard( 'a scratch read result must be handled, not dropped' )]
	public function recall( string $run_id, string $key ): AbstractResult {
		$option_name = self::option_name( $this->identity, $run_id );
		$selected    = $this->rows->read( $option_name );
		if ( $selected->is_failure() ) {
			return $selected;
		}
		if ( null === $selected->value ) {
			return new Success( null );
		}

		$value = RawOptionDecoder::decode( $selected->value );
		if ( ! \is_array( $value ) ) {
			return new Success( null );
		}

		$stored = $value[ $key ] ?? null;

		return new Success( \is_array( $stored ) ? $stored : null );
	}

	/**
	 * Stores one run's scratch value under one key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id Run identifier.
	 * @param   string                  $key    Scratch key.
	 * @param   array<array-key, mixed> $value  Portable value to store.
	 *
	 * @throws  \LogicException When WordPress does not serialize the scratch row to a string.
	 *
	 * @return  bool|null True when the write is confirmed, null when the complete row would exceed its ceiling.
	 */
	#[\NoDiscard( 'a scratch write result must be handled, not dropped' )]
	public function remember( string $run_id, string $key, array $value ): ?bool {
		$option_name = self::option_name( $this->identity, $run_id );

		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$selected = $this->rows->read( $option_name );
			if ( $selected->is_failure() ) {
				return false;
			}

			$expected_raw = $selected->value;
			$decoded      = null === $expected_raw ? null : RawOptionDecoder::decode( $expected_raw );
			$scratch      = \is_array( $decoded ) ? $decoded : array();

			$scratch[ $key ] = $value;

			$replacement_raw = self::serialize_scratch( $scratch );
			if ( self::MAX_ROW_BYTES < \strlen( $replacement_raw ) ) {
				return null;
			}
			if ( $replacement_raw === $expected_raw ) {
				return true;
			}

			if ( null === $expected_raw ) {
				if ( RowWriteOutcome::Won === $this->rows->insert_if_absent( $option_name, $replacement_raw ) ) {
					return true;
				}

				continue;
			}

			$write = $this->rows->compare_and_swap( $option_name, $expected_raw, $replacement_raw );
			if ( RowWriteOutcome::Won === $write ) {
				return true;
			}
			if ( RowWriteOutcome::WriteFailed === $write ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Drops one run's complete scratch row.
	 *
	 * Byte-exact like every other engine delete, so a write that landed between the read and the
	 * delete is left rather than clobbered. Losing that race strands one row, which is the state the
	 * maintenance sweep exists to collect.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @return  bool Whether no scratch row remains for the run.
	 */
	#[\NoDiscard( 'a scratch delete result must be handled, not dropped' )]
	public function forget( string $run_id ): bool {
		$option_name = self::option_name( $this->identity, $run_id );
		$selected    = $this->rows->read( $option_name );
		if ( $selected->is_failure() ) {
			return false;
		}
		if ( null === $selected->value ) {
			return true;
		}

		return RowDeleteOutcome::Deleted === $this->rows->delete_if_value_matches( $option_name, $selected->value );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns a scratch row's exact WordPress option representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $scratch Complete scratch state.
	 *
	 * @throws  \LogicException When WordPress does not serialize the scratch row to a string.
	 *
	 * @return  string
	 */
	private static function serialize_scratch( array $scratch ): string {
		$raw = \maybe_serialize( $scratch );
		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'WordPress must serialize run scratch to a string.' );
		}

		return $raw;
	}

	// endregion
}
