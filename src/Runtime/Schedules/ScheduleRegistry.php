<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowDeleteOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowWriteOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Retains declared schedules request-locally and persists their per-scope timing state.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-type Registration array{
 *     fingerprint: string,
 *     next_due: int,
 *     last_fired: int|null,
 *     misfire_skips: int,
 *     overlap_skips: int,
 *     undeclared_occurrences: int,
 *     undeclared_escalated: bool
 * }
 */
final class ScheduleRegistry {
	// region FIELDS AND CONSTANTS

	/**
	 * Prefix for per-scope schedule-registration rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string OPTION_PREFIX = 'a8csp_bgje_schedule_registrations_';

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
	 * Current-request declarations keyed by complete schedule identity inside each scope.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<string, array<string, array{schedule: Schedule, job: Identity}>>
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
	 * Returns whether one scope has an authoritative registry option row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope Stable client identifier.
	 *
	 * @return  AbstractResult<bool, EngineError>
	 */
	#[\NoDiscard( 'a schedule-registry presence outcome must be handled, not dropped' )]
	public function scope_exists( string $scope ): AbstractResult {
		$selected = $this->rows->read( self::option_name( $scope ) );
		if ( $selected->is_failure() ) {
			return $selected;
		}

		return new Success( null !== $selected->value );
	}

	/**
	 * Returns valid persisted registrations belonging to exactly one scope.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope Stable client identifier.
	 *
	 * @return  AbstractResult<array<string, Registration>, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a schedule-registry read outcome must be handled, not dropped' )]
	public function registrations_for( string $scope ): AbstractResult {
		$option_name = self::option_name( $scope );
		$selected    = $this->rows->read( $option_name );
		if ( $selected->is_failure() ) {
			return $selected;
		}

		$raw = $selected->value;
		if ( null === $raw ) {
			return new Success( array() );
		}

		$stored = RawOptionDecoder::decode( $raw );
		if ( ! \is_array( $stored ) || self::has_registration_without_undeclared_markers( $scope, $stored ) ) {
			$this->warn_corrupt_row( $option_name );

			return new Failure( SchedulingError::registry_corrupt( $scope, $option_name ) );
		}

		return new Success( self::registrations_from_rows( $scope, $stored ) );
	}

	/**
	 * Returns every valid persisted registration keyed by its complete backend identity.
	 *
	 * @internal Read-only engine inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  AbstractResult<array<string, Registration>, EngineError>
	 */
	#[\NoDiscard( 'a schedule-registry read outcome must be handled, not dropped' )]
	public function all_registrations(): AbstractResult {
		$names = $this->rows->option_names( self::OPTION_PREFIX );
		if ( $names->is_failure() ) {
			return $names;
		}

		$registrations = array();
		foreach ( $names->value as $option_name ) {
			$scope = self::scope_from_option_name( $option_name );
			if ( null === $scope ) {
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
			if ( ! \is_array( $stored ) || self::has_registration_without_undeclared_markers( $scope, $stored ) ) {
				$this->warn_corrupt_row( $option_name );
				continue;
			}

			foreach ( self::registrations_from_rows( $scope, $stored ) as $identity => $registration ) {
				$registrations[ $identity ] = $registration;
			}
		}

		return new Success( $registrations );
	}

	/**
	 * Replaces one scope's request declarations and persisted registration state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, array{schedule: Schedule, job: Identity}> $schedules
	 * @phpstan-param array<string, Registration>                            $registrations
	 *
	 * @param   string $scope                       Stable client identifier.
	 * @param   array  $schedules                   Declared schedules keyed by complete identity.
	 * @param   array  $registrations               Persisted scope state keyed by complete identity.
	 * @param   bool   $reset_undeclared_episodes   Whether successful request declarations end their inactive episodes.
	 *
	 * @throws  \InvalidArgumentException When a registration identity is invalid or belongs to another scope.
	 *
	 * @return  ScopeReplacementOutcome Classified persistence outcome.
	 */
	#[\NoDiscard( 'a schedule-registry persistence failure must be handled, not dropped' )]
	public function replace_scope( string $scope, array $schedules, array $registrations, bool $reset_undeclared_episodes = false ): ScopeReplacementOutcome {
		$scope_registrations = self::scope_registrations( $scope, $registrations );
		if ( null === $scope_registrations ) {
			throw new \InvalidArgumentException( 'Schedule registration identities must be canonical and belong to the bound scope.' );
		}
		$replacement_baseline = $scope_registrations;
		if ( $reset_undeclared_episodes ) {
			// Resetting the baseline keeps a lost-update retry from re-inserting stale inactive episode state.
			foreach ( $replacement_baseline as $registration_key => $registration ) {
				$registration['undeclared_occurrences']    = 0;
				$registration['undeclared_escalated']      = false;
				$replacement_baseline[ $registration_key ] = $registration;
			}
		}
		$option_name = self::option_name( $scope );

		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$read = $this->rows->read( $option_name );
			if ( $read->is_failure() ) {
				return ScopeReplacementOutcome::ReadFailed;
			}

			$expected_raw = $read->value;
			if ( null === $expected_raw ) {
				if ( array() === $scope_registrations ) {
					$this->retain_scope( $scope, $schedules );

					return ScopeReplacementOutcome::Persisted;
				}

				$replacement_raw = self::serialize_registrations( $replacement_baseline );
				if ( RowWriteOutcome::Won === $this->rows->insert_if_absent( $option_name, $replacement_raw ) ) {
					$this->retain_scope( $scope, $schedules );

					return ScopeReplacementOutcome::Persisted;
				}

				continue;
			}

			$stored = RawOptionDecoder::decode( $expected_raw );
			if ( ! \is_array( $stored ) ) {
				return ScopeReplacementOutcome::Corrupt;
			}

			$replacement_registrations = $replacement_baseline;
			$stored_registrations      = self::registrations_from_rows( $scope, $stored );
			foreach ( $replacement_registrations as $registration_key => $registration ) {
				$stored_registration = $stored_registrations[ $registration_key ] ?? null;
				if ( null !== $stored_registration && $registration['fingerprint'] === $stored_registration['fingerprint'] ) {
					// An unchanged definition retains the selected generation while a completed declaration refresh ends its inactive episode.
					if ( $reset_undeclared_episodes ) {
						$stored_registration['undeclared_occurrences'] = 0;
						$stored_registration['undeclared_escalated']   = false;
					}
					$replacement_registrations[ $registration_key ] = $stored_registration;
				}
			}

			if ( $replacement_registrations === $stored ) {
				$this->retain_scope( $scope, $schedules );

				return ScopeReplacementOutcome::Persisted;
			}

			if ( array() === $scope_registrations ) {
				if ( RowDeleteOutcome::Deleted === $this->rows->delete_if_value_matches( $option_name, $expected_raw ) ) {
					$this->retain_scope( $scope, $schedules );

					return ScopeReplacementOutcome::Persisted;
				}

				$current = $this->rows->read( $option_name );
				if ( $current->is_failure() ) {
					return ScopeReplacementOutcome::ReadFailed;
				}

				// A lost delete whose row is already gone means another writer reached the goal state first.
				$current_raw = $current->value;
				if ( null === $current_raw ) {
					$this->retain_scope( $scope, $schedules );

					return ScopeReplacementOutcome::Persisted;
				}
				if ( $current_raw === $expected_raw ) {
					return ScopeReplacementOutcome::CasFailed;
				}

				continue;
			}

			$replacement_raw = self::serialize_registrations( $replacement_registrations );
			$write           = $this->rows->compare_and_swap( $option_name, $expected_raw, $replacement_raw );
			if ( RowWriteOutcome::Won === $write ) {
				$this->retain_scope( $scope, $schedules );

				return ScopeReplacementOutcome::Persisted;
			}
			if ( RowWriteOutcome::WriteFailed === $write ) {
				return ScopeReplacementOutcome::CasFailed;
			}
		}

		return ScopeReplacementOutcome::CasFailed;
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the request-local schedule identified by its backend registration key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete scope-qualified schedule identity.
	 *
	 * @return  array{schedule: Schedule, job: Identity}|null
	 */
	public function declaration( Identity $identity ): ?array {
		return $this->declarations[ $identity->scope() ][ (string) $identity ] ?? null;
	}

	/**
	 * Returns valid persisted timing state for one backend registration key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{scope}:{name}` schedule identity.
	 *
	 * @return  AbstractResult<Registration|null, EngineError|SchedulingError>
	 */
	#[\NoDiscard( 'a schedule-registry read outcome must be handled, not dropped' )]
	public function registration( string $registration_key ): AbstractResult {
		$identity = Identity::tryFrom( $registration_key );
		if ( null === $identity ) {
			return new Success( null );
		}

		$registrations = $this->registrations_for( $identity->scope() );
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
	 * @phpstan-param Registration $registration
	 *
	 * @param   Identity $identity             Complete scope-qualified schedule identity.
	 * @param   string   $observed_fingerprint Definition fingerprint observed before the update.
	 * @param   array    $registration         Complete registration timing state.
	 *
	 * @return  RegistrationUpdateOutcome Fenced row-update outcome.
	 */
	#[\NoDiscard( 'a schedule-registry persistence failure must be handled, not dropped' )]
	public function update_registration( Identity $identity, string $observed_fingerprint, array $registration ): RegistrationUpdateOutcome {
		$registration_key = (string) $identity;
		$scope            = $identity->scope();
		$option_name      = self::option_name( $scope );
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

	/**
	 * Classifies one undeclared delivery and atomically persists its pre-escalation aging transition.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity          Complete scope-qualified schedule identity.
	 * @param   int      $warning_threshold Consecutive undeclared occurrences required for escalation.
	 *
	 * @return  UndeclaredOccurrenceOutcome Fenced aging outcome.
	 */
	#[\NoDiscard( 'an undeclared occurrence outcome must be handled, not dropped' )]
	public function record_undeclared_occurrence( Identity $identity, int $warning_threshold ): UndeclaredOccurrenceOutcome {
		if ( 1 > $warning_threshold ) {
			return UndeclaredOccurrenceOutcome::Failed;
		}

		$registration_key = (string) $identity;
		$scope            = $identity->scope();
		$option_name      = self::option_name( $scope );
		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; ++$attempt ) {
			$expected = $this->rows->read( $option_name );
			if ( $expected->is_failure() ) {
				return UndeclaredOccurrenceOutcome::Failed;
			}

			$expected_raw = $expected->value;
			if ( null === $expected_raw ) {
				return UndeclaredOccurrenceOutcome::Pruned;
			}

			$stored = RawOptionDecoder::decode( $expected_raw );
			if ( ! \is_array( $stored ) ) {
				return UndeclaredOccurrenceOutcome::Failed;
			}
			if ( ! \array_key_exists( $registration_key, $stored ) ) {
				return UndeclaredOccurrenceOutcome::Pruned;
			}

			$current = self::registrations_from_rows( $scope, $stored )[ $registration_key ] ?? null;
			if ( null === $current ) {
				return UndeclaredOccurrenceOutcome::Failed;
			}
			if ( $current['undeclared_escalated'] ) {
				return UndeclaredOccurrenceOutcome::AlreadyEscalated;
			}

			$current['undeclared_occurrences'] = self::increment_counter( $current['undeclared_occurrences'] );
			$outcome                           = UndeclaredOccurrenceOutcome::Recorded;
			if ( $warning_threshold <= $current['undeclared_occurrences'] ) {
				$current['undeclared_escalated'] = true;
				$outcome                         = UndeclaredOccurrenceOutcome::Escalated;
			}

			$stored[ $registration_key ] = $current;
			$replacement_raw             = self::serialize_registrations( $stored );
			$write                       = $this->rows->compare_and_swap( $option_name, $expected_raw, $replacement_raw );
			if ( RowWriteOutcome::Won === $write ) {
				return $outcome;
			}
			if ( RowWriteOutcome::WriteFailed === $write ) {
				return UndeclaredOccurrenceOutcome::Failed;
			}

			$current_row = $this->rows->read( $option_name );
			if ( $current_row->is_failure() ) {
				return UndeclaredOccurrenceOutcome::Failed;
			}
			if ( null === $current_row->value ) {
				return UndeclaredOccurrenceOutcome::Pruned;
			}
			if ( $current_row->value === $expected_raw ) {
				return UndeclaredOccurrenceOutcome::Failed;
			}
		}

		return UndeclaredOccurrenceOutcome::Failed;
	}

	// endregion

	// region HELPERS

	/**
	 * Detects a canonical registration written without the required inactive-episode markers.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $scope Validated persisted scope key.
	 * @param   array<array-key, mixed> $rows  Persisted rows for one scope.
	 *
	 * @return  bool
	 */
	public static function has_registration_without_undeclared_markers( string $scope, array $rows ): bool {
		return \array_any(
			$rows,
			static fn ( mixed $row, int|string $registration_key ): bool => \is_string( $registration_key )
				&& \is_array( $row )
				&& Identity::tryFrom( $registration_key )?->scope() === $scope
				&& ( ! \array_key_exists( 'undeclared_occurrences', $row ) || ! \array_key_exists( 'undeclared_escalated', $row ) )
		);
	}

	/**
	 * Returns valid registration rows from one persisted scope slice.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $scope Validated persisted scope key.
	 * @param   array<array-key, mixed> $rows  Persisted rows for one scope.
	 *
	 * @return  array<string, Registration>
	 */
	private static function registrations_from_rows( string $scope, array $rows ): array {
		$registrations = array();
		foreach ( $rows as $registration_key => $row ) {
			if ( ! \is_string( $registration_key ) ) {
				continue;
			}

			$identity = Identity::tryFrom( $registration_key );
			if ( null === $identity || $scope !== $identity->scope() ) {
				continue;
			}

			if ( ! \is_array( $row ) ) {
				continue;
			}

			$misfire_skips          = $row['misfire_skips'] ?? 0;
			$overlap_skips          = $row['overlap_skips'] ?? 0;
			$undeclared_occurrences = $row['undeclared_occurrences'] ?? null;
			$undeclared_escalated   = $row['undeclared_escalated'] ?? null;
			if (
				! \is_string( $row['fingerprint'] ?? null )
				|| ! \is_int( $row['next_due'] ?? null )
				|| 1 > $row['next_due']
				|| ( null !== ( $row['last_fired'] ?? null ) && ! \is_int( $row['last_fired'] ?? null ) )
				|| ! \is_int( $misfire_skips )
				|| 0 > $misfire_skips
				|| ! \is_int( $overlap_skips )
				|| 0 > $overlap_skips
				|| ! \is_int( $undeclared_occurrences )
				|| 0 > $undeclared_occurrences
				|| ! \is_bool( $undeclared_escalated )
			) {
				continue;
			}

			$registrations[ $registration_key ] = array(
				'fingerprint'            => $row['fingerprint'],
				'next_due'               => $row['next_due'],
				'last_fired'             => $row['last_fired'] ?? null,
				'misfire_skips'          => $misfire_skips,
				'overlap_skips'          => $overlap_skips,
				'undeclared_occurrences' => $undeclared_occurrences,
				'undeclared_escalated'   => $undeclared_escalated,
			);
		}

		return $registrations;
	}

	/**
	 * Returns one scope's persisted row shape from complete registration identities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array<string, Registration> $registrations
	 *
	 * @param   string $scope         Stable client or engine identifier.
	 * @param   array  $registrations Persisted scope state keyed by complete identity.
	 *
	 * @return  array<string, Registration>|null
	 */
	private static function scope_registrations( string $scope, array $registrations ): ?array {
		$rows = array();
		foreach ( $registrations as $registration_key => $registration ) {
			if ( ! \is_string( $registration_key ) ) {
				return null;
			}

			$identity = Identity::tryFrom( $registration_key );
			if ( null === $identity || $scope !== $identity->scope() ) {
				return null;
			}

			$rows[ $registration_key ] = $registration;
		}

		return $rows;
	}

	/**
	 * Returns the option name for one scope's registration row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scope Stable client or engine identifier.
	 *
	 * @throws  \InvalidArgumentException When the scope fails identity validation.
	 *
	 * @return  string
	 */
	public static function option_name( string $scope ): string {
		Identity::validate_scope( $scope, true );

		return self::OPTION_PREFIX . $scope;
	}

	/**
	 * Returns one scope row's exact WordPress option representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $registrations Complete scope registration state.
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
	 * Increments an operational counter without overflowing persisted integer state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $counter Current non-negative count.
	 *
	 * @return  int
	 */
	private static function increment_counter( int $counter ): int {
		return \PHP_INT_MAX === $counter ? $counter : $counter + 1;
	}

	/**
	 * Returns the canonical scope encoded by one registration option name.
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
	public static function scope_from_option_name( string $option_name ): ?string {
		$scope = \substr( $option_name, \strlen( self::OPTION_PREFIX ) );
		try {
			Identity::validate_scope( $scope, true );
		} catch ( \InvalidArgumentException ) {
			return null;
		}

		return self::OPTION_PREFIX . $scope === $option_name ? $scope : null;
	}

	/**
	 * Reports one undecodable scope row without treating child-registration validation as row corruption.
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
			$this->logger->warning( 'Schedule registry option row is unreadable; maintenance reclaims it, then re-declare schedules on the next init.', array( 'option_name' => $option_name ) );
		} finally {
			unset( $this->corrupt_warnings_in_flight[ $option_name ] );
		}
	}

	/**
	 * Replaces one scope's request-local schedule definitions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                                                  $scope     Stable client identifier.
	 * @param   array<string, array{schedule: Schedule, job: Identity}> $schedules Declared schedules keyed by complete identity.
	 *
	 * @return  void
	 */
	private function retain_scope( string $scope, array $schedules ): void {
		if ( array() === $schedules ) {
			unset( $this->declarations[ $scope ] );
			return;
		}

		$this->declarations[ $scope ] = $schedules;
	}

	// endregion
}
