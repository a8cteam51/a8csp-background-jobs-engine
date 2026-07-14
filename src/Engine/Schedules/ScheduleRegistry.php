<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;

\defined( 'ABSPATH' ) || exit;

/**
 * Retains declared schedules request-locally and persists their owner-scoped timing state.
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
	private const OPTION_NAME = 'a8csp_bgte_schedules';

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
	 * Current-request declarations keyed independently inside each owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<array-key, array<array-key, Schedule>>
	 */
	private array $schedules = array();

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
	public function __construct( private readonly OptionRows $rows ) {}

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
	 * @return  array<array-key, array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}>
	 */
	public function registrations_for( string $owner ): array {
		$registry = $this->stored_registry();
		$rows     = $registry[ $owner ] ?? null;
		if ( ! \is_array( $rows ) ) {
			return array();
		}

		return self::registrations_from_rows( $rows );
	}

	/**
	 * Returns every valid persisted registration keyed by its complete backend identity.
	 *
	 * @internal Read-only engine inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}>
	 */
	public function all_registrations(): array {
		$registrations = array();
		foreach ( $this->stored_registry() as $owner => $rows ) {
			if ( ! \is_array( $rows ) ) {
				continue;
			}

			foreach ( self::registrations_from_rows( $rows ) as $name => $registration ) {
				$key = (string) $owner . ':' . (string) $name;
				if ( null !== self::key_parts( $key ) ) {
					$registrations[ $key ] = $registration;
				}
			}
		}

		return $registrations;
	}

	/**
	 * Replaces one owner's request declarations and persisted registration state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<array-key, Schedule>                                                                    $schedules
	 * @phpstan-param array<array-key, array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}> $registrations
	 *
	 * @param   string $owner         Stable consumer identifier.
	 * @param   array  $schedules     Declared schedules keyed by name.
	 * @param   array  $registrations Persisted owner state.
	 *
	 * @return  bool True when the requested registry state is confirmed persisted.
	 */
	#[\NoDiscard( 'a schedule-registry persistence failure must be handled, not dropped' )]
	public function replace_owner( string $owner, array $schedules, array $registrations ): bool {
		$stored = $this->stored_registry();
		$next   = $stored;
		if ( array() === $registrations ) {
			unset( $next[ $owner ] );
		} else {
			$next[ $owner ] = $registrations;
		}

		if ( $next === $stored ) {
			$this->retain_owner( $owner, $schedules );

			return true;
		}

		if ( array() === $next ) {
			\delete_option( self::OPTION_NAME );
			$missing = new \stdClass();
			if ( \get_option( self::OPTION_NAME, $missing ) !== $missing ) {
				return false;
			}
		} else {
			\update_option( self::OPTION_NAME, $next, false );
			if ( \get_option( self::OPTION_NAME, null ) !== $next ) {
				return false;
			}
		}

		$this->retain_owner( $owner, $schedules );

		return true;
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
	 * @return  Schedule|null
	 */
	public function get( string $registration_key ): ?Schedule {
		$parts = self::key_parts( $registration_key );
		if ( null === $parts ) {
			return null;
		}

		return $this->schedules[ $parts[0] ][ $parts[1] ] ?? null;
	}

	/**
	 * Returns valid persisted timing state for one backend registration key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}|null
	 */
	public function registration( string $registration_key ): ?array {
		$parts = self::key_parts( $registration_key );
		if ( null === $parts ) {
			return null;
		}

		return $this->registrations_for( $parts[0] )[ $parts[1] ] ?? null;
	}

	/**
	 * Replaces one persisted registration while retaining this request's owner declarations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int} $registration
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 * @param   array  $registration     Complete registration timing state.
	 *
	 * @return  RegistrationUpdateOutcome Fenced row-update outcome.
	 */
	#[\NoDiscard( 'a schedule-registry persistence failure must be handled, not dropped' )]
	public function update_registration( string $registration_key, array $registration ): RegistrationUpdateOutcome {
		$parts = self::key_parts( $registration_key );
		if ( null === $parts ) {
			return RegistrationUpdateOutcome::Failed;
		}

		[ $owner, $name ] = $parts;
		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$expected_raw = $this->rows->select( self::OPTION_NAME );
			if ( null === $expected_raw ) {
				return $this->rows->last_select_failed()
					? RegistrationUpdateOutcome::Failed
					: RegistrationUpdateOutcome::Pruned;
			}

			$stored = self::decode_registry( $expected_raw );
			if ( ! \is_array( $stored ) ) {
				return RegistrationUpdateOutcome::Failed;
			}

			$owner_rows = $stored[ $owner ] ?? null;
			// The fresh existence check prevents a concurrently pruned row from being resurrected; concurrent writers of the same row remain last-writer-wins.
			if ( ! \is_array( $owner_rows ) || ! \array_key_exists( $name, $owner_rows ) ) {
				return RegistrationUpdateOutcome::Pruned;
			}

			$owner_rows[ $name ] = $registration;
			$stored[ $owner ]    = $owner_rows;
			$replacement_raw     = self::serialize_registry( $stored );
			if ( $this->rows->replace( self::OPTION_NAME, $expected_raw, $replacement_raw ) ) {
				return RegistrationUpdateOutcome::Updated;
			}

			$current_raw = $this->rows->select( self::OPTION_NAME );
			if ( null === $current_raw ) {
				return $this->rows->last_select_failed()
					? RegistrationUpdateOutcome::Failed
					: RegistrationUpdateOutcome::Pruned;
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
	 * @param   array<array-key, mixed> $rows Persisted rows for one owner.
	 *
	 * @return  array<array-key, array{fingerprint: string, next_due: int, last_fired: int|null, misfires: int, skips: int}>
	 */
	private static function registrations_from_rows( array $rows ): array {
		$registrations = array();
		foreach ( $rows as $name => $row ) {
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

			$registrations[ $name ] = array(
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
	 * Returns the persisted top-level registry or an empty replacement for malformed data.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>
	 */
	private function stored_registry(): array {
		$raw = $this->rows->select( self::OPTION_NAME );
		if ( null === $raw ) {
			return array();
		}

		$stored = RawOptionDecoder::decode( $raw );

		return \is_array( $stored ) ? $stored : array();
	}

	/**
	 * Returns a raw registry row without constructing serialized objects.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $raw Exact persisted option value.
	 *
	 * @return  mixed
	 */
	private static function decode_registry( string $raw ): mixed {
		\call_user_func( 'set_error_handler', static fn (): bool => true );

		try {
			return \call_user_func( 'unserialize', $raw, array( 'allowed_classes' => false ) );
		} catch ( \Throwable ) {
			return null;
		} finally {
			\call_user_func( 'restore_error_handler' );
		}
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
	 * @param   string                     $owner     Stable consumer identifier.
	 * @param   array<array-key, Schedule> $schedules Declared schedules keyed by name.
	 *
	 * @return  void
	 */
	private function retain_owner( string $owner, array $schedules ): void {
		if ( array() === $schedules ) {
			unset( $this->schedules[ $owner ] );
			return;
		}

		$this->schedules[ $owner ] = $schedules;
	}

	/**
	 * Splits a complete backend registration key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  array{string, string}|null
	 */
	private static function key_parts( string $registration_key ): ?array {
		$parts = \explode( ':', $registration_key, 2 );
		if ( 2 !== \count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return null;
		}

		return array( $parts[0], $parts[1] );
	}

	// endregion
}
