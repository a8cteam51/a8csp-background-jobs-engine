<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;

\defined( 'ABSPATH' ) || exit;

/**
 * Adapts one closure to the public job contract.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class CallableJob extends AbstractJob {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(array<array-key, mixed>): mixed $handler
	 *
	 * @param   string           $name        Stable job name.
	 * @param   \Closure         $handler     Job handler.
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
	 * Returns the stable owner-local job name.
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
	 * Invokes the configured job handler.
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
