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
		$registrations                      = ( new ScheduleRegistry( $this->rows ) )->registrations_for( 'owner-a' );
		if ( $registrations->is_failure() ) {
			self::fail( 'The owner schedule registrations could not be read.' );
		}

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
			$registrations->value
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
		$registrations = ( new ScheduleRegistry( $this->rows ) )->registrations_for( 'owner-a' );
		if ( $registrations->is_failure() ) {
			self::fail( 'The owner schedule registrations could not be read.' );
		}

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
			$registrations->value
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

		$registry          = new ScheduleRegistry( $this->rows );
		$owner_b           = $registry->registrations_for( 'owner-b' );
		$owner_a           = $registry->registrations_for( 'owner-a' );
		$all_registrations = $registry->all_registrations();
		if ( $owner_b->is_failure() ) {
			self::fail( 'The owner-b schedule registrations could not be read.' );
		}
		if ( $owner_a->is_failure() ) {
			self::fail( 'The owner-a schedule registrations could not be read.' );
		}
		if ( $all_registrations->is_failure() ) {
			self::fail( 'The complete schedule registry could not be read.' );
		}

		self::assertSame(
			array(
				'owner-b:hourly'  => $owner_b->value['hourly'],
				'owner-a:nightly' => $owner_a->value['nightly'],
			),
			$all_registrations->value
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
		$registrations                      = ( new ScheduleRegistry( $this->rows ) )->registrations_for( '123' );
		if ( $registrations->is_failure() ) {
			self::fail( 'The numeric owner schedule registrations could not be read.' );
		}

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
			$registrations->value
		);
	}

	/**
	 * Replacing one owner preserves every other owner and the registry's non-autoloaded setting.
	 *
	 * @return  void
	 */
	public function test_replace_owner_preserves_other_owners_and_the_non_autoloaded_setting(): void {
		$stored     = array(
			'owner-b' => array(
				'hourly' => array(
					'fingerprint' => 'fingerprint-b',
					'next_due'    => 1_700_003_600,
					'last_fired'  => null,
				),
			),
		);
		$stored_raw = \maybe_serialize( $stored );
		self::assertIsString( $stored_raw );
		$this->wpdb->put( 'a8csp_bgte_schedules', $stored_raw );

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

		$replaced      = $registry->replace_owner( 'owner-a', array( 'nightly' => $schedule ), $state );
		$persisted_raw = $this->wpdb->rows['a8csp_bgte_schedules'] ?? null;
		self::assertIsString( $persisted_raw );
		$persisted = RawOptionDecoder::decode( $persisted_raw );
		self::assertIsArray( $persisted );

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
			$persisted
		);
		self::assertSame( 'off', $this->wpdb->autoload['a8csp_bgte_schedules'] ?? null );
		self::assertSame( $schedule, $registry->get( 'owner-a:nightly' ) );
		self::assertNull( $registry->get( 'owner-b:hourly' ) );
		self::assertCount( 2, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'UPDATE ', $this->wpdb->recorded_queries[1] );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $this->wpdb->recorded_queries[1] );
	}

	/**
	 * Creating the registry inserts an exact non-autoloaded raw option row.
	 *
	 * @return  void
	 */
	public function test_replace_owner_inserts_a_non_autoloaded_registry_when_the_row_is_absent(): void {
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

		$raw = $this->wpdb->rows['a8csp_bgte_schedules'] ?? null;
		self::assertIsString( $raw );
		self::assertSame( array( 'owner-a' => $state ), RawOptionDecoder::decode( $raw ) );
		self::assertSame( 'off', $this->wpdb->autoload['a8csp_bgte_schedules'] ?? null );
		self::assertTrue( $replaced );
		self::assertSame( $schedule, $registry->get( 'owner-a:nightly' ) );
		self::assertCount( 2, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'INSERT IGNORE ', $this->wpdb->recorded_queries[1] );
	}

	/** A failed authoritative registry read aborts owner replacement without writing or retaining declarations. */
	public function test_replace_owner_aborts_without_writing_when_the_registry_read_fails(): void {
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted registry read failure';
			}
		);
		$registry = new ScheduleRegistry( $this->rows );

		$replaced = $registry->replace_owner(
			'owner-a',
			array( 'nightly' => $schedule ),
			array(
				'nightly' => array(
					'fingerprint' => $schedule->fingerprint(),
					'next_due'    => 1_700_000_300,
					'last_fired'  => null,
					'misfires'    => 0,
					'skips'       => 0,
				),
			)
		);

		self::assertFalse( $replaced );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertNull( $registry->get( 'owner-a:nightly' ) );
		self::assertCount( 1, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
	}

	/**
	 * Removing the final owner deletes the empty registry option.
	 *
	 * @return  void
	 */
	public function test_replace_owner_deletes_the_option_when_no_registrations_remain(): void {
		$stored = array(
			'owner-a' => array(
				'nightly' => array(
					'fingerprint' => 'fingerprint-a',
					'next_due'    => 1_700_000_300,
					'last_fired'  => null,
				),
			),
		);
		$raw    = \maybe_serialize( $stored );
		self::assertIsString( $raw );
		$this->wpdb->put( 'a8csp_bgte_schedules', $raw );

		$replaced = ( new ScheduleRegistry( $this->rows ) )->replace_owner( 'owner-a', array(), array() );

		self::assertTrue( $replaced );
		self::assertArrayNotHasKey( 'a8csp_bgte_schedules', $this->wpdb->rows );
		self::assertArrayNotHasKey( 'a8csp_bgte_schedules', $this->wpdb->autoload );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertCount( 2, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'DELETE ', $this->wpdb->recorded_queries[1] );
		self::assertStringContainsString( 'BINARY `option_value` = BINARY ', $this->wpdb->recorded_queries[1] );
	}

	/**
	 * An unchanged raw row after a failed exact update remains observable to the synchronization layer.
	 *
	 * @return  void
	 */
	public function test_replace_owner_reports_a_failed_exact_option_update(): void {
		$stored = $this->two_registration_registry();
		self::store_registry( $stored );
		$this->wpdb->script_result( 'update', false );
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

		$replaced = $registry->replace_owner(
			'owner-a',
			array( 'nightly' => $schedule ),
			$state
		);

		self::assertFalse( $replaced );
		self::assertSame( $stored, self::stored_registry() );
		self::assertNull( $registry->get( 'owner-a:nightly' ) );
		self::assertCount( 3, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'UPDATE ', $this->wpdb->recorded_queries[1] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[2] );
	}

	/**
	 * A failed post-CAS reread preserves the exact concurrent registry bytes.
	 *
	 * @return  void
	 */
	public function test_replace_owner_preserves_concurrent_bytes_when_the_post_cas_reread_fails(): void {
		$stored                = $this->two_registration_registry();
		$concurrent            = $stored;
		$concurrent['owner-b'] = array(
			'hourly' => array(
				'fingerprint' => 'concurrent-fingerprint',
				'next_due'    => 1_700_003_900,
				'last_fired'  => 1_700_000_111,
				'misfires'    => 2,
				'skips'       => 3,
			),
		);
		$stored_raw            = \maybe_serialize( $stored );
		$concurrent_raw        = \maybe_serialize( $concurrent );
		self::assertIsString( $stored_raw );
		self::assertIsString( $concurrent_raw );
		$this->wpdb->put( 'a8csp_bgte_schedules', $stored_raw );
		$this->wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $concurrent_raw ): void {
				$wpdb->put( 'a8csp_bgte_schedules', $concurrent_raw );
			}
		);
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted post-CAS registry reread failure';
			}
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

		$replaced = $registry->replace_owner(
			'owner-a',
			array( 'nightly' => $schedule ),
			$state
		);

		self::assertFalse( $replaced );
		self::assertSame( $concurrent_raw, $this->wpdb->rows['a8csp_bgte_schedules'] ?? null );
		self::assertNull( $registry->get( 'owner-a:nightly' ) );
		self::assertCount( 3, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'UPDATE ', $this->wpdb->recorded_queries[1] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[2] );
	}

	/**
	 * A lost owner replacement retries from fresh bytes and preserves a concurrent owner slice exactly.
	 *
	 * @return  void
	 */
	public function test_replace_owner_retries_a_lost_cas_and_preserves_a_concurrent_owner_slice_byte_for_byte(): void {
		$stored                = $this->two_registration_registry();
		$owner_b               = array(
			'hourly' => array(
				'fingerprint' => "owner-b-\0fingerprint",
				'next_due'    => 1_700_003_600,
				'last_fired'  => 1_700_000_111,
				'misfires'    => 2,
				'skips'       => 3,
			),
		);
		$concurrent            = $stored;
		$concurrent['owner-b'] = $owner_b;
		$stored_raw            = \maybe_serialize( $stored );
		$concurrent_raw        = \maybe_serialize( $concurrent );
		self::assertIsString( $stored_raw );
		self::assertIsString( $concurrent_raw );
		$this->wpdb->put( 'a8csp_bgte_schedules', $stored_raw );
		$this->wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $concurrent_raw ): void {
				$wpdb->put( 'a8csp_bgte_schedules', $concurrent_raw );
			}
		);
		$requested                        = $stored['owner-a'];
		$requested['nightly']['next_due'] = 1_700_000_900;
		$schedule                         = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$registry                         = new ScheduleRegistry( $this->rows );

		$replaced = $registry->replace_owner( 'owner-a', array( 'nightly' => $schedule ), $requested );

		$expected            = $concurrent;
		$expected['owner-a'] = $requested;
		$expected_raw        = \maybe_serialize( $expected );
		$persisted_raw       = $this->wpdb->rows['a8csp_bgte_schedules'] ?? null;
		self::assertIsString( $expected_raw );
		self::assertIsString( $persisted_raw );
		self::assertSame( $expected_raw, $persisted_raw );
		$persisted = RawOptionDecoder::decode( $persisted_raw );
		self::assertIsArray( $persisted );
		self::assertTrue( $replaced );
		self::assertSame( $expected, $persisted );
		$expected_owner_b_raw  = \maybe_serialize( $owner_b );
		$persisted_owner_b_raw = \maybe_serialize( $persisted['owner-b'] );
		self::assertIsString( $expected_owner_b_raw );
		self::assertIsString( $persisted_owner_b_raw );
		self::assertSame( $expected_owner_b_raw, $persisted_owner_b_raw );
		self::assertStringContainsString( $expected_owner_b_raw, $persisted_raw );
		self::assertSame( $schedule, $registry->get( 'owner-a:nightly' ) );
		self::assertCount( 5, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'UPDATE ', $this->wpdb->recorded_queries[1] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[2] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[3] );
		self::assertStringStartsWith( 'UPDATE ', $this->wpdb->recorded_queries[4] );
	}

	/**
	 * A lost replacement whose row vanished re-establishes only the caller's slice on the retry.
	 *
	 * @return  void
	 */
	public function test_replace_owner_reinserts_only_its_own_slice_after_a_concurrent_row_deletion(): void {
		$stored     = $this->two_registration_registry();
		$stored_raw = \maybe_serialize( $stored );
		self::assertIsString( $stored_raw );
		$this->wpdb->put( 'a8csp_bgte_schedules', $stored_raw );
		$this->wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ): void {
				unset( $wpdb->rows['a8csp_bgte_schedules'], $wpdb->autoload['a8csp_bgte_schedules'] );
			}
		);
		$owner_b  = array(
			'hourly' => array(
				'fingerprint' => 'owner-b-fingerprint',
				'next_due'    => 1_700_003_600,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			),
		);
		$schedule = new Schedule( 'hourly', Recurrence::every( 3600 ), 'sync-hourly' );
		$registry = new ScheduleRegistry( $this->rows );

		$replaced = $registry->replace_owner( 'owner-b', array( 'hourly' => $schedule ), $owner_b );

		$expected_raw  = \maybe_serialize( array( 'owner-b' => $owner_b ) );
		$persisted_raw = $this->wpdb->rows['a8csp_bgte_schedules'] ?? null;
		self::assertIsString( $expected_raw );
		self::assertIsString( $persisted_raw );
		self::assertTrue( $replaced );
		self::assertSame( $expected_raw, $persisted_raw );
		$persisted = RawOptionDecoder::decode( $persisted_raw );
		self::assertIsArray( $persisted );
		self::assertArrayNotHasKey( 'owner-a', $persisted );
		self::assertSame( $schedule, $registry->get( 'owner-b:hourly' ) );
		self::assertCount( 5, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'UPDATE ', $this->wpdb->recorded_queries[1] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[2] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[3] );
		self::assertStringStartsWith( 'INSERT IGNORE ', $this->wpdb->recorded_queries[4] );
	}

	/**
	 * Retrying an owner replacement preserves another owner's concurrently advanced timing state.
	 *
	 * @return  void
	 */
	public function test_replace_owner_preserves_a_concurrent_next_due_advance(): void {
		$stored                                      = $this->two_registration_registry();
		$stored['owner-b']                           = array(
			'hourly' => array(
				'fingerprint' => 'owner-b-fingerprint',
				'next_due'    => 1_700_003_600,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			),
		);
		$advanced                                    = $stored;
		$advanced['owner-b']['hourly']['next_due']   = 1_700_003_900;
		$advanced['owner-b']['hourly']['last_fired'] = 1_700_003_600;
		$requested                                   = $stored['owner-a'];
		$requested['nightly']['next_due']            = 1_700_000_900;
		$expected                                    = $advanced;
		$expected['owner-a']                         = $requested;
		self::store_registry( $stored );
		$this->wpdb->before_next(
			'update',
			static function () use ( $advanced ): void {
				self::store_registry( $advanced );
			}
		);

		$replaced = ( new ScheduleRegistry( $this->rows ) )->replace_owner( 'owner-a', array(), $requested );

		$persisted = self::stored_registry();
		self::assertTrue( $replaced );
		self::assertSame( $expected, $persisted );
		self::assertSame( 1_700_003_900, $persisted['owner-b']['hourly']['next_due'] );
		self::assertSame( 1_700_003_600, $persisted['owner-b']['hourly']['last_fired'] );
		self::assertCount( 5, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'UPDATE ', $this->wpdb->recorded_queries[1] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[2] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[3] );
		self::assertStringStartsWith( 'UPDATE ', $this->wpdb->recorded_queries[4] );
	}

	/**
	 * A corrupt shared registry remains byte-identical and cannot be replaced by an owner write.
	 *
	 * @return  void
	 */
	public function test_replace_owner_rejects_a_corrupt_registry_without_writing(): void {
		$raw = 'not-a-serialized-registry';
		$this->wpdb->put( 'a8csp_bgte_schedules', $raw );
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$registry = new ScheduleRegistry( $this->rows );

		$replaced = $registry->replace_owner(
			'owner-a',
			array( 'nightly' => $schedule ),
			array(
				'nightly' => array(
					'fingerprint' => $schedule->fingerprint(),
					'next_due'    => 1_700_000_300,
					'last_fired'  => null,
					'misfires'    => 0,
					'skips'       => 0,
				),
			)
		);

		self::assertFalse( $replaced );
		self::assertSame( $raw, $this->wpdb->rows['a8csp_bgte_schedules'] ?? null );
		self::assertNull( $registry->get( 'owner-a:nightly' ) );
		self::assertSame( array(), $GLOBALS['a8csp_bgte_test_option_calls'] );
		self::assertCount( 1, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
	}

	/**
	 * Removing the final owner retries a delete whose exact raw precondition changed concurrently.
	 *
	 * @return  void
	 */
	public function test_replace_owner_retries_final_owner_removal_after_a_concurrent_change(): void {
		$stored                                       = $this->two_registration_registry();
		$concurrent                                   = $stored;
		$concurrent['owner-a']['nightly']['next_due'] = 1_700_000_600;
		self::store_registry( $stored );
		$this->wpdb->before_next(
			'delete',
			static function () use ( $concurrent ): void {
				self::store_registry( $concurrent );
			}
		);

		$replaced = ( new ScheduleRegistry( $this->rows ) )->replace_owner( 'owner-a', array(), array() );

		self::assertTrue( $replaced );
		self::assertArrayNotHasKey( 'a8csp_bgte_schedules', self::test_options() );
		self::assertCount( 5, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'DELETE ', $this->wpdb->recorded_queries[1] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[2] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[3] );
		self::assertStringStartsWith( 'DELETE ', $this->wpdb->recorded_queries[4] );
	}

	/**
	 * A lost final-owner delete converges when another writer already removed the row.
	 *
	 * @return  void
	 */
	public function test_replace_owner_confirms_final_owner_removal_when_the_row_is_already_gone(): void {
		self::store_registry( $this->two_registration_registry() );
		$this->wpdb->before_next(
			'delete',
			static function (): void {
				$options = self::test_options();
				unset( $options['a8csp_bgte_schedules'] );
				$GLOBALS['a8csp_bgte_test_options'] = $options;
			}
		);
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$registry = new ScheduleRegistry( $this->rows );

		$replaced = $registry->replace_owner( 'owner-a', array( 'nightly' => $schedule ), array() );

		self::assertTrue( $replaced );
		self::assertArrayNotHasKey( 'a8csp_bgte_schedules', self::test_options() );
		self::assertSame( $schedule, $registry->get( 'owner-a:nightly' ) );
		self::assertCount( 3, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'DELETE ', $this->wpdb->recorded_queries[1] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[2] );
	}

	/**
	 * Persistent raw-value interference exhausts the bounded owner replacement loop safely.
	 *
	 * @return  void
	 */
	public function test_replace_owner_reports_failure_after_persistent_interference_exhausts_the_retry_bound(): void {
		$stored            = $this->two_registration_registry();
		$stored['owner-b'] = array(
			'hourly' => array(
				'fingerprint' => 'owner-b-fingerprint',
				'next_due'    => 1_700_003_600,
				'last_fired'  => null,
				'misfires'    => 0,
				'skips'       => 0,
			),
		);
		self::store_registry( $stored );
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$next_due = 1_700_004_000 + $attempt;
			$this->wpdb->before_next(
				'update',
				static function () use ( $next_due ): void {
					$concurrent = self::stored_registry();
					$owner_b    = $concurrent['owner-b'] ?? null;
					self::assertIsArray( $owner_b );
					$hourly = $owner_b['hourly'] ?? null;
					self::assertIsArray( $hourly );
					$hourly['next_due']    = $next_due;
					$hourly['last_fired']  = $next_due - 300;
					$owner_b['hourly']     = $hourly;
					$concurrent['owner-b'] = $owner_b;
					self::store_registry( $concurrent );
				}
			);
		}
		$requested                        = $stored['owner-a'];
		$requested['nightly']['next_due'] = 1_700_000_900;
		$schedule                         = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$registry                         = new ScheduleRegistry( $this->rows );

		$replaced = $registry->replace_owner( 'owner-a', array( 'nightly' => $schedule ), $requested );

		$persisted = self::stored_registry();
		$owner_b   = $persisted['owner-b'] ?? null;
		self::assertIsArray( $owner_b );
		$hourly = $owner_b['hourly'] ?? null;
		self::assertIsArray( $hourly );
		self::assertFalse( $replaced );
		self::assertSame( $stored['owner-a'], $persisted['owner-a'] ?? null );
		self::assertSame( 1_700_004_004, $hourly['next_due'] ?? null );
		self::assertNull( $registry->get( 'owner-a:nightly' ) );
		self::assertCount( 15, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[14] );
		$updates = \array_values(
			\array_filter(
				$this->wpdb->recorded_queries,
				static fn ( string $query ): bool => \str_starts_with( $query, 'UPDATE ' )
			)
		);
		self::assertCount( 5, $updates );
		self::assertSame(
			array(),
			\array_values(
				\array_filter(
					$this->wpdb->recorded_queries,
					static fn ( string $query ): bool => \str_starts_with( $query, 'INSERT ' )
						|| \str_starts_with( $query, 'DELETE ' )
				)
			)
		);
	}

	/**
	 * An initial authoritative read failure reports repairable persistence failure without writing.
	 *
	 * @return  void
	 */
	public function test_update_registration_reports_failed_when_the_initial_read_fails(): void {
		$registry = $this->two_registration_registry();
		$raw      = \maybe_serialize( $registry );
		self::assertIsString( $raw );
		$this->wpdb->put( 'a8csp_bgte_schedules', $raw );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted initial registry read failure';
			}
		);
		$nightly             = $registry['owner-a']['nightly'];
		$nightly['next_due'] = 1_700_000_600;

		$outcome = ( new ScheduleRegistry( $this->rows ) )->update_registration( 'owner-a:nightly', $nightly );

		self::assertSame( RegistrationUpdateOutcome::Failed, $outcome );
		self::assertSame( $raw, $this->wpdb->rows['a8csp_bgte_schedules'] ?? null );
		self::assertCount( 1, $this->wpdb->recorded_queries );
	}

	/**
	 * A failed authoritative reread after a lost CAS preserves the concurrent registry bytes.
	 *
	 * @return  void
	 */
	public function test_update_registration_reports_failed_when_the_post_cas_reread_fails(): void {
		$registry     = $this->two_registration_registry();
		$expected_raw = \maybe_serialize( $registry );
		$concurrent   = $registry;

		$concurrent['owner-a']['hourly']['last_fired'] = 1_700_000_111;

		$concurrent_raw = \maybe_serialize( $concurrent );
		self::assertIsString( $expected_raw );
		self::assertIsString( $concurrent_raw );
		$this->wpdb->put( 'a8csp_bgte_schedules', $expected_raw );
		$this->wpdb->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $concurrent_raw ): void {
				$wpdb->put( 'a8csp_bgte_schedules', $concurrent_raw );
			}
		);
		$this->wpdb->before_next( 'select', static function (): void {} );
		$this->wpdb->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'scripted post-CAS registry reread failure';
			}
		);
		$nightly             = $registry['owner-a']['nightly'];
		$nightly['next_due'] = 1_700_000_600;

		$outcome = ( new ScheduleRegistry( $this->rows ) )->update_registration( 'owner-a:nightly', $nightly );

		self::assertSame( RegistrationUpdateOutcome::Failed, $outcome );
		self::assertSame( $concurrent_raw, $this->wpdb->rows['a8csp_bgte_schedules'] ?? null );
		self::assertCount( 3, $this->wpdb->recorded_queries );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[0] );
		self::assertStringStartsWith( 'UPDATE ', $this->wpdb->recorded_queries[1] );
		self::assertStringStartsWith( 'SELECT ', $this->wpdb->recorded_queries[2] );
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
