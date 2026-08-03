<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Backends;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Action Scheduler procedural boundary that cannot run through EngineRig.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ActionSchedulerBackend::class )]
final class ActionSchedulerBackendTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string HOOK = 'a8csp_bgje_test_hook';

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded Action Scheduler functions before the backend is autoloaded.
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

		require_once \dirname( __DIR__, 2 ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/as-function-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/as-class-stubs.php';
	}

	/**
	 * Resets the procedural boundary state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgje_test_as_calls']    = array();
		$GLOBALS['a8csp_bgje_test_as_results']  = array();
		$GLOBALS['a8csp_bgje_test_as_version']  = '4.0.0';
		$GLOBALS['a8csp_bgje_test_did_actions'] = array(
			'init'                  => 1,
			'action_scheduler_init' => 1,
		);
	}

	// endregion.

	// region TESTS.

	/**
	 * Runtime readiness follows Action Scheduler initialization while absence follows its function table.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_runtime_facts_distinguish_readiness_from_absence(): void {
		$backend = new ActionSchedulerBackend();
		self::assertTrue( $backend->is_ready() );
		self::assertFalse( $backend->is_absent() );

		$GLOBALS['a8csp_bgje_test_did_actions'] = array( 'init' => 1 );
		self::assertFalse( $backend->is_ready() );
		self::assertFalse( $backend->is_absent() );
	}

	/**
	 * An Action Scheduler below the supported floor is present but unusable for scheduling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $version Elected Action Scheduler version.
	 * @param   bool   $ready   Whether the adapter accepts writes at that version.
	 *
	 * @return  void
	 */
	#[DataProvider( 'elected_versions' )]
	public function test_readiness_requires_the_supported_action_scheduler_floor( string $version, bool $ready ): void {
		$GLOBALS['a8csp_bgje_test_as_version'] = $version;
		$backend                               = new ActionSchedulerBackend();

		self::assertSame( $ready, $backend->is_ready() );
		self::assertFalse( $backend->is_absent() );
	}

	/**
	 * Elected Action Scheduler versions around the supported floor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{string, bool}>
	 */
	public static function elected_versions(): array {
		return array(
			'below the floor'   => array( '3.9.3', false ),
			'one patch below'   => array( '3.99.99', false ),
			'exactly the floor' => array( '4.0.0', true ),
			'above the floor'   => array( '4.1.0', true ),
			'a later major'     => array( '5.0.0', true ),
		);
	}

	/**
	 * Successful writes preserve the Action Scheduler identity and uniqueness contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_writes_preserve_vendor_identity_and_uniqueness_arguments(): void {
		$backend = new ActionSchedulerBackend();

		$recurring = $backend->schedule_recurring( self::HOOK, 300, array( 'schedule-17' ), 1_700_000_300, 'reports', 23 );
		$async     = $backend->enqueue_async( self::HOOK, array( 'run-18' ), 'reports|run-18', 31 );

		self::assertInstanceOf( Success::class, $recurring );
		self::assertInstanceOf( Success::class, $async );
		self::assertSame( array( 1_700_000_300, 300, self::HOOK, array( 'schedule-17' ), 'reports', true, 23 ), $this->calls( 'as_schedule_recurring_action' )[0]['args'] );
		self::assertSame( array( self::HOOK, array( 'run-18' ), 'reports|run-18', true, 31 ), $this->calls( 'as_enqueue_async_action' )[0]['args'] );
	}

	/**
	 * Run cancellation clears only matching pending actions from an identity group.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unschedule_run_selects_the_run_inside_its_identity_group(): void {
		$identity                              = 'reports:refresh';
		$run_id                                = '00000000001700000000-0000000000000000042';
		$sibling_run_id                        = '00000000001700000000-0000000000000000043';
		$first_args                            = array( $identity, $run_id, 1 );
		$second_args                           = array( $identity, $run_id, 2 );
		$GLOBALS['a8csp_bgje_test_as_results'] = array(
			'as_get_scheduled_actions' => array(
				array(
					41 => new \A8CSP_BGJE_Test_AS_Action( $first_args, $identity ),
					42 => new \A8CSP_BGJE_Test_AS_Action( array( $identity, $sibling_run_id, 1 ), $identity ),
					43 => new \A8CSP_BGJE_Test_AS_Action( $second_args, $identity ),
				),
			),
		);

		$result = ( new ActionSchedulerBackend() )->unschedule_run( self::HOOK, $identity, $run_id );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				array(
					'hook'     => self::HOOK,
					'group'    => $identity,
					'status'   => 'pending',
					'per_page' => -1,
					'orderby'  => 'none',
				),
				'OBJECT',
			),
			$this->calls( 'as_get_scheduled_actions' )[0]['args']
		);
		self::assertSame(
			array(
				array( self::HOOK, $first_args, $identity ),
				array( self::HOOK, $second_args, $identity ),
			),
			\array_column( $this->calls( 'as_unschedule_all_actions' ), 'args' )
		);
	}

	/**
	 * Run cancellation reports a store that still exposes the target delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unschedule_run_fails_when_the_target_remains_pending(): void {
		$identity                              = 'reports:refresh';
		$run_id                                = '00000000001700000000-0000000000000000042';
		$args                                  = array( $identity, $run_id, 1 );
		$action                                = new \A8CSP_BGJE_Test_AS_Action( $args, $identity );
		$GLOBALS['a8csp_bgje_test_as_results'] = array(
			'as_get_scheduled_actions' => array(
				array( 41 => $action ),
				array( 41 => $action ),
			),
		);

		$result = ( new ActionSchedulerBackend() )->unschedule_run( self::HOOK, $identity, $run_id );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::ScheduleFailed, $result->error->reason );
		self::assertCount( 2, $this->calls( 'as_get_scheduled_actions' ) );
		self::assertSame( array( array( self::HOOK, $args, $identity ) ), \array_column( $this->calls( 'as_unschedule_all_actions' ), 'args' ) );
	}

	/**
	 * Same-backend duplicate chains remain visible through the scheduled-chain census.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Same-backend duplicate chains are invisible through existence and next-occurrence reads, so each identity's scheduled-chain census must retain exact pending cardinality.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduled_chains_preserve_same_backend_duplicate_cardinality(): void {
		$GLOBALS['a8csp_bgje_test_as_results'] = array(
			'as_get_scheduled_actions' => array(
				array(
					41 => new \A8CSP_BGJE_Test_AS_Action( array( 'schedule-17' ), 'schedule-17' ),
					42 => new \A8CSP_BGJE_Test_AS_Action( array( 'schedule-17' ), 'schedule-17' ),
				),
			),
		);

		$chains = ( new ActionSchedulerBackend() )->scheduled_chains( self::HOOK, array( 'schedule-17' ) );

		self::assertSame( 2, $chains['schedule-17']['count'] );
		self::assertSame(
			array(
				array(
					'hook'     => self::HOOK,
					'args'     => array( 'schedule-17' ),
					'group'    => 'schedule-17',
					'status'   => 'pending',
					'per_page' => -1,
					'orderby'  => 'none',
				),
				'OBJECT',
			),
			$this->calls( 'as_get_scheduled_actions' )[0]['args']
		);
	}

	/**
	 * Each declared identity receives only the pending total returned by its scoped query.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduled_chains_keep_declared_identity_totals_separate(): void {
		$GLOBALS['a8csp_bgje_test_as_results'] = array(
			'as_get_scheduled_actions' => array(
				array( 41 => new \A8CSP_BGJE_Test_AS_Action( array( 'single' ), 'single', new \A8CSP_BGJE_Test_AS_Schedule( 300 ) ) ),
				array(),
				array(
					42 => new \A8CSP_BGJE_Test_AS_Action( array( 'many' ), 'many', new \A8CSP_BGJE_Test_AS_Schedule( 300 ) ),
					43 => new \A8CSP_BGJE_Test_AS_Action( array( 'many' ), 'many', new \A8CSP_BGJE_Test_AS_Schedule( 900 ) ),
				),
			),
		);

		$counts = ( new ActionSchedulerBackend() )->scheduled_chains( self::HOOK, array( 'single', 'missing', 'many' ) );

		self::assertSame(
			array(
				'single'  => array(
					'count'    => 1,
					'interval' => 300,
				),
				'missing' => array(
					'count'    => 0,
					'interval' => null,
				),
				'many'    => array(
					'count'    => 2,
					'interval' => null,
				),
			),
			$counts
		);
	}

	/**
	 * The census asks Action Scheduler one exact query per declared identity.
	 *
	 * @load-bearing performance
	 * @pin-rationale Args and group retain the engine's complete schedule identity, so each query matches only its own chain and hydration stays proportional to the chains a scope declares rather than to every pending action sharing the hook. The return format is objects because a chain's cadence is readable only from the action, and a retained chain's cadence is the one disagreement no fingerprint or count reveals.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduled_chains_use_identity_scoped_queries(): void {
		$GLOBALS['a8csp_bgje_test_as_results'] = array(
			'as_get_scheduled_actions' => array(
				array(),
				array(),
			),
		);

		( new ActionSchedulerBackend() )->scheduled_chains( self::HOOK, array( 'single', 'many' ) );

		self::assertSame(
			array(
				array(
					'function' => 'as_get_scheduled_actions',
					'args'     => array(
						array(
							'hook'     => self::HOOK,
							'args'     => array( 'single' ),
							'group'    => 'single',
							'status'   => 'pending',
							'per_page' => -1,
							'orderby'  => 'none',
						),
						'OBJECT',
					),
				),
				array(
					'function' => 'as_get_scheduled_actions',
					'args'     => array(
						array(
							'hook'     => self::HOOK,
							'args'     => array( 'many' ),
							'group'    => 'many',
							'status'   => 'pending',
							'per_page' => -1,
							'orderby'  => 'none',
						),
						'OBJECT',
					),
				),
			),
			$this->calls( 'as_get_scheduled_actions' )
		);
	}

	/**
	 * Numeric-string identities retain their string type in exact Action Scheduler queries.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduled_chains_preserve_numeric_string_identity_query_types(): void {
		$GLOBALS['a8csp_bgje_test_as_results'] = array(
			'as_get_scheduled_actions' => array(
				array(),
			),
		);

		( new ActionSchedulerBackend() )->scheduled_chains( self::HOOK, array( '123' ) );

		$calls = $this->calls( 'as_get_scheduled_actions' );
		self::assertCount( 1, $calls );
		$query = $calls[0]['args'][0] ?? null;
		self::assertIsArray( $query );
		self::assertSame( array( '123' ), $query['args'] ?? null );
		self::assertSame( '123', $query['group'] ?? null );
	}

	/**
	 * A gated write reached before init names that cause instead of blaming the store.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_write_before_init_names_init_rather_than_a_store_rejection(): void {
		$GLOBALS['a8csp_bgje_test_did_actions'] = array( 'action_scheduler_init' => 1 );
		$GLOBALS['a8csp_bgje_test_as_results']  = array(
			'as_enqueue_async_action' => array( 0 ),
			'as_has_scheduled_action' => array( false ),
		);

		$result = ( new ActionSchedulerBackend() )->enqueue_async( self::HOOK, array(), 'reports' );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertStringContainsString( 'WordPress init has not fired', $result->error->message );
		self::assertFalse( $result->error->context['wp_init_fired'] ?? null );
	}

	/**
	 * A rejected action reports the identifier this call returned rather than ambient request state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_negative_action_id_outranks_the_unfired_init_cause(): void {
		$GLOBALS['a8csp_bgje_test_did_actions'] = array( 'action_scheduler_init' => 1 );
		$GLOBALS['a8csp_bgje_test_as_results']  = array( 'as_enqueue_async_action' => array( -1 ) );

		$result = ( new ActionSchedulerBackend() )->enqueue_async( self::HOOK, array(), 'reports' );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertStringContainsString( 'returned negative action ID -1', $result->error->message );
	}

	/**
	 * An unready adapter fails before calling any Action Scheduler write function.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unready_adapter_rejects_writes_without_touching_the_vendor_api(): void {
		$GLOBALS['a8csp_bgje_test_did_actions'] = array( 'init' => 1 );

		$result = ( new ActionSchedulerBackend() )->enqueue_async( self::HOOK );

		self::assertInstanceOf( Failure::class, $result );
		self::assertInstanceOf( SchedulingError::class, $result->error );
		self::assertSame( SchedulingErrorReason::BackendNotReady, $result->error->reason );
		self::assertSame( array(), $this->calls() );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns recorded Action Scheduler calls, optionally filtered by function.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string|null $function_name Function name, or null for every call.
	 *
	 * @return  list<array{function: string, args: list<mixed>}>
	 */
	private function calls( ?string $function_name = null ): array {
		/** @var list<array{function: string, args: list<mixed>}> $calls */
		$calls = $GLOBALS['a8csp_bgje_test_as_calls'];

		return null === $function_name
			? $calls
			: \array_values( \array_filter( $calls, static fn ( array $call ): bool => $function_name === $call['function'] ) );
	}

	// endregion.
}
