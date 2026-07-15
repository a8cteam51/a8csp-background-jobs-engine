<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Runs;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunState;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Run\RunStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins complete immutable copies for every RunState mutation surface.
 *
 */
#[CoversClass( RunState::class )]
#[UsesClass( PendingAction::class )]
#[UsesClass( RunStatus::class )]
final class RunStateTest extends TestCase {
	private const EFFECTS = array( 'retention', 'callbacks' );

	private const ERROR = array(
		'class'   => \RuntimeException::class,
		'message' => 'Database unavailable.',
		'stage'   => 'execution',
		'code'    => 'execution_failed',
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
				'status'          => RunStatus::Failed,
				'executing'       => true,
				'start_args'      => array( 'scope' => 'all' ),
				'args_hash'       => 'hash-a',
				'queue'           => array( array( 'page' => 1 ) ),
				'failed_attempts' => 2,
				'action_seq'      => 7,
				'created_at'      => 100,
				'heartbeat_at'    => 125,
				'pending'         => self::pending(),
				'error'           => self::ERROR,
				'effects'         => self::EFFECTS,
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
				'status'          => RunStatus::Running,
				'executing'       => false,
				'start_args'      => array( 'scope' => 'all' ),
				'args_hash'       => 'hash-a',
				'queue'           => array( array( 'page' => 1 ) ),
				'failed_attempts' => 2,
				'action_seq'      => 7,
				'created_at'      => 100,
				'heartbeat_at'    => 125,
				'pending'         => self::pending(),
				'error'           => self::ERROR,
				'effects'         => self::EFFECTS,
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
				'status'          => RunStatus::Running,
				'executing'       => true,
				'start_args'      => array( 'scope' => 'all' ),
				'args_hash'       => 'hash-a',
				'queue'           => array( array( 'page' => 2 ), array( 'page' => 3 ) ),
				'failed_attempts' => 2,
				'action_seq'      => 7,
				'created_at'      => 100,
				'heartbeat_at'    => 125,
				'pending'         => self::pending(),
				'error'           => self::ERROR,
				'effects'         => self::EFFECTS,
			),
			self::fields( $copy )
		);
	}

	/**
	 * Retry copies change only the current-chunk counter.
	 *
	 * @return  void
	 */
	public function test_with_failed_attempts_preserves_every_other_field(): void {
		$original = self::state();
		$copy     = $original->with_failed_attempts( 3 );

		self::assertNotSame( $original, $copy );
		self::assertSame(
			array(
				'status'          => RunStatus::Running,
				'executing'       => true,
				'start_args'      => array( 'scope' => 'all' ),
				'args_hash'       => 'hash-a',
				'queue'           => array( array( 'page' => 1 ) ),
				'failed_attempts' => 3,
				'action_seq'      => 7,
				'created_at'      => 100,
				'heartbeat_at'    => 125,
				'pending'         => self::pending(),
				'error'           => self::ERROR,
				'effects'         => self::EFFECTS,
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
				'status'          => RunStatus::Running,
				'executing'       => true,
				'start_args'      => array( 'scope' => 'all' ),
				'args_hash'       => 'hash-a',
				'queue'           => array( array( 'page' => 1 ) ),
				'failed_attempts' => 2,
				'action_seq'      => 8,
				'created_at'      => 100,
				'heartbeat_at'    => 125,
				'pending'         => self::pending(),
				'error'           => self::ERROR,
				'effects'         => self::EFFECTS,
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
				'status'          => RunStatus::Running,
				'executing'       => true,
				'start_args'      => array( 'scope' => 'all' ),
				'args_hash'       => 'hash-a',
				'queue'           => array( array( 'page' => 1 ) ),
				'failed_attempts' => 2,
				'action_seq'      => 7,
				'created_at'      => 100,
				'heartbeat_at'    => 150,
				'pending'         => self::pending(),
				'error'           => self::ERROR,
				'effects'         => self::EFFECTS,
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
		$pending  = PendingAction::async( 'run', 23 );
		$copy     = $original->with_pending( $pending );

		self::assertNotSame( $original, $copy );
		self::assertSame(
			array(
				'status'          => RunStatus::Running,
				'executing'       => true,
				'start_args'      => array( 'scope' => 'all' ),
				'args_hash'       => 'hash-a',
				'queue'           => array( array( 'page' => 1 ) ),
				'failed_attempts' => 2,
				'action_seq'      => 7,
				'created_at'      => 100,
				'heartbeat_at'    => 125,
				'pending'         => $pending,
				'error'           => self::ERROR,
				'effects'         => self::EFFECTS,
			),
			self::fields( $copy )
		);
	}

	/** Error copies change only terminal failure detail. */
	public function test_with_error_preserves_every_other_field(): void {
		$original = self::state();
		$error    = array(
			'class'        => null,
			'message'      => 'Task returned an invalid result.',
			'stage'        => 'execution',
			'code'         => 'execution_failed',
			'failed_chunk' => array( 'page' => 7 ),
		);
		$copy     = $original->with_error( $error );

		self::assertNotSame( $original, $copy );
		self::assertSame(
			array(
				'status'          => RunStatus::Running,
				'executing'       => true,
				'start_args'      => array( 'scope' => 'all' ),
				'args_hash'       => 'hash-a',
				'queue'           => array( array( 'page' => 1 ) ),
				'failed_attempts' => 2,
				'action_seq'      => 7,
				'created_at'      => 100,
				'heartbeat_at'    => 125,
				'pending'         => self::pending(),
				'error'           => $error,
				'effects'         => self::EFFECTS,
			),
			self::fields( $copy )
		);
	}

	/** Effect copies change only monotonic terminal progress. */
	public function test_with_effects_preserves_every_other_field(): void {
		$original = self::state();
		$effects  = array( 'retention', 'callbacks', 'hooks' );
		$copy     = $original->with_effects( $effects );

		self::assertNotSame( $original, $copy );
		self::assertSame(
			array(
				'status'          => RunStatus::Running,
				'executing'       => true,
				'start_args'      => array( 'scope' => 'all' ),
				'args_hash'       => 'hash-a',
				'queue'           => array( array( 'page' => 1 ) ),
				'failed_attempts' => 2,
				'action_seq'      => 7,
				'created_at'      => 100,
				'heartbeat_at'    => 125,
				'pending'         => self::pending(),
				'error'           => self::ERROR,
				'effects'         => $effects,
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
			failed_attempts: 2,
			action_seq: 7,
			created_at: 100,
			heartbeat_at: 125,
			pending: self::pending(),
			error: self::ERROR,
			effects: self::EFFECTS,
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
	 *     failed_attempts: int,
	 *     action_seq: int,
	 *     created_at: int,
	 *     heartbeat_at: int,
	 *     pending: PendingAction|null,
	 *     error: array{class: string|null, message: string, stage: string, code: string, failed_chunk?: array<array-key, mixed>}|null,
	 *     effects: list<string>
	 * }
	 */
	private static function fields( RunState $state ): array {
		return array(
			'status'          => $state->status,
			'executing'       => $state->executing,
			'start_args'      => $state->start_args,
			'args_hash'       => $state->args_hash,
			'queue'           => $state->queue,
			'failed_attempts' => $state->failed_attempts,
			'action_seq'      => $state->action_seq,
			'created_at'      => $state->created_at,
			'heartbeat_at'    => $state->heartbeat_at,
			'pending'         => $state->pending,
			'error'           => $state->error,
			'effects'         => $state->effects,
		);
	}

	/**
	 * Returns the shared pending-action value.
	 *
	 * @return  PendingAction
	 */
	private static function pending(): PendingAction {
		/** @var PendingAction|null $pending */
		static $pending = null;

		return $pending ??= PendingAction::single( 'continue', 175, 10 );
	}
}
