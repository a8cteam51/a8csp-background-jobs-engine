<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins run state's kind-owned payload: one opaque kind_state field, no kind-specific fields.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RunState::class )]
final class KindStateShapeTest extends TestCase {
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
	 * Run state carries one opaque kind-owned payload and no kind-specific field.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_state_carries_one_opaque_kind_owned_payload(): void {
		$reflection = new \ReflectionClass( RunState::class );

		self::assertTrue( $reflection->hasProperty( 'kind_state' ), 'Run state must carry the opaque kind-owned payload.' );
		self::assertFalse( $reflection->hasProperty( 'queue' ), 'The chunk queue is handler-owned kind state, not an engine field.' );
		self::assertFalse( $reflection->hasMethod( 'with_queue' ), 'The kind-specific queue mutator must not survive.' );
	}

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

		$payload = array( 'queue' => array( array( 'site_id' => 7 ) ) );
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

	// endregion.
}
