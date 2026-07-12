<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Schedules;

use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\Cadence;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Schedules\ScheduleRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins owner-sliced schedule persistence and request-local definition lookup.
 *
 */
#[CoversClass( ScheduleRegistry::class )]
#[UsesClass( Cadence::class )]
#[UsesClass( Schedule::class )]
final class ScheduleRegistryTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads WordPress option and JSON seams before registry classes are first autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__ ) . '/Scheduling/wp-json-encode-stub.php';
	}

	/**
	 * Resets the in-memory option store and call ledger.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_options']               = array();
		$GLOBALS['a8csp_bgte_test_option_calls']          = array();
		$GLOBALS['a8csp_bgte_test_option_autoload']       = array();
		$GLOBALS['a8csp_bgte_test_update_option_results'] = array();
		$GLOBALS['a8csp_bgte_test_update_option_values']  = array();
		$GLOBALS['a8csp_bgte_test_delete_option_results'] = array();
	}

	/**
	 * Removes option-write scripts before another test class uses the shared stubs.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function tearDown(): void {
		unset(
			$GLOBALS['a8csp_bgte_test_update_option_results'],
			$GLOBALS['a8csp_bgte_test_update_option_values'],
			$GLOBALS['a8csp_bgte_test_delete_option_results']
		);

		parent::tearDown();
	}

	// endregion.

	// region TESTS.

	/**
	 * Reading one owner returns only its valid registration rows.
	 *
	 * @return  void
	 */
	public function test_registrations_for_returns_only_the_requested_owner_slice(): void {
		$GLOBALS['a8csp_bgte_test_options'] = array(
			'a8csp_bgte_schedules' => array(
				'owner-a' => array(
					'nightly' => array(
						'fingerprint' => 'fingerprint-a',
						'next_due'    => 1_700_000_300,
						'last_fired'  => null,
					),
					'broken'  => array( 'fingerprint' => false ),
				),
				'owner-b' => array(
					'hourly' => array(
						'fingerprint' => 'fingerprint-b',
						'next_due'    => 1_700_003_600,
						'last_fired'  => 1_700_000_000,
					),
				),
			),
		);

		self::assertSame(
			array(
				'nightly' => array(
					'fingerprint' => 'fingerprint-a',
					'next_due'    => 1_700_000_300,
					'last_fired'  => null,
				),
			),
			( new ScheduleRegistry() )->registrations_for( 'owner-a' )
		);
	}

	/**
	 * Numeric stable identifiers survive PHP's integer array-key coercion.
	 *
	 * @return  void
	 */
	public function test_registrations_for_preserves_numeric_names_through_key_coercion(): void {
		$GLOBALS['a8csp_bgte_test_options'] = array(
			'a8csp_bgte_schedules' => array(
				123 => array(
					456 => array(
						'fingerprint' => 'numeric-fingerprint',
						'next_due'    => 1_700_000_300,
						'last_fired'  => null,
					),
				),
			),
		);

		self::assertSame(
			array(
				456 => array(
					'fingerprint' => 'numeric-fingerprint',
					'next_due'    => 1_700_000_300,
					'last_fired'  => null,
				),
			),
			( new ScheduleRegistry() )->registrations_for( '123' )
		);
	}

	/**
	 * Replacing one owner preserves every other owner and disables autoload.
	 *
	 * @return  void
	 */
	public function test_replace_owner_preserves_other_owners_and_disables_autoload(): void {
		$GLOBALS['a8csp_bgte_test_options'] = array(
			'a8csp_bgte_schedules' => array(
				'owner-b' => array(
					'hourly' => array(
						'fingerprint' => 'fingerprint-b',
						'next_due'    => 1_700_003_600,
						'last_fired'  => null,
					),
				),
			),
		);

		$schedule = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );
		$state    = array(
			'nightly' => array(
				'fingerprint' => $schedule->fingerprint(),
				'next_due'    => 1_700_000_300,
				'last_fired'  => null,
			),
		);

		$registry = new ScheduleRegistry();

		$replaced = $registry->replace_owner( 'owner-a', array( 'nightly' => $schedule ), $state );
		$options  = $GLOBALS['a8csp_bgte_test_options'];
		$autoload = $GLOBALS['a8csp_bgte_test_option_autoload'];

		self::assertIsArray( $autoload );

		self::assertTrue( $replaced );
		self::assertSame(
			array(
				'owner-b' => array(
					'hourly' => array(
						'fingerprint' => 'fingerprint-b',
						'next_due'    => 1_700_003_600,
						'last_fired'  => null,
					),
				),
				'owner-a' => $state,
			),
			$options['a8csp_bgte_schedules']
		);
		self::assertFalse( $autoload['a8csp_bgte_schedules'] );
		self::assertSame( $schedule, $registry->get( 'owner-a:nightly' ) );
		self::assertNull( $registry->get( 'owner-b:hourly' ) );
	}

	/**
	 * Removing the final owner deletes the empty registry option.
	 *
	 * @return  void
	 */
	public function test_replace_owner_deletes_the_option_when_no_registrations_remain(): void {
		$GLOBALS['a8csp_bgte_test_options'] = array(
			'a8csp_bgte_schedules' => array(
				'owner-a' => array(
					'nightly' => array(
						'fingerprint' => 'fingerprint-a',
						'next_due'    => 1_700_000_300,
						'last_fired'  => null,
					),
				),
			),
		);

		$replaced = ( new ScheduleRegistry() )->replace_owner( 'owner-a', array(), array() );
		$options  = $GLOBALS['a8csp_bgte_test_options'];

		self::assertTrue( $replaced );
		self::assertArrayNotHasKey( 'a8csp_bgte_schedules', $options );
		self::assertSame(
			array(
				array(
					'function' => 'delete_option',
					'args'     => array( 'a8csp_bgte_schedules' ),
				),
			),
			$GLOBALS['a8csp_bgte_test_option_calls']
		);
	}

	/**
	 * A failed option update remains observable to the synchronization layer.
	 *
	 * @return  void
	 */
	public function test_replace_owner_reports_a_failed_option_update(): void {
		$GLOBALS['a8csp_bgte_test_update_option_results'] = array( 'a8csp_bgte_schedules' => false );
		$schedule = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );
		$state    = array(
			'nightly' => array(
				'fingerprint' => $schedule->fingerprint(),
				'next_due'    => 1_700_000_300,
				'last_fired'  => null,
			),
		);

		$replaced = ( new ScheduleRegistry() )->replace_owner(
			'owner-a',
			array( 'nightly' => $schedule ),
			$state
		);
		$options  = $GLOBALS['a8csp_bgte_test_options'];

		self::assertIsArray( $options );

		self::assertFalse( $replaced );
		self::assertArrayNotHasKey( 'a8csp_bgte_schedules', $options );
	}

	/**
	 * A successful update whose stored value is filtered remains a failed postcondition.
	 *
	 * @return  void
	 */
	public function test_replace_owner_verifies_the_exact_stored_value_after_update(): void {
		$GLOBALS['a8csp_bgte_test_update_option_values'] = array(
			'a8csp_bgte_schedules' => array( 'foreign-owner' => array() ),
		);

		$schedule = new Schedule( 'nightly', Cadence::every( 300 ), 'refresh-index' );
		$state    = array(
			'nightly' => array(
				'fingerprint' => $schedule->fingerprint(),
				'next_due'    => 1_700_000_300,
				'last_fired'  => null,
			),
		);

		$replaced = ( new ScheduleRegistry() )->replace_owner(
			'owner-a',
			array( 'nightly' => $schedule ),
			$state
		);
		$options  = $GLOBALS['a8csp_bgte_test_options'];

		self::assertIsArray( $options );

		self::assertFalse( $replaced );
		self::assertSame(
			array( 'foreign-owner' => array() ),
			$options['a8csp_bgte_schedules']
		);
	}

	// endregion.
}
