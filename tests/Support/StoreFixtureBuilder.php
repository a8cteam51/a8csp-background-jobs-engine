<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\HeartbeatOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockClaimOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\PortableArguments;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;
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
		if ( null === WorkIdentity::parts( $identity ) ) {
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
				$created = $store->create( $run_id, $state->start_args, $state->args_hash, $state->queue, $state->pending );
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
	 * @param   RunFailure              $failure    Consumer failure payload.
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
				$store = new RunHistory( $this->identity, new OptionRows( $wpdb ) );
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
	 *     declarations: array<string, array{schedule: \A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedule, task: string}>,
	 *     registrations: array<string, array{fingerprint: string, next_due: int, last_fired: int|null, misfire_skips: int, overlap_skips: int}>
	 * } $owner
	 *
	 * @param   array $owner Complete owner fixture request.
	 *
	 * @return  array{string, string}
	 */
	public function schedule_registration( array $owner ): array {
		return $this->isolated(
			function ( \wpdb $wpdb ) use ( $owner ): array {
				$registry = new ScheduleRegistry( new OptionRows( $wpdb ) );
				if ( ! $registry->replace_owner( $owner['owner'], $owner['declarations'], $owner['registrations'] ) ) {
					throw new \LogicException( 'Production ScheduleRegistry rejected an isolated registration fixture.' );
				}

				return $this->row( $wpdb, ScheduleRegistry::option_name( $owner['owner'] ) );
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

		$keys      = array( 'wpdb', 'a8csp_bgte_test_options', 'a8csp_bgte_test_option_autoload', 'a8csp_bgte_test_option_calls', 'a8csp_bgte_test_before_add_option', 'a8csp_bgte_test_get_option', 'a8csp_bgte_test_update_option_results', 'a8csp_bgte_test_update_option_values', 'a8csp_bgte_test_delete_option_results', 'a8csp_bgte_test_lifecycle_events', 'a8csp_bgte_test_blog_id', 'a8csp_bgte_test_cache', 'a8csp_bgte_test_cache_calls' );
		$preserved = array();
		foreach ( $keys as $key ) {
			$preserved[ $key ] = array(
				'exists' => \array_key_exists( $key, $GLOBALS ),
				'value'  => $GLOBALS[ $key ] ?? null,
			);
		}

		$wpdb                                       = new WpdbLockSpy();
		$GLOBALS['wpdb']                            = $wpdb;
		$GLOBALS['a8csp_bgte_test_options']         = array();
		$GLOBALS['a8csp_bgte_test_option_autoload'] = array();
		$GLOBALS['a8csp_bgte_test_option_calls']    = array();
		$GLOBALS['a8csp_bgte_test_update_option_results'] = array();
		$GLOBALS['a8csp_bgte_test_update_option_values']  = array();
		$GLOBALS['a8csp_bgte_test_delete_option_results'] = array();
		$GLOBALS['a8csp_bgte_test_lifecycle_events']      = array();
		$GLOBALS['a8csp_bgte_test_blog_id']               = 1;
		$GLOBALS['a8csp_bgte_test_cache']                 = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']           = array();
		unset( $GLOBALS['a8csp_bgte_test_before_add_option'], $GLOBALS['a8csp_bgte_test_get_option'] );

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
		$pattern = $wpdb->esc_like( 'a8csp_bgte_' ) . '%';
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
			&& $left->executing === $right->executing
			&& $left->start_args === $right->start_args
			&& $left->args_hash === $right->args_hash
			&& $left->queue === $right->queue
			&& $left->failed_attempts === $right->failed_attempts
			&& $left->action_seq === $right->action_seq
			&& $left->created_at === $right->created_at
			&& $left->heartbeat_at === $right->heartbeat_at
			&& ( $left->pending === $right->pending || ( null !== $left->pending && null !== $right->pending && $left->pending->stage === $right->pending->stage && $left->pending->mode === $right->pending->mode && $left->pending->fire_at === $right->pending->fire_at && $left->pending->priority === $right->pending->priority ) )
			&& $left->error === $right->error
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
			$options = $GLOBALS['a8csp_bgte_test_options'] ?? array();
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
