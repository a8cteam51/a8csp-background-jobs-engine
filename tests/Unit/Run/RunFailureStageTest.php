<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Run;

use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the open string-backed terminalization stage: enum-shaped wrapping over any
 * grammar-valid stage, byte-identical persisted values for the engine's own stages.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunFailureStage::class )]
final class RunFailureStageTest extends TestCase {

	/**
	 * Satisfies the production files' `ABSPATH` boot guard before first autoload.
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
	 * Every grammar-valid stage wraps enum-shaped, exposes its value, and is identity-stable.
	 *
	 * @param   string $value Grammar-valid stage value.
	 *
	 * @return  void
	 */
	#[DataProvider( 'valid_stage_provider' )]
	public function test_grammar_valid_stages_wrap_identity_stable( string $value ): void {
		$stage = RunFailureStage::from( $value );

		self::assertSame( $value, $stage->value );
		self::assertSame( $stage, RunFailureStage::from( $value ), 'Equal values must wrap to the same instance so identity comparisons keep working.' );
		self::assertSame( $stage, RunFailureStage::tryFrom( $value ) );
	}

	/**
	 * Lexically malformed stages are rejected: null from tryFrom(), a ValueError from from().
	 *
	 * @param   string $value Malformed stage candidate.
	 *
	 * @return  void
	 */
	#[DataProvider( 'malformed_stage_provider' )]
	public function test_malformed_stages_are_rejected( string $value ): void {
		self::assertNull( RunFailureStage::tryFrom( $value ), '"' . $value . '" must not wrap.' );

		$this->expectException( \ValueError::class );
		RunFailureStage::from( $value );
	}

	/**
	 * Returns grammar-valid stages: the engine's four persisted values plus a vendor-qualified extension.
	 *
	 * @return  iterable<string, array{string}>
	 */
	public static function valid_stage_provider(): iterable {
		yield 'execution' => array( 'execution' );
		yield 'queue_generation' => array( 'queue_generation' );
		yield 'crash_reclamation' => array( 'crash_reclamation' );
		yield 'scheduling' => array( 'scheduling' );
		yield 'vendor-qualified' => array( 'acme.export_sync' );
	}

	/**
	 * Returns lexically malformed stage candidates.
	 *
	 * @return  iterable<string, array{string}>
	 */
	public static function malformed_stage_provider(): iterable {
		yield 'empty' => array( '' );
		yield 'uppercase' => array( 'Execution' );
		yield 'leading digit' => array( '9stage' );
		yield 'inner space' => array( 'has space' );
		yield 'trailing dot' => array( 'trailing.' );
		yield 'leading dot' => array( '.leading' );
		yield 'double dot' => array( 'a..b' );
	}
}
