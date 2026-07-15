<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins complete immutable copies for every RunState mutation surface.
 *
 */
#[CoversClass( RunState::class )]
#[UsesClass( RunStatus::class )]
final class RunStateTest extends TestCase {
	private const PENDING = array(
		'stage'    => 'continue',
		'mode'     => 'single',
		'fire_at'  => 175,
		'unique'   => false,
		'priority' => 10,
	);

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
	 * The value shape is closed to inheritance and mutation.
	 *
	 * @return  void
	 */
	public function test_class_is_final_and_readonly(): void {
		$reflection = new \ReflectionClass( RunState::class );

		self::assertTrue( $reflection->isFinal() );
		self::assertTrue( $reflection->isReadOnly() );
	}

	/**
	 * Status copies change only the lifecycle state.
	 *
	 * @return  void
	 */
	public function test_with_status_preserves_every_other_field(): void {
		$original = self::state();
		$copy     = $original->with_status( RunStatus::Failed );

		self::assertNotSame( $original, $copy );
		self::assertSame(
			array(
				'status'        => RunStatus::Failed,
				'executing'     => true,
				'start_args'    => array( 'scope' => 'all' ),
				'args_hash'     => 'hash-a',
				'queue'         => array( array( 'page' => 1 ) ),
				'chunk_retries' => 2,
				'action_seq'    => 7,
				'created_at'    => 100,
				'heartbeat_at'  => 125,
				'pending'       => self::PENDING,
			),
			self::fields( $copy )
		);
	}

	/**
	 * Execution-marker copies change only delivery admission state.
	 *
	 * @return  void
	 */
	public function test_with_executing_preserves_every_other_field(): void {
		$original = self::state();
		$copy     = $original->with_executing( false );

		self::assertNotSame( $original, $copy );
		self::assertSame(
			array(
				'status'        => RunStatus::Running,
				'executing'     => false,
				'start_args'    => array( 'scope' => 'all' ),
				'args_hash'     => 'hash-a',
				'queue'         => array( array( 'page' => 1 ) ),
				'chunk_retries' => 2,
				'action_seq'    => 7,
				'created_at'    => 100,
				'heartbeat_at'  => 125,
				'pending'       => self::PENDING,
			),
			self::fields( $copy )
		);
	}

	/**
	 * Queue copies change only the pending chunks.
	 *
	 * @return  void
	 */
	public function test_with_queue_preserves_every_other_field(): void {
		$original = self::state();
		$copy     = $original->with_queue( array( array( 'page' => 2 ), array( 'page' => 3 ) ) );

		self::assertNotSame( $original, $copy );
		self::assertSame(
			array(
				'status'        => RunStatus::Running,
				'executing'     => true,
				'start_args'    => array( 'scope' => 'all' ),
				'args_hash'     => 'hash-a',
				'queue'         => array( array( 'page' => 2 ), array( 'page' => 3 ) ),
				'chunk_retries' => 2,
				'action_seq'    => 7,
				'created_at'    => 100,
				'heartbeat_at'  => 125,
				'pending'       => self::PENDING,
			),
			self::fields( $copy )
		);
	}

	/**
	 * Retry copies change only the current-chunk counter.
	 *
	 * @return  void
	 */
	public function test_with_chunk_retries_preserves_every_other_field(): void {
		$original = self::state();
		$copy     = $original->with_chunk_retries( 3 );

		self::assertNotSame( $original, $copy );
		self::assertSame(
			array(
				'status'        => RunStatus::Running,
				'executing'     => true,
				'start_args'    => array( 'scope' => 'all' ),
				'args_hash'     => 'hash-a',
				'queue'         => array( array( 'page' => 1 ) ),
				'chunk_retries' => 3,
				'action_seq'    => 7,
				'created_at'    => 100,
				'heartbeat_at'  => 125,
				'pending'       => self::PENDING,
			),
			self::fields( $copy )
		);
	}

	/**
	 * Action-sequence copies change only the newest scheduled lifecycle delivery.
	 *
	 * @return  void
	 */
	public function test_with_action_seq_preserves_every_other_field(): void {
		$original = self::state();
		$copy     = $original->with_action_seq( 8 );

		self::assertNotSame( $original, $copy );
		self::assertSame(
			array(
				'status'        => RunStatus::Running,
				'executing'     => true,
				'start_args'    => array( 'scope' => 'all' ),
				'args_hash'     => 'hash-a',
				'queue'         => array( array( 'page' => 1 ) ),
				'chunk_retries' => 2,
				'action_seq'    => 8,
				'created_at'    => 100,
				'heartbeat_at'  => 125,
				'pending'       => self::PENDING,
			),
			self::fields( $copy )
		);
	}

	/**
	 * Heartbeat copies change only the liveness timestamp.
	 *
	 * @return  void
	 */
	public function test_with_heartbeat_at_preserves_every_other_field(): void {
		$original = self::state();
		$copy     = $original->with_heartbeat_at( 150 );

		self::assertNotSame( $original, $copy );
		self::assertSame(
			array(
				'status'        => RunStatus::Running,
				'executing'     => true,
				'start_args'    => array( 'scope' => 'all' ),
				'args_hash'     => 'hash-a',
				'queue'         => array( array( 'page' => 1 ) ),
				'chunk_retries' => 2,
				'action_seq'    => 7,
				'created_at'    => 100,
				'heartbeat_at'  => 150,
				'pending'       => self::PENDING,
			),
			self::fields( $copy )
		);
	}

	/**
	 * Pending-action copies change only the durable successor descriptor.
	 *
	 * @return  void
	 */
	public function test_with_pending_preserves_every_other_field(): void {
		$original = self::state();
		$pending  = array(
			'stage'    => 'run',
			'mode'     => 'async',
			'fire_at'  => null,
			'unique'   => true,
			'priority' => 23,
		);
		$copy     = $original->with_pending( $pending );

		self::assertNotSame( $original, $copy );
		self::assertSame(
			array(
				'status'        => RunStatus::Running,
				'executing'     => true,
				'start_args'    => array( 'scope' => 'all' ),
				'args_hash'     => 'hash-a',
				'queue'         => array( array( 'page' => 1 ) ),
				'chunk_retries' => 2,
				'action_seq'    => 7,
				'created_at'    => 100,
				'heartbeat_at'  => 125,
				'pending'       => $pending,
			),
			self::fields( $copy )
		);
	}

	/**
	 * Returns the shared source state.
	 *
	 * @return  RunState
	 */
	private static function state(): RunState {
		return new RunState(
			status: RunStatus::Running,
			executing: true,
			start_args: array( 'scope' => 'all' ),
			args_hash: 'hash-a',
			queue: array( array( 'page' => 1 ) ),
			chunk_retries: 2,
			action_seq: 7,
			created_at: 100,
			heartbeat_at: 125,
			pending: self::PENDING,
		);
	}

	/**
	 * Returns every field in persisted schema order.
	 *
	 * @param   RunState $state Run state.
	 *
	 * @return  array{
	 *     status: RunStatus,
	 *     executing: bool,
	 *     start_args: array<array-key, mixed>,
	 *     args_hash: string,
	 *     queue: list<array<array-key, mixed>>,
	 *     chunk_retries: int,
	 *     action_seq: int,
	 *     created_at: int,
	 *     heartbeat_at: int,
	 *     pending: array{stage: string, mode: 'async'|'single', fire_at: int|null, unique: bool, priority: int}|null
	 * }
	 */
	private static function fields( RunState $state ): array {
		return array(
			'status'        => $state->status,
			'executing'     => $state->executing,
			'start_args'    => $state->start_args,
			'args_hash'     => $state->args_hash,
			'queue'         => $state->queue,
			'chunk_retries' => $state->chunk_retries,
			'action_seq'    => $state->action_seq,
			'created_at'    => $state->created_at,
			'heartbeat_at'  => $state->heartbeat_at,
			'pending'       => $state->pending,
		);
	}
}
