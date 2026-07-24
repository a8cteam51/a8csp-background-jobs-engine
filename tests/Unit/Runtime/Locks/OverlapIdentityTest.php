<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Locks;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the byte-level identities shared by run admission and lock inspection.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( OverlapIdentity::class )]
final class OverlapIdentityTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads the guarded JSON boundary before the resolver is autoloaded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/Backends/wp-json-encode-stub.php';
	}

	// endregion.

	// region TESTS.

	/**
	 * Canonical arguments and opaque keys retain their established SHA-256 bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_resolve_preserves_canonical_and_opaque_hash_bytes(): void {
		$resolver = new OverlapIdentity();
		$args     = array(
			'site_id' => 7,
			'ratio'   => 1.0,
			'nested'  => array( 'mode' => 'incremental' ),
		);

		$canonical = $resolver->resolve( 'job', 'owner:catalog-sync', new JobOptions(), $args );
		$opaque    = $resolver->resolve( 'job', 'owner:catalog-sync', new JobOptions( overlap_key: static fn ( array $start_args ): string => "catalog\0\xFF" ), $args );

		self::assertSame( '306e56a4efd7fd71db49a2a4424d410ca43f38d865b70eb605cf641ba584f24f', $canonical );
		self::assertSame( '839bc2e28e961c09b79c9adbb7c53159e4a2a138e1dfe756247d9d45cf0a29e9', $opaque );
	}

	// endregion.
}
