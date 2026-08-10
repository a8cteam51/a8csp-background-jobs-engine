<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Locks;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\EngineError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockInspection;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\LockWindows;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins lock inspection to authoritative snapshots and redacted malformed correlation.
 *
 * @load-bearing concurrency
 * @pin-rationale Inspection must classify the selected lock generation without exposing malformed persisted bytes.
 * @fixture StoreFixtureBuilder
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( LockInspection::class )]
#[UsesClass( LockWindows::class )]
#[UsesClass( OverlapGuard::class )]
#[UsesClass( OptionRows::class )]
final class LockInspectionTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ARGS_HASH  = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const string IDENTITY   = 'inspection-tests:reports';
	private const int NOW           = 1_700_000_000;
	private const string OTHER_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	private const string THIRD_HASH = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
	private const string RUN_ID     = '00000000001700000000-0000000000000000042';

	private StoreFixtureBuilder $fixtures;
	private LockInspection $inspection;
	private EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded production files before collaborators are built.
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
	 * Builds one deterministic inspection graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig        = EngineRig::set_up( self::NOW );
		$rows             = new OptionRows( $this->rig->wpdb() );
		$lock_windows     = new LockWindows( $this->rig->clock(), $this->rig->logger() );
		$guard            = new OverlapGuard( $this->rig->clock(), $this->rig->logger(), $rows, $lock_windows );
		$this->inspection = new LockInspection( $rows, $guard, $lock_windows );
		$this->fixtures   = StoreFixtureBuilder::for_identity( self::IDENTITY );
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
	 * Inspection classifies owned, stale, and malformed lanes without exposing malformed bytes.
	 *
	 * @return  void
	 */
	public function test_inspect_lanes_classifies_every_persisted_generation(): void {
		$this->put_fixture( $this->fixtures->lock( self::ARGS_HASH, self::RUN_ID, self::NOW, self::NOW ) );
		$this->put_fixture( $this->fixtures->lock( self::OTHER_HASH, self::RUN_ID, self::NOW - 901, self::NOW - 901 ) );
		$malformed = 'secret-malformed-lock';
		$this->rig->wpdb()->put( OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . self::THIRD_HASH, $malformed );

		$inspected = $this->inspection->inspect_lanes();

		self::assertInstanceOf( Success::class, $inspected );
		self::assertSame(
			array(
				array(
					'identity'   => self::IDENTITY,
					'args_hash'  => self::ARGS_HASH,
					'state'      => 'owned',
					'raw_length' => null,
					'raw_sha256' => null,
				),
				array(
					'identity'   => self::IDENTITY,
					'args_hash'  => self::OTHER_HASH,
					'state'      => 'stale',
					'raw_length' => null,
					'raw_sha256' => null,
				),
				array(
					'identity'   => self::IDENTITY,
					'args_hash'  => self::THIRD_HASH,
					'state'      => 'malformed',
					'raw_length' => \strlen( $malformed ),
					'raw_sha256' => \substr( \hash( 'sha256', $malformed ), 0, 16 ),
				),
			),
			$inspected->value
		);
	}

	/**
	 * Inspection reports the replacement selected after lane enumeration.
	 *
	 * @return  void
	 */
	public function test_inspect_lanes_classifies_a_concurrent_replacement_generation(): void {
		$option_name = OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . self::ARGS_HASH;
		$this->rig->wpdb()->put( $option_name, 'malformed-lock' );
		[ $replacement_name, $replacement ] = $this->fixtures->lock( self::ARGS_HASH, self::RUN_ID, self::NOW, self::NOW );
		self::assertSame( $option_name, $replacement_name );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( $option_name, $replacement ): void {
				$wpdb->put( $option_name, $replacement );
			}
		);

		$inspected = $this->inspection->inspect_lanes();

		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		$lanes = $inspected->value;
		self::assertCount( 1, $lanes );
		self::assertIsArray( $lanes[0] ?? null );
		self::assertSame( 'owned', $lanes[0]['state'] ?? null );
		self::assertNull( $lanes[0]['raw_length'] ?? null );
		self::assertNull( $lanes[0]['raw_sha256'] ?? null );
	}

	/**
	 * An authoritative enumeration failure is returned to the command boundary.
	 *
	 * @return  void
	 */
	public function test_inspect_lanes_propagates_an_enumeration_failure(): void {
		$this->rig->wpdb()->before_next(
			'scan',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'lock enumeration failed';
			}
		);

		$inspected = $this->inspection->inspect_lanes();

		self::assertInstanceOf( Failure::class, $inspected );
		self::assertInstanceOf( EngineError::class, $inspected->error );
		self::assertStringContainsString( 'option-name read failed', $inspected->error->message );
	}

	/**
	 * Names outside the canonical identity-and-hash shape are ignored.
	 *
	 * @return  void
	 */
	public function test_inspect_lanes_skips_a_noncanonical_option_name(): void {
		$this->rig->wpdb()->put( OverlapGuard::OPTION_PREFIX . 'invalid', 'malformed-lock' );

		$inspected = $this->inspection->inspect_lanes();

		self::assertInstanceOf( Success::class, $inspected );
		self::assertSame( array(), $inspected->value );
	}

	/**
	 * A point-read failure after enumeration remains an inspection failure.
	 *
	 * @return  void
	 */
	public function test_inspect_lanes_propagates_a_point_read_failure(): void {
		$this->put_fixture( $this->fixtures->lock( self::ARGS_HASH, self::RUN_ID, self::NOW, self::NOW ) );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'lock read failed';
			}
		);

		$inspected = $this->inspection->inspect_lanes();

		self::assertInstanceOf( Failure::class, $inspected );
		self::assertInstanceOf( EngineError::class, $inspected->error );
		self::assertStringContainsString( 'option-row read failed', $inspected->error->message );
	}

	/**
	 * A lane deleted after enumeration is absent from the returned snapshot.
	 *
	 * @return  void
	 */
	public function test_inspect_lanes_skips_a_concurrently_deleted_lane(): void {
		[ $option_name, $raw ] = $this->fixtures->lock( self::ARGS_HASH, self::RUN_ID, self::NOW, self::NOW );
		$this->rig->wpdb()->put( $option_name, $raw );
		$this->rig->wpdb()->before_next(
			'select',
			static function ( WpdbLockSpy $wpdb ) use ( $option_name ): void {
				unset( $wpdb->rows[ $option_name ], $wpdb->autoload[ $option_name ] );
			}
		);

		$inspected = $this->inspection->inspect_lanes();

		self::assertInstanceOf( Success::class, $inspected );
		self::assertSame( array(), $inspected->value );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Stores one complete option fixture.
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

	// endregion.
}
