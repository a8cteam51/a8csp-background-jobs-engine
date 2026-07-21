<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\LockClaimOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Maintenance\MaintenanceJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\OccurrenceLeaseOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\OwnerReplacementOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\LifecycleEffects;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunReconciliation;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores\StoreFactory;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\RowWriteOutcome;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\JobRegistry;
use Psr\Log\NullLogger;

/**
 * Produces exact raw store fixtures by executing production encoders.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class StoreFixtureBuilder {
	// region MAGIC METHODS.

	/**
	 * Retains the canonical identity used by every production store write.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified work identity.
	 */
	private function __construct(
		private string $identity,
	) {}

	// endregion.

	// region FACTORIES.

	/**
	 * Creates a builder for one canonical work identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified work identity.
	 *
	 * @return  self
	 */
	public static function for_identity( string $identity ): self {
		EngineRig::bootstrap();
		if ( null === JobIdentity::parts( $identity ) ) {
			throw new \InvalidArgumentException( 'Store fixtures require one canonical owner-qualified work identity.' );
		}

		return new self( $identity );
	}

	// endregion.

	// region METHODS.

	/**
	 * Returns the production argument identity for one portable argument tree.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args Work arguments.
	 *
	 * @return  string
	 */
	public function args_hash( array $args ): string {
		$hash = PortableArguments::hash( $args );
		if ( null === $hash ) {
			throw new \InvalidArgumentException( 'Store fixtures require portable JSON-encodable arguments.' );
		}

		return $hash;
	}

	/**
	 * Returns one complete schedule-registration state value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $fingerprint            Schedule-definition fingerprint.
	 * @param   int      $next_due               Next due timestamp.
	 * @param   int|null $last_fired             Last fired timestamp.
	 * @param   int      $misfire_skips          Misfire-skip count.
	 * @param   int      $overlap_skips          Overlap-skip count.
	 * @param   int      $undeclared_occurrences Consecutive undeclared occurrence count.
	 * @param   bool     $undeclared_escalated   Whether the current undeclared episode warned.
	 *
	 * @return  array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int, undeclared_occurrences: int, undeclared_escalated: bool}
	 */
	public static function schedule_registration_state( string $fingerprint, int $next_due, ?int $last_fired = null, int $misfire_skips = 0, int $overlap_skips = 0, int $undeclared_occurrences = 0, bool $undeclared_escalated = false ): array {
		return array(
			'fingerprint'            => $fingerprint,
			'next_due'               => $next_due,
			'last_fired'             => $last_fired,
			'misfire_skips'          => $misfire_skips,
			'overlap_skips'          => $overlap_skips,
			'undeclared_occurrences' => $undeclared_occurrences,
			'undeclared_escalated'   => $undeclared_escalated,
		);
	}

	/**
	 * Returns a registration fixture missing its mandatory inactive-episode markers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{string, string} $fixture Complete production registration fixture.
	 *
	 * @return  array{string, string}
	 */
	public static function schedule_registration_without_undeclared_markers( array $fixture ): array {
		$registrations = RawOptionDecoder::decode( $fixture[1] );
		if ( ! \is_array( $registrations ) ) {
			throw new \InvalidArgumentException( 'The schedule-registration fixture must decode to an owner row.' );
		}

		foreach ( $registrations as $registration_key => $registration ) {
			if ( ! \is_array( $registration ) ) {
				continue;
			}

			unset( $registration['undeclared_occurrences'], $registration['undeclared_escalated'] );
			$registrations[ $registration_key ] = $registration;
		}

		$raw = \maybe_serialize( $registrations );
		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'WordPress must serialize the incomplete schedule-registration fixture to a string.' );
		}

		return array( $fixture[0], $raw );
	}

	/**
	 * Returns one active-run option name and exact raw value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $run_id Run identifier.
	 * @param   RunState $state  Complete active-run state.
	 *
	 * @return  array{string, string}
	 */
	public function run( string $run_id, RunState $state ): array {
		return $this->isolated(
			function ( \wpdb $wpdb ) use ( $run_id, $state ): array {
				$store   = new RunStore( $this->identity, new FixedClock( $state->created_at ), new OptionRows( $wpdb ) );
				$created = $store->create( $run_id, $state->kind, $state->start_args, $state->args_hash, $state->queue, $state->pending );
				if ( null === $created ) {
					throw new \LogicException( 'Production RunStore rejected an isolated active-run fixture.' );
				}

				$raw = self::same_state( $created, $state )
					? $this->raw_option( RunIdentity::option_name( $this->identity, $run_id ) )
					: $store->replace_if_state_matches( $run_id, $created, $state );
				if ( null === $raw ) {
					throw new \LogicException( 'Production RunStore could not serialize the requested active-run fixture.' );
				}

				return array( RunIdentity::option_name( $this->identity, $run_id ), $raw );
			}
		);
	}

	/**
	 * Returns one failed-run option name and exact raw value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int                     $failed_at  Failure timestamp.
	 * @param   array<array-key, mixed> $start_args Original run arguments.
	 * @param   RunFailure              $failure    Client failure payload.
	 * @param   EngineError|null        $error      Internal failure detail.
	 *
	 * @return  array{string, string}
	 */
	public function failed( int $failed_at, array $start_args, RunFailure $failure, ?EngineError $error = null ): array {
		return $this->failed_runs(
			array(
				array(
					'failed_at'  => $failed_at,
					'start_args' => $start_args,
					'failure'    => $failure,
					'error'      => $error,
				),
			)
		);
	}

	/**
	 * Returns one failed-run option containing each requested entry in record order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<array{
	 *     failed_at: int,
	 *     start_args: array<array-key, mixed>,
	 *     failure: RunFailure,
	 *     error?: EngineError|null
	 * }> $entries
	 *
	 * @param   array $entries Failed-run writes in record order.
	 *
	 * @return  array{string, string}
	 */
	public function failed_runs( array $entries ): array {
		if ( array() === $entries ) {
			throw new \InvalidArgumentException( 'Failed-run fixtures require at least one entry.' );
		}

		return $this->isolated(
			function ( \wpdb $wpdb ) use ( $entries ): array {
				$store = new FailedRunStore( $this->identity, new OptionRows( $wpdb ), new NullLogger() );
				foreach ( $entries as $entry ) {
					$failure = $entry['failure'];
					$error   = $entry['error'] ?? null;
					if ( ! $store->record( $failure->run_id, $entry['failed_at'], $entry['start_args'], $failure->attempts, $error ?? new EngineError( $failure->summary ), $failure ) ) {
						throw new \LogicException( 'Production FailedRunStore rejected an isolated failed-run fixture.' );
					}
				}

				return $this->row( $wpdb, FailedRunStore::OPTION_PREFIX . $this->identity );
			}
		);
	}

	/**
	 * Returns one run-history option name and exact raw value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<array{run_id: string, args_hash: string}>                    $started  Started entries in record order.
	 * @param   list<array{run_id: string, args_hash: string, status: RunStatus}> $terminal Terminal entries in record order.
	 *
	 * @return  array{string, string}
	 */
	public function history( array $started = array(), array $terminal = array() ): array {
		return $this->isolated(
			function ( \wpdb $wpdb ) use ( $started, $terminal ): array {
				$store = new RunHistory( $this->identity, new OptionRows( $wpdb ), new NullLogger() );
				foreach ( $started as $entry ) {
					if ( ! $store->record_started( $entry['run_id'], $entry['args_hash'] ) ) {
						throw new \LogicException( 'Production RunHistory rejected an isolated started fixture.' );
					}
				}
				foreach ( $terminal as $entry ) {
					if ( ! $store->record_terminal( $entry['run_id'], $entry['args_hash'], $entry['status'] ) ) {
						throw new \LogicException( 'Production RunHistory rejected an isolated terminal fixture.' );
					}
				}

				return $this->row( $wpdb, RunHistory::OPTION_PREFIX . $this->identity );
			}
		);
	}

	/**
	 * Returns one latest-run pointer option name and exact raw value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<array{run_id: string, args_hash: string}> $entries Pointer writes in record order.
	 *
	 * @return  array{string, string}
	 */
	public function latest( array $entries ): array {
		return $this->isolated(
			function ( \wpdb $wpdb ) use ( $entries ): array {
				$store = new LatestRunPointer( $this->identity, new OptionRows( $wpdb ) );
				foreach ( $entries as $entry ) {
					if ( ! $store->record( $entry['run_id'], $entry['args_hash'] ) ) {
						throw new \LogicException( 'Production LatestRunPointer rejected an isolated pointer fixture.' );
					}
				}

				return $this->row( $wpdb, LatestRunPointer::OPTION_PREFIX . $this->identity );
			}
		);
	}

	/**
	 * Returns one owner's schedule-registration option name and exact raw value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{
	 *     owner: string,
	 *     declarations: array<string, array{schedule: \A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\Schedule, job: string}>,
	 *     registrations: array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int, undeclared_occurrences: int, undeclared_escalated: bool}>
	 * } $owner
	 *
	 * @param   array $owner Complete owner fixture request.
	 *
	 * @return  array{string, string}
	 */
	public function schedule_registration( array $owner ): array {
		return $this->isolated(
			function ( \wpdb $wpdb ) use ( $owner ): array {
				$registry = new ScheduleRegistry( new OptionRows( $wpdb ), new NullLogger() );
				if ( OwnerReplacementOutcome::Persisted !== $registry->replace_owner( $owner['owner'], $owner['declarations'], $owner['registrations'] ) ) {
					throw new \LogicException( 'Production ScheduleRegistry rejected an isolated registration fixture.' );
				}

				return $this->row( $wpdb, ScheduleRegistry::option_name( $owner['owner'] ) );
			}
		);
	}

	/**
	 * Returns one occurrence-lease option name and exact raw value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $claimed_at  Claim timestamp.
	 * @param   int $claim_token Deterministic claim-token source.
	 *
	 * @return  array{string, string}
	 */
	public function occurrence_lease( int $claimed_at, int $claim_token = 42 ): array {
		return $this->isolated(
			function ( \wpdb $wpdb ) use ( $claimed_at, $claim_token ): array {
				$rows  = new OptionRows( $wpdb );
				$claim = ( new OccurrenceLease( $rows, new FixedClock( $claimed_at ), new RecordingRandomizer( $claim_token ) ) )->claim( $this->identity );
				if ( OccurrenceLeaseOutcome::Claimed !== $claim->outcome ) {
					throw new \LogicException( 'Production OccurrenceLease rejected an isolated lease fixture.' );
				}

				return $this->only_row_under( $rows, $wpdb, OccurrenceLease::OPTION_PREFIX );
			}
		);
	}

	/**
	 * Returns one cleanup-intent option name and exact raw value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $created_at Intent timestamp.
	 *
	 * @return  array{string, string}
	 */
	public function cleanup_intent( int $created_at ): array {
		return $this->isolated(
			function ( \wpdb $wpdb ) use ( $created_at ): array {
				$rows      = new OptionRows( $wpdb );
				$scheduler = new SchedulerFacade( array( new RecordingBackend() ) );
				$intents   = new CleanupIntents( new ScheduleRegistry( $rows, new NullLogger() ), $scheduler, $rows, new FixedClock( $created_at ), new NullLogger() );
				$intents->record_intent( $this->identity );

				return $this->only_row_under( $rows, $wpdb, CleanupIntents::OPTION_PREFIX );
			}
		);
	}

	/**
	 * Returns the cursor row authored by one bounded production cleanup-intent sweep.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $created_at Intent timestamp.
	 *
	 * @return  array{string, string}
	 */
	public function cleanup_intent_sweep_cursor( int $created_at ): array {
		return $this->isolated(
			function ( \wpdb $wpdb ) use ( $created_at ): array {
				$rows      = new OptionRows( $wpdb );
				$scheduler = new SchedulerFacade( array( new RecordingBackend() ) );
				$intents   = new CleanupIntents( new ScheduleRegistry( $rows, new NullLogger() ), $scheduler, $rows, new FixedClock( $created_at ), new NullLogger() );
				for ( $index = 0; $index < 500; ++$index ) {
					$intents->record_intent( $this->identity . '-' . \sprintf( '%03d', $index ) );
				}

				$intents->converge_pending_intents();

				return $this->row( $wpdb, CleanupIntents::SWEEP_CURSOR_OPTION );
			}
		);
	}

	/**
	 * Returns the cursor row authored by one incomplete production maintenance pass.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{string, string}
	 */
	public function sweep_cursor(): array {
		return $this->isolated(
			function ( \wpdb $wpdb ): array {
				$clock   = new FixedClock( 1_700_000_000 );
				$logger  = new NullLogger();
				$backend = new RecordingBackend();
				$rows    = new OptionRows( $wpdb );
				for ( $index = 0; $index < 500; ++$index ) {
					if ( RowWriteOutcome::Won !== $rows->insert_if_absent( RunIdentity::option_prefix() . '!fixture-' . \sprintf( '%03d', $index ), 'schema-invalid-run' ) ) {
						throw new \LogicException( 'Store fixtures could not stage the maintenance scan budget.' );
					}
				}

				$before         = $this->option_names( $rows, '' );
				$guard          = new OverlapGuard( $clock, $logger, $rows );
				$stores         = new StoreFactory( $clock, $rows, $logger );
				$windows        = new LockWindows( $clock, $logger );
				$effects        = new LifecycleEffects( $guard, $stores, $logger );
				$transitions    = new RunTransitions( $guard, $stores, $clock, $windows, $logger, $effects );
				$reconciliation = new RunReconciliation( $guard, $stores, $clock, $logger, $windows, $transitions, $effects, new JobRegistry(), $backend );
				$intents        = new CleanupIntents( new ScheduleRegistry( $rows, $logger ), new SchedulerFacade( array( $backend ) ), $rows, $clock, $logger );

				( new MaintenanceJob( $rows, $reconciliation, $guard, $intents, $logger ) )->handle( array(), new RunContext( 'fixture-maintenance-run', array() ) );
				$added = \array_values( \array_diff( $this->option_names( $rows, '' ), $before ) );
				if ( 1 !== \count( $added ) ) {
					throw new \LogicException( 'Production MaintenanceJob did not emit exactly one isolated cursor row.' );
				}

				return $this->row( $wpdb, $added[0] );
			}
		);
	}

	/**
	 * Returns one overlap-lock option name and exact raw value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $args_hash   Stable single-flight identity.
	 * @param   string $run_id      Lock owner.
	 * @param   int    $claimed_at  Claim timestamp.
	 * @param   int    $heartbeat_at Latest liveness timestamp.
	 *
	 * @return  array{string, string}
	 */
	public function lock( string $args_hash, string $run_id, int $claimed_at, int $heartbeat_at ): array {
		return $this->isolated(
			function ( \wpdb $wpdb ) use ( $args_hash, $run_id, $claimed_at, $heartbeat_at ): array {
				$clock = new FixedClock( $claimed_at );
				$guard = new OverlapGuard( $clock, new RecordingLogger(), new OptionRows( $wpdb ) );
				if ( LockClaimOutcome::Claimed !== $guard->claim( $this->identity, $args_hash, $run_id, 0 ) ) {
					throw new \LogicException( 'Production OverlapGuard rejected an isolated lock fixture.' );
				}

				if ( $heartbeat_at !== $claimed_at ) {
					$clock->timestamp = $heartbeat_at;
					if ( HeartbeatOutcome::Owned !== $guard->heartbeat( $this->identity, $args_hash, $run_id ) ) {
						throw new \LogicException( 'Production OverlapGuard could not serialize the requested lock heartbeat.' );
					}
				}

				return $this->row( $wpdb, OverlapGuard::OPTION_PREFIX . $this->identity . '_' . $args_hash );
			}
		);
	}

	// endregion.

	// region CORRUPTION FIXTURES.

	/**
	 * Returns a production failed-run row with one deliberately unreadable member appended.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{string, string} $fixture Complete production failed-run fixture.
	 *
	 * @return  array{string, string}
	 */
	public static function failed_runs_with_corrupt_member( array $fixture ): array {
		$entries = RawOptionDecoder::decode( $fixture[1] );
		if ( ! \is_array( $entries ) ) {
			throw new \InvalidArgumentException( 'The failed-run fixture must decode to an entry list.' );
		}

		$entries[] = array( 'run_id' => null );

		return array( $fixture[0], self::corrupt_row( $entries ) );
	}

	/**
	 * Returns a whole failed-run row that cannot decode to an entry list.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   object|null $value Deliberately unreadable row value, or null for an inert object.
	 *
	 * @return  array{string, string}
	 */
	public function unreadable_failed_runs( ?object $value = null ): array {
		return array( FailedRunStore::OPTION_PREFIX . $this->identity, self::corrupt_row( $value ?? new \stdClass() ) );
	}

	/**
	 * Returns a production failed-run row whose first member has an unknown failure stage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{string, string} $fixture Complete production failed-run fixture.
	 *
	 * @return  array{string, string}
	 */
	public static function failed_runs_with_unknown_stage( array $fixture ): array {
		$entries = RawOptionDecoder::decode( $fixture[1] );
		if ( ! \is_array( $entries ) || ! \is_array( $entries[0] ?? null ) || ! \is_array( $entries[0]['error'] ?? null ) ) {
			throw new \InvalidArgumentException( 'The failed-run fixture must contain a complete first entry.' );
		}

		$entries[0]['error']['stage'] = 'unknown';

		return array( $fixture[0], self::corrupt_row( $entries ) );
	}

	/**
	 * Returns one deliberately unreadable run row for a canonical or malformed run-id suffix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $run_id Persisted run-id suffix.
	 *
	 * @return  array{string, string}
	 */
	public function unreadable_run( string $run_id ): array {
		return array( RunIdentity::option_name_prefix( $this->identity ) . $run_id, self::corrupt_row( array( 'state' => 'unreadable' ) ) );
	}

	/**
	 * Mints one deliberately malformed row for corruption tests that cannot pass through a canonical store writer.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed>|object $value Deliberately malformed row value.
	 *
	 * @return  string
	 */
	public static function corrupt_row( array|object $value ): string {
		EngineRig::bootstrap();
		$raw = \maybe_serialize( $value );
		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'Corrupt row fixtures require a serializable array or object.' );
		}

		return $raw;
	}

	// endregion.

	// region HELPERS.

	/**
	 * Executes one fixture write without changing the active test's option state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(\wpdb): array{string, string} $produce
	 *
	 * @param   \Closure $produce Production store write.
	 *
	 * @throws  \LogicException When the real WordPress database boundary is unavailable.
	 *
	 * @return  array{string, string}
	 */
	private function isolated( \Closure $produce ): array {
		if ( \defined( 'WPINC' ) ) {
			return $this->isolated_wordpress( $produce );
		}

		$keys      = array( 'wpdb', 'a8csp_bgje_test_options', 'a8csp_bgje_test_option_autoload', 'a8csp_bgje_test_option_calls', 'a8csp_bgje_test_before_add_option', 'a8csp_bgje_test_get_option', 'a8csp_bgje_test_update_option_results', 'a8csp_bgje_test_update_option_values', 'a8csp_bgje_test_delete_option_results', 'a8csp_bgje_test_lifecycle_events', 'a8csp_bgje_test_blog_id', 'a8csp_bgje_test_cache', 'a8csp_bgje_test_cache_calls' );
		$preserved = array();
		foreach ( $keys as $key ) {
			$preserved[ $key ] = array(
				'exists' => \array_key_exists( $key, $GLOBALS ),
				'value'  => $GLOBALS[ $key ] ?? null,
			);
		}

		$wpdb                                       = new WpdbLockSpy();
		$GLOBALS['wpdb']                            = $wpdb;
		$GLOBALS['a8csp_bgje_test_options']         = array();
		$GLOBALS['a8csp_bgje_test_option_autoload'] = array();
		$GLOBALS['a8csp_bgje_test_option_calls']    = array();
		$GLOBALS['a8csp_bgje_test_update_option_results'] = array();
		$GLOBALS['a8csp_bgje_test_update_option_values']  = array();
		$GLOBALS['a8csp_bgje_test_delete_option_results'] = array();
		$GLOBALS['a8csp_bgje_test_lifecycle_events']      = array();
		$GLOBALS['a8csp_bgje_test_blog_id']               = 1;
		$GLOBALS['a8csp_bgje_test_cache']                 = array();
		$GLOBALS['a8csp_bgje_test_cache_calls']           = array();
		unset( $GLOBALS['a8csp_bgje_test_before_add_option'], $GLOBALS['a8csp_bgje_test_get_option'] );

		try {
			$pair = $produce( $wpdb );

			return $pair;
		} finally {
			foreach ( $preserved as $key => $state ) {
				if ( $state['exists'] ) {
					$GLOBALS[ $key ] = $state['value'];
				} else {
					unset( $GLOBALS[ $key ] );
				}
			}
		}
	}

	/**
	 * Executes one fixture write while preserving every real engine option row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(\wpdb): array{string, string} $produce
	 *
	 * @param   \Closure $produce Production store write.
	 *
	 * @throws  \LogicException When the real WordPress database boundary is unavailable.
	 *
	 * @return  array{string, string}
	 */
	private function isolated_wordpress( \Closure $produce ): array {
		$wpdb    = $this->wordpress_database();
		$pattern = $wpdb->esc_like( 'a8csp_bgje_' ) . '%';
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT `option_name`, `option_value`, `autoload` FROM %i WHERE `option_name` LIKE %s', $wpdb->options, $pattern ), \ARRAY_A );
		if ( ! \is_array( $rows ) ) {
			throw new \LogicException( 'Store fixtures could not snapshot the real WordPress option rows.' );
		}

		$snapshot = array();
		foreach ( $rows as $row ) {
			if ( ! \is_array( $row ) || ! \is_string( $row['option_name'] ?? null ) || ! \is_string( $row['option_value'] ?? null ) || ! \is_string( $row['autoload'] ?? null ) ) {
				throw new \LogicException( 'Store fixtures received an invalid real WordPress option row.' );
			}

			$snapshot[] = array(
				'option_name'  => $row['option_name'],
				'option_value' => $row['option_value'],
				'autoload'     => $row['autoload'],
			);
		}

		// Production stores need a clean engine-row window: a caller-persisted row under the same option name makes add_option refuse the fixture write.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `option_name` LIKE %s', $wpdb->options, $pattern ) ?? throw new \LogicException( 'Store fixtures could not prepare the real WordPress option cleanup.' ) );
		\wp_cache_flush();

		try {
			return $produce( $wpdb );
		} finally {
			try {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `option_name` LIKE %s', $wpdb->options, $pattern ) );

				foreach ( $snapshot as $row ) {
					$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s)', $wpdb->options, $row['option_name'], $row['option_value'], $row['autoload'] ) ?? throw new \LogicException( 'Store fixtures could not prepare a real WordPress option restoration.' ) );
				}
			} finally {
				\wp_cache_flush();
			}
		}
	}

	/**
	 * Returns one exact raw row from the isolated database.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \wpdb  $wpdb        Isolated database boundary.
	 * @param   string $option_name Expected option name.
	 *
	 * @throws  \LogicException When the production store does not emit the expected row.
	 *
	 * @return  array{string, string}
	 */
	private function row( \wpdb $wpdb, string $option_name ): array {
		if ( \defined( 'WPINC' ) ) {
			$raw = $this->raw_option( $option_name );
		} else {
			$raw = $wpdb instanceof WpdbLockSpy ? ( $wpdb->rows[ $option_name ] ?? null ) : null;
		}

		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'The production store did not emit the expected isolated raw row.' );
		}

		return array( $option_name, $raw );
	}

	/**
	 * Returns the only production-authored row under one option prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OptionRows $rows   Authoritative option-name reader.
	 * @param   \wpdb      $wpdb   Isolated database boundary.
	 * @param   string     $prefix Complete option prefix.
	 *
	 * @return  array{string, string}
	 */
	private function only_row_under( OptionRows $rows, \wpdb $wpdb, string $prefix ): array {
		$names = $this->option_names( $rows, $prefix );
		if ( 1 !== \count( $names ) ) {
			throw new \LogicException( 'The production store did not emit exactly one isolated row.' );
		}

		return $this->row( $wpdb, $names[0] );
	}

	/**
	 * Returns authoritative option names or rejects an isolated read failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OptionRows $rows   Authoritative option-name reader.
	 * @param   string     $prefix Complete literal prefix.
	 *
	 * @return  list<string>
	 */
	private function option_names( OptionRows $rows, string $prefix ): array {
		$selected = $rows->option_names( $prefix );
		if ( $selected->is_failure() ) {
			throw new \LogicException( 'Store fixtures could not enumerate isolated option rows.' );
		}

		return $selected->value;
	}

	/**
	 * Returns whether two typed states contain exactly the same persisted values.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunState $left  First persisted state.
	 * @param   RunState $right Second persisted state.
	 *
	 * @return  bool
	 */
	private static function same_state( RunState $left, RunState $right ): bool {
		// Every RunState field is explicit because omitting a new field can falsely classify distinct persisted states as identical.
		return $left->status === $right->status
			&& $left->kind === $right->kind
			&& $left->executing === $right->executing
			&& $left->start_args === $right->start_args
			&& $left->args_hash === $right->args_hash
			&& $left->queue === $right->queue
			&& $left->failed_attempts === $right->failed_attempts
			&& $left->action_sequence === $right->action_sequence
			&& $left->created_at === $right->created_at
			&& $left->heartbeat_at === $right->heartbeat_at
			&& ( $left->pending === $right->pending || ( null !== $left->pending && null !== $right->pending && $left->pending->stage === $right->pending->stage && $left->pending->mode === $right->pending->mode && $left->pending->fire_at === $right->pending->fire_at && $left->pending->priority === $right->pending->priority ) )
			&& $left->error === $right->error
			&& $left->previous_completed_run_id === $right->previous_completed_run_id
			&& $left->effects === $right->effects;
	}

	/**
	 * Returns one exact raw option created through add_option().
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $option_name Expected option name.
	 *
	 * @throws  \LogicException When the production store does not emit the expected option.
	 *
	 * @return  string
	 */
	private function raw_option( string $option_name ): string {
		if ( \defined( 'WPINC' ) ) {
			$wpdb = $this->wordpress_database();
			$raw  = $wpdb->get_var( $wpdb->prepare( 'SELECT `option_value` FROM %i WHERE `option_name` = %s', $wpdb->options, $option_name ) );
		} else {
			$options = $GLOBALS['a8csp_bgje_test_options'] ?? array();
			$raw     = \is_array( $options ) && \array_key_exists( $option_name, $options )
				? \maybe_serialize( $options[ $option_name ] )
				: null;
		}

		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'The production run store did not emit the expected isolated option.' );
		}

		return $raw;
	}

	/**
	 * Returns the real WordPress database boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @throws  \LogicException When the real WordPress database boundary is unavailable.
	 *
	 * @return  \wpdb
	 */
	private function wordpress_database(): \wpdb {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! $wpdb instanceof \wpdb ) {
			throw new \LogicException( 'Store fixtures require the real WordPress database boundary.' );
		}

		return $wpdb;
	}

	// endregion.
}
