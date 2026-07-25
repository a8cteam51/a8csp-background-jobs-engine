<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Constructs identity-bound stores.
 *
 * A single factory keeps identity binding at the orchestration boundary without exposing four
 * untyped closure dependencies.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class StoreFactory {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ClockInterface  $clock  Run timestamp source.
	 * @param   OptionRows      $rows   Authoritative raw option-row I/O.
	 * @param   LoggerInterface $logger Engine diagnostic sink.
	 */
	public function __construct(
		private ClockInterface $clock,
		private OptionRows $rows,
		private LoggerInterface $logger,
	) {}

	// endregion

	// region METHODS

	/**
	 * Constructs the active-run store for a job or chunked job identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  RunStore
	 */
	public function run_store( Identity $identity ): RunStore {
		return new RunStore( (string) $identity, $this->clock, $this->rows );
	}

	/**
	 * Constructs an active-run store bound to untrusted scheduler-wire identity bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Raw scheduler-wire identity bytes.
	 *
	 * @return  RunStore
	 */
	public function raw_run_store( string $identity ): RunStore {
		return new RunStore( $identity, $this->clock, $this->rows );
	}

	/**
	 * Constructs the latest-run pointer for a job or chunked job identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  LatestRunPointer
	 */
	public function latest_run_pointer( Identity $identity ): LatestRunPointer {
		return new LatestRunPointer( (string) $identity, $this->rows );
	}

	/**
	 * Constructs a latest-run pointer bound to untrusted scheduler-wire identity bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Raw scheduler-wire identity bytes.
	 *
	 * @return  LatestRunPointer
	 */
	public function raw_latest_run_pointer( string $identity ): LatestRunPointer {
		return new LatestRunPointer( $identity, $this->rows );
	}

	/**
	 * Constructs the run history for a job or chunked job identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  RunHistory
	 */
	public function run_history( Identity $identity ): RunHistory {
		return new RunHistory( $identity, $this->rows, $this->logger );
	}

	/**
	 * Constructs the failed-run store for a job or chunked job identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  FailedRunStore
	 */
	public function failed_run_store( Identity $identity ): FailedRunStore {
		return new FailedRunStore( $identity, $this->rows, $this->logger );
	}

	// endregion
}
