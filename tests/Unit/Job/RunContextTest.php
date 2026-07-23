<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Job;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the public invocation context to an eagerly validated run identifier.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunContext::class )]
#[UsesClass( RunId::class )]
final class RunContextTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string RUN_ID = '00000000001700000000-0000000000000000042';

	// endregion.

	// region LIFECYCLE.

	/**
	 * Satisfies production-file boot guards before the model types autoload.
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
	 * The context returns the exact validated value supplied to its constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_get_run_id_returns_the_constructor_value(): void {
		$run_id  = RunId::from( self::RUN_ID );
		$context = new RunContext( $run_id, array( 'site_id' => 7 ) );

		self::assertSame( $run_id, $context->get_run_id() );
	}

	/**
	 * A malformed wire value fails before it can enter the typed context constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_non_canonical_string_is_rejected_before_context_construction(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIsOrContains( 'Run identifier must match the canonical shape' );

		$context = new RunContext( RunId::from( 'non-canonical-run-id' ), array() );
		self::fail( 'A non-canonical identifier constructed ' . $context::class . '.' );
	}

	// endregion.
}
