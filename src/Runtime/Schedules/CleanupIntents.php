<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\RowDeleteOutcome;
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
	public const string OPTION_PREFIX = 'a8csp_bgje_cleanup_intent_';

	/**
	 * Maximum cleanup-intent option names inspected per maintenance invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int INTENT_SWEEP_BUDGET = 500;

	/**
	 * Durable cursor row for the cleanup-intent maintenance sweep.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	public const string SWEEP_CURSOR_OPTION = 'a8csp_bgje_cleanup_sweep_cursor';

	/**
	 * Maximum option names returned by one cleanup-intent enumeration query.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int SWEEP_PAGE_SIZE = 100;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ScheduleRegistry $registry    Per-scope schedule registry.
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
	 * @param   string $registration_key `{scope}:{name}` schedule identity.
	 *
	 * @throws  \LogicException When WordPress does not serialize the intent to a string.
	 *
	 * @return  void
	 */
	public function record_intent( string $registration_key ): void {
		$raw = \maybe_serialize(
			array(
				'schedule_identity' => $registration_key,
				'created_at'        => $this->clock->now()->getTimestamp(),
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
	 * @param   string $registration_key `{scope}:{name}` schedule identity.
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

		return $this->converge_selected_intent( $registration_key, $expected_raw );
	}

	/**
	 * Converges a bounded page of durable unknown-chain cleanup intents, resuming from a durable cursor on the next sweep.
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
			$selected_cursor = $this->option_rows->read( self::SWEEP_CURSOR_OPTION );
			if ( $selected_cursor->is_failure() ) {
				return;
			}

			$cursor_raw      = $selected_cursor->value;
			$cursor          = $this->decode_sweep_cursor( $cursor_raw );
			$malformed_count = 0;
			$scanned         = 0;
			// The budget is an exact multiple of SWEEP_PAGE_SIZE, so full pages stop with zero overshoot; a short page ends the sweep.
			while ( $scanned < self::INTENT_SWEEP_BUDGET ) {
				$page = $this->option_rows->option_names_after( self::OPTION_PREFIX, $cursor, self::SWEEP_PAGE_SIZE );
				if ( $page->is_failure() ) {
					return;
				}

				$intents = $this->intents_for_names( $page->value['names'] );
				if ( $intents->is_failure() ) {
					return;
				}

				$malformed_count += $intents->value['malformed_count'];
				foreach ( $intents->value['entries'] as $intent ) {
					try {
						$this->converge_selected_intent( $intent['registration_key'], $intent['expected_raw'] );
					} catch ( \Throwable $throwable ) {
						$this->log_pending_intent(
							'Unknown schedule cleanup intent could not converge during maintenance; retry on the next sweep.',
							array(
								'schedule_identity' => $intent['registration_key'],
								'exception'         => $throwable,
							)
						);
					}
				}

				$scanned += $page->value['scanned'];
				$cursor   = $page->value['next_cursor'];
				if ( null === $cursor ) {
					break;
				}
			}

			$this->log_malformed_intents( $malformed_count );
			$this->persist_sweep_cursor( $cursor, $cursor_raw );
		} catch ( \Throwable $throwable ) {
			$this->log_pending_intent( 'Unknown schedule cleanup intents could not be enumerated during maintenance; retry on the next sweep.', array( 'exception' => $throwable ) );
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
	 * @param   string $registration_key `{scope}:{name}` schedule identity.
	 *
	 * @return  AbstractResult<string|null, EngineError>
	 */
	private function read_intent( string $registration_key ): AbstractResult {
		return $this->option_rows->read( self::intent_option_name( $registration_key ) );
	}

	/**
	 * Resolves one exact cleanup-intent generation against registry and scheduler state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{scope}:{name}` schedule identity.
	 * @param   string $expected_raw     Exact selected intent value.
	 *
	 * @return  bool Whether the selected intent no longer needs convergence.
	 */
	private function converge_selected_intent( string $registration_key, string $expected_raw ): bool {
		$registration = $this->registry->registration( $registration_key );
		if ( $registration->is_failure() ) {
			return false;
		}

		if ( null !== $registration->value ) {
			return $this->clear_intent( $registration_key, $expected_raw );
		}

		// A concurrent sync can publish a registration after this read; its persisted fingerprint
		// makes the next scope sync recreate any chain removed here.
		$clearance = $this->scheduler->unschedule_for_convergence( OccurrenceDelivery::SCHEDULE_HOOK, array( $registration_key ), $registration_key );
		$removed   = $clearance->result;
		if ( $removed->is_failure() ) {
			$this->log_pending_intent(
				'Unknown schedule cleanup intent remains pending because verified clearance failed.',
				array(
					'schedule_identity' => $registration_key,
					'error'             => $removed->error->message,
				)
			);

			return false;
		}

		if ( ! $clearance->authoritative ) {
			$this->log_pending_intent( 'Unknown schedule cleanup intent remains pending until every scheduler backend is ready or absent.', array( 'schedule_identity' => $registration_key ), LogLevel::DEBUG );

			return false;
		}

		return $this->clear_intent( $registration_key, $expected_raw );
	}

	/**
	 * Deletes the observed cleanup-intent generation or confirms the row is absent.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{scope}:{name}` schedule identity.
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
	 * Decodes one durable cleanup-intent sweep cursor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $cursor_raw Exact persisted cursor bytes, or null when absent.
	 *
	 * @return  string|null Exclusive option-name cursor, or null for the prefix start.
	 */
	private function decode_sweep_cursor( ?string $cursor_raw ): ?string {
		if ( null === $cursor_raw ) {
			return null;
		}

		$decoded = RawOptionDecoder::decode( $cursor_raw );
		if ( ! \is_array( $decoded ) || 1 !== \count( $decoded ) || ! \is_string( $decoded['after_name'] ?? null ) ) {
			return null;
		}

		return $decoded['after_name'];
	}

	/**
	 * Returns well-formed cleanup intents from one bounded option-name page.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string> $option_names Exact cleanup-intent option names.
	 *
	 * @return  AbstractResult<array{entries: list<array{registration_key: string, expected_raw: string}>, malformed_count: int}, EngineError>
	 */
	private function intents_for_names( array $option_names ): AbstractResult {
		$selected = $this->option_rows->read_many( $option_names );
		if ( $selected->is_failure() ) {
			return $selected;
		}

		$entries         = array();
		$malformed_count = 0;
		foreach ( $option_names as $option_name ) {
			$raw = $selected->value[ $option_name ] ?? null;
			if ( null === $raw ) {
				continue;
			}

			$value            = RawOptionDecoder::decode( $raw );
			$registration_key = \is_array( $value ) ? ( $value['schedule_identity'] ?? null ) : null;
			if (
				! \is_array( $value )
				|| 2 !== \count( $value )
				|| ! \is_string( $registration_key )
				|| ! \is_int( $value['created_at'] ?? null )
				|| self::intent_option_name( $registration_key ) !== $option_name
			) {
				++$malformed_count;
				continue;
			}

			$entries[] = array(
				'registration_key' => $registration_key,
				'expected_raw'     => $raw,
			);
		}

		return new Success(
			array(
				'entries'         => $entries,
				'malformed_count' => $malformed_count,
			)
		);
	}

	/**
	 * Advances or clears the durable cleanup-intent sweep cursor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $cursor     Exclusive cursor for the next sweep, or null when exhausted.
	 * @param   string|null $cursor_raw Exact cursor generation selected before the sweep.
	 *
	 * @throws  \LogicException When WordPress does not serialize cursor state to a string.
	 *
	 * @return  void
	 */
	private function persist_sweep_cursor( ?string $cursor, ?string $cursor_raw ): void {
		if ( null === $cursor ) {
			if ( null !== $cursor_raw ) {
				$this->option_rows->delete_if_value_matches( self::SWEEP_CURSOR_OPTION, $cursor_raw );
			}

			return;
		}

		$replacement_raw = \maybe_serialize( array( 'after_name' => $cursor ) );
		if ( ! \is_string( $replacement_raw ) ) {
			throw new \LogicException( 'WordPress must serialize the cleanup-intent sweep cursor to a string.' );
		}

		if ( null === $cursor_raw ) {
			$this->option_rows->insert_if_absent( self::SWEEP_CURSOR_OPTION, $replacement_raw );
		} else {
			$this->option_rows->compare_and_swap( self::SWEEP_CURSOR_OPTION, $cursor_raw, $replacement_raw );
		}
	}

	/**
	 * Returns the fixed-size option identity for one registration key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $registration_key `{scope}:{name}` schedule identity.
	 *
	 * @return  string
	 */
	private static function intent_option_name( string $registration_key ): string {
		return self::OPTION_PREFIX . \hash( 'sha256', $registration_key );
	}

	/**
	 * Emits one aggregate malformed-intent diagnostic for the bounded sweep.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $malformed_count Number of malformed rows skipped.
	 *
	 * @return  void
	 */
	private function log_malformed_intents( int $malformed_count ): void {
		if ( 0 === $malformed_count ) {
			return;
		}

		$this->log_pending_intent(
			'Malformed unknown-schedule cleanup intent rows were skipped during maintenance; repair or remove them before the next sweep.',
			array(
				'count'         => $malformed_count,
				'option_prefix' => self::OPTION_PREFIX,
			)
		);
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
