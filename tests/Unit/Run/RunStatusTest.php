<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Run;

use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the public run-lifecycle projection.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunStatus::class )]
final class RunStatusTest extends TestCase {

	/**
	 * Satisfies the production files' ABSPATH boot guard before first autoload.
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

	/**
	 * The public backing values remain an exact order-independent set.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_backing_values_are_an_exact_set(): void {
		self::assertEqualsCanonicalizing(
			array( 'running', 'completed', 'failed', 'cancelled', 'superseded' ),
			\array_map( static fn ( RunStatus $status ): string => $status->value, RunStatus::cases() )
		);
	}

	/**
	 * Every internal lifecycle state has a public projection counterpart.
	 *
	 * @load-bearing structural-guard
	 * @pin-rationale The public projection must cover every internal run state or the future status mapping has no counterpart.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_public_projection_covers_every_internal_run_state(): void {
		$public_values   = \array_map( static fn ( RunStatus $status ): string => $status->value, RunStatus::cases() );
		$internal_values = \array_map(
			static fn ( \A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunStatus $status ): string => $status->value,
			\A8C\SpecialProjects\BackgroundJobsEngine\Engine\Runs\RunStatus::cases()
		);

		self::assertSame( array(), \array_values( \array_diff( $internal_values, $public_values ) ) );
	}
}
