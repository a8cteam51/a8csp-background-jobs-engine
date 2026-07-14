<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Schedules;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\RawOptionDecoder;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Recurrence;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\RegistrationUpdateOutcome;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\Schedule;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/** Detects whether registry-row decoding constructs a serialized class. */
final class ScheduleRegistryWakeupProbe {
	public static bool $woke = false;

	/** Records an unsafe object construction during unserialization. */
	public function __wakeup(): void {
		self::$woke = true;
	}
}

/**
 * Pins owner-sliced schedule persistence and request-local definition lookup.
 *
 */
#[CoversClass( ScheduleRegistry::class )]
#[UsesClass( Recurrence::class )]
#[UsesClass( Schedule::class )]
#[UsesClass( RegistrationUpdateOutcome::class )]
#[UsesClass( RawOptionDecoder::class )]
final class ScheduleRegistryTest extends TestCase {
	private OptionRows $rows;
	private WpdbLockSpy $wpdb;

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

		require_once \dirname( __DIR__, 2 ) . '/wp-options-stubs.php';
		require_once \dirname( __DIR__, 2 ) . '/wp-lock-stubs.php';
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
		$GLOBALS['a8csp_bgte_test_blog_id']               = 1;
		$GLOBALS['a8csp_bgte_test_cache']                 = array();
		$GLOBALS['a8csp_bgte_test_cache_calls']           = array();
		$this->wpdb                                       = new WpdbLockSpy();
		$this->rows                                       = new OptionRows( $this->wpdb );
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
					'misfires'    => 0,
					'skips'       => 0,
				),
			),
			( new ScheduleRegistry( $this->rows ) )->registrations_for( 'owner-a' )
		);
	}

	/**
	 * A serialized object is malformed without constructing its class during registry reads.
	 *
	 * @return  void
	 */
	public function test_registrations_for_rejects_an_object_row_without_class_construction(): void {
		ScheduleRegistryWakeupProbe::$woke = false;

		$raw = \maybe_serialize(
			array(
				'owner-a'      => array(
					'nightly' => array(
						'fingerprint' => 'fingerprint-a',
						'next_due'    => 1_700_000_300,
						'last_fired'  => null,
					),
				),
				'poison-owner' => new ScheduleRegistryWakeupProbe(),
			)
		);
		self::assertIsString( $raw );
		$this->wpdb->put( 'a8csp_bgte_schedules', $raw );

		self::assertSame(
			array(
				'nightly' => array(
					'fingerprint' => 'fingerprint-a',
					'next_due'    => 1_700_000_300,
					'last_fired'  => null,
					'misfires'    => 0,
					'skips'       => 0,
				),
			),
			( new ScheduleRegistry( $this->rows ) )->registrations_for( 'owner-a' )
		);
		self::assertFalse( ScheduleRegistryWakeupProbe::$woke );
	}

	/**
	 * The all-owner read flattens the same validated owner slices under complete identities.
	 *
	 * @return  void
	 */
	public function test_all_registrations_flattens_valid_owner_slices_without_new_decoding(): void {
		$GLOBALS['a8csp_bgte_test_options'] = array(
			'a8csp_bgte_schedules' => array(
				'owner-b'      => array(
					'hourly' => array(
						'fingerprint' => 'fingerprint-b',
						'next_due'    => 1_700_003_600,
						'last_fired'  => 1_700_000_000,
						'misfires'    => 2,
						'skips'       => 3,
					),
				),
				'owner-a'      => array(
					'nightly' => array(
						'fingerprint' => 'fingerprint-a',
						'next_due'    => 1_700_000_300,
						'last_fired'  => null,
					),
					'broken'  => array( 'fingerprint' => false ),
				),
				'broken-owner' => 'not-an-owner-slice',
			),
		);

		$registry = new ScheduleRegistry( $this->rows );

		self::assertSame(
			array(
				'owner-b:hourly'  => $registry->registrations_for( 'owner-b' )['hourly'],
				'owner-a:nightly' => $registry->registrations_for( 'owner-a' )['nightly'],
			),
			$registry->all_registrations()
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
					'misfires'    => 0,
					'skips'       => 0,
				),
			),
			( new ScheduleRegistry( $this->rows ) )->registrations_for( '123' )
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

		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$state    = array(
			'nightly' => array(
				'fingerprint' => $schedule->fingerprint(),
				'next_due'    => 1_700_000_300,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			),
		);

		$registry = new ScheduleRegistry( $this->rows );

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

		$replaced = ( new ScheduleRegistry( $this->rows ) )->replace_owner( 'owner-a', array(), array() );
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
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$state    = array(
			'nightly' => array(
				'fingerprint' => $schedule->fingerprint(),
				'next_due'    => 1_700_000_300,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			),
		);

		$replaced = ( new ScheduleRegistry( $this->rows ) )->replace_owner(
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

		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$state    = array(
			'nightly' => array(
				'fingerprint' => $schedule->fingerprint(),
				'next_due'    => 1_700_000_300,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			),
		);

		$replaced = ( new ScheduleRegistry( $this->rows ) )->replace_owner(
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

	/**
	 * A lost whole-option CAS retries against the fresh owner slice and preserves its sibling row.
	 *
	 * @return  void
	 */
	public function test_update_registration_retries_a_lost_cas_and_preserves_the_concurrent_sibling(): void {
		self::store_registry( $this->two_registration_registry() );
		$this->wpdb->before_next(
			'update',
			static function (): void {
				$registry = self::stored_registry();
				$owner    = $registry['owner-a'] ?? null;
				self::assertIsArray( $owner );
				$hourly = $owner['hourly'] ?? null;
				self::assertIsArray( $hourly );

				$hourly['last_fired'] = 1_700_000_111;
				$owner['hourly']      = $hourly;
				$registry['owner-a']  = $owner;
				self::store_registry( $registry );
			}
		);
		$nightly             = $this->two_registration_registry()['owner-a']['nightly'];
		$nightly['next_due'] = 1_700_000_600;

		$outcome = ( new ScheduleRegistry( $this->rows ) )->update_registration( 'owner-a:nightly', $nightly );

		self::assertSame( RegistrationUpdateOutcome::Updated, $outcome );
		$stored = self::stored_registry();
		$owner  = $stored['owner-a'] ?? null;
		self::assertIsArray( $owner );
		$stored_nightly = $owner['nightly'] ?? null;
		$stored_hourly  = $owner['hourly'] ?? null;
		self::assertIsArray( $stored_nightly );
		self::assertIsArray( $stored_hourly );
		self::assertSame( 1_700_000_600, $stored_nightly['next_due'] ?? null );
		self::assertSame( 1_700_000_111, $stored_hourly['last_fired'] ?? null );
	}

	/**
	 * A row pruned after inspection is not resurrected by the losing delivery writer.
	 *
	 * @return  void
	 */
	public function test_update_registration_reports_pruned_without_resurrecting_the_row(): void {
		self::store_registry( $this->two_registration_registry() );
		$this->wpdb->before_next(
			'update',
			static function (): void {
				$registry = self::stored_registry();
				$owner    = $registry['owner-a'] ?? null;
				self::assertIsArray( $owner );

				unset( $owner['nightly'] );
				$registry['owner-a'] = $owner;
				self::store_registry( $registry );
			}
		);
		$nightly = $this->two_registration_registry()['owner-a']['nightly'];

		$outcome = ( new ScheduleRegistry( $this->rows ) )->update_registration( 'owner-a:nightly', $nightly );

		self::assertSame( RegistrationUpdateOutcome::Pruned, $outcome );
		$stored = self::stored_registry();
		$owner  = $stored['owner-a'] ?? null;
		self::assertIsArray( $owner );
		self::assertArrayNotHasKey( 'nightly', $owner );
	}

	/**
	 * An unchanged row after a failed SQL write reports repairable persistence failure.
	 *
	 * @return  void
	 */
	public function test_update_registration_reports_write_verification_failure(): void {
		self::store_registry( $this->two_registration_registry() );
		$this->wpdb->script_result( 'update', false );
		$nightly             = $this->two_registration_registry()['owner-a']['nightly'];
		$nightly['next_due'] = 1_700_000_600;

		$outcome = ( new ScheduleRegistry( $this->rows ) )->update_registration( 'owner-a:nightly', $nightly );

		self::assertSame( RegistrationUpdateOutcome::Failed, $outcome );
		$stored = self::stored_registry();
		$owner  = $stored['owner-a'] ?? null;
		self::assertIsArray( $owner );
		$stored_nightly = $owner['nightly'] ?? null;
		self::assertIsArray( $stored_nightly );
		self::assertSame( 1_700_000_300, $stored_nightly['next_due'] ?? null );
	}

	/**
	 * An existing unreadable registry row is a repairable failure, not a concurrent prune.
	 *
	 * @return  void
	 */
	public function test_update_registration_reports_malformed_storage_as_failed(): void {
		$this->wpdb->put( 'a8csp_bgte_schedules', 'not-serialized' );
		$nightly = $this->two_registration_registry()['owner-a']['nightly'];

		$outcome = ( new ScheduleRegistry( $this->rows ) )->update_registration( 'owner-a:nightly', $nightly );

		self::assertSame( RegistrationUpdateOutcome::Failed, $outcome );
		self::assertSame( 'not-serialized', $this->wpdb->rows['a8csp_bgte_schedules'] ?? null );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns two persisted registration rows for one owner.
	 *
	 * @return  array{
	 *     'owner-a': array{
	 *         nightly: array{
	 *             fingerprint: string,
	 *             next_due: int,
	 *             last_fired: null,
	 *             misfires: int,
	 *             skips: int
	 *         },
	 *         hourly: array{
	 *             fingerprint: string,
	 *             next_due: int,
	 *             last_fired: null,
	 *             misfires: int,
	 *             skips: int
	 *         }
	 *     }
	 * }
	 */
	private function two_registration_registry(): array {
		return array(
			'owner-a' => array(
				'nightly' => array(
					'fingerprint' => 'nightly-fingerprint',
					'next_due'    => 1_700_000_300,
					'last_fired'  => null,
					'misfires'    => 0,
					'skips'       => 0,
				),
				'hourly'  => array(
					'fingerprint' => 'hourly-fingerprint',
					'next_due'    => 1_700_003_600,
					'last_fired'  => null,
					'misfires'    => 0,
					'skips'       => 0,
				),
			),
		);
	}

	/**
	 * Replaces the registry option while preserving the test store's outer shape.
	 *
	 * @param   array<mixed> $registry  Registry value to persist.
	 *
	 * @return  void
	 */
	private static function store_registry( array $registry ): void {
		$options                            = self::test_options();
		$options['a8csp_bgte_schedules']    = $registry;
		$GLOBALS['a8csp_bgte_test_options'] = $options;
	}

	/**
	 * Returns the persisted registry option.
	 *
	 * @return  array<mixed>
	 */
	private static function stored_registry(): array {
		$options  = self::test_options();
		$registry = $options['a8csp_bgte_schedules'] ?? null;

		self::assertIsArray( $registry );

		return $registry;
	}

	/**
	 * Returns the in-memory option store.
	 *
	 * @return  array<mixed>
	 */
	private static function test_options(): array {
		$options = $GLOBALS['a8csp_bgte_test_options'] ?? null;

		self::assertIsArray( $options );

		return $options;
	}

	// endregion.
}
