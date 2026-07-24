<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

/**
 * Supplies consumer overlap-key resolvers that fail at invocation.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class FaultingOverlapKeyResolverProvider {
	// region METHODS.

	/**
	 * Supplies throwing and wrong-returning overlap-key resolvers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{resolver: \Closure}>
	 */
	public static function resolvers(): array {
		return array(
			'throws'             => array( 'resolver' => static fn ( array $args ): never => throw new \RuntimeException( 'Consumer resolver failed.' ) ),
			'returns non-string' => array( 'resolver' => static fn ( array $args ): array => $args ),
		);
	}

	// endregion.
}
