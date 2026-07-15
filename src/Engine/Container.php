<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Engine;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\Batches as ApiBatches;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Consumer;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\Runs as ApiRuns;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule\Schedules as ApiSchedules;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\Tasks as ApiTasks;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine as EngineFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\Inspection;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\AdmissionErrorMapper;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Boots the internal graph and binds supported consumer facades to it.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class Container {
	// region METHODS

	/**
	 * Builds the shared engine graph once.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public static function boot(): void {
		( new Component() )->initialize();
	}

	/**
	 * Returns a supported facade set bound to one validated consumer owner.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $owner Validated consumer owner.
	 *
	 * @throws  \InvalidArgumentException When the owner violates the consumer-owner contract.
	 * @throws  \LogicException           When the internal graph is unavailable.
	 *
	 * @return  Consumer
	 */
	public static function consumer( string $owner ): Consumer {
		WorkIdentity::validate_owner( $owner );
		$engine = Component::get_engine();
		if ( null === $engine ) {
			throw new \LogicException(
				'The background tasks engine graph is unavailable after container boot.'
			);
		}

		$identity = static fn ( string $name ): string => WorkIdentity::compose( $owner, $name );

		return new Consumer(
			$owner,
			new ApiTasks(
				$identity,
				static function ( string $name, TaskInterface $task ) use ( $engine ): void {
					$engine->tasks()->register( $name, $task );
				},
				static fn ( string $name, array $args, int $delay, bool $unique, int $priority ) => AdmissionErrorMapper::map(
					$engine->tasks()->enqueue( $name, $args, $delay, $unique, $priority )
				)
			),
			new ApiBatches(
				$identity,
				static function ( string $name, BatchInterface $batch ) use ( $engine ): void {
					$engine->batches()->register( $name, $batch );
				},
				static fn ( string $name, array $args, bool $unique, int $priority ) => AdmissionErrorMapper::map(
					$engine->batches()->start( $name, $args, $unique, $priority )
				)
			),
			new ApiSchedules(
				$identity,
				static fn ( array $declarations ) => AdmissionErrorMapper::map(
					$engine->schedules()->sync( $owner, $declarations )
				),
				static fn ( string $name ) => AdmissionErrorMapper::map( $engine->schedules()->run_now( $name ) )
			),
			new ApiRuns(
				$identity,
				static fn ( string $name, string $run_id ) => AdmissionErrorMapper::map(
					$engine->retry_failed( $name, $run_id )
				),
				static fn ( string $name, string $run_id ) => AdmissionErrorMapper::map(
					$engine->cancel( $name, $run_id )
				)
			)
		);
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the initialized internal engine facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  EngineFacade|null
	 */
	public static function get_engine(): ?EngineFacade {
		return Component::get_engine();
	}

	/**
	 * Returns the initialized read-only inspection service.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  Inspection|null
	 */
	public static function get_inspection(): ?Inspection {
		return Component::get_inspection();
	}

	/**
	 * Returns the initialized scheduling facade.
	 *
	 * @internal CLI development reset only.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  SchedulerFacade|null
	 */
	public static function get_scheduler(): ?SchedulerFacade {
		return Component::get_scheduler();
	}

	// endregion
}
