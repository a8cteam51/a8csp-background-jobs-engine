<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\PortableArguments;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineErrorReason;

\defined( 'ABSPATH' ) || exit;

/**
 * Resolves the stable single-flight identity shared by admission and lock inspection.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class OverlapIdentity {
	// region FIELDS AND CONSTANTS

	/**
	 * Maximum bytes accepted from a custom overlap-key resolver.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_OVERLAP_KEY_BYTES = 64;

	// endregion

	// region METHODS

	/**
	 * Returns the canonical argument hash or the tagged hash of a resolved opaque overlap key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $kind     Persisted kind key.
	 * @param   string                  $identity Complete owner-qualified work identity.
	 * @param   JobOptions              $options  Registered policy declaration.
	 * @param   array<array-key, mixed> $args     Work arguments.
	 *
	 * @return  string|Failure<EngineError>
	 */
	public function resolve( string $kind, string $identity, JobOptions $options, array $args ): string|Failure {
		$overlap_key = null;
		if ( null !== $options->overlap_key ) {
			// The helper's ?string return is the only type check, turning a wrong-typed consumer
			// return into a TypeError caught here instead of a fatal.
			try {
				$overlap_key = self::invoke_overlap_key( $options->overlap_key, $args );
			} catch ( \Throwable $throwable ) {
				$exception_type = \get_debug_type( $throwable );

				return new Failure(
					new EngineError(
						\sprintf( '%1$s "%2$s" could not resolve its overlap key because %3$s was thrown. Fix the overlap-key resolver before dispatching the background work again.', $kind, $identity, $exception_type ),
						$exception_type,
						reason: EngineErrorReason::ExecutionFailed,
						context: array(
							'identity' => $identity,
							'kind'     => $kind,
						),
					)
				);
			}
		}

		$args_hash = $this->canonical( $kind, $identity, $args );
		if ( $args_hash instanceof Failure || null === $overlap_key ) {
			return $args_hash;
		}
		if ( '' === $overlap_key || self::MAX_OVERLAP_KEY_BYTES < \strlen( $overlap_key ) ) {
			return new Failure( new EngineError( \sprintf( '%1$s "%2$s" overlap key must contain 1 to %3$d bytes when provided.', $kind, $identity, self::MAX_OVERLAP_KEY_BYTES ), reason: EngineErrorReason::PayloadRejected, context: array( 'identity' => $identity ), ) );
		}

		return \hash( 'sha256', 'dedup:' . $overlap_key );
	}

	/**
	 * Returns the SHA-256 identity of insertion-ordered portable JSON.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $kind     Persisted kind key.
	 * @param   string                  $identity Complete owner-qualified work identity.
	 * @param   array<array-key, mixed> $args     Work arguments.
	 *
	 * @return  string|Failure<EngineError>
	 */
	public function canonical( string $kind, string $identity, array $args ): string|Failure {
		$exception_class = null;
		try {
			$hash = PortableArguments::hash( $args );
		} catch ( \JsonException $exception ) {
			$exception_class = \get_debug_type( $exception );
			$hash            = null;
		}
		if ( null === $hash ) {
			return new Failure(
				new EngineError(
					\sprintf( '%1$s "%2$s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.', $kind, $identity ),
					$exception_class,
					reason: EngineErrorReason::PayloadRejected,
					context: array(
						'identity' => $identity,
						'kind'     => $kind,
					),
				)
			);
		}

		return $hash;
	}

	// endregion

	// region HELPERS

	/**
	 * Invokes one resolver through the engine's typed return boundary.
	 *
	 * The declared return type is the only check on consumer output, so a wrong type raises a
	 * TypeError inside the caller's throwable containment.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param \Closure(array<array-key, mixed>): ?string $resolver
	 *
	 * @param   \Closure                $resolver Registered overlap-key resolver.
	 * @param   array<array-key, mixed> $args     Work arguments.
	 *
	 * @return  string|null
	 */
	private static function invoke_overlap_key( \Closure $resolver, array $args ): ?string {
		return $resolver( $args );
	}

	// endregion
}
