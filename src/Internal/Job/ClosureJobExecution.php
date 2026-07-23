<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Internal\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobExecution;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;

\defined( 'ABSPATH' ) || exit;

/**
 * Adapts the definition convenience closure to the standard execution role.
 *
 * @internal JobDefinition only.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class ClosureJobExecution implements JobExecution {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(array<array-key, mixed>, RunContext): mixed $handler
	 *
	 * @param   \Closure $handler Job handler.
	 */
	public function __construct(
		private \Closure $handler,
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * Invokes the composed closure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args    Invocation arguments.
	 * @param   RunContext              $context Controlled access to this run.
	 *
	 * @return  void
	 */
	#[\Override]
	public function handle( array $args, RunContext $context ): void {
		( $this->handler )( $args, $context );
	}

	// endregion
}
