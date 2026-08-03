<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the pending lifecycle-action value: stages obey the lexical grammar, not a whitelist.
 *
 */
#[CoversClass( PendingAction::class )]
final class PendingActionTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies the production file's ABSPATH boot guard before first autoload.
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
	 * Every grammar-valid stage supports immediate asynchronous delivery.
	 *
	 * @param   string $stage Grammar-valid stage.
	 *
	 * @return  void
	 */
	#[DataProvider( 'valid_stage_provider' )]
	public function test_async_factory_accepts_every_grammar_valid_stage( string $stage ): void {
		$pending = PendingAction::async( $stage, 23 );

		self::assertSame( $stage, $pending->stage );
		self::assertSame( 'async', $pending->mode );
		self::assertNull( $pending->fire_at );
		self::assertSame( 23, $pending->priority );
	}

	/**
	 * Every grammar-valid stage supports scheduled single delivery.
	 *
	 * @param   string $stage Grammar-valid stage.
	 *
	 * @return  void
	 */
	#[DataProvider( 'valid_stage_provider' )]
	public function test_single_factory_accepts_every_grammar_valid_stage( string $stage ): void {
		$pending = PendingAction::single( $stage, 175, 31 );

		self::assertSame( $stage, $pending->stage );
		self::assertSame( 'single', $pending->mode );
		self::assertSame( 175, $pending->fire_at );
		self::assertSame( 31, $pending->priority );
	}

	/**
	 * Lexically malformed stages cannot enter run state through the asynchronous factory.
	 *
	 * @param   string $stage Malformed stage candidate.
	 *
	 * @return  void
	 */
	#[DataProvider( 'malformed_stage_provider' )]
	public function test_async_factory_rejects_a_malformed_stage( string $stage ): void {
		$this->expectException( \InvalidArgumentException::class );

		PendingAction::async( $stage, 10 );
	}

	/**
	 * Lexically malformed stages cannot enter run state through the scheduled factory.
	 *
	 * @param   string $stage Malformed stage candidate.
	 *
	 * @return  void
	 */
	#[DataProvider( 'malformed_stage_provider' )]
	public function test_single_factory_rejects_a_malformed_stage( string $stage ): void {
		$this->expectException( \InvalidArgumentException::class );

		PendingAction::single( $stage, 175, 10 );
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Returns grammar-valid stages: engine-owned, unowned, and vendor-qualified extensions.
	 *
	 * @return  iterable<string, array{string}>
	 */
	public static function valid_stage_provider(): iterable {
		yield 'start' => array( 'start' );
		yield 'run' => array( 'run' );
		yield 'continue' => array( 'continue' );
		yield 'cleanup' => array( 'cleanup' );
		yield 'queue_generation' => array( 'queue_generation' );
		yield 'vendor-qualified' => array( 'acme.export' );
	}

	/**
	 * Returns lexically malformed stage candidates.
	 *
	 * @return  iterable<string, array{string}>
	 */
	public static function malformed_stage_provider(): iterable {
		yield 'empty' => array( '' );
		yield 'uppercase' => array( 'Run' );
		yield 'leading digit' => array( '9start' );
		yield 'inner space' => array( 'has space' );
		yield 'trailing dot' => array( 'trailing.' );
		yield 'leading dot' => array( '.leading' );
		yield 'double dot' => array( 'a..b' );
		yield 'leading underscore' => array( '_private' );
	}

	// endregion.
}
