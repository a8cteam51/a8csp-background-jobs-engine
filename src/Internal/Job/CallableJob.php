<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Internal\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Job;
use A8C\SpecialProjects\BackgroundJobsEngine\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\OverlapPolicy;

\defined( 'ABSPATH' ) || exit;

/**
 * Adapts closures to the public job contract.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class CallableJob extends Job {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(array<array-key, mixed>, RunContext): mixed $handler
	 * @phpstan-param (\Closure(array<array-key, mixed>): ?string)|null $overlap_key
	 * @phpstan-param (\Closure(string, array<array-key, mixed>, ?string): void)|null $on_completed
	 * @phpstan-param (\Closure(string, array<array-key, mixed>, RunFailure): void)|null $on_failed
	 *
	 * @param   string             $name         Stable job name.
	 * @param   \Closure           $handler      Job handler.
	 * @param   int|null           $max_runtime  Optional callback-runtime ceiling in seconds.
	 * @param   RetryPolicy|null   $retry        Optional retry policy.
	 * @param   OverlapPolicy|null $overlap      Optional overlap policy.
	 * @param   \Closure|null      $overlap_key  Optional argument-aware overlap-key resolver.
	 * @param   \Closure|null      $on_completed Optional completed-run callback.
	 * @param   \Closure|null      $on_failed    Optional failed-run callback.
	 */
	public function __construct(
		private string $name,
		private \Closure $handler,
		private ?int $max_runtime = null,
		private ?RetryPolicy $retry = null,
		private ?OverlapPolicy $overlap = null,
		private ?\Closure $overlap_key = null,
		private ?\Closure $on_completed = null,
		private ?\Closure $on_failed = null,
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
	 * @param   array<array-key, mixed> $args    Invocation arguments.
	 * @param   RunContext              $context Controlled access to this run.
	 *
	 * @throws  \Throwable When the configured handler fails.
	 *
	 * @return  void
	 */
	#[\Override]
	public function handle( array $args, RunContext $context ): void {
		( $this->handler )( $args, $context );
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
	 * Returns the configured overlap policy or the shared default.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  OverlapPolicy
	 */
	#[\Override]
	public function overlap_policy(): OverlapPolicy {
		return $this->overlap ?? parent::overlap_policy();
	}

	/**
	 * Resolves the configured overlap key or uses the canonical argument identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
	 *
	 * @return  string|null
	 */
	#[\Override]
	public function overlap_key( array $start_args ): ?string {
		return null === $this->overlap_key ? parent::overlap_key( $start_args ) : ( $this->overlap_key )( $start_args );
	}

	/**
	 * Invokes the configured completed-run callback when present.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id                    Run identifier.
	 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
	 * @param   string|null             $previous_completed_run_id Previous completed run identifier for this identity, or null.
	 *
	 * @throws  \Throwable When the configured callback fails.
	 *
	 * @return  void
	 */
	#[\Override]
	public function on_completed( string $run_id, array $start_args, ?string $previous_completed_run_id ): void {
		$this->on_completed?->__invoke( $run_id, $start_args, $previous_completed_run_id );
	}

	/**
	 * Invokes the configured failed-run callback when present.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Run identifier.
	 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
	 * @param   RunFailure              $failure    Persisted terminal-failure value.
	 *
	 * @throws  \Throwable When the configured callback fails.
	 *
	 * @return  void
	 */
	#[\Override]
	public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void {
		$this->on_failed?->__invoke( $run_id, $start_args, $failure );
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
