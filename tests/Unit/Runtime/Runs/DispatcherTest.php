<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\OwnerOperations;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Error\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailureStage;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Dispatcher;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FaultingOverlapKeyResolverProvider;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * Exercises job admission and failed-run retry through owner-bound facades.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Dispatcher::class )]
final class DispatcherTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const array ARGS              = array(
		'site_id' => 7,
		'mode'    => 'full',
	);
	private const string IDENTITY         = self::OWNER . ':' . self::NAME;
	private const string INCUMBENT_RUN_ID = '00000000001699999998-0000000000000000040';
	private const string NAME             = 'email-digest';
	private const int NOW                 = 1_700_000_000;
	private const string OTHER_RUN_ID     = '00000000001700000001-0000000000000000043';
	private const string OWNER            = 'runs-tests';
	private const string RUN_ID           = '00000000001700000000-0000000000000000042';
	private const string UNKNOWN_NAME     = 'unknown';
	private const string UNKNOWN_IDENTITY = self::OWNER . ':' . self::UNKNOWN_NAME;

	private OwnerOperations $client;
	private StoreFixtureBuilder $fixtures;
	private EngineRig $rig;
	private RecordingJob $job;

	/** @var (\Closure(array<array-key, mixed>): mixed)|null */
	private ?\Closure $overlap_key_resolver = null;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress seams before the production graph is built.
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

	/**
	 * Boots one registered job against deterministic interface fakes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->boot();
	}

	/**
	 * Releases request-local engine state after each scenario.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function tearDown(): void {
		try {
			$this->rig->tear_down();
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * The default canonical argument identity dispatches the original arguments with the requested priority.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_with_default_overlap_key_dispatches_the_original_arguments(): void {
		$result = $this->client->dispatch( self::NAME, self::ARGS, priority: 23 );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$call = $this->single_run_delivery_call();
		self::assertSame( 23, $call['args']['priority'] ?? null );
		$run = \get_option( $this->run_option_name() );
		self::assertIsArray( $run );
		self::assertSame( 'job', $run['kind'] ?? null );
		$this->rig->backend()->assert_scheduled( self::IDENTITY );
		$started = $this->rig->hooks()->fired( 'a8csp_bgje/started/' . self::IDENTITY );
		$run_id  = $started[0][0] ?? null;
		self::assertInstanceOf( RunId::class, $run_id );
		self::assertSame( self::RUN_ID, (string) $run_id );
		self::assertSame( array( array( $run_id, self::ARGS ) ), $started );
		$this->rig->run_due();
		self::assertSame( array( self::ARGS ), $this->job->calls );
	}

	/**
	 * An opaque key cannot alias the canonical argument identity with the same bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dedup_key_cannot_collide_with_the_argument_identity_domain(): void {
		$this->overlap_key_resolver = static fn ( array $args ): ?string => isset( $args['opaque'] ) ? '[]' : null;

		$argument_identity = $this->client->dispatch( self::NAME );
		self::assertInstanceOf( Success::class, $argument_identity );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$overlap_identity = $this->client->dispatch( self::NAME, array( 'opaque' => true ) );

		self::assertInstanceOf( Success::class, $overlap_identity );
		self::assertNotSame( $argument_identity->value, $overlap_identity->value );
		self::assertCount( 2, $this->run_delivery_calls() );
	}

	/**
	 * Different argument-derived opaque keys admit independent runs.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_treats_different_overlap_keys_as_distinct_single_flight_identities(): void {
		$this->overlap_key_resolver = static fn ( array $args ): ?string => \is_string( $args['overlap_key'] ?? null ) ? $args['overlap_key'] : null;
		$first                      = $this->client->dispatch( self::NAME, self::ARGS + array( 'overlap_key' => 'site-7-full' ) );
		self::assertInstanceOf( Success::class, $first );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$second = $this->client->dispatch( self::NAME, self::ARGS + array( 'overlap_key' => 'site-8-full' ) );

		self::assertInstanceOf( Success::class, $second );
		self::assertNotSame( $first->value, $second->value );
		self::assertCount( 2, $this->run_delivery_calls() );
	}

	/**
	 * An Allow invariant admits matching imperative enqueues without a caller policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_honors_the_job_allow_invariant_without_a_caller_policy(): void {
		$this->restart_with_overlap_policy( OverlapPolicy::Allow );

		$first = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $first );
		$this->rig->clock()->timestamp = self::NOW + 1;
		$second                        = $this->client->dispatch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $second );
		self::assertNotSame( $first->value, $second->value );
		self::assertCount( 2, $this->run_delivery_calls() );
	}

	/**
	 * A Job overlap key accepts the 64-byte boundary at engine consumption.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_accepts_a_64_byte_overlap_key(): void {
		$this->overlap_key_resolver = static fn ( array $args ): string => \str_repeat( 'a', 64 );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $result );
	}

	/**
	 * A Job overlap key outside the 1-to-64-byte boundary produces a contained payload rejection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $overlap_key Invalid overlap key.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_overlap_keys' )]
	public function test_dispatch_rejects_an_invalid_job_overlap_key( string $overlap_key ): void {
		$this->overlap_key_resolver = static fn ( array $args ): string => $overlap_key;

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ErrorCode::PayloadRejected );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * Supplies overlap keys outside the closed byte-length boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{overlap_key: string}>
	 */
	public static function invalid_overlap_keys(): array {
		return array(
			'empty'    => array( 'overlap_key' => '' ),
			'65 bytes' => array( 'overlap_key' => \str_repeat( 'a', 65 ) ),
		);
	}

	/**
	 * Cancellation clears only the accepted run's scheduler group.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_cancel_clears_the_run_scheduler_group(): void {
		$run_id = $this->dispatch_job();
		$this->reset_observations();

		$result = $this->client->cancel( self::NAME, $run_id );

		self::assertInstanceOf( Success::class, $result );
		$unschedule = $this->backend_calls( 'unschedule' );
		self::assertCount( 1, $unschedule );
		self::assertSame( self::IDENTITY . '|' . $run_id, $unschedule[0]['args']['group'] ?? null );
	}

	/**
	 * A throwing started listener fails the accepted run through public hooks and Results.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_terminalizes_when_a_started_listener_throws(): void {
		$GLOBALS['a8csp_bgje_test_action_throwables'] = array( 'a8csp_bgje/started/' . self::IDENTITY => new \RuntimeException( 'Started listener exploded.' ) );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::ExecutionFailed );
		self::assertSame( \sprintf( 'job "%s" started listener failed because RuntimeException was thrown. Fix the started-hook listener before dispatching the job again.', self::IDENTITY ), $error->message );
		self::assertSame( self::IDENTITY, $error->context['identity'] ?? null );
		$this->rig->assert_failed( ErrorCode::ExecutionFailed );
		self::assertSame(
			array(
				'a8csp_bgje/started/' . self::IDENTITY,
				'a8csp_bgje/started',
				'a8csp_bgje/failed/' . self::IDENTITY,
				'a8csp_bgje/failed',
			),
			$this->rig->hooks()->sequence()
		);
	}

	/**
	 * A throwing started listener cannot mutate the persisted state used by terminal CAS.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_detaches_started_hook_arguments_before_terminalizing_listener_failure(): void {
		$value      = 'accepted';
		$start_args = array(
			'value'  => &$value,
			'mirror' => &$value,
		);
		$run_option = $this->run_option_name();
		$wpdb       = $this->rig->wpdb();
		$GLOBALS['a8csp_bgje_test_before_add_option'] = static function ( string $option, mixed $option_value, string $deprecated, bool|string|null $autoload ) use ( $run_option, $wpdb ): void {
			if ( $run_option !== $option ) {
				return;
			}

			$raw = \maybe_serialize( $option_value );
			self::assertIsString( $raw );
			$wpdb->put( $option, $raw );
			$wpdb->before_next(
				'update',
				static function () use ( $option ): void {
					$options = $GLOBALS['a8csp_bgje_test_options'] ?? null;
					self::assertIsArray( $options );

					// The option stub mirrors add_option() outside the authoritative wpdb row, so its shadow must not survive the raw-row fixture.
					unset( $options[ $option ] );
					$GLOBALS['a8csp_bgje_test_options'] = $options;
				}
			);
		};

		$callbacks = $GLOBALS['a8csp_bgje_test_action_callbacks'] ?? null;
		self::assertIsArray( $callbacks );
		$callbacks[ 'a8csp_bgje/started/' . self::IDENTITY ] = static function ( RunId $run_id, array $hook_args ): never {
			$hook_args['value'] = 'listener-mutated';

			throw new \RuntimeException( 'Started listener exploded after mutating its payload.' );
		};

		$GLOBALS['a8csp_bgje_test_action_callbacks'] = $callbacks;

		$result = $this->client->dispatch( self::NAME, $start_args );

		$this->assert_failure_code( $result, ErrorCode::ExecutionFailed );
		self::assertSame( 'accepted', $value );
		$this->rig->assert_failed( ErrorCode::ExecutionFailed );
		$snapshot = $this->rig->inspection()->runs( self::IDENTITY );
		self::assertSame( array(), $snapshot['live'] );
		self::assertSame( 'failed', $snapshot['history'][0]['outcome'] ?? null );
		self::assertTrue( $snapshot['history'][0]['failed_store'] ?? false );

		$GLOBALS['a8csp_bgje_test_action_callbacks'] = array();
		$this->rig->clock()->timestamp               = self::NOW + 1;
		self::assertInstanceOf( Success::class, $this->client->dispatch( self::NAME, $start_args ) );
	}

	/**
	 * Real lock outcomes preserve every default, filtered, and delay-floored boundary.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Production-built foreign lock bytes distinguish the exact fresh/stale edge that controls whether admission may replace an incumbent.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int|null $staleness_filter Filtered staleness window.
	 * @param   int|null $continue_filter  Filtered continuation delay.
	 * @param   int      $heartbeat_age    Incumbent heartbeat age.
	 * @param   bool     $takes_over_stale Whether admission should take over a stale incumbent.
	 *
	 * @return  void
	 */
	#[DataProvider( 'lock_window_boundaries' )]
	public function test_dispatch_resolves_the_exact_lock_staleness_window( ?int $staleness_filter, ?int $continue_filter, int $heartbeat_age, bool $takes_over_stale ): void {
		if ( null !== $staleness_filter ) {
			$this->set_filter_value( 'a8csp_bgje/lock_staleness/' . self::IDENTITY, $staleness_filter );
		}
		if ( null !== $continue_filter ) {
			$this->set_filter_value( 'a8csp_bgje/continue_delay', $continue_filter );
		}
		$this->seed_running_lock( $heartbeat_age );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		if ( $takes_over_stale ) {
			self::assertInstanceOf( Success::class, $result );
			self::assertSame( self::RUN_ID, $result->value );
			$this->rig->backend()->assert_scheduled( self::IDENTITY );
			return;
		}

		$error = $this->assert_failure_code( $result, ErrorCode::OverlapHeld );
		self::assertSame( self::INCUMBENT_RUN_ID, $error->context['run_id'] ?? null );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * An indeterminate lock selection refuses admission without changing any persisted byte.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The authoritative claim read fails after the losing insert, so exact row equality proves the refusal is fail-closed and write-free.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_fails_closed_when_lock_selection_is_indeterminate(): void {
		$this->seed_running_lock( 0 );
		$before = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient lock selection failure';
			}
		);

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ErrorCode::StorageFailed );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/** A malformed lock fails closed without changing its raw bytes or creating provisional state. */
	public function test_dispatch_maps_a_malformed_lock_to_storage_failure_without_mutation(): void {
		$lock_option = OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash();
		$malformed   = 'malformed-overlap-lock';
		$this->rig->wpdb()->put( $lock_option, $malformed );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::StorageFailed );
		self::assertSame(
			array(
				'identity' => self::IDENTITY,
				'run_id'   => self::RUN_ID,
				'kind'     => 'job',
			),
			$error->context
		);
		self::assertSame( $malformed, $this->rig->wpdb()->rows[ $lock_option ] ?? null );
		self::assertFalse( \get_option( $this->run_option_name() ) );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * A lost incumbent supersession aborts before lock transfer and removes only the exact provisional R2.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The incumbent changes during the first CAS, proving the later lock CAS is unreachable and compensation cannot erase either changed R1 or its lock.
	 * @fixture StoreFixtureBuilder
	 */
	public function test_replace_dispatch_aborts_before_lock_transfer_when_incumbent_supersession_is_lost(): void {
		$this->restart_with_overlap_policy( OverlapPolicy::Replace );
		$this->seed_running_lock( 0 );
		$incumbent_option = RunStore::OPTION_PREFIX . self::IDENTITY . '_' . self::INCUMBENT_RUN_ID;
		$incumbent_raw    = $this->rig->wpdb()->rows[ $incumbent_option ] ?? null;
		self::assertIsString( $incumbent_raw );
		$advanced = \maybe_unserialize( $incumbent_raw );
		self::assertIsArray( $advanced );
		$advanced['action_sequence'] = 2;
		$advanced_raw                = \maybe_serialize( $advanced );
		self::assertIsString( $advanced_raw );
		$lock_option = OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash();
		$lock_raw    = $this->rig->wpdb()->rows[ $lock_option ] ?? null;
		self::assertIsString( $lock_raw );
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $incumbent_option, $advanced_raw ): void {
				$wpdb->put( $incumbent_option, $advanced_raw );
			}
		);

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::OverlapHeld );
		self::assertSame( \sprintf( 'job "%s" incumbent run changed while the replacement was superseding it; retry the dispatch against the current incumbent state.', self::IDENTITY ), $error->message );
		self::assertSame(
			array(
				'identity' => self::IDENTITY,
				'run_id'   => self::RUN_ID,
				'kind'     => 'job',
			),
			$error->context
		);
		self::assertSame( $advanced_raw, $this->rig->wpdb()->rows[ $incumbent_option ] ?? null );
		self::assertSame( $lock_raw, $this->rig->wpdb()->rows[ $lock_option ] ?? null );
		self::assertFalse( \get_option( $this->run_option_name() ) );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * A failed incumbent supersession write reports unavailable storage before lock transfer.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A failed first CAS leaves both incumbent generations unchanged, proving storage failure is distinct from a competing run-row writer.
	 * @fixture StoreFixtureBuilder
	 */
	public function test_replace_dispatch_reports_storage_failure_when_incumbent_supersession_write_fails(): void {
		$this->restart_with_overlap_policy( OverlapPolicy::Replace );
		$this->seed_running_lock( 0 );
		$incumbent_option = RunStore::OPTION_PREFIX . self::IDENTITY . '_' . self::INCUMBENT_RUN_ID;
		$incumbent_raw    = $this->rig->wpdb()->rows[ $incumbent_option ] ?? null;
		self::assertIsString( $incumbent_raw );
		$lock_option = OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash();
		$lock_raw    = $this->rig->wpdb()->rows[ $lock_option ] ?? null;
		self::assertIsString( $lock_raw );
		$this->rig->wpdb()->script_result( 'update', false );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::StorageFailed );
		self::assertSame( \sprintf( 'Run "%1$s" for job "%2$s" could not confirm the incumbent supersession before overlap transfer; repair option writes and retry.', self::RUN_ID, self::IDENTITY ), $error->message );
		self::assertSame(
			array(
				'identity' => self::IDENTITY,
				'run_id'   => self::RUN_ID,
				'kind'     => 'job',
			),
			$error->context
		);
		self::assertSame( $incumbent_raw, $this->rig->wpdb()->rows[ $incumbent_option ] ?? null );
		self::assertSame( $lock_raw, $this->rig->wpdb()->rows[ $lock_option ] ?? null );
		self::assertFalse( \get_option( $this->run_option_name() ) );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * A rival transfer winner remains admitted without duplicate supersession effects from the loser.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale R3 transfers the captured R1 lock inside R2's lock CAS, proving the losing R2 compensates only its exact provisional row and never replays R3-owned effects.
	 * @fixture StoreFixtureBuilder
	 */
	public function test_replace_dispatch_preserves_a_rival_that_transfers_the_lock_before_replace(): void {
		$this->restart_with_overlap_policy( OverlapPolicy::Replace );
		$this->seed_running_lock( 0 );
		$nested = null;
		$this->rig->wpdb()->before_next( 'update', static function (): void {} );
		$this->rig->wpdb()->before_next(
			'update',
			function () use ( &$nested ): void {
				$this->rig->clock()->timestamp  = self::NOW + 1;
				$this->rig->randomizer()->value = 43;
				$nested                         = $this->client->dispatch( self::NAME, self::ARGS );
			}
		);

		$outer = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $outer, ErrorCode::OverlapHeld );
		self::assertInstanceOf( Success::class, $nested );
		self::assertSame( self::OTHER_RUN_ID, $nested->value );
		self::assertFalse( \get_option( $this->run_option_name() ) );
		$lock_raw = $this->rig->wpdb()->rows[ OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash() ] ?? null;
		self::assertIsString( $lock_raw );
		$lock = \maybe_unserialize( $lock_raw );
		self::assertIsArray( $lock );
		self::assertSame( self::OTHER_RUN_ID, $lock['run_id'] ?? null );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_bgje/superseded/' . self::IDENTITY ) );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_bgje/superseded' ) );
	}

	/**
	 * Supersession hooks run only after the replacement has committed its discovery and delivery state.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A superseded listener admits R3 synchronously, proving outer R2 performs no pointer or scheduler writes after exposing the takeover hook.
	 * @fixture StoreFixtureBuilder
	 */
	public function test_replace_dispatch_commits_replacement_before_firing_superseded_hooks(): void {
		$this->restart_with_overlap_policy( OverlapPolicy::Replace );
		$this->seed_running_lock( 0 );
		$nested  = null;
		$entered = false;

		$callbacks = $GLOBALS['a8csp_bgje_test_action_callbacks'] ?? null;
		self::assertIsArray( $callbacks );
		$callbacks[ 'a8csp_bgje/superseded/' . self::IDENTITY ] = function () use ( &$nested, &$entered ): void {
			if ( $entered ) {
				return;
			}

			$entered = true;

			$this->rig->clock()->timestamp  = self::NOW + 1;
			$this->rig->randomizer()->value = 43;

			$nested = $this->client->dispatch( self::NAME, self::ARGS );
		};

		$GLOBALS['a8csp_bgje_test_action_callbacks'] = $callbacks;

		$outer = $this->client->dispatch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $outer );
		self::assertSame( self::RUN_ID, $outer->value );
		self::assertInstanceOf( Success::class, $nested );
		self::assertSame( self::OTHER_RUN_ID, $nested->value );
		$lock_raw = $this->rig->wpdb()->rows[ OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash() ] ?? null;
		self::assertIsString( $lock_raw );
		$lock = \maybe_unserialize( $lock_raw );
		self::assertIsArray( $lock );
		self::assertSame( self::OTHER_RUN_ID, $lock['run_id'] ?? null );
		$latest_raw = $this->rig->wpdb()->rows[ 'a8csp_bgje_latest_run_' . self::IDENTITY ] ?? null;
		self::assertIsString( $latest_raw );
		$latest = \maybe_unserialize( $latest_raw );
		self::assertIsArray( $latest );
		self::assertSame( self::OTHER_RUN_ID, $latest['all'] ?? null );
		$latest_by_hash = $latest['by_hash'] ?? null;
		self::assertIsArray( $latest_by_hash );
		self::assertSame( self::OTHER_RUN_ID, $latest_by_hash[ $this->args_hash() ] ?? null );
		self::assertCount( 2, $this->run_delivery_calls() );
	}

	/**
	 * A throwing superseded listener cannot abandon a replacement whose lock transfer is definite.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The listener throws after R2 scheduling commits, proving dispatch returns the accepted run while R1 retains unmarked effects for maintenance replay.
	 * @fixture StoreFixtureBuilder
	 */
	public function test_replace_dispatch_contains_superseded_listener_failure_after_committing_replacement(): void {
		$this->restart_with_overlap_policy( OverlapPolicy::Replace );
		$this->seed_running_lock( 0 );
		$listener = new \RuntimeException( 'Superseded listener exploded.' );

		$GLOBALS['a8csp_bgje_test_action_throwables'] = array(
			'a8csp_bgje/superseded/' . self::IDENTITY => $listener,
		);

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$this->rig->backend()->assert_scheduled( self::IDENTITY );
		$lock_raw = $this->rig->wpdb()->rows[ OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash() ] ?? null;
		self::assertIsString( $lock_raw );
		$lock = \maybe_unserialize( $lock_raw );
		self::assertIsArray( $lock );
		self::assertSame( self::RUN_ID, $lock['run_id'] ?? null );
		$incumbent_raw = $this->rig->wpdb()->rows[ RunStore::OPTION_PREFIX . self::IDENTITY . '_' . self::INCUMBENT_RUN_ID ] ?? null;
		self::assertIsString( $incumbent_raw );
		$incumbent = \maybe_unserialize( $incumbent_raw );
		self::assertIsArray( $incumbent );
		self::assertSame( RunStatus::Superseded->value, $incumbent['status'] ?? null );
		$effects = $incumbent['effects'] ?? null;
		self::assertIsArray( $effects );
		self::assertNotContains( 'hooks', $effects );
		$effect_errors = \array_values(
			\array_filter(
				$this->rig->logger()->records,
				static fn ( array $record ): bool => 'Superseded-run terminal effects could not finish synchronously; the durable terminal row retains unmarked effects for maintenance replay.' === $record['message']
			)
		);
		self::assertCount( 1, $effect_errors );
		self::assertSame( $listener, $effect_errors[0]['context']['exception'] ?? null );
	}

	/**
	 * Scheduling compensation preserves a concurrent replacement-run generation.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The scheduler interleaving advances provisional R2 while its lock is unchanged, proving failure cleanup preserves both the newer state and its fence.
	 * @fixture StoreFixtureBuilder
	 */
	public function test_replace_dispatch_preserves_a_concurrent_run_generation_when_scheduling_fails(): void {
		$this->restart_with_overlap_policy( OverlapPolicy::Replace );
		$this->seed_running_lock( 0 );
		$this->rig->backend()->results['enqueue_async'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore scheduling.' ) );

		$run_option     = $this->run_option_name();
		$lock_option    = OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash();
		$advanced_state = null;
		$lock_raw       = null;
		$this->rig->backend()->before_next(
			'enqueue_async',
			function () use ( $run_option, $lock_option, &$advanced_state, &$lock_raw ): void {
				$advanced = \get_option( $run_option );
				self::assertIsArray( $advanced );
				$advanced['action_sequence'] = 2;
				$advanced_state              = $advanced;
				self::assertTrue( \update_option( $run_option, $advanced, false ) );
				$lock_raw = $this->rig->wpdb()->rows[ $lock_option ] ?? null;
				self::assertIsString( $lock_raw );
			}
		);

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ErrorCode::BackendRejected );
		self::assertIsArray( $advanced_state );
		self::assertSame( $advanced_state, \get_option( $run_option ) );
		self::assertIsString( $lock_raw );
		self::assertSame( $lock_raw, $this->rig->wpdb()->rows[ $lock_option ] ?? null );
		self::assertFalse( \get_option( RunStore::OPTION_PREFIX . self::IDENTITY . '_' . self::INCUMBENT_RUN_ID ) );
	}

	/**
	 * A definite retry transfer replays supersession effects left pending by an indeterminate transfer.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The first admission wins the run-state CAS but cannot classify the lock CAS; exact hooks and history appear only after the retry transfers that same captured lock.
	 * @fixture StoreFixtureBuilder
	 */
	public function test_replace_dispatch_replays_pending_supersession_after_a_definite_retry_transfer(): void {
		$this->restart_with_overlap_policy( OverlapPolicy::Replace );
		$this->seed_running_lock( 0 );
		$this->rig->wpdb()->before_next( 'update', static function (): void {} );
		$this->rig->wpdb()->before_next( 'update', static fn ( WpdbLockSpy $wpdb ) => $wpdb->script_result( 'update', false ) );

		$first = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $first, ErrorCode::StorageFailed );
		$incumbent_option = RunStore::OPTION_PREFIX . self::IDENTITY . '_' . self::INCUMBENT_RUN_ID;
		$incumbent_raw    = $this->rig->wpdb()->rows[ $incumbent_option ] ?? null;
		self::assertIsString( $incumbent_raw );
		$incumbent = \maybe_unserialize( $incumbent_raw );
		self::assertIsArray( $incumbent );
		self::assertSame( RunStatus::Superseded->value, $incumbent['status'] ?? null );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_bgje/superseded/' . self::IDENTITY ) );
		self::assertFalse( \get_option( $this->run_option_name() ) );
		$this->rig->clock()->timestamp  = self::NOW + 1;
		$this->rig->randomizer()->value = 43;

		$second = $this->client->dispatch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $second );
		self::assertSame( self::OTHER_RUN_ID, $second->value );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_bgje/superseded/' . self::IDENTITY ) );
		self::assertCount( 1, $this->rig->hooks()->fired( 'a8csp_bgje/superseded' ) );
		self::assertArrayNotHasKey( $incumbent_option, $this->rig->wpdb()->rows );
		$lock_raw = $this->rig->wpdb()->rows[ OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash() ] ?? null;
		self::assertIsString( $lock_raw );
		$lock = \maybe_unserialize( $lock_raw );
		self::assertIsArray( $lock );
		self::assertSame( self::OTHER_RUN_ID, $lock['run_id'] ?? null );
		$history_raw = $this->rig->wpdb()->rows[ 'a8csp_bgje_run_history_' . self::IDENTITY ] ?? null;
		self::assertIsString( $history_raw );
		$history = \maybe_unserialize( $history_raw );
		self::assertIsArray( $history );
		$terminal = $history['terminal'] ?? null;
		self::assertIsArray( $terminal );
		self::assertContains(
			array(
				'run_id' => self::INCUMBENT_RUN_ID,
				'status' => RunStatus::Superseded->value,
			),
			$terminal
		);
	}

	/**
	 * Reject takes over a stale parseable lock after its owner row is already absent.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The stale lock is the only surviving owner snapshot, proving Reject transfers it without inventing a Running state to supersede.
	 * @fixture StoreFixtureBuilder
	 */
	public function test_stale_reject_dispatch_takes_over_an_orphaned_parseable_lock(): void {
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), self::INCUMBENT_RUN_ID, self::NOW - 901, self::NOW - 901 ) );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$lock_raw = $this->rig->wpdb()->rows[ OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash() ] ?? null;
		self::assertIsString( $lock_raw );
		$lock = \maybe_unserialize( $lock_raw );
		self::assertIsArray( $lock );
		self::assertSame( self::RUN_ID, $lock['run_id'] ?? null );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_bgje/superseded/' . self::IDENTITY ) );
	}

	/**
	 * Replace takes over a fresh parseable lock after its owner row is already absent.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The ownerless lock remains fresh, proving Replace policy reaches the exact transfer without a fictitious supersession transition.
	 * @fixture StoreFixtureBuilder
	 */
	public function test_fresh_replace_dispatch_takes_over_an_orphaned_parseable_lock(): void {
		$this->restart_with_overlap_policy( OverlapPolicy::Replace );
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), self::INCUMBENT_RUN_ID, self::NOW, self::NOW ) );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::RUN_ID, $result->value );
		$lock_raw = $this->rig->wpdb()->rows[ OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash() ] ?? null;
		self::assertIsString( $lock_raw );
		$lock = \maybe_unserialize( $lock_raw );
		self::assertIsArray( $lock );
		self::assertSame( self::RUN_ID, $lock['run_id'] ?? null );
		self::assertSame( array(), $this->rig->hooks()->fired( 'a8csp_bgje/superseded/' . self::IDENTITY ) );
	}

	/**
	 * A contended lock cannot supersede a valid run from another overlap lane.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A parseable lock names a real Running row whose persisted argument hash differs, proving relational corruption aborts before either exact CAS.
	 * @fixture StoreFixtureBuilder
	 */
	public function test_replace_dispatch_refuses_a_lock_owner_from_another_overlap_lane(): void {
		$this->restart_with_overlap_policy( OverlapPolicy::Replace );
		$this->seed_running_lock( 0 );
		$incumbent_option = RunStore::OPTION_PREFIX . self::IDENTITY . '_' . self::INCUMBENT_RUN_ID;
		$incumbent_raw    = $this->rig->wpdb()->rows[ $incumbent_option ] ?? null;
		self::assertIsString( $incumbent_raw );
		$incumbent = \maybe_unserialize( $incumbent_raw );
		self::assertIsArray( $incumbent );
		$incumbent['args_hash'] = \str_repeat( 'f', 64 );
		$mismatched_raw         = \maybe_serialize( $incumbent );
		self::assertIsString( $mismatched_raw );
		$this->rig->wpdb()->put( $incumbent_option, $mismatched_raw );
		$lock_option = OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash();
		$lock_raw    = $this->rig->wpdb()->rows[ $lock_option ] ?? null;
		self::assertIsString( $lock_raw );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ErrorCode::StorageFailed );
		self::assertSame( $mismatched_raw, $this->rig->wpdb()->rows[ $incumbent_option ] ?? null );
		self::assertSame( $lock_raw, $this->rig->wpdb()->rows[ $lock_option ] ?? null );
		self::assertFalse( \get_option( $this->run_option_name() ) );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * A generated run identifier cannot alias the selected incumbent owner.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A stale lock names the deterministic new ID without an active row, proving collision handling occurs before provisional state can supersede itself.
	 * @fixture StoreFixtureBuilder
	 */
	public function test_dispatch_rejects_a_generated_run_id_that_aliases_the_selected_owner(): void {
		$lock_option = OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash();
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), self::RUN_ID, self::NOW - 901, self::NOW - 901 ) );
		$lock_raw = $this->rig->wpdb()->rows[ $lock_option ] ?? null;
		self::assertIsString( $lock_raw );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ErrorCode::OverlapHeld );
		self::assertSame( $lock_raw, $this->rig->wpdb()->rows[ $lock_option ] ?? null );
		self::assertFalse( \get_option( $this->run_option_name() ) );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * A fresh generated-ID collision cannot release the incumbent's same-owner lock.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The selected lock and active row both name the deterministic new ID, proving collision refusal precedes provisional-create compensation.
	 * @fixture StoreFixtureBuilder
	 */
	public function test_dispatch_rejects_a_fresh_generated_run_id_collision_without_releasing_incumbent(): void {
		$lock_option = OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $this->args_hash();
		$run_option  = $this->run_option_name();
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), self::RUN_ID, self::NOW, self::NOW ) );
		$this->put_fixture(
			$this->fixtures->run(
				self::RUN_ID,
				new RunState(
					status: RunStatus::Running,
					kind: 'job',
					executing: false,
					start_args: self::ARGS,
					args_hash: $this->args_hash(),
					kind_state: array(),
					failed_attempts: 0,
					action_sequence: 1,
					created_at: self::NOW,
					heartbeat_at: self::NOW,
				)
			)
		);
		$lock_raw = $this->rig->wpdb()->rows[ $lock_option ] ?? null;
		$run_raw  = $this->rig->wpdb()->rows[ $run_option ] ?? null;
		self::assertIsString( $lock_raw );
		self::assertIsString( $run_raw );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ErrorCode::OverlapHeld );
		self::assertSame( $lock_raw, $this->rig->wpdb()->rows[ $lock_option ] ?? null );
		self::assertSame( $run_raw, $this->rig->wpdb()->rows[ $run_option ] ?? null );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * The identity-specific lock-staleness filter receives its documented payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_passes_the_documented_lock_staleness_filter_arguments(): void {
		$filter_args = null;
		$this->set_filter_value(
			'a8csp_bgje/lock_staleness/' . self::IDENTITY,
			static function ( int $default_staleness ) use ( &$filter_args ): int {
				$filter_args = array(
					'arity' => \func_num_args(),
					'args'  => \func_get_args(),
				);

				return $default_staleness;
			}
		);

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				'arity' => 1,
				'args'  => array( 15 * \MINUTE_IN_SECONDS ),
			),
			$filter_args
		);
	}

	/**
	 * Positive delay selects single scheduling at the clock-relative timestamp.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_with_delay_routes_to_single_scheduling(): void {
		$result = $this->client->dispatch( self::NAME, self::ARGS, delay: 120, priority: 31 );

		self::assertInstanceOf( Success::class, $result );
		$calls = $this->backend_calls( 'schedule_single' );
		self::assertCount( 1, $calls );
		self::assertSame( 'a8csp_bgje/internal/deliver', $calls[0]['args']['hook'] ?? null );
		self::assertSame( self::NOW + 120, $calls[0]['args']['timestamp'] ?? null );
		self::assertSame( 31, $calls[0]['args']['priority'] ?? null );
		$this->rig->run_due();
		self::assertSame( self::NOW + 120, $this->rig->clock()->timestamp );
		self::assertSame( array( self::ARGS ), $this->job->calls );
	}

	/**
	 * Negative delay is rejected before job admission reaches a boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_rejects_a_negative_delay_for_a_job_before_every_boundary(): void {
		$before = $this->security_boundary_snapshot();

		try {
			(void) $this->client->dispatch( self::NAME, self::ARGS, delay: -1 );
			self::fail( 'Negative delay must throw before job admission.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( 'Background-work "email-digest" delay -1 is invalid; pass a non-negative number of seconds.', $exception->getMessage() );
			self::assertSame( $before, $this->security_boundary_snapshot() );
		}
	}

	/**
	 * A failed delayed-heartbeat write releases the provisional lock and run.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale A storage write error occurs after provisional state exists; a second public dispatch proves compensation released both fences.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_with_delay_releases_its_lock_when_heartbeat_write_fails(): void {
		$this->rig->wpdb()->script_result( 'update', false );

		$failed = $this->client->dispatch( self::NAME, self::ARGS, delay: 120 );
		$this->assert_failure_code( $failed, ErrorCode::StorageFailed );
		self::assertSame( array(), $this->run_delivery_calls() );

		$retried = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $retried );
	}

	/**
	 * An indeterminate delayed heartbeat aborts scheduling and removes provisional state.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale An authoritative read fails after lock claim and run creation; successful re-admission proves the fail-closed cleanup left no fence.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_with_delay_aborts_when_heartbeat_read_is_indeterminate(): void {
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'transient heartbeat read failure';
			}
		);

		$failed = $this->client->dispatch( self::NAME, self::ARGS, delay: 120 );
		$this->assert_failure_code( $failed, ErrorCode::StorageFailed );
		self::assertSame( array(), $this->run_delivery_calls() );

		$retried = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $retried );
	}

	/**
	 * A failed delayed-state transition releases the resolved overlap key for immediate reuse.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The second run-state write fails after the lock heartbeat; reusing the opaque key proves both provisional generations were compensated.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_with_delay_releases_overlap_key_when_state_transition_fails(): void {
		$this->overlap_key_resolver = static fn ( array $args ): string => 'delayed-site-digest';
		$this->rig->wpdb()->before_next( 'update', static function (): void {} );
		$this->rig->wpdb()->before_next( 'update', static fn ( WpdbLockSpy $wpdb ) => $wpdb->script_result( 'update', false ) );

		$failed = $this->client->dispatch( self::NAME, self::ARGS, delay: 120 );
		$this->assert_failure_code( $failed, ErrorCode::StorageFailed );
		self::assertSame( array(), $this->run_delivery_calls() );

		$this->rig->clock()->timestamp = self::NOW + 1;
		$reused                        = $this->client->dispatch( self::NAME, self::ARGS, delay: 120 );
		self::assertInstanceOf( Success::class, $reused );
	}

	/**
	 * One resolved opaque key supplies the single-flight identity across differing arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_uses_the_overlap_key_as_the_single_flight_identity(): void {
		$this->overlap_key_resolver = static fn ( array $args ): string => "logical-account\0\xFF";
		$first                      = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $first );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$duplicate = $this->client->dispatch( self::NAME, array( 'site_id' => 8 ) );

		$error = $this->assert_failure_code( $duplicate, ErrorCode::OverlapHeld );
		self::assertSame( $first->value, $error->context['run_id'] ?? null );
		self::assertCount( 1, $this->run_delivery_calls() );
	}

	/**
	 * An unknown job fails before scheduling or lifecycle hooks.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_rejects_an_unknown_job_without_boundary_effects(): void {
		$before = $this->public_effects_snapshot();

		$result = $this->client->dispatch( self::UNKNOWN_NAME, self::ARGS );

		$error = $this->assert_failure_code( $result, ErrorCode::UnknownJob );
		self::assertSame( self::UNKNOWN_IDENTITY, $error->context['identity'] ?? null );
		self::assertSame( $before, $this->public_effects_snapshot() );
	}

	/**
	 * Public priority validation rejects values before an engine boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $priority Invalid priority.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_priorities' )]
	public function test_dispatch_rejects_priority_outside_the_public_range( int $priority ): void {
		$before = $this->public_effects_snapshot();

		try {
			(void) $this->client->dispatch( self::NAME, self::ARGS, priority: $priority );
			self::fail( 'Invalid priority must throw before dispatch.' );
		} catch ( \InvalidArgumentException ) {
			self::assertSame( $before, $this->public_effects_snapshot() );
		}
	}

	/**
	 * A scheduling failure is mapped and active admission is compensated.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_maps_backend_failure_and_allows_readmission(): void {
		$this->rig->backend()->results['enqueue_async'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore scheduling.' ) );

		$failed = $this->client->dispatch( self::NAME, self::ARGS );
		$this->assert_failure_code( $failed, ErrorCode::BackendRejected );
		unset( $this->rig->backend()->results['enqueue_async'] );

		$retried = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $retried );
	}

	/**
	 * A backend throwable is mapped and cannot strand the admitted row or overlap lock.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_contains_backend_throwable_and_rolls_back_admission(): void {
		$this->rig->backend()->before_next(
			'enqueue_async',
			static function (): void {
				throw new \RuntimeException( 'Scripted scheduler store failure.' );
			}
		);

		$failed = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $failed, ErrorCode::BackendRejected );
		$missing = $this->client->cancel( self::NAME, self::RUN_ID );
		$this->assert_failure_code( $missing, ErrorCode::RunNotRetained );

		$retried = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $retried );
	}

	/**
	 * An incomplete scheduling rollback warns that maintenance may redeliver its retained row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $failure Incomplete rollback boundary.
	 *
	 * @return  void
	 */
	#[DataProvider( 'incomplete_scheduling_rollback_failures' )]
	public function test_dispatch_warns_when_scheduling_rollback_cannot_be_confirmed( string $failure ): void {
		$this->rig->backend()->results['enqueue_async'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore scheduling.' ) );
		$this->script_scheduling_rollback_failure( $failure );

		$result = $this->client->dispatch( self::NAME, self::ARGS );

		$this->assert_failure_code( $result, ErrorCode::BackendRejected );
		$record = $this->scheduling_rollback_warning();
		self::assertSame( 'warning', $record['level'] ?? null );
		self::assertSame( self::IDENTITY, $record['context']['identity'] ?? null );
		self::assertSame( self::RUN_ID, $record['context']['run_id'] ?? null );
		self::assertFalse( $record['context']['lock_release_confirmed'] ?? null );
		self::assertSame( 'run_delete' !== $failure, $record['context']['run_deleted'] ?? null );
	}

	/**
	 * Returns incomplete scheduling-rollback boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{string}>
	 */
	public static function incomplete_scheduling_rollback_failures(): array {
		return array(
			'lock release' => array( 'lock_release' ),
			'run delete'   => array( 'run_delete' ),
		);
	}

	/**
	 * Non-portable input reaches no clock, randomizer, storage, hook, or scheduler boundary.
	 *
	 * @load-bearing security
	 * @pin-rationale The opaque object is rejected by the public payload validator; exact boundary equality proves it cannot be serialized, logged, or passed to a backend.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_rejects_non_portable_input_before_every_boundary(): void {
		$before = $this->security_boundary_snapshot();

		try {
			(void) $this->client->dispatch( self::NAME, array( 'private-payload' => new \stdClass() ) );
			self::fail( 'Non-portable payload must throw before dispatch.' );
		} catch ( \InvalidArgumentException ) {
			self::assertSame( $before, $this->security_boundary_snapshot() );
		}
	}

	/**
	 * Delay overflow returns a typed public payload rejection without scheduling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_rejects_a_delay_that_overflows_unix_seconds(): void {
		$this->rig->clock()->timestamp = \PHP_INT_MAX - 5;

		$result = $this->client->dispatch( self::NAME, self::ARGS, delay: 10 );

		$this->assert_failure_code( $result, ErrorCode::PayloadRejected );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * The public dispatch contract declares its Result non-discardable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dispatch_declares_no_discard_on_the_public_facade(): void {
		$method = new \ReflectionMethod( OwnerOperations::class, 'dispatch' );

		self::assertCount( 1, $method->getAttributes( \NoDiscard::class ) );
	}

	/**
	 * Failed-run retry rejects a malformed run identifier at the engine boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_rejects_a_malformed_run_identifier(): void {
		try {
			(void) $this->client->retry_failed( self::NAME, 'malformed_run_id' );
			self::fail( 'A malformed retry identifier must be rejected before storage lookup.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( 'Run identifier is malformed; pass a run ID the engine returned.', $exception->getMessage() );
		}
	}

	/**
	 * Manual retry schedules the failed job's original arguments and consumes the entry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_redispatches_original_arguments_and_consumes_the_entry(): void {
		$this->seed_failed_run( self::RUN_ID, self::ARGS, 2 );
		$this->rig->clock()->timestamp = self::NOW + 100;

		$result = $this->client->retry_failed( self::NAME, self::RUN_ID );

		self::assertInstanceOf( Success::class, $result );
		$this->rig->run_due();
		self::assertSame( array( self::ARGS ), $this->job->calls );
		$consumed = $this->client->retry_failed( self::NAME, self::RUN_ID );
		$this->assert_failure_code( $consumed, ErrorCode::RunNotRetained );
	}

	/**
	 * Failed-run retry contains resolver failures without consuming the retained entry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \Closure $resolver Faulting overlap-key resolver.
	 *
	 * @return  void
	 */
	#[DataProviderExternal( FaultingOverlapKeyResolverProvider::class, 'resolvers' )]
	public function test_retry_failed_contains_overlap_key_resolver_failures_without_consuming_the_entry( \Closure $resolver ): void {
		$this->seed_failed_run( self::RUN_ID, self::ARGS, 2 );
		$this->rig->clock()->timestamp = self::NOW + 100;
		$this->overlap_key_resolver    = $resolver;

		$failure = $this->client->retry_failed( self::NAME, self::RUN_ID );

		$this->assert_failure_code( $failure, ErrorCode::ExecutionFailed );
		$this->overlap_key_resolver = static fn ( array $args ): string => 'recovered';
		self::assertInstanceOf( Success::class, $this->client->retry_failed( self::NAME, self::RUN_ID ) );
	}

	/**
	 * Manual retry refuses a matching incumbent when the Job declares Replace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_forces_reject_for_a_replace_job(): void {
		$this->restart_with_overlap_policy( OverlapPolicy::Replace );

		$incumbent = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $incumbent );
		$this->seed_failed_run( self::OTHER_RUN_ID, self::ARGS, 2 );
		$this->rig->clock()->timestamp = self::NOW + 100;

		$retry = $this->client->retry_failed( self::NAME, self::OTHER_RUN_ID );

		$error = $this->assert_failure_code( $retry, ErrorCode::OverlapHeld );
		self::assertSame( $incumbent->value, $error->context['run_id'] ?? null );
		$still_retained = $this->client->retry_failed( self::NAME, self::OTHER_RUN_ID );
		$this->assert_failure_code( $still_retained, ErrorCode::OverlapHeld );
	}

	/**
	 * Manual retry admits an Allow Job under a fresh per-run overlap lane.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_admits_an_allow_job_under_its_own_policy(): void {
		$this->restart_with_overlap_policy( OverlapPolicy::Allow );

		$incumbent = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $incumbent );
		$this->seed_failed_run( self::OTHER_RUN_ID, self::ARGS, 2 );
		$this->rig->clock()->timestamp = self::NOW + 100;

		$retry = $this->client->retry_failed( self::NAME, self::OTHER_RUN_ID );

		self::assertInstanceOf( Success::class, $retry );
		self::assertNotSame( $incumbent->value, $retry->value );
	}

	/**
	 * A failed retained-entry removal leaves a successful retry and keeps the entry retryable.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The failed-run store's exact removal write fails after the fresh run is accepted; cancelling that run and retrying again proves the source entry was not consumed.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_keeps_the_entry_when_consumption_cas_fails(): void {
		$this->seed_failed_run( self::RUN_ID, self::ARGS, 2 );
		$this->rig->clock()->timestamp = self::NOW + 100;
		$this->rig->wpdb()->script_result( 'update', false );

		$first = $this->client->retry_failed( self::NAME, self::RUN_ID );
		self::assertInstanceOf( Success::class, $first );
		self::assertIsString( $first->value );
		$cancelled = $this->client->cancel( self::NAME, $first->value );
		self::assertInstanceOf( Success::class, $cancelled );
		$this->rig->clock()->timestamp = self::NOW + 101;

		$second = $this->client->retry_failed( self::NAME, self::RUN_ID );
		self::assertInstanceOf( Success::class, $second );
	}

	/**
	 * A deliberately duplicated retained identifier retries the first stored payload.
	 * Production failed-run recording deduplicates run IDs, so this corrupt row cannot be builder-produced.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_uses_the_first_payload_for_a_corrupt_duplicate_identifier(): void {
		$raw = \maybe_serialize(
			array(
				$this->failed_entry( self::RUN_ID, array( 'ordinal' => 'first' ), 2, self::NOW - 2 ),
				$this->failed_entry( self::RUN_ID, array( 'ordinal' => 'second' ), 2, self::NOW - 1 ),
			)
		);
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( 'a8csp_bgje_failed_runs_' . self::IDENTITY, $raw );
		$this->rig->clock()->timestamp = self::NOW + 100;

		$result = $this->client->retry_failed( self::NAME, self::RUN_ID );

		self::assertInstanceOf( Success::class, $result );
		$this->rig->run_due();
		self::assertSame( array( array( 'ordinal' => 'first' ) ), $this->job->calls );
	}

	/**
	 * An unreadable failed-run store rejects retry before fresh admission.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale The authoritative failed-store read fails before dispatch; unchanged fixture bytes and an empty backend ledger prove fail-closed behavior.
	 * @fixture StoreFixtureBuilder
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_rejects_an_authoritative_store_read_failure(): void {
		$this->seed_failed_run( self::RUN_ID, self::ARGS, 2 );
		$before = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted retry store read failure';
			}
		);

		$result = $this->client->retry_failed( self::NAME, self::RUN_ID );

		$this->assert_failure_code( $result, ErrorCode::StorageFailed );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		self::assertSame( array(), $this->run_delivery_calls() );
	}

	/**
	 * A missing identifier is refused while an actually retained run remains retryable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_rejects_a_missing_entry_without_consuming_existing_work(): void {
		$this->seed_failed_run( self::RUN_ID, self::ARGS, 2 );

		$missing = $this->client->retry_failed( self::NAME, self::OTHER_RUN_ID );
		$error   = $this->assert_failure_code( $missing, ErrorCode::RunNotRetained );
		self::assertSame( self::OTHER_RUN_ID, $error->context['run_id'] ?? null );

		$retained = $this->client->retry_failed( self::NAME, self::RUN_ID );
		self::assertInstanceOf( Success::class, $retained );
	}

	/**
	 * A delegated scheduling failure leaves the failed job entry retryable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_retry_failed_retains_the_entry_when_dispatch_fails(): void {
		$this->seed_failed_run( self::RUN_ID, self::ARGS, 2 );
		$this->rig->backend()->results['enqueue_async'] = new Failure( new SchedulingError( SchedulingErrorReason::ScheduleFailed, 'Restore scheduling.' ) );

		$failed = $this->client->retry_failed( self::NAME, self::RUN_ID );
		$this->assert_failure_code( $failed, ErrorCode::BackendRejected );
		unset( $this->rig->backend()->results['enqueue_async'] );
		$this->rig->clock()->timestamp = self::NOW + 1;

		$retried = $this->client->retry_failed( self::NAME, self::RUN_ID );
		self::assertInstanceOf( Success::class, $retried );
	}

	/**
	 * Supplies fresh and stale edges for every staleness-resolution path.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{staleness_filter: int|null, continue_filter: int|null, heartbeat_age: int, takes_over_stale: bool}>
	 */
	public static function lock_window_boundaries(): array {
		return array(
			'default boundary remains fresh'      => array(
				'staleness_filter' => null,
				'continue_filter'  => null,
				'heartbeat_age'    => 900,
				'takes_over_stale' => false,
			),
			'default boundary plus one is stale'  => array(
				'staleness_filter' => null,
				'continue_filter'  => null,
				'heartbeat_age'    => 901,
				'takes_over_stale' => true,
			),
			'filtered boundary remains fresh'     => array(
				'staleness_filter' => 300,
				'continue_filter'  => null,
				'heartbeat_age'    => 300,
				'takes_over_stale' => false,
			),
			'filtered boundary plus one is stale' => array(
				'staleness_filter' => 300,
				'continue_filter'  => null,
				'heartbeat_age'    => 301,
				'takes_over_stale' => true,
			),
			'floor boundary remains fresh'        => array(
				'staleness_filter' => 1,
				'continue_filter'  => 75,
				'heartbeat_age'    => 150,
				'takes_over_stale' => false,
			),
			'floor boundary plus one is stale'    => array(
				'staleness_filter' => 1,
				'continue_filter'  => 75,
				'heartbeat_age'    => 151,
				'takes_over_stale' => true,
			),
			'zero-delay filtered fresh edge'      => array(
				'staleness_filter' => 1,
				'continue_filter'  => 0,
				'heartbeat_age'    => 1,
				'takes_over_stale' => false,
			),
			'zero-delay filtered stale edge'      => array(
				'staleness_filter' => 1,
				'continue_filter'  => 0,
				'heartbeat_age'    => 2,
				'takes_over_stale' => true,
			),
			'zero-delay default fresh edge'       => array(
				'staleness_filter' => null,
				'continue_filter'  => 0,
				'heartbeat_age'    => 900,
				'takes_over_stale' => false,
			),
			'zero-delay default stale edge'       => array(
				'staleness_filter' => null,
				'continue_filter'  => 0,
				'heartbeat_age'    => 901,
				'takes_over_stale' => true,
			),
		);
	}

	/**
	 * Supplies values immediately outside both inclusive priority boundaries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array<string, array{priority: int}>
	 */
	public static function invalid_priorities(): array {
		return array(
			'below minimum' => array( 'priority' => -1 ),
			'above maximum' => array( 'priority' => 256 ),
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Boots the deterministic graph with one registered policy declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OverlapPolicy|null $overlap Optional overlap policy.
	 *
	 * @return  void
	 */
	private function boot( ?OverlapPolicy $overlap = null ): void {
		$this->overlap_key_resolver = null;
		$this->rig                  = EngineRig::set_up( self::NOW );
		$this->client               = $this->rig->operations( self::OWNER );
		$this->job                  = new RecordingJob( self::NAME );
		$overlap_key                = function ( array $args ): ?string {
			if ( null === $this->overlap_key_resolver ) {
				return null;
			}

			$resolved = ( $this->overlap_key_resolver )( $args );
			// PHPStan needs the mixed fixture narrowed before the wrapper's declared return type enforces the runtime boundary.
			if ( null !== $resolved && ! \is_string( $resolved ) ) {
				throw new \TypeError( 'The test overlap-key resolver violated its declared return contract.' );
			}

			return $resolved;
		};
		$this->client->register( $this->job->definition( new JobOptions( overlap: $overlap, overlap_key: $overlap_key ) ) );
		$this->fixtures = StoreFixtureBuilder::for_identity( self::IDENTITY );
		$this->reset_observations();
	}

	/**
	 * Rebuilds the request-local graph with one non-default overlap policy.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   OverlapPolicy $overlap Overlap policy to register.
	 *
	 * @return  void
	 */
	private function restart_with_overlap_policy( OverlapPolicy $overlap ): void {
		$this->rig->tear_down();
		$this->boot( $overlap );
	}

	/**
	 * Enqueues the deterministic job and returns its run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function dispatch_job(): string {
		$result = $this->client->dispatch( self::NAME, self::ARGS );
		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );

		return $result->value;
	}

	/**
	 * Stores a production-built foreign lock and latest pointer.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $heartbeat_age Existing heartbeat age.
	 *
	 * @return  void
	 */
	private function seed_running_lock( int $heartbeat_age ): void {
		$heartbeat = self::NOW - $heartbeat_age;
		$this->put_fixture( $this->fixtures->lock( $this->args_hash(), self::INCUMBENT_RUN_ID, $heartbeat, $heartbeat ) );
		$this->put_fixture(
			$this->fixtures->run(
				self::INCUMBENT_RUN_ID,
				new RunState(
					status: RunStatus::Running,
					kind: 'job',
					executing: false,
					start_args: self::ARGS,
					args_hash: $this->args_hash(),
					kind_state: array(),
					failed_attempts: 0,
					action_sequence: 1,
					created_at: $heartbeat,
					heartbeat_at: $heartbeat,
				)
			)
		);
		$this->put_fixture(
			$this->fixtures->latest(
				array(
					array(
						'run_id'    => self::INCUMBENT_RUN_ID,
						'args_hash' => $this->args_hash(),
					),
				)
			)
		);
		$this->reset_observations();
	}

	/**
	 * Stores one production-built retained failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Failed run identifier.
	 * @param   array<array-key, mixed> $start_args Original arguments.
	 * @param   int                     $attempts   Attempts consumed.
	 *
	 * @return  void
	 */
	private function seed_failed_run( string $run_id, array $start_args, int $attempts ): void {
		$failure = new RunFailure( identity: self::IDENTITY, run_id: RunId::from( $run_id ), attempts: $attempts, stage: RunFailureStage::execution(), code: ErrorCode::ExecutionFailed, summary: 'Database unavailable.', details: null );
		$this->put_fixture( $this->fixtures->failed( self::NOW - 1, $start_args, $failure ) );
		$this->reset_observations();
	}

	/**
	 * Returns one deliberately duplicated failed-store entry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $run_id     Failed run identifier.
	 * @param   array<array-key, mixed> $start_args Original arguments.
	 * @param   int                     $attempts   Attempts consumed.
	 * @param   int                     $failed_at  Failure timestamp.
	 *
	 * @return array{run_id: string, failed_at: int, start_args: array<array-key, mixed>, attempts: int, error: array{class: null, message: string, stage: string, code: string}}
	 */
	private function failed_entry( string $run_id, array $start_args, int $attempts, int $failed_at ): array {
		return array(
			'run_id'     => $run_id,
			'failed_at'  => $failed_at,
			'start_args' => $start_args,
			'attempts'   => $attempts,
			'error'      => array(
				'class'   => null,
				'message' => 'Database unavailable.',
				'stage'   => RunFailureStage::execution()->value,
				'code'    => ErrorCode::ExecutionFailed->value,
			),
		);
	}

	/**
	 * Stores one production-built raw fixture.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array{string, string} $fixture Option name and raw value.
	 *
	 * @return  void
	 */
	private function put_fixture( array $fixture ): void {
		$this->rig->wpdb()->put( $fixture[0], $fixture[1] );
	}

	/**
	 * Returns the canonical argument identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function args_hash(): string {
		return $this->fixtures->args_hash( self::ARGS );
	}

	/**
	 * Scripts one incomplete scheduling-rollback boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $failure Incomplete rollback boundary.
	 *
	 * @return  void
	 */
	private function script_scheduling_rollback_failure( string $failure ): void {
		if ( 'lock_release' === $failure ) {
			$this->rig->wpdb()->before_next( 'select', static function (): void {} );
			$this->rig->wpdb()->before_next(
				'select',
				static function ( WpdbLockSpy $wpdb ): void {
					$wpdb->last_error = 'scripted rollback lock read failure';
				}
			);

			return;
		}

		if ( 'run_delete' !== $failure ) {
			throw new \InvalidArgumentException( 'Unknown scheduling rollback failure.' );
		}

		$this->rig->wpdb()->script_result( 'delete', false );
	}

	/**
	 * Returns the active run option for the deterministic admission.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function run_option_name(): string {
		return 'a8csp_bgje_active_run_' . self::IDENTITY . '_' . self::RUN_ID;
	}

	/**
	 * Returns the scheduling-rollback diagnostic by its outcome context.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{level: mixed, message: string, context: array<array-key, mixed>}
	 */
	private function scheduling_rollback_warning(): array {
		foreach ( $this->rig->logger()->records as $record ) {
			if ( \array_key_exists( 'lock_release_confirmed', $record['context'] ) && \array_key_exists( 'run_deleted', $record['context'] ) ) {
				return $record;
			}
		}

		throw new \LogicException( 'Expected a scheduling rollback warning.' );
	}

	/**
	 * Returns the only accepted job-run call.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{verb: string, args: array<string, mixed>}
	 */
	private function single_run_delivery_call(): array {
		$calls = $this->run_delivery_calls();
		self::assertCount( 1, $calls );

		return $calls[0];
	}

	/**
	 * Returns accepted job-run backend calls.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function run_delivery_calls(): array {
		return \array_values(
			\array_filter(
				$this->rig->backend()->calls,
				static function ( array $call ): bool {
					$args = $call['args']['args'] ?? null;

					return \is_array( $args )
						&& \in_array( $call['verb'], array( 'enqueue_async', 'schedule_single' ), true )
						&& 'a8csp_bgje/internal/deliver' === ( $call['args']['hook'] ?? null )
						&& self::IDENTITY === ( $args[0] ?? null );
				}
			)
		);
	}

	/**
	 * Returns backend calls for one verb.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $verb Backend verb.
	 *
	 * @return  list<array{verb: string, args: array<string, mixed>}>
	 */
	private function backend_calls( string $verb ): array {
		return \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => $verb === $call['verb'] ) );
	}

	/**
	 * Captures the public effects visible to ordinary admission refusals.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array{backend: array<array-key, mixed>, hooks: array<array-key, mixed>}
	 */
	private function public_effects_snapshot(): array {
		return array(
			'backend' => $this->rig->backend()->calls,
			'hooks'   => $this->rig->hooks()->sequence(),
		);
	}

	/**
	 * Captures every boundary that must reject a non-portable payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return array{backend: array<array-key, mixed>, rows: array<array-key, mixed>, queries: array<array-key, mixed>, hooks: array<array-key, mixed>, options: array<array-key, mixed>}
	 */
	private function security_boundary_snapshot(): array {
		$options = $GLOBALS['a8csp_bgje_test_option_calls'] ?? null;
		self::assertIsArray( $options );

		return array(
			'backend' => $this->rig->backend()->calls,
			'rows'    => $this->rig->wpdb()->rows,
			'queries' => $this->rig->wpdb()->recorded_queries,
			'hooks'   => $this->rig->hooks()->sequence(),
			'options' => $options,
		);
	}

	/**
	 * Clears observations without changing retained state or backend outcomes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function reset_observations(): void {
		$this->rig->backend()->calls             = array();
		$this->rig->wpdb()->recorded_queries     = array();
		$GLOBALS['a8csp_bgje_test_option_calls'] = array();
	}

	/**
	 * Asserts one mapped facade failure code.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed        $result Facade result.
	 * @param   ErrorCode $code   Expected public code.
	 *
	 * @return  BoundaryError
	 */
	private function assert_failure_code( mixed $result, ErrorCode $code ): BoundaryError {
		self::assertInstanceOf( Failure::class, $result );
		$error = $result->error;
		self::assertInstanceOf( BoundaryError::class, $error );
		self::assertSame( $code, $error->code );

		return $error;
	}

	/**
	 * Scripts one legitimate WordPress filter seam.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $hook_name Filter hook name.
	 * @param   mixed  $value     Filter value or callback.
	 *
	 * @return  void
	 */
	private function set_filter_value( string $hook_name, mixed $value ): void {
		$filters = $GLOBALS['a8csp_bgje_test_filter_values'] ?? null;
		self::assertIsArray( $filters );
		$filters[ $hook_name ]                    = $value;
		$GLOBALS['a8csp_bgje_test_filter_values'] = $filters;
	}

	// endregion.
}
