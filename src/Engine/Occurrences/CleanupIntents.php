<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Error\EngineError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RowDeleteOutcome;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

\defined( 'ABSPATH' ) || exit;

/**
 * Stores durable unknown-chain cleanup intents and converges scheduler state.
 *
 * @internal Engine schedule cleanup only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class CleanupIntents {
	// region FIELDS AND CONSTANTS

	/**
	 * Prefix for durable unknown-chain cleanup intents.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string OPTION_PREFIX = 'a8csp_bgte_cleanup_intent_';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ScheduleRegistry $registry    Owner-scoped schedule registry.
	 * @param   SchedulerFacade  $scheduler   Scheduling backend facade.
	 * @param   OptionRows       $option_rows Authoritative cleanup-intent row I/O.
	 * @param   ClockInterface   $clock       Current-time source.
	 * @param   LoggerInterface  $logger      Log event sink.
	 */
	public function __construct(
		private ScheduleRegistry $registry,
		private SchedulerFacade $scheduler,
		private OptionRows $option_rows,
		private ClockInterface $clock,
		private LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Records one durable cleanup intent while preserving an existing generation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @throws  \LogicException When WordPress does not serialize the intent to a string.
	 *
	 * @return  void
	 */
	public function record_intent( string $registration_key ): void {
		$raw = \maybe_serialize(
			array(
				'key'        => $registration_key,
				'created_at' => $this->clock->now()->getTimestamp(),
			)
		);
		if ( ! \is_string( $raw ) ) {
			throw new \LogicException( 'WordPress must serialize an unknown-schedule cleanup intent to a string.' );
		}

		$this->option_rows->insert_if_absent( self::intent_option_name( $registration_key ), $raw );
	}

	/**
	 * Resolves one observed cleanup intent against current registry and scheduler state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  bool Whether the observed intent no longer needs convergence.
	 */
	public function converge_unknown_chain( string $registration_key ): bool {
		$selected = $this->read_intent( $registration_key );
		if ( $selected->is_failure() ) {
			return false;
		}

		$expected_raw = $selected->value;
		if ( null === $expected_raw ) {
			return true;
		}

		$registration = $this->registry->registration( $registration_key );
		if ( $registration->is_failure() ) {
			return false;
		}

		if ( null !== $registration->value ) {
			return $this->clear_intent( $registration_key, $expected_raw );
		}

		$clearance = $this->scheduler->unschedule_for_convergence( OccurrenceDelivery::SCHEDULE_HOOK, array( $registration_key ), $registration_key );
		$removed   = $clearance->result;
		if ( $removed->is_failure() ) {
			$this->log_pending_intent(
				'Unknown schedule cleanup intent remains pending because verified clearance failed.',
				array(
					'registration_key' => $registration_key,
					'error'            => $removed->error->message,
				)
			);

			return false;
		}

		if ( ! $clearance->authoritative ) {
			$this->log_pending_intent( 'Unknown schedule cleanup intent remains pending until every scheduler backend is ready or absent.', array( 'registration_key' => $registration_key ), LogLevel::DEBUG );

			return false;
		}

		return $this->clear_intent( $registration_key, $expected_raw );
	}

	/**
	 * Converges every well-formed durable unknown-chain cleanup intent.
	 *
	 * @internal Engine maintenance only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function converge_pending_intents(): void {
		try {
			$registration_keys = $this->intent_keys();
		} catch ( \Throwable $throwable ) {
			$this->log_pending_intent( 'Unknown schedule cleanup intents could not be enumerated during maintenance; retry on the next sweep.', array( 'exception' => $throwable ) );

			return;
		}

		foreach ( $registration_keys as $registration_key ) {
			try {
				$this->converge_unknown_chain( $registration_key );
			} catch ( \Throwable $throwable ) {
				$this->log_pending_intent(
					'Unknown schedule cleanup intent could not converge during maintenance; retry on the next sweep.',
					array(
						'registration_key' => $registration_key,
						'exception'        => $throwable,
					)
				);
			}
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Reads one intent generation as exact persisted bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  AbstractResult<string|null, EngineError>
	 */
	private function read_intent( string $registration_key ): AbstractResult {
		return $this->option_rows->read( self::intent_option_name( $registration_key ) );
	}

	/**
	 * Deletes the observed cleanup-intent generation or confirms the row is absent.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 * @param   string $expected_raw     Exact selected intent value.
	 *
	 * @return  bool
	 */
	private function clear_intent( string $registration_key, string $expected_raw ): bool {
		if ( RowDeleteOutcome::Deleted === $this->option_rows->delete_if_value_matches( self::intent_option_name( $registration_key ), $expected_raw ) ) {
			return true;
		}

		$selected = $this->read_intent( $registration_key );
		if ( $selected->is_failure() ) {
			return false;
		}

		return null === $selected->value;
	}

	/**
	 * Returns registration keys carried by well-formed cleanup-intent rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>
	 */
	private function intent_keys(): array {
		$keys            = array();
		$malformed_count = 0;
		$names           = $this->option_rows->option_names( self::OPTION_PREFIX );
		if ( $names->is_failure() ) {
			return $keys;
		}

		foreach ( $names->value as $option_name ) {
			$selected = $this->option_rows->read( $option_name );
			if ( $selected->is_failure() ) {
				continue;
			}

			$raw = $selected->value;
			if ( null === $raw ) {
				continue;
			}

			$value = RawOptionDecoder::decode( $raw );
			if (
				! \is_array( $value )
				|| 2 !== \count( $value )
				|| ! \is_string( $value['key'] ?? null )
				|| ! \is_int( $value['created_at'] ?? null )
				|| self::intent_option_name( $value['key'] ) !== $option_name
			) {
				++$malformed_count;
				continue;
			}

			$keys[] = $value['key'];
		}
		if ( 0 < $malformed_count ) {
			$this->log_pending_intent(
				'Malformed unknown-schedule cleanup intent rows were skipped during maintenance; repair or remove them before the next sweep.',
				array(
					'count'         => $malformed_count,
					'option_prefix' => self::OPTION_PREFIX,
				)
			);
		}

		return $keys;
	}

	/**
	 * Returns the fixed-size option identity for one registration key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{owner}:{name}` schedule identity.
	 *
	 * @return  string
	 */
	private static function intent_option_name( string $registration_key ): string {
		return self::OPTION_PREFIX . \hash( 'sha256', $registration_key );
	}

	/**
	 * Emits a maintenance diagnostic without allowing the diagnostic sink to abort the sweep.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string               $message Log message.
	 * @param   array<string, mixed> $context Log context.
	 * @param   'debug'|'warning'    $level   Diagnostic severity.
	 *
	 * @return  void
	 */
	private function log_pending_intent( string $message, array $context, string $level = LogLevel::WARNING ): void {
		try {
			$this->logger->log( $level, $message, $context );
		} catch ( \Throwable ) {
			// Maintenance convergence remains retryable even when diagnostics are unavailable.
			return;
		}
	}

	// endregion
}
