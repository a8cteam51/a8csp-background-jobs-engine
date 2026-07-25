<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Kinds;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunTransitions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Shares bounded execution leases and orphan terminalization across persisted run kinds.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract readonly class AbstractKindHandler implements KindHandlerInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   LoggerInterface $logger               Log event sink.
	 * @param   ClockInterface  $clock                Timestamp source.
	 * @param   LockWindows     $lock_windows         Filterable run-lock timing policy.
	 * @param   RunTransitions  $terminal_transitions Fenced terminal-write coordinator.
	 */
	public function __construct(
		protected LoggerInterface $logger,
		protected ClockInterface $clock,
		protected LockWindows $lock_windows,
		protected RunTransitions $terminal_transitions,
	) {}

	// endregion

	// region HELPERS

	/**
	 * Fails a live run whose required execution definition is no longer registered.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Identity $identity  Complete owner-qualified work identity.
	 * @param   string   $run_id    Run identifier.
	 * @param   RunState $state     Fenced running state.
	 * @param   RunStore $run_store Active-run store.
	 *
	 * @return  void
	 */
	final protected function fail_orphaned_run( Identity $identity, string $run_id, RunState $state, RunStore $run_store ): void {
		$kind  = $this->key();
		$error = new EngineError( \sprintf( '%1$s identity "%2$s" has no registered %1$s implementation for run "%3$s"; register that %1$s or purge the run.', $kind, (string) $identity, $run_id ) );

		$this->terminal_transitions->fail_unregistered_run( $this, $identity, $run_id, $state, $run_store, $error );
	}

	/**
	 * Returns the bounded future liveness timestamp for one execution invocation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   JobOptions $options Registered policy declaration.
	 *
	 * @return  int
	 */
	final protected function execution_lease_at( JobOptions $options ): int {
		$lease = $this->lock_windows->execution_lease( $options->max_runtime );
		$now   = $this->clock->now()->getTimestamp();

		return $now > \PHP_INT_MAX - $lease ? \PHP_INT_MAX : $now + $lease;
	}

	// endregion
}
