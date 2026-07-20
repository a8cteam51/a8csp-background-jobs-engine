<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\PendingAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the only valid pending lifecycle-action combinations.
 *
 */
#[CoversClass( PendingAction::class )]
final class PendingActionTest extends TestCase {
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

	/**
	 * Every lifecycle stage supports immediate asynchronous delivery.
	 *
	 * @param   string $stage Supported lifecycle stage.
	 *
	 * @return  void
	 */
	#[DataProvider( 'async_stage_provider' )]
	public function test_async_factory_creates_every_supported_stage( string $stage ): void {
		$pending = PendingAction::async( $stage, 23 );

		self::assertSame( $stage, $pending->stage );
		self::assertSame( 'async', $pending->mode );
		self::assertNull( $pending->fire_at );
		self::assertSame( 23, $pending->priority );
	}

	/**
	 * Retryable work stages support scheduled single delivery.
	 *
	 * @param   string $stage Supported lifecycle stage.
	 *
	 * @return  void
	 */
	#[DataProvider( 'single_stage_provider' )]
	public function test_single_factory_creates_only_scheduled_stages( string $stage ): void {
		$pending = PendingAction::single( $stage, 175, 31 );

		self::assertSame( $stage, $pending->stage );
		self::assertSame( 'single', $pending->mode );
		self::assertSame( 175, $pending->fire_at );
		self::assertSame( 31, $pending->priority );
	}

	/** Unsupported asynchronous stages cannot enter run state. */
	public function test_async_factory_rejects_an_unknown_stage(): void {
		$this->expectException( \InvalidArgumentException::class );

		PendingAction::async( 'unknown', 10 );
	}

	/**
	 * Scheduled single delivery rejects stages that are async-only.
	 *
	 * @param   string $stage Async-only lifecycle stage.
	 *
	 * @return  void
	 */
	#[DataProvider( 'async_only_stage_provider' )]
	public function test_single_factory_rejects_async_only_stages( string $stage ): void {
		$this->expectException( \InvalidArgumentException::class );

		PendingAction::single( $stage, 175, 10 );
	}

	/**
	 * Returns every asynchronous lifecycle stage.
	 *
	 * @return  iterable<string, array{string}>
	 */
	public static function async_stage_provider(): iterable {
		yield 'start' => array( 'start' );
		yield 'run' => array( 'run' );
		yield 'continue' => array( 'continue' );
		yield 'cleanup' => array( 'cleanup' );
	}

	/**
	 * Returns every scheduled single lifecycle stage.
	 *
	 * @return  iterable<string, array{string}>
	 */
	public static function single_stage_provider(): iterable {
		yield 'start' => array( 'start' );
		yield 'run' => array( 'run' );
		yield 'continue' => array( 'continue' );
	}

	/**
	 * Returns lifecycle stages that support only asynchronous delivery.
	 *
	 * @return  iterable<string, array{string}>
	 */
	public static function async_only_stage_provider(): iterable {
		yield 'cleanup' => array( 'cleanup' );
		yield 'unknown' => array( 'unknown' );
	}
}
