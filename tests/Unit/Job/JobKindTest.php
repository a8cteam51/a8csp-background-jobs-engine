<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\JobKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins built-in kind keys and the grammar for prospective engine-installed kinds.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( JobKind::class )]
final class JobKindTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies the production file's boot guard before first autoload.
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
	}

	// endregion.

	// region TESTS.

	/**
	 * Named constructors expose the two installed kind keys.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_named_constructors_expose_installed_kind_keys(): void {
		self::assertSame( 'job', JobKind::job()->value );
		self::assertSame( 'chunked_job', JobKind::chunked_job()->value );
	}

	/**
	 * Generic construction wraps a grammar-valid prospective kind without installing it.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_from_wraps_a_grammar_valid_kind(): void {
		self::assertSame( 'vendor.future_kind', JobKind::from( 'vendor.future_kind' )->value );
		self::assertSame( 'vendor.future_kind', JobKind::tryFrom( 'vendor.future_kind' )?->value );
	}

	/**
	 * Malformed kind keys fail at the representation boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $value Malformed kind key.
	 *
	 * @return  void
	 */
	#[DataProvider( 'malformed_kind_keys' )]
	public function test_from_rejects_a_malformed_kind( string $value ): void {
		$this->expectException( \ValueError::class );

		JobKind::from( $value );
	}

	/**
	 * Non-throwing construction returns null for malformed kind keys.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $value Malformed kind key.
	 *
	 * @return  void
	 */
	#[DataProvider( 'malformed_kind_keys' )]
	public function test_try_from_returns_null_for_a_malformed_kind( string $value ): void {
		self::assertNull( JobKind::tryFrom( $value ) );
	}

	/**
	 * Supplies malformed keys around every grammar boundary.
	 *
	 * @return  array<string, array{value: string}>
	 */
	public static function malformed_kind_keys(): array {
		return array(
			'empty'         => array( 'value' => '' ),
			'uppercase'     => array( 'value' => 'Job' ),
			'leading digit' => array( 'value' => '7job' ),
			'hyphen'        => array( 'value' => 'future-kind' ),
			'trailing dot'  => array( 'value' => 'vendor.' ),
			'two dots'      => array( 'value' => 'vendor.future.kind' ),
		);
	}

	// endregion.
}
