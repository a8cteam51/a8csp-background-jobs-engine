<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RowDeleteOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\Success;

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
	 * Fixed option containing every owner's registration state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string OPTION_NAME = 'a8csp_bgte_schedules';

	/**
	 * Maximum compare-and-swap attempts before a contended write fails safely.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const UPDATE_ATTEMPTS = 5;

	/**
	 * Current-request declarations keyed by complete schedule identity inside each owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, array<string, array{schedule: Schedule, task: string}>>
	 */
	private array $declarations = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OptionRows $rows Authoritative raw registry-row I/O.
	 */
	public function __construct(
		private readonly OptionRows $rows,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns valid persisted registrations belonging to exactly one owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Stable consumer identifier.
	 *
	 * @return  AbstractResult<array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}>, EngineError>
	 */
	#[\NoDiscard( 'a schedule-registry read outcome must be handled, not dropped' )]
	public function registrations_for( string $owner ): AbstractResult {
		$registry = $this->stored_registry();
		if ( $registry->is_failure() ) {
			return $registry;
		}

		$rows = $registry->value[ $owner ] ?? null;
		if ( ! \is_array( $rows ) ) {
			return new Success( array() );
		}

		return new Success( self::registrations_from_rows( $owner, $rows ) );
	}

	/**
	 * Returns every valid persisted registration keyed by its complete backend identity.
	 *
	 * @internal Read-only engine inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}>, EngineError>
	 */
	#[\NoDiscard( 'a schedule-registry read outcome must be handled, not dropped' )]
	public function all_registrations(): AbstractResult {
		$registry = $this->stored_registry();
		if ( $registry->is_failure() ) {
			return $registry;
		}

		$registrations = array();
		foreach ( $registry->value as $owner => $rows ) {
			if ( ! \is_array( $rows ) ) {
				continue;
			}

			foreach ( self::registrations_from_rows( (string) $owner, $rows ) as $identity => $registration ) {
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
	 * @phpstan-param array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}> $registrations
	 *
	 * @param   string $owner         Stable consumer identifier.
	 * @param   array  $schedules     Declared schedules keyed by complete identity.
	 * @param   array  $registrations Persisted owner state keyed by complete identity.
	 *
	 * @return  bool True when the requested registry state is confirmed persisted.
	 */
	#[\NoDiscard( 'a schedule-registry persistence failure must be handled, not dropped' )]
	public function replace_owner( string $owner, array $schedules, array $registrations ): bool {
		$owner_registrations = self::owner_registrations( $owner, $registrations );
		if ( null === $owner_registrations ) {
			return false;
		}

		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$read = $this->rows->read( self::OPTION_NAME );
			if ( $read->is_failure() ) {
				return false;
			}

			$expected_raw = $read->value;
			if ( null === $expected_raw ) {
				if ( array() === $owner_registrations ) {
					$this->retain_owner( $owner, $schedules );

					return true;
				}

				$replacement_raw = self::serialize_registry( array( $owner => $owner_registrations ) );
				if ( $this->rows->insert_if_absent( self::OPTION_NAME, $replacement_raw ) ) {
					$this->retain_owner( $owner, $schedules );

					return true;
				}

				continue;
			}

			$stored = RawOptionDecoder::decode( $expected_raw );
			if ( ! \is_array( $stored ) ) {
				return false;
			}

			$next = $stored;
			if ( array() === $owner_registrations ) {
				unset( $next[ $owner ] );
			} else {
				$next[ $owner ] = $owner_registrations;
			}

			if ( $next === $stored ) {
				$this->retain_owner( $owner, $schedules );

				return true;
			}

			if ( array() === $next ) {
				if ( RowDeleteOutcome::Deleted === $this->rows->delete_if_value_matches( self::OPTION_NAME, $expected_raw ) ) {
					$this->retain_owner( $owner, $schedules );

					return true;
				}

				$current = $this->rows->read( self::OPTION_NAME );
				if ( $current->is_failure() ) {
					return false;
				}

				// A lost delete whose row is already gone means another writer reached the goal state first.
				$current_raw = $current->value;
				if ( null === $current_raw ) {
					$this->retain_owner( $owner, $schedules );

					return true;
				}
				if ( $current_raw === $expected_raw ) {
					return false;
				}

				continue;
			}

			$replacement_raw = self::serialize_registry( $next );
			if ( $this->rows->compare_and_swap( self::OPTION_NAME, $expected_raw, $replacement_raw ) ) {
				$this->retain_owner( $owner, $schedules );

				return true;
			}

			$current = $this->rows->read( self::OPTION_NAME );
			if ( $current->is_failure() ) {
				return false;
			}

			$current_raw = $current->value;
			if ( null === $current_raw ) {
				continue;
			}
			if ( $current_raw === $expected_raw ) {
				return false;
			}
		}

		return false;
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
	 * @return  AbstractResult<array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}|null, EngineError>
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
	 * @phpstan-param array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int} $registration
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

		$owner = $parts[0];
		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$expected = $this->rows->read( self::OPTION_NAME );
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

			$owner_rows = $stored[ $owner ] ?? null;
			// The fresh existence check prevents a concurrently pruned row from being resurrected.
			if ( ! \is_array( $owner_rows ) || ! \array_key_exists( $registration_key, $owner_rows ) ) {
				return RegistrationUpdateOutcome::Pruned;
			}

			// A row update persists only for the definition generation the caller validated;
			// a changed fingerprint marks an in-flight occurrence as superseded by synchronization.
			$current_registration = $owner_rows[ $registration_key ];
			if (
				! \is_array( $current_registration )
				|| ( $current_registration['fingerprint'] ?? null ) !== $observed_fingerprint
			) {
				return RegistrationUpdateOutcome::Superseded;
			}

			$owner_rows[ $registration_key ] = $registration;
			$stored[ $owner ]                = $owner_rows;
			$replacement_raw                 = self::serialize_registry( $stored );
			if ( $this->rows->compare_and_swap( self::OPTION_NAME, $expected_raw, $replacement_raw ) ) {
				return RegistrationUpdateOutcome::Updated;
			}

			$current = $this->rows->read( self::OPTION_NAME );
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
	 * @return  array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}>
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

			$misfires = $row['misfires'] ?? 0;
			$skips    = $row['skips'] ?? 0;
			if (
				! \is_string( $row['fingerprint'] ?? null )
				|| ! \is_int( $row['next_due'] ?? null )
				|| 1 > $row['next_due']
				|| ( null !== ( $row['last_fired'] ?? null ) && ! \is_int( $row['last_fired'] ?? null ) )
				|| ! \is_int( $misfires )
				|| 0 > $misfires
				|| ! \is_int( $skips )
				|| 0 > $skips
			) {
				continue;
			}

			$registrations[ $registration_key ] = array(
				'fingerprint' => $row['fingerprint'],
				'next_due'    => $row['next_due'],
				'last_fired'  => $row['last_fired'] ?? null,
				'misfires'    => $misfires,
				'skips'       => $skips,
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
	 * @phpstan-param array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}> $registrations
	 *
	 * @param   string $owner         Stable consumer or engine identifier.
	 * @param   array  $registrations Persisted owner state keyed by complete identity.
	 *
	 * @return  array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}>|null
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
	 * Returns the persisted top-level registry or an empty replacement for malformed data.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<array<array-key, mixed>, EngineError>
	 */
	private function stored_registry(): AbstractResult {
		$selected = $this->rows->read( self::OPTION_NAME );
		if ( $selected->is_failure() ) {
			return $selected;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return new Success( array() );
		}

		$stored = RawOptionDecoder::decode( $raw );

		return new Success( \is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Returns a registry's exact WordPress option representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $registry Complete registry state.
	 *
	 * @throws  \LogicException When WordPress does not serialize the registry to a string.
	 *
	 * @return  string
	 */
	private static function serialize_registry( array $registry ): string {
		$raw = \maybe_serialize( $registry );
		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'WordPress must serialize the schedule registry to a string.' );
		}

		return $raw;
	}

	/**
	 * Replaces one owner's request-local schedule definitions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                                                 $owner     Stable consumer identifier.
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
