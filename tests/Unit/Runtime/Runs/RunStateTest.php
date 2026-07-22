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
	 * The kind-state mutator clones with the replacement payload and leaves everything else equal.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_with_kind_state_clones_with_the_replacement_payload(): void {
		$state = new RunState( status: RunStatus::Running, kind: 'chunked_job', executing: false, start_args: array( 'scope' => 'all' ), args_hash: 'stable-hash', kind_state: array(), failed_attempts: 0, action_sequence: 1, created_at: 1_700_000_000, heartbeat_at: 1_700_000_000 );

		$payload = array( array( 'site_id' => 7 ) );
		$next    = $state->with_kind_state( $payload );

		self::assertNotSame( $state, $next );
		self::assertSame( $payload, $next->kind_state );
		self::assertSame( array(), $state->kind_state, 'The original state must stay untouched.' );

		self::assertSame( $state->status, $next->status );
		self::assertSame( $state->kind, $next->kind );
		self::assertSame( $state->executing, $next->executing );
		self::assertSame( $state->start_args, $next->start_args );
		self::assertSame( $state->args_hash, $next->args_hash );
		self::assertSame( $state->failed_attempts, $next->failed_attempts );
		self::assertSame( $state->action_sequence, $next->action_sequence );
		self::assertSame( $state->created_at, $next->created_at );
		self::assertSame( $state->heartbeat_at, $next->heartbeat_at );
		self::assertSame( $state->pending, $next->pending );
		self::assertSame( $state->error, $next->error );
		self::assertSame( $state->previous_completed_run_id, $next->previous_completed_run_id );
		self::assertSame( $state->effects, $next->effects );
	}

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
		return new RunState( status: RunStatus::Running, kind: $kind, executing: false, start_args: array(), args_hash: 'hash', kind_state: array(), failed_attempts: 0, action_sequence: 1, created_at: 1, heartbeat_at: 1 );
	}

	// endregion.
}
