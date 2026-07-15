<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\PortableArguments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins portable-argument depth boundaries directly.
 *
 */
#[CoversClass( PortableArguments::class )]
final class PortableArgumentsTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies production boot guards before the portable-arguments validator is autoloaded.
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
		self::assertTrue( PortableArguments::is_valid( $this->nested_values( 512 ) ) );
	}

	/**
	 * One array level beyond the JSON-compatible ceiling is rejected.
	 *
	 * @return  void
	 */
	public function test_rejects_513_array_levels(): void {
		self::assertFalse( PortableArguments::is_valid( $this->nested_values( 513 ) ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Builds portable arguments with the requested number of array levels.
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
