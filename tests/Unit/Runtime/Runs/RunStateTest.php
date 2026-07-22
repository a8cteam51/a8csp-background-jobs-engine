<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins typed run-state arithmetic and kind-key invariants.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunState::class )]
final class RunStateTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads guarded production files before the value object is exercised.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
	}

	// endregion.

	// region TESTS.

	/**
	 * Attempt increments remain positive and saturate instead of overflowing persisted integers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_attempt_increment_is_positive_and_saturating(): void {
		self::assertSame( 1, RunState::increment_attempts_safely( -5 ) );
		self::assertSame( 1, RunState::increment_attempts_safely( 0 ) );
		self::assertSame( 3, RunState::increment_attempts_safely( 2 ) );
		self::assertSame( \PHP_INT_MAX, RunState::increment_attempts_safely( \PHP_INT_MAX ) );
	}

	/**
	 * Grammar-valid handler keys remain opaque run-state values.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $kind Grammar-valid kind key.
	 *
	 * @return  void
	 */
	#[DataProvider( 'valid_kind_provider' )]
	public function test_grammar_valid_kind_keys_are_accepted( string $kind ): void {
		self::assertSame( $kind, $this->state( $kind )->kind );
	}

	/**
	 * Malformed handler keys cannot enter typed run state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $kind Malformed kind candidate.
	 *
	 * @return  void
	 */
	#[DataProvider( 'malformed_kind_provider' )]
	public function test_malformed_kind_keys_are_rejected( string $kind ): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->state( $kind );
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Returns grammar-valid engine and extension kind keys.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  iterable<string, array{string}>
	 */
	public static function valid_kind_provider(): iterable {
		yield 'job' => array( 'job' );
		yield 'chunked job' => array( 'chunked_job' );
		yield 'vendor-qualified' => array( 'acme.export' );
	}

	/**
	 * Returns lexically malformed kind candidates.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  iterable<string, array{string}>
	 */
	public static function malformed_kind_provider(): iterable {
		yield 'empty' => array( '' );
		yield 'uppercase' => array( 'Job' );
		yield 'punctuation' => array( 'Job!' );
		yield 'double qualification' => array( 'acme.export.daily' );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a minimal run state carrying one kind key.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $kind Kind key under test.
	 *
	 * @return  RunState
	 */
	private function state( string $kind ): RunState {
		return new RunState( status: RunStatus::Running, kind: $kind, executing: false, start_args: array(), args_hash: 'hash', queue: array(), failed_attempts: 0, action_sequence: 1, created_at: 1, heartbeat_at: 1 );
	}

	// endregion.
}
