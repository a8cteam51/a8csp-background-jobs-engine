<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Composes a stable owner-local job name, execution role, kind, and policy declaration.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class JobDefinition {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                 $name      Stable owner-local job name.
	 * @param   JobKind                $kind      Engine-owned job kind.
	 * @param   KindExecutionInterface $execution Kind-specific execution object.
	 * @param   JobOptions             $options   Execution, retry, and overlap policy.
	 */
	private function __construct(
		public string $name,
		public JobKind $kind,
		public KindExecutionInterface $execution,
		public JobOptions $options,
	) {}

	// endregion

	// region METHODS

	/**
	 * Defines a standard job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                $name      Stable owner-local job name.
	 * @param   JobExecutionInterface $execution Job execution.
	 * @param   JobOptions|null       $options   Optional policy declaration.
	 *
	 * @return  self
	 */
	public static function job( string $name, JobExecutionInterface $execution, ?JobOptions $options = null ): self {
		return self::for_kind( $name, JobKind::job(), $execution, $options );
	}

	/**
	 * Defines a chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                       $name      Stable owner-local job name.
	 * @param   ChunkedJobExecutionInterface $execution Chunked job execution.
	 * @param   JobOptions|null              $options   Optional policy declaration.
	 *
	 * @return  self
	 */
	public static function chunked_job( string $name, ChunkedJobExecutionInterface $execution, ?JobOptions $options = null ): self {
		return self::for_kind( $name, JobKind::chunked_job(), $execution, $options );
	}

	/**
	 * Defines a closure-backed standard job with engine-default policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(array<array-key, mixed>, RunContextInterface): mixed $handler
	 *
	 * @param   string   $name    Stable owner-local job name.
	 * @param   \Closure $handler Job handler.
	 *
	 * @return  self
	 */
	public static function closure( string $name, \Closure $handler ): self {
		$execution = new readonly class( $handler ) implements JobExecutionInterface {
			/**
			 * Constructor.
			 *
			 * @phpstan-param \Closure(array<array-key, mixed>, RunContextInterface): mixed $handler
			 *
			 * @param   \Closure $handler Job handler.
			 */
			public function __construct(
				private \Closure $handler,
			) {}

			/** {@inheritDoc} */
			#[\Override]
			public function handle( array $start_args, RunContextInterface $context ): void {
				( $this->handler )( $start_args, $context );
			}
		};

		return self::job( $name, $execution );
	}

	/**
	 * Defines background work for one engine-owned kind.
	 *
	 * Only engine-installed kinds can be registered today. This generic registration data path keeps
	 * a future engine-installed kind additive, while execution compatibility remains the resolved
	 * internal handler's responsibility and the handler SPI remains internal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                 $name      Stable owner-local job name.
	 * @param   JobKind                $kind      Engine-owned job kind.
	 * @param   KindExecutionInterface $execution Kind-specific execution object.
	 * @param   JobOptions|null        $options   Optional policy declaration.
	 *
	 * @return  self
	 */
	public static function for_kind( string $name, JobKind $kind, KindExecutionInterface $execution, ?JobOptions $options = null ): self {
		return new self( $name, $kind, $execution, $options ?? new JobOptions() );
	}

	// endregion
}
