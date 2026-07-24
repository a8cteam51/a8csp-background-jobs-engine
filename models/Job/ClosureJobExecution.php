<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Job;

\defined( 'ABSPATH' ) || exit;

/**
 * Adapts the definition convenience closure to the standard execution role.
 *
 * @internal JobDefinition only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ClosureJobExecution implements JobExecutionInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(array<array-key, mixed>, RunContextInterface): mixed $handler
	 *
	 * @param   \Closure $handler Job handler.
	 */
	public function __construct(
		private \Closure $handler,
	) {}

	// endregion

	// region METHODS

	/**
	 * Invokes the composed closure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 * @param   RunContextInterface     $context    Controlled access to this run.
	 *
	 * @return  void
	 */
	#[\Override]
	public function handle( array $start_args, RunContextInterface $context ): void {
		( $this->handler )( $start_args, $context );
	}

	// endregion
}
