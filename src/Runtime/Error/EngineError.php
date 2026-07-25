<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\ErrorInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;

\defined( 'ABSPATH' ) || exit;

/**
 * Carries terminal failure detail or a classified admission failure.
 *
 * Terminal detail is retained while the engine terminalizes a run. Classified admission failures
 * cross the public boundary mapper. The message describes the failure, and the optional exception
 * class preserves the throwable category without retaining the throwable.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class EngineError implements ErrorInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                 $message         Human-readable failure detail.
	 * @param   string|null            $exception_class Exception class associated with the failure.
	 * @param   EngineErrorReason|null $reason          Machine-readable admission cause, or null for terminal-only detail.
	 * @param   array<string, mixed>   $context         Structured internal diagnostic detail.
	 */
	public function __construct(
		public string $message,
		public ?string $exception_class = null,
		public ?EngineErrorReason $reason = null,
		public array $context = array(),
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns a public held-lock failure without relying on message inspection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $kind           Persisted kind key.
	 * @param   Identity $identity       Complete owner-qualified work identity.
	 * @param   string   $running_run_id Discoverable incumbent run identifier.
	 *
	 * @return  self
	 */
	public static function held( string $kind, Identity $identity, string $running_run_id ): self {
		return new self( \sprintf( '%1$s "%2$s" is already running as run "%3$s"; wait for that run to finish before dispatching the same arguments or overlap key.', $kind, (string) $identity, $running_run_id ), reason: EngineErrorReason::OverlapHeld, context: array( 'run_id' => $running_run_id ), );
	}

	/**
	 * Converts a failed lifecycle schedule into terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                             $kind      Persisted kind key.
	 * @param   Identity                           $identity  Complete owner-qualified job or chunked job identity.
	 * @param   'continue'|'run'|'cleanup'|'retry' $stage     Internal action that was not scheduled.
	 * @param   SchedulingError                    $error     Scheduling failure.
	 *
	 * @return  self
	 */
	public static function scheduling( string $kind, Identity $identity, string $stage, SchedulingError $error ): self {
		return new self( \sprintf( '%1$s "%2$s" could not schedule the %3$s action: %4$s', $kind, (string) $identity, $stage, $error->message ), SchedulingError::class );
	}

	/**
	 * Converts one execution throwable into engine failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Throwable $throwable Callback failure.
	 *
	 * @return  self
	 */
	public static function from_throwable( \Throwable $throwable ): self {
		$exception_type = \get_debug_type( $throwable );

		return new self( \sprintf( 'Background-work execution failed because %s was thrown.', $exception_type ), $exception_type );
	}

	/**
	 * Converts a retry-policy boundary throwable into terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string     $kind      Persisted kind key.
	 * @param   Identity   $identity  Complete owner-qualified job or chunked job identity.
	 * @param   \Throwable $throwable Retry-policy provider or filter failure.
	 *
	 * @return  self
	 */
	public static function retry_policy( string $kind, Identity $identity, \Throwable $throwable ): self {
		$exception_type = \get_debug_type( $throwable );

		return new self( \sprintf( '%1$s "%2$s" could not resolve the retry policy because %3$s was thrown. Fix the retry policy provider or filter before retrying the failed run manually.', $kind, (string) $identity, $exception_type ), $exception_type );
	}

	/**
	 * Converts one retry-state construction throwable into terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string     $kind      Persisted kind key.
	 * @param   Identity   $identity  Complete owner-qualified job or chunked job identity.
	 * @param   \Throwable $throwable Retry-state construction failure.
	 *
	 * @return  self
	 */
	public static function retry_state( string $kind, Identity $identity, \Throwable $throwable ): self {
		$exception_type = \get_debug_type( $throwable );

		return new self( \sprintf( '%1$s "%2$s" could not construct the retry state because %3$s was thrown. Restore the engine before retrying the failed run manually.', $kind, (string) $identity, $exception_type ), $exception_type );
	}

	/**
	 * Converts one retry-preparation throwable into terminal failure detail.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string     $kind      Persisted kind key.
	 * @param   Identity   $identity  Complete owner-qualified job or chunked job identity.
	 * @param   \Throwable $throwable Retry-policy, randomness, hook, or scheduler failure.
	 *
	 * @return  self
	 */
	public static function retry_preparation( string $kind, Identity $identity, \Throwable $throwable ): self {
		$exception_type = \get_debug_type( $throwable );

		return new self( \sprintf( '%1$s "%2$s" could not prepare the retry action because %3$s was thrown. Fix the retry policy, randomness source, retry-scheduled hook, or scheduler before retrying the failed run manually.', $kind, (string) $identity, $exception_type ), $exception_type );
	}

	// endregion
}
