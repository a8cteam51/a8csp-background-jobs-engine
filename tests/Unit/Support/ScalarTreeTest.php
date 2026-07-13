<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Support\ScalarTree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins scalar-tree depth boundaries directly.
 *
 */
#[CoversClass( ScalarTree::class )]
final class ScalarTreeTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies production boot guards before the scalar-tree helper is autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * The JSON-compatible depth ceiling remains valid.
	 *
	 * @return  void
	 */
	public function test_accepts_exactly_512_array_levels(): void {
		self::assertTrue( ScalarTree::is_valid( $this->nested_values( 512 ) ) );
	}

	/**
	 * One array level beyond the JSON-compatible ceiling is rejected.
	 *
	 * @return  void
	 */
	public function test_rejects_513_array_levels(): void {
		self::assertFalse( ScalarTree::is_valid( $this->nested_values( 513 ) ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Builds a scalar tree with the requested number of array levels.
	 *
	 * @param   int $depth Array depth.
	 *
	 * @return  array<array-key, mixed>
	 */
	private function nested_values( int $depth ): array {
		$values = array( null );
		for ( $level = 1; $depth > $level; ++$level ) {
			$values = array( $values );
		}

		return $values;
	}

	// endregion.
}
