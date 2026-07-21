<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Engine\Backends;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Backends\ActionSchedulerBackend;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\Error\SchedulingErrorReason;
use PHPUnit\Framework\Attributes\CoversClass;
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
	 * Pending occurrences are counted without fetching Action Scheduler objects.
	 *
	 * @load-bearing concurrency
	 * @pin-rationale Same-backend duplicate chains are invisible through logical schedule reads, so exact pending-ID cardinality remains the scalar query signal; requesting IDs avoids materializing complete actions for callers that need only one identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_pending_occurrence_count_uses_an_identity_scoped_id_query(): void {
		$GLOBALS['a8csp_bgje_test_as_results'] = array(
			'as_get_scheduled_actions' => array( array( 41, 42 ) ),
		);

		$count = ( new ActionSchedulerBackend() )->scheduled_count( self::HOOK, array( 'schedule-17' ), 'reports' );

		self::assertSame( 2, $count );
		self::assertSame(
			array(
				array(
					'hook'     => self::HOOK,
					'args'     => array( 'schedule-17' ),
					'group'    => 'reports',
					'status'   => 'pending',
					'per_page' => -1,
					'orderby'  => 'none',
				),
				'ids',
			),
			$this->calls( 'as_get_scheduled_actions' )[0]['args']
		);
	}

	/**
	 * Multiple schedule identities are bucketed from one hook-wide pending-action read.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_scheduled_counts_buckets_multiple_identities_from_one_query(): void {
		$GLOBALS['a8csp_bgje_test_as_results'] = array(
			'as_get_scheduled_actions' => array(
				array(
					41 => self::action( array( 'single' ), 'single' ),
					42 => self::action( array( 'many' ), 'many' ),
					43 => self::action( array( 'many' ), 'many' ),
					44 => self::action( array( 'many' ), 'wrong-group' ),
					45 => self::action( array( 'many', 'extra' ), 'many' ),
					46 => self::action( array( '' ), 'empty-group-is-unconstrained' ),
				),
			),
		);

		$counts = ( new ActionSchedulerBackend() )->scheduled_counts( self::HOOK, array( 'single', 'missing', 'many', '' ) );

		self::assertSame(
			array(
				'single'  => 1,
				'missing' => 0,
				'many'    => 2,
				''        => 1,
			),
			$counts
		);
		self::assertSame(
			array(
				array(
					'hook'     => self::HOOK,
					'status'   => 'pending',
					'per_page' => -1,
					'orderby'  => 'none',
				),
				'OBJECT',
			),
			$this->calls( 'as_get_scheduled_actions' )[0]['args']
		);
		self::assertCount( 1, $this->calls( 'as_get_scheduled_actions' ) );
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

	/**
	 * Returns one Action Scheduler object-shaped fixture.
	 *
	 * @param   list<mixed> $args  Action arguments.
	 * @param   string      $group Action group.
	 *
	 * @return  object
	 */
	private static function action( array $args, string $group ): object {
		return new readonly class( $args, $group ) {
			/**
			 * Creates an immutable action fixture.
			 *
			 * @param   list<mixed> $args  Action arguments.
			 * @param   string      $group Action group.
			 */
			public function __construct(
				private array $args,
				private string $group,
			) {}

			/**
			 * Returns the action arguments.
			 *
			 * @return  list<mixed>
			 */
			public function get_args(): array {
				return $this->args;
			}

			/**
			 * Returns the action group.
			 *
			 * @return  string
			 */
			public function get_group(): string {
				return $this->group;
			}
		};
	}

	// endregion.
}
