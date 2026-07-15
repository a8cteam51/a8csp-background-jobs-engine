<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Locks;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins filterable continuation and lock timing policy.
 *
 */
#[CoversClass( LockWindows::class )]
final class LockWindowsTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const NAME   = 'catalog-sync';
	private const NOW    = 1_700_000_000;
	private const RUN_ID = '00000000001700000000-0000000000000000042';

	private LockWindows $lock_windows;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress filter functions and time constants.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-hook-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-time-constant-stubs.php';
	}

	/**
	 * Resets filter values and constructs the timing policy.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_filter_values'] = array();

		$this->lock_windows = new LockWindows( new FixedClock( self::NOW ) );
	}

	// endregion.

	// region TESTS.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Signatures and providers carry test parameter types.

	/**
	 * Accepted and invalid filter values resolve to exact continuation delays.
	 *
	 * @return  void
	 */
	#[DataProvider( 'continue_delay_filter_values' )]
	public function test_continue_delay_resolves_filter_values( mixed $filtered_delay, int $expected_delay ): void {
		$this->set_filter_value( 'a8csp_background_tasks/continue_delay', $filtered_delay );

		self::assertSame( $expected_delay, $this->lock_windows->continue_delay( self::NAME, self::RUN_ID ) );
	}

	/**
	 * The continuation filter receives its documented default, work identity, and run identifier.
	 *
	 * @return  void
	 */
	public function test_continue_delay_passes_all_documented_filter_arguments(): void {
		$filter_call = null;
		$this->set_filter_value(
			'a8csp_background_tasks/continue_delay',
			static function ( int $default_delay, string $identity, string $run_id ) use ( &$filter_call ): int {
				$filter_call = array(
					'arity' => \func_num_args(),
					'args'  => \func_get_args(),
				);

				return 75;
			}
		);

		self::assertSame( 75, $this->lock_windows->continue_delay( self::NAME, self::RUN_ID ) );
		self::assertSame(
			array(
				'arity' => 3,
				'args'  => array( 60, self::NAME, self::RUN_ID ),
			),
			$filter_call
		);
	}

	/**
	 * Staleness policy applies its default, validation, continuation floor, and saturation.
	 *
	 * @return  void
	 */
	#[DataProvider( 'lock_staleness_values' )]
	public function test_lock_staleness_resolves_exact_windows(
		mixed $staleness_filter,
		mixed $continue_filter,
		int $expected_staleness
	): void {
		if ( null !== $staleness_filter ) {
			$this->set_filter_value(
				'a8csp_background_tasks/lock_staleness/' . self::NAME,
				$staleness_filter
			);
		}
		if ( null !== $continue_filter ) {
			$this->set_filter_value( 'a8csp_background_tasks/continue_delay', $continue_filter );
		}

		self::assertSame( $expected_staleness, $this->lock_windows->lock_staleness( self::NAME, self::RUN_ID ) );
	}

	/**
	 * The identity-specific staleness filter receives only its documented default.
	 *
	 * @return  void
	 */
	public function test_lock_staleness_passes_all_documented_filter_arguments(): void {
		$filter_call = null;
		$this->set_filter_value(
			'a8csp_background_tasks/lock_staleness/' . self::NAME,
			static function ( int $default_staleness ) use ( &$filter_call ): int {
				$filter_call = array(
					'arity' => \func_num_args(),
					'args'  => \func_get_args(),
				);

				return $default_staleness;
			}
		);

		self::assertSame( 15 * \MINUTE_IN_SECONDS, $this->lock_windows->lock_staleness( self::NAME, self::RUN_ID ) );
		self::assertSame(
			array(
				'arity' => 1,
				'args'  => array( 15 * \MINUTE_IN_SECONDS ),
			),
			$filter_call
		);
	}

	/**
	 * Declared callback ceilings resolve through the shared default and runaway cap.
	 *
	 * @return  void
	 */
	#[DataProvider( 'execution_lease_values' )]
	public function test_execution_lease_resolves_declared_runtime( ?int $declared, int $expected_lease ): void {
		self::assertSame(
			$expected_lease,
			$this->lock_windows->execution_lease( $declared )
		);
	}

	/**
	 * Heartbeat staleness uses a strict boundary without overflowing minimum Unix seconds.
	 *
	 * @return  void
	 */
	#[DataProvider( 'heartbeat_boundaries' )]
	public function test_heartbeat_staleness_resolves_strict_boundaries(
		int $now,
		int $heartbeat_at,
		int $staleness,
		bool $expected_stale
	): void {
		$lock_windows = new LockWindows( new FixedClock( $now ) );

		self::assertSame( $expected_stale, $lock_windows->heartbeat_is_stale( $heartbeat_at, $staleness ) );
	}

	/**
	 * Supplies accepted and invalid continuation-delay filter values.
	 *
	 * @return  array<string, array{filtered_delay: int|string, expected_delay: int}>
	 */
	public static function continue_delay_filter_values(): array {
		return array(
			'positive is accepted'              => array(
				'filtered_delay' => 75,
				'expected_delay' => 75,
			),
			'zero is accepted'                  => array(
				'filtered_delay' => 0,
				'expected_delay' => 0,
			),
			'negative falls back to default'    => array(
				'filtered_delay' => -1,
				'expected_delay' => 60,
			),
			'non-integer falls back to default' => array(
				'filtered_delay' => '75',
				'expected_delay' => 60,
			),
		);
	}

	/**
	 * Supplies default, filtered, invalid, floored, and saturated staleness values.
	 *
	 * @return  array<string, array{staleness_filter: int|string|null, continue_filter: int|null, expected_staleness: int}>
	 */
	public static function lock_staleness_values(): array {
		return array(
			'default'            => array(
				'staleness_filter'   => null,
				'continue_filter'    => null,
				'expected_staleness' => 900,
			),
			'filtered'           => array(
				'staleness_filter'   => 300,
				'continue_filter'    => null,
				'expected_staleness' => 300,
			),
			'invalid zero'       => array(
				'staleness_filter'   => 0,
				'continue_filter'    => null,
				'expected_staleness' => 900,
			),
			'invalid type'       => array(
				'staleness_filter'   => '300',
				'continue_filter'    => null,
				'expected_staleness' => 900,
			),
			'continuation floor' => array(
				'staleness_filter'   => 1,
				'continue_filter'    => 75,
				'expected_staleness' => 150,
			),
			'saturated floor'    => array(
				'staleness_filter'   => 1,
				'continue_filter'    => \PHP_INT_MAX,
				'expected_staleness' => \PHP_INT_MAX,
			),
		);
	}

	/**
	 * Supplies accepted, invalid, and capped callback runtime declarations.
	 *
	 * @return  array<string, array{declared: int|null, expected_lease: int}>
	 */
	public static function execution_lease_values(): array {
		return array(
			'declared runtime'               => array(
				'declared'       => 1_200,
				'expected_lease' => 1_200,
			),
			'zero falls back to default'     => array(
				'declared'       => 0,
				'expected_lease' => 300,
			),
			'negative falls back to default' => array(
				'declared'       => -1,
				'expected_lease' => 300,
			),
			'absent falls back to default'   => array(
				'declared'       => null,
				'expected_lease' => 300,
			),
			'runaway runtime is capped'      => array(
				'declared'       => 86_400,
				'expected_lease' => 21_600,
			),
		);
	}

	/**
	 * Supplies strict fresh, stale, and minimum-integer heartbeat boundaries.
	 *
	 * @return  array<string, array{now: int, heartbeat_at: int, staleness: int, expected_stale: bool}>
	 */
	public static function heartbeat_boundaries(): array {
		return array(
			'exact boundary is fresh'      => array(
				'now'            => self::NOW,
				'heartbeat_at'   => self::NOW - 900,
				'staleness'      => 900,
				'expected_stale' => false,
			),
			'beyond boundary is stale'     => array(
				'now'            => self::NOW,
				'heartbeat_at'   => self::NOW - 901,
				'staleness'      => 900,
				'expected_stale' => true,
			),
			'future heartbeat is fresh'    => array(
				'now'            => self::NOW,
				'heartbeat_at'   => self::NOW + 21_600,
				'staleness'      => 900,
				'expected_stale' => false,
			),
			'minimum integer remains safe' => array(
				'now'            => -1,
				'heartbeat_at'   => \PHP_INT_MIN,
				'staleness'      => \PHP_INT_MAX,
				'expected_stale' => false,
			),
		);
	}

	// phpcs:enable Squiz.Commenting.FunctionComment.MissingParamTag
	// endregion.

	// region HELPERS.

	/**
	 * Scripts one WordPress filter value through a typed global boundary.
	 *
	 * @param   string $hook_name Hook name.
	 * @param   mixed  $value     Scripted value.
	 *
	 * @return  void
	 */
	private function set_filter_value( string $hook_name, mixed $value ): void {
		$filters = $GLOBALS['a8csp_bgte_test_filter_values'] ?? null;
		self::assertIsArray( $filters );
		$filters[ $hook_name ] = $value;

		$GLOBALS['a8csp_bgte_test_filter_values'] = $filters;
	}

	// endregion.
}
