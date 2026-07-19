<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\OneOffJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;

/**
 * Records job invocations with an optional observation callback and failure.
 */
final class RecordingJob implements OneOffJobInterface {
	/**
	 * Handler arguments in call order.
	 *
	 * @var list<array<array-key, mixed>>
	 */
	public array $calls = array();

	/** Throwable raised after the invocation is recorded. */
	public ?\Throwable $throwable = null;

	/**
	 * Observation run after recording and before an optional failure.
	 *
	 * @var (\Closure(array<array-key, mixed>): void)|null
	 */
	public ?\Closure $on_handle = null;

	/** Configured retry policy. */
	public RetryPolicy $retry_policy;

	/** Declared ceiling for one handler invocation. */
	public int $max_callback_runtime = self::DEFAULT_MAX_CALLBACK_RUNTIME;

	/** Throwable raised by max_callback_runtime(), or null to return the configured ceiling. */
	public ?\Throwable $max_callback_runtime_throwable = null;

	/**
	 * Constructor.
	 *
	 * @param   string $name Stable job name.
	 */
	public function __construct(
		private readonly string $name,
	) {
		$this->retry_policy = new RetryPolicy();
	}

	/** {@inheritDoc} */
	#[\Override]
	public function get_name(): string {
		return $this->name;
	}

	/** {@inheritDoc} */
	#[\Override]
	public function max_callback_runtime(): int {
		if ( null !== $this->max_callback_runtime_throwable ) {
			throw $this->max_callback_runtime_throwable;
		}

		return $this->max_callback_runtime;
	}

	/**
	 * Records one job invocation before applying scripted behavior.
	 *
	 * @param   array<array-key, mixed> $args Invocation arguments.
	 *
	 * @return  void
	 */
	#[\Override]
	public function handle( array $args ): void {
		$this->calls[] = $args;

		$lifecycle_events = $GLOBALS['a8csp_bgje_test_lifecycle_events'] ?? null;
		if ( \is_array( $lifecycle_events ) ) {
			$lifecycle_events[] = array(
				'type' => 'job',
				'name' => $this->name,
				'args' => $args,
			);

			$GLOBALS['a8csp_bgje_test_lifecycle_events'] = $lifecycle_events;
		}

		if ( null !== $this->on_handle ) {
			( $this->on_handle )( $args );
		}

		if ( null !== $this->throwable ) {
			throw $this->throwable;
		}
	}

	/** {@inheritDoc} */
	#[\Override]
	public function get_retry_policy(): RetryPolicy {
		return $this->retry_policy;
	}
}
