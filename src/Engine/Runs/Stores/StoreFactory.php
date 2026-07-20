<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\Stores;

use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Storage\OptionRows;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Constructs identity-bound stores and enumerates active-run identities.
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
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  RunStore
	 */
	public function run_store( string $identity ): RunStore {
		return new RunStore( $identity, $this->clock, $this->rows );
	}

	/**
	 * Constructs the latest-run pointer for a job or chunked job identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  LatestRunPointer
	 */
	public function latest_run_pointer( string $identity ): LatestRunPointer {
		return new LatestRunPointer( $identity, $this->rows );
	}

	/**
	 * Constructs the run history for a job or chunked job identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  RunHistory
	 */
	public function run_history( string $identity ): RunHistory {
		return new RunHistory( $identity, $this->rows, $this->logger );
	}

	/**
	 * Constructs the failed-run store for a job or chunked job identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  FailedRunStore
	 */
	public function failed_run_store( string $identity ): FailedRunStore {
		return new FailedRunStore( $identity, $this->rows, $this->logger );
	}

	/**
	 * Returns every canonical run identifier with an active-run option for one work identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete owner-qualified job or chunked job identity.
	 *
	 * @return  list<string>|null Null when authoritative option-name enumeration fails.
	 */
	public function active_run_ids( string $identity ): ?array {
		$names = $this->rows->option_names( RunIdentity::option_name_prefix( $identity ) );
		if ( $names->is_failure() ) {
			return null;
		}

		$run_ids = array();
		foreach ( $names->value as $name ) {
			$parsed = RunIdentity::from_option_name( $name );
			if ( null !== $parsed && $identity === $parsed['identity'] ) {
				$run_ids[] = $parsed['run_id'];
			}
		}

		return $run_ids;
	}

	// endregion
}
