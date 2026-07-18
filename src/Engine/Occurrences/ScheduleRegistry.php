<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RowDeleteOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RowWriteOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\SchedulingError;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Retains declared schedules request-locally and persists their owner-scoped timing state.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class ScheduleRegistry {
	// region FIELDS AND CONSTANTS

	/**
	 * Prefix for per-owner schedule-registration rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string OPTION_PREFIX = 'a8csp_bgte_schedule_registrations_';

	/**
	 * Maximum compare-and-swap attempts before a contended write fails safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int UPDATE_ATTEMPTS = 5;

	/**
	 * Current-request declarations keyed by complete schedule identity inside each owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, array<string, array{schedule: Schedule, task: string}>>
	 */
	private array $declarations = array();

	/**
	 * Corrupt-row warnings currently crossing the logger boundary, keyed by exact option name.
	 *
	 * @var array<string, true>
	 */
	private array $corrupt_warnings_in_flight = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OptionRows      $rows   Authoritative raw registry-row I/O.
	 * @param   LoggerInterface $logger Log event sink.
	 */
	public function __construct(
		private readonly OptionRows $rows,
		private readonly LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns valid persisted registrations belonging to exactly one owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Stable client identifier.
	 *
	 * @return  AbstractResult<array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int}>, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a schedule-registry read outcome must be handled, not dropped' )]
	public function registrations_for( string $owner ): AbstractResult {
		$option_name = self::option_name( $owner );
		$selected    = $this->rows->read( $option_name );
		if ( $selected->is_failure() ) {
			return $selected;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return new Success( array() );
		}

		$stored = RawOptionDecoder::decode( $raw );
		if ( ! \is_array( $stored ) ) {
			$this->warn_corrupt_row( $option_name );

			return new Failure( SchedulingError::registry_corrupt( $owner, $option_name ) );
		}

		return new Success( self::registrations_from_rows( $owner, $stored ) );
	}

	/**
	 * Returns every valid persisted registration keyed by its complete backend identity.
	 *
	 * @internal Read-only engine inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int}>, EngineError>
	 */
	#[\NoDiscard( 'a schedule-registry read outcome must be handled, not dropped' )]
	public function all_registrations(): AbstractResult {
		$names = $this->rows->option_names( self::OPTION_PREFIX );
		if ( $names->is_failure() ) {
			return $names;
		}

		$registrations = array();
		foreach ( $names->value as $option_name ) {
			$owner = self::owner_from_option_name( $option_name );
			if ( null === $owner ) {
				continue;
			}

			$selected = $this->rows->read( $option_name );
			if ( $selected->is_failure() ) {
				return $selected;
			}

			$raw = $selected->value;
			if ( null === $raw ) {
				continue;
			}

			$stored = RawOptionDecoder::decode( $raw );
			if ( ! \is_array( $stored ) ) {
				$this->warn_corrupt_row( $option_name );
				continue;
			}

			foreach ( self::registrations_from_rows( $owner, $stored ) as $identity => $registration ) {
				$registrations[ $identity ] = $registration;
			}
		}

		return new Success( $registrations );
	}

	/**
	 * Replaces one owner's request declarations and persisted registration state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, array{schedule: Schedule, task: string}>                                          $schedules
	 * @phpstan-param array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int}> $registrations
	 *
	 * @param   string $owner         Stable client identifier.
	 * @param   array  $schedules     Declared schedules keyed by complete identity.
	 * @param   array  $registrations Persisted owner state keyed by complete identity.
	 *
	 * @throws  \InvalidArgumentException When a registration identity is invalid or belongs to another owner.
	 *
	 * @return  OwnerReplacementOutcome Classified persistence outcome.
	 */
	#[\NoDiscard( 'a schedule-registry persistence failure must be handled, not dropped' )]
	public function replace_owner( string $owner, array $schedules, array $registrations ): OwnerReplacementOutcome {
		$owner_registrations = self::owner_registrations( $owner, $registrations );
		if ( null === $owner_registrations ) {
			throw new \InvalidArgumentException( 'Schedule registration identities must be canonical and belong to the bound owner.' );
		}
		$option_name = self::option_name( $owner );

		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$read = $this->rows->read( $option_name );
			if ( $read->is_failure() ) {
				return OwnerReplacementOutcome::ReadFailed;
			}

			$expected_raw = $read->value;
			if ( null === $expected_raw ) {
				if ( array() === $owner_registrations ) {
					$this->retain_owner( $owner, $schedules );

					return OwnerReplacementOutcome::Persisted;
				}

				$replacement_raw = self::serialize_registrations( $owner_registrations );
				if ( RowWriteOutcome::Won === $this->rows->insert_if_absent( $option_name, $replacement_raw ) ) {
					$this->retain_owner( $owner, $schedules );

					return OwnerReplacementOutcome::Persisted;
				}

				continue;
			}

			$stored = RawOptionDecoder::decode( $expected_raw );
			if ( ! \is_array( $stored ) ) {
				return OwnerReplacementOutcome::Corrupt;
			}

			$replacement_registrations = $owner_registrations;
			$stored_registrations      = self::registrations_from_rows( $owner, $stored );
			foreach ( $replacement_registrations as $registration_key => $registration ) {
				$stored_registration = $stored_registrations[ $registration_key ] ?? null;
				if ( null !== $stored_registration && $registration['fingerprint'] === $stored_registration['fingerprint'] ) {
					// An unchanged definition retains the freshest delivery fence from the selected generation.
					$replacement_registrations[ $registration_key ] = $stored_registration;
				}
			}

			if ( $replacement_registrations === $stored ) {
				$this->retain_owner( $owner, $schedules );

				return OwnerReplacementOutcome::Persisted;
			}

			if ( array() === $owner_registrations ) {
				if ( RowDeleteOutcome::Deleted === $this->rows->delete_if_value_matches( $option_name, $expected_raw ) ) {
					$this->retain_owner( $owner, $schedules );

					return OwnerReplacementOutcome::Persisted;
				}

				$current = $this->rows->read( $option_name );
				if ( $current->is_failure() ) {
					return OwnerReplacementOutcome::ReadFailed;
				}

				// A lost delete whose row is already gone means another writer reached the goal state first.
				$current_raw = $current->value;
				if ( null === $current_raw ) {
					$this->retain_owner( $owner, $schedules );

					return OwnerReplacementOutcome::Persisted;
				}
				if ( $current_raw === $expected_raw ) {
					return OwnerReplacementOutcome::CasFailed;
				}

				continue;
			}

			$replacement_raw = self::serialize_registrations( $replacement_registrations );
			$write           = $this->rows->compare_and_swap( $option_name, $expected_raw, $replacement_raw );
			if ( RowWriteOutcome::Won === $write ) {
				$this->retain_owner( $owner, $schedules );

				return OwnerReplacementOutcome::Persisted;
			}
			if ( RowWriteOutcome::WriteFailed === $write ) {
				return OwnerReplacementOutcome::CasFailed;
			}
		}

		return OwnerReplacementOutcome::CasFailed;
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the request-local schedule identified by its backend registration key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  array{schedule: Schedule, task: string}|null
	 */
	public function declaration( string $registration_key ): ?array {
		$parts = WorkIdentity::parts( $registration_key );
		if ( null === $parts ) {
			return null;
		}

		return $this->declarations[ $parts[0] ][ $registration_key ] ?? null;
	}

	/**
	 * Returns valid persisted timing state for one backend registration key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  AbstractResult<array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int}|null, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a schedule-registry read outcome must be handled, not dropped' )]
	public function registration( string $registration_key ): AbstractResult {
		$parts = WorkIdentity::parts( $registration_key );
		if ( null === $parts ) {
			return new Success( null );
		}

		$registrations = $this->registrations_for( $parts[0] );
		if ( $registrations->is_failure() ) {
			return $registrations;
		}

		return new Success( $registrations->value[ $registration_key ] ?? null );
	}

	/**
	 * Replaces one persisted registration while its observed definition fingerprint remains current.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int} $registration
	 *
	 * @param   string $registration_key     `{owner}:{name}` schedule identity.
	 * @param   string $observed_fingerprint Definition fingerprint observed before the update.
	 * @param   array  $registration         Complete registration timing state.
	 *
	 * @return  RegistrationUpdateOutcome Fenced row-update outcome.
	 */
	#[\NoDiscard( 'a schedule-registry persistence failure must be handled, not dropped' )]
	public function update_registration( string $registration_key, string $observed_fingerprint, array $registration ): RegistrationUpdateOutcome {
		$parts = WorkIdentity::parts( $registration_key );
		if ( null === $parts ) {
			return RegistrationUpdateOutcome::Failed;
		}

		$owner       = $parts[0];
		$option_name = self::option_name( $owner );
		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$expected = $this->rows->read( $option_name );
			if ( $expected->is_failure() ) {
				return RegistrationUpdateOutcome::Failed;
			}

			$expected_raw = $expected->value;
			if ( null === $expected_raw ) {
				return RegistrationUpdateOutcome::Pruned;
			}

			$stored = RawOptionDecoder::decode( $expected_raw );
			if ( ! \is_array( $stored ) ) {
				return RegistrationUpdateOutcome::Failed;
			}

			// The fresh existence check prevents a concurrently pruned row from being resurrected.
			if ( ! \array_key_exists( $registration_key, $stored ) ) {
				return RegistrationUpdateOutcome::Pruned;
			}

			// A row update persists only for the definition generation the caller validated;
			// a changed fingerprint marks an in-flight occurrence as superseded by synchronization.
			$current_registration = $stored[ $registration_key ];
			if (
				! \is_array( $current_registration )
				|| ( $current_registration['fingerprint'] ?? null ) !== $observed_fingerprint
			) {
				return RegistrationUpdateOutcome::Superseded;
			}

			$stored[ $registration_key ] = $registration;
			$replacement_raw             = self::serialize_registrations( $stored );
			$write                       = $this->rows->compare_and_swap( $option_name, $expected_raw, $replacement_raw );
			if ( RowWriteOutcome::Won === $write ) {
				return RegistrationUpdateOutcome::Updated;
			}
			if ( RowWriteOutcome::WriteFailed === $write ) {
				return RegistrationUpdateOutcome::Failed;
			}

			$current = $this->rows->read( $option_name );
			if ( $current->is_failure() ) {
				return RegistrationUpdateOutcome::Failed;
			}

			$current_raw = $current->value;
			if ( null === $current_raw ) {
				return RegistrationUpdateOutcome::Pruned;
			}
			if ( $current_raw === $expected_raw ) {
				return RegistrationUpdateOutcome::Failed;
			}
		}

		return RegistrationUpdateOutcome::Failed;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns valid registration rows from one persisted owner slice.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $owner Validated persisted owner key.
	 * @param   array<array-key, mixed> $rows  Persisted rows for one owner.
	 *
	 * @return  array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int}>
	 */
	private static function registrations_from_rows( string $owner, array $rows ): array {
		$registrations = array();
		foreach ( $rows as $registration_key => $row ) {
			if ( ! \is_string( $registration_key ) ) {
				continue;
			}

			$parts = WorkIdentity::parts( $registration_key );
			if ( null === $parts || $owner !== $parts[0] ) {
				continue;
			}

			if ( ! \is_array( $row ) ) {
				continue;
			}

			$misfire_skips = $row['misfire_skips'] ?? 0;
			$overlap_skips = $row['overlap_skips'] ?? 0;
			if (
				! \is_string( $row['fingerprint'] ?? null )
				|| ! \is_int( $row['next_due'] ?? null )
				|| 1 > $row['next_due']
				|| ( null !== ( $row['last_fired'] ?? null ) && ! \is_int( $row['last_fired'] ?? null ) )
				|| ! \is_int( $misfire_skips )
				|| 0 > $misfire_skips
				|| ! \is_int( $overlap_skips )
				|| 0 > $overlap_skips
			) {
				continue;
			}

			$registrations[ $registration_key ] = array(
				'fingerprint'   => $row['fingerprint'],
				'next_due'      => $row['next_due'],
				'last_fired'    => $row['last_fired'] ?? null,
				'misfire_skips' => $misfire_skips,
				'overlap_skips' => $overlap_skips,
			);
		}

		return $registrations;
	}

	/**
	 * Returns one owner's persisted row shape from complete registration identities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int}> $registrations
	 *
	 * @param   string $owner         Stable client or engine identifier.
	 * @param   array  $registrations Persisted owner state keyed by complete identity.
	 *
	 * @return  array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int}>|null
	 */
	private static function owner_registrations( string $owner, array $registrations ): ?array {
		$rows = array();
		foreach ( $registrations as $registration_key => $registration ) {
			if ( ! \is_string( $registration_key ) ) {
				return null;
			}

			$parts = WorkIdentity::parts( $registration_key );
			if ( null === $parts || $owner !== $parts[0] ) {
				return null;
			}

			$rows[ $registration_key ] = $registration;
		}

		return $rows;
	}

	/**
	 * Returns the option name for one owner's registration row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Stable client or engine identifier.
	 *
	 * @throws  \InvalidArgumentException When the owner fails identity validation.
	 *
	 * @return  string
	 */
	public static function option_name( string $owner ): string {
		WorkIdentity::validate_owner( $owner, true );

		return self::OPTION_PREFIX . $owner;
	}

	/**
	 * Returns one owner row's exact WordPress option representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $registrations Complete owner registration state.
	 *
	 * @throws  \LogicException When WordPress does not serialize the registrations to a string.
	 *
	 * @return  string
	 */
	private static function serialize_registrations( array $registrations ): string {
		$raw = \maybe_serialize( $registrations );
		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'WordPress must serialize schedule registrations to a string.' );
		}

		return $raw;
	}

	/**
	 * Returns the canonical owner encoded by one registration option name.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Persisted option name.
	 *
	 * @return  string|null
	 */
	public static function owner_from_option_name( string $option_name ): ?string {
		$owner = \substr( $option_name, \strlen( self::OPTION_PREFIX ) );
		try {
			WorkIdentity::validate_owner( $owner, true );
		} catch ( \InvalidArgumentException ) {
			return null;
		}

		return self::OPTION_PREFIX . $owner === $option_name ? $owner : null;
	}

	/**
	 * Reports one undecodable owner row without treating child-registration validation as row corruption.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Exact persisted option name.
	 *
	 * @return  void
	 */
	private function warn_corrupt_row( string $option_name ): void {
		if ( isset( $this->corrupt_warnings_in_flight[ $option_name ] ) ) {
			return;
		}

		$this->corrupt_warnings_in_flight[ $option_name ] = true;
		try {
			$this->logger->warning(
				'Schedule registry option row is unreadable; maintenance reclaims it, then re-declare schedules on the next init.',
				array( 'option_name' => $option_name )
			);
		} finally {
			unset( $this->corrupt_warnings_in_flight[ $option_name ] );
		}
	}

	/**
	 * Replaces one owner's request-local schedule definitions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                                                 $owner     Stable client identifier.
	 * @param   array<string, array{schedule: Schedule, task: string}> $schedules Declared schedules keyed by complete identity.
	 *
	 * @return  void
	 */
	private function retain_owner( string $owner, array $schedules ): void {
		if ( array() === $schedules ) {
			unset( $this->declarations[ $owner ] );
			return;
		}

		$this->declarations[ $owner ] = $schedules;
	}

	// endregion
}
