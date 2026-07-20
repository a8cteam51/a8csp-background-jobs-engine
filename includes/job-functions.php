<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\OneOffJobInterface as InternalJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy as InternalRetry;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Run\RunContextInterface as InternalRunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\DuplicateRegistrationException;

use function A8C\SpecialProjects\BackgroundJobsEngine\Bridge\failure_to_array;
use function A8C\SpecialProjects\BackgroundJobsEngine\Bridge\overlap_policy;
use function A8C\SpecialProjects\BackgroundJobsEngine\Bridge\retry_policy;

\defined( 'ABSPATH' ) || exit;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- Local validation exceptions are translated to WP_Error before crossing the procedural boundary.
/**
 * Registers one callable-backed job for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @phpstan-param callable(array<array-key, mixed>, string): mixed $handler
 * @phpstan-param array<array-key, mixed> $options
 *
 * @param   string   $owner   Client plugin owner.
 * @param   string   $name    Owner-local job name.
 * @param   callable $handler Job handler.
 * @param   array    $options Optional overrides, all validated at runtime: `max_runtime` (int),
 *                            `retry` (array of int max_attempts/base_delay/multiplier/max_delay),
 *                            `overlap` (string allow/reject/replace), `overlap_key` (callable(array
 *                            $args): ?string), `on_completed` (callable(string $run_id, array $args,
 *                            ?string $previous_completed_run_id)), and `on_failed` (callable(string
 *                            $run_id, array $args, array $failure)).
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
function a8csp_bgje_job_register( string $owner, string $name, callable $handler, array $options = array() ): true|\WP_Error {
	try {
		$max_runtime = $options['max_runtime'] ?? \A8CSP_Job::DEFAULT_MAX_CALLBACK_RUNTIME;
		if ( ! \is_int( $max_runtime ) ) {
			throw new \InvalidArgumentException( 'max_runtime must be an integer or null' );
		}

		$retry = $options['retry'] ?? array();
		if ( ! \is_array( $retry ) ) {
			throw new \InvalidArgumentException( 'retry must be an array or null' );
		}

		$overlap = $options['overlap'] ?? 'reject';
		if ( ! \is_string( $overlap ) ) {
			throw new \InvalidArgumentException( 'overlap must be a string or null' );
		}
		overlap_policy( $overlap );

		$overlap_key = $options['overlap_key'] ?? null;
		if ( null !== $overlap_key && ! \is_callable( $overlap_key ) ) {
			throw new \InvalidArgumentException( 'overlap_key must be callable or null' );
		}

		$on_completed = $options['on_completed'] ?? null;
		if ( null !== $on_completed && ! \is_callable( $on_completed ) ) {
			throw new \InvalidArgumentException( 'on_completed must be callable or null' );
		}

		$on_failed = $options['on_failed'] ?? null;
		if ( null !== $on_failed && ! \is_callable( $on_failed ) ) {
			throw new \InvalidArgumentException( 'on_failed must be callable or null' );
		}
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	$job = new class(
		$name,
		\Closure::fromCallable( $handler ),
		$max_runtime,
		$retry,
		$overlap,
		null === $overlap_key ? null : \Closure::fromCallable( $overlap_key ),
		null === $on_completed ? null : \Closure::fromCallable( $on_completed ),
		null === $on_failed ? null : \Closure::fromCallable( $on_failed ),
	) extends \A8CSP_Job {
		// region MAGIC METHODS

		/**
		 * Constructor.
		 *
		 * @phpstan-param \Closure(array<array-key, mixed>, string): mixed $handler
		 * @phpstan-param array<array-key, mixed> $retry
		 *
		 * @param   string        $name         Stable owner-local job name.
		 * @param   \Closure      $handler      Job handler.
		 * @param   int           $max_runtime  Callback-runtime ceiling.
		 * @param   array         $retry        Retry declaration.
		 * @param   string        $overlap      Overlap declaration.
		 * @param   \Closure|null $overlap_key  Optional argument-aware overlap-key resolver.
		 * @param   \Closure|null $on_completed Optional completion callback.
		 * @param   \Closure|null $on_failed    Optional failure callback.
		 */
		public function __construct(
			private string $name,
			private \Closure $handler,
			private int $max_runtime,
			private array $retry,
			private string $overlap,
			private ?\Closure $overlap_key,
			private ?\Closure $on_completed,
			private ?\Closure $on_failed,
		) {}

		// endregion

		// region INHERITED METHODS

		/** {@inheritDoc} */
		#[\Override]
		public function get_name(): string {
			return $this->name;
		}

		/** {@inheritDoc} */
		#[\Override]
		public function handle( array $args, string $run_id ): void {
			( $this->handler )( $args, $run_id );
		}

		/** {@inheritDoc} */
		#[\Override]
		public function on_completed( string $run_id, array $args, ?string $previous_completed_run_id ): void {
			if ( null !== $this->on_completed ) {
				( $this->on_completed )( $run_id, $args, $previous_completed_run_id );
			}
		}

		/** {@inheritDoc} */
		#[\Override]
		public function on_failed( string $run_id, array $args, array $failure ): void {
			if ( null !== $this->on_failed ) {
				( $this->on_failed )( $run_id, $args, $failure );
			}
		}

		/** {@inheritDoc} */
		#[\Override]
		public function max_callback_runtime(): int {
			return $this->max_runtime;
		}

		/** {@inheritDoc} */
		#[\Override]
		public function overlap_policy(): string {
			return $this->overlap;
		}

		/** {@inheritDoc} */
		#[\Override]
		public function overlap_key( array $start_args ): ?string {
			if ( null === $this->overlap_key ) {
				return null;
			}

			$key = ( $this->overlap_key )( $start_args );
			if ( null !== $key && ! \is_string( $key ) ) {
				throw new \TypeError( 'overlap_key must return a string or null' );
			}

			return $key;
		}

		/** {@inheritDoc} */
		#[\Override]
		public function retry(): array {
			return $this->retry;
		}

		// endregion
	};

	return \a8csp_bgje_job_register_object( $owner, $job );
}
// phpcs:enable

/**
 * Registers one consumer-authored job object for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string     $owner Client plugin owner.
 * @param   \A8CSP_Job $job   Consumer-authored job.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a job-registration failure must be handled, not dropped' )]
function a8csp_bgje_job_register_object( string $owner, \A8CSP_Job $job ): true|\WP_Error {
	try {
		$client = \a8csp_bgje( $owner );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	try {
		$adapter = new class( $job ) implements InternalJob {
			// region FIELDS AND CONSTANTS

			/**
			 * Materialized internal retry value.
			 *
			 * @var InternalRetry|null
			 */
			private ?InternalRetry $retry_policy = null;

			// endregion

			// region MAGIC METHODS

			/**
			 * Constructor.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   \A8CSP_Job $job Consumer-authored job.
			 *
			 * @throws  \InvalidArgumentException When the retry or overlap declaration is invalid.
			 */
			public function __construct(
				private readonly \A8CSP_Job $job,
			) {
				$this->get_retry_policy();
				$this->overlap_policy();
			}

			// endregion

			// region INHERITED METHODS

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return $this->job->get_name();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function max_callback_runtime(): int {
				return $this->job->max_callback_runtime();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function overlap_policy(): OverlapPolicy {
				return overlap_policy( $this->job->overlap_policy() );
			}

			/** {@inheritDoc} */
			#[\Override]
			public function overlap_key( array $start_args ): ?string {
				return $this->job->overlap_key( $start_args );
			}

			/** {@inheritDoc} */
			#[\Override]
			public function get_retry_policy(): InternalRetry {
				return $this->retry_policy ??= retry_policy( $this->job->retry() );
			}

			/**
			 * Forwards one invocation to the consumer-authored job.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $args    Invocation arguments.
			 * @param   InternalRunContext      $context Internal run context.
			 *
			 * @return  void
			 */
			#[\Override]
			public function handle( array $args, InternalRunContext $context ): void {
				$this->job->handle( $args, $context->get_run_id() );
			}

			/**
			 * Forwards a completed run to the consumer-authored job.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $run_id                    Run identifier.
			 * @param   array<array-key, mixed> $start_args                Arguments supplied when the run started.
			 * @param   string|null             $previous_completed_run_id Previous completed run identifier retained inside the engine.
			 *
			 * @return  void
			 */
			#[\Override]
			public function on_completed( string $run_id, array $start_args, ?string $previous_completed_run_id ): void {
				$this->job->on_completed( $run_id, $start_args, $previous_completed_run_id );
			}

			/**
			 * Converts and forwards a failed run to the consumer-authored job.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $run_id     Run identifier.
			 * @param   array<array-key, mixed> $start_args Arguments supplied when the run started.
			 * @param   RunFailure              $failure    Internal terminal failure.
			 *
			 * @return  void
			 */
			#[\Override]
			public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void {
				$this->job->on_failed( $run_id, $start_args, failure_to_array( $failure ) );
			}

			// endregion
		};
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	try {
		$client->jobs()->register( $adapter );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	} catch ( DuplicateRegistrationException $exception ) {
		return new \WP_Error( 'already_registered', $exception->getMessage() );
	}

	return true;
}

/**
 * Creates and schedules one job run for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner         Client plugin owner.
 * @param   string                  $name          Owner-local job name.
 * @param   array<array-key, mixed> $args          Job arguments.
 * @param   int                     $delay_seconds Scheduling delay in seconds.
 * @param   int                     $priority      Advisory priority from 0 through 255.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  string|\WP_Error
 */
#[\NoDiscard( 'an enqueue failure must be handled, not dropped' )]
function a8csp_bgje_job_enqueue( string $owner, string $name, array $args = array(), int $delay_seconds = 0, int $priority = 10 ): string|\WP_Error {
	try {
		$result = \a8csp_bgje( $owner )->jobs()->enqueue( $name, $args, $delay_seconds, $priority );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}
