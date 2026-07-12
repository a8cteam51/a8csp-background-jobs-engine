<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Schedules;

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
	 * Current-request declarations keyed independently inside each owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<array-key, array<array-key, Schedule>>
	 */
	private array $schedules = array();

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
	 * @return  array<array-key, array{fingerprint: string, next_due: int, last_fired: int|null}>
	 */
	public function registrations_for( string $owner ): array {
		$registry = $this->stored_registry();
		$rows     = $registry[ $owner ] ?? null;
		if ( ! \is_array( $rows ) ) {
			return array();
		}

		$registrations = array();
		foreach ( $rows as $name => $row ) {
			if (
				! \is_array( $row )
				|| ! \is_string( $row['fingerprint'] ?? null )
				|| ! \is_int( $row['next_due'] ?? null )
				|| ( null !== ( $row['last_fired'] ?? null ) && ! \is_int( $row['last_fired'] ?? null ) )
			) {
				continue;
			}

			$registrations[ $name ] = array(
				'fingerprint' => $row['fingerprint'],
				'next_due'    => $row['next_due'],
				'last_fired'  => $row['last_fired'] ?? null,
			);
		}

		return $registrations;
	}

	/**
	 * Replaces one owner's request declarations and persisted registration state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<array-key, Schedule>                                                     $schedules
	 * @phpstan-param array<array-key, array{fingerprint: string, next_due: int, last_fired: int|null}> $registrations
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
		$parts = \explode( ':', $registration_key, 2 );
		if ( 2 !== \count( $parts ) ) {
			return null;
		}

		return $this->schedules[ $parts[0] ][ $parts[1] ] ?? null;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the persisted top-level registry or an empty replacement for malformed data.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<array-key, mixed>
	 */
	private function stored_registry(): array {
		$stored = \get_option( self::OPTION_NAME, array() );

		return \is_array( $stored ) ? $stored : array();
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

	// endregion
}
