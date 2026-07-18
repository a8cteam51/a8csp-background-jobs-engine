<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Task;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;

\defined( 'ABSPATH' ) || exit;

/**
 * Adapts one closure to the public task contract.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class CallableTask extends AbstractTask {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(array<array-key, mixed>): mixed $handler
	 *
	 * @param   string           $name        Stable task name.
	 * @param   \Closure         $handler     Task handler.
	 * @param   int|null         $max_runtime Optional callback-runtime ceiling in seconds.
	 * @param   RetryPolicy|null $retry       Optional retry policy.
	 */
	public function __construct(
		private string $name,
		private \Closure $handler,
		private ?int $max_runtime = null,
		private ?RetryPolicy $retry = null,
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * Returns the stable owner-local task name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	#[\Override]
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Invokes the configured task handler.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $args Invocation arguments.
	 *
	 * @throws  \Throwable When the configured handler fails.
	 *
	 * @return  void
	 */
	#[\Override]
	public function handle( array $args ): void {
		( $this->handler )( $args );
	}

	/**
	 * Returns the configured callback-runtime ceiling or the shared default.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  int
	 */
	#[\Override]
	public function max_callback_runtime(): int {
		return $this->max_runtime ?? parent::max_callback_runtime();
	}

	/**
	 * Returns the configured retry policy or the shared default.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RetryPolicy
	 */
	#[\Override]
	public function get_retry_policy(): RetryPolicy {
		return $this->retry ?? parent::get_retry_policy();
	}

	// endregion
}
