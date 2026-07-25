<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Schedule;

use A8C\SpecialProjects\BackgroundJobsEngine\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins schedule construction, validation, and behavioral identity.
 *
 */
#[CoversClass( Schedule::class )]
#[UsesClass( Recurrence::class )]
#[UsesClass( CatchUpPolicy::class )]
final class ScheduleTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Loads the WordPress JSON seam before schedule classes are first autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/Runtime/Backends/wp-json-encode-stub.php';
	}

	// endregion.

	// region TESTS.

	/**
	 * Constructor defaults and supplied values remain directly observable.
	 *
	 * @return  void
	 */
	public function test_constructor_retains_the_complete_definition_and_defaults(): void {
		$recurrence = Recurrence::every( 300 );
		$schedule   = new Schedule( name: 'refresh_index-2', recurrence: $recurrence, job: 'refresh-index', args: array( 'site_id' => 7 ), );

		self::assertSame( 'refresh_index-2', $schedule->name );
		self::assertSame( $recurrence, $schedule->recurrence );
		self::assertSame( 'refresh-index', $schedule->job );
		self::assertSame( array( 'site_id' => 7 ), $schedule->args );
		self::assertSame( CatchUpPolicy::RunOnce, $schedule->catch_up );
		self::assertNull( $schedule->priority );
	}

	/**
	 * Construction severs caller-held references before retaining and fingerprinting arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_constructor_snapshots_referenced_arguments_before_retaining_them(): void {
		$site_id  = 7;
		$args     = array(
			'site_id' => &$site_id,
			'mirror'  => &$site_id,
		);
		$schedule = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', $args );

		$site_id             = 8;
		$retained            = $schedule->args;
		$retained['site_id'] = 9;

		$expected = array(
			'site_id' => 7,
			'mirror'  => 7,
		);
		self::assertSame( $expected, $schedule->args );
		self::assertSame( ( new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', $expected ) )->fingerprint(), $schedule->fingerprint() );
	}

	/**
	 * Resource arguments remain non-portable across the snapshot boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_constructor_rejects_resources_before_snapshotting_arguments(): void {
		$stream = \fopen( 'php://memory', 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A resource payload is required to exercise the portability boundary.
		self::assertIsResource( $stream );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Schedule "nightly" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.' );

		try {
			new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', array( 'stream' => $stream ) );
		} finally {
			\fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The test-owned resource must be released.
		}
	}

	/**
	 * Names outside the stable grammar identify the spelling correction.
	 *
	 * @param   string $name Invalid schedule name.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_names' )]
	public function test_constructor_rejects_invalid_names_with_the_fix( string $name ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		new Schedule( $name, Recurrence::every( 300 ), 'refresh-index' );
	}

	/**
	 * The shared local-name byte ceiling is inclusive at 64 bytes.
	 *
	 * @return  void
	 */
	public function test_constructor_accepts_64_name_bytes_and_rejects_65(): void {
		$accepted = new Schedule( \str_repeat( 'a', 64 ), Recurrence::every( 300 ), 'refresh-index' );

		self::assertSame( \str_repeat( 'a', 64 ), $accepted->name );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		new Schedule( \str_repeat( 'a', 65 ), Recurrence::every( 300 ), 'refresh-index' );
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Supplies names outside the complete stable-name grammar.
	 *
	 * @return  array<string, array{name: string}>
	 */
	public static function invalid_names(): array {
		return array(
			'empty'     => array( 'name' => '' ),
			'uppercase' => array( 'name' => 'RefreshIndex' ),
			'space'     => array( 'name' => 'refresh index' ),
			'period'    => array( 'name' => 'refresh.index' ),
			'non-ASCII' => array( 'name' => 'réindex' ),
		);
	}

	// endregion.

	// region TESTS.

	/**
	 * Target job names obey the same stable grammar at definition construction.
	 *
	 * @return  void
	 */
	public function test_constructor_rejects_an_invalid_target_job_name(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		new Schedule( 'nightly', Recurrence::every( 300 ), 'Refresh Index' );
	}

	/**
	 * Priorities below the supported byte range identify the accepted boundary.
	 *
	 * @return  void
	 */
	public function test_constructor_rejects_a_negative_priority_with_the_fix(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Schedule "nightly" priority -1 is invalid; pass a value from 0 through 255.' );

		new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', priority: -1 );
	}

	/**
	 * Priorities above the supported byte range identify the accepted boundary.
	 *
	 * @return  void
	 */
	public function test_constructor_rejects_a_priority_above_255_with_the_fix(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Schedule "nightly" priority 256 is invalid; pass a value from 0 through 255.' );

		new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', priority: 256 );
	}

	/**
	 * Both inclusive priority boundaries remain valid.
	 *
	 * @return  void
	 */
	public function test_constructor_accepts_both_priority_boundaries(): void {
		self::assertSame( 0, ( new Schedule( 'lowest', Recurrence::every( 1 ), 'job', priority: 0 ) )->priority );
		self::assertSame( 255, ( new Schedule( 'highest', Recurrence::every( 1 ), 'job', priority: 255 ) )->priority );
	}

	/**
	 * Schedule arguments accept the byte ceiling and reject its adjacent overflow.
	 *
	 * @return  void
	 */
	public function test_constructor_observes_the_argument_json_byte_ceiling(): void {
		$args     = array( 'payload' => \str_repeat( 'a', 8_192 - 14 ) );
		$schedule = new Schedule( 'accepted', Recurrence::every( 300 ), 'refresh-index', $args );

		self::assertSame( $args, $schedule->args );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Schedule "rejected" arguments contain 8193 JSON bytes; the limit is 8192 bytes.' );

		new Schedule( 'rejected', Recurrence::every( 300 ), 'refresh-index', array( 'payload' => \str_repeat( 'a', 8_193 - 14 ) ) );
	}

	/**
	 * Non-scalar argument leaves identify the portable representation the caller must use.
	 *
	 * @return  void
	 */
	public function test_constructor_rejects_non_scalar_arguments_with_the_fix(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Schedule "nightly" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.' );

		new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', array( new \stdClass() ) );
	}

	/**
	 * Non-finite scalar values identify the finite representation the caller must use.
	 *
	 * @return  void
	 */
	public function test_constructor_rejects_non_finite_arguments_with_the_fix(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Schedule "nightly" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.' );

		new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', array( \INF ) );
	}

	/**
	 * Identical definitions retain the same stable fingerprint.
	 *
	 * @return  void
	 */
	public function test_identical_schedules_have_the_same_fingerprint(): void {
		$first  = $this->schedule();
		$second = $this->schedule();

		self::assertSame( $first->fingerprint(), $second->fingerprint() );
	}

	/**
	 * An unspecified priority remains distinct from an explicit engine-default priority.
	 *
	 * @return  void
	 */
	public function test_unspecified_priority_fingerprints_as_json_null(): void {
		$unspecified = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$explicit    = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', priority: 10 );
		$encoded     = '{"name":"nightly","recurrence":{"kind":"every","interval":300},"job":"refresh-index","args":[],"catch_up":"run_once","priority":null}';

		self::assertNull( $unspecified->priority );
		self::assertSame( \hash( 'sha256', $encoded ), $unspecified->fingerprint() );
		self::assertNotSame( $explicit->fingerprint(), $unspecified->fingerprint() );
	}

	/**
	 * Every behavioral field contributes to the stable fingerprint.
	 *
	 * @return  void
	 */
	public function test_each_field_change_changes_the_fingerprint(): void {
		$baseline = $this->schedule();
		$changed  = array(
			new Schedule( 'nightly-2', Recurrence::every( 300 ), 'refresh-index', array( 'site_id' => 7 ) ),
			new Schedule( 'nightly', Recurrence::every( 301 ), 'refresh-index', array( 'site_id' => 7 ) ),
			new Schedule( 'nightly', Recurrence::every_anchored( 300, 1 ), 'refresh-index', array( 'site_id' => 7 ) ),
			new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index-2', array( 'site_id' => 7 ) ),
			new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', array( 'site_id' => 8 ) ),
			new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', array( 'site_id' => 7 ), catch_up: CatchUpPolicy::Skip ),
			new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', array( 'site_id' => 7 ), priority: 11 ),
		);

		foreach ( $changed as $schedule ) {
			self::assertNotSame( $baseline->fingerprint(), $schedule->fingerprint() );
		}
	}

	/**
	 * Preserved zero fractions keep integer and floating-point arguments distinct.
	 *
	 * @return  void
	 */
	public function test_fingerprint_preserves_zero_fractions(): void {
		$integer = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', array( 'value' => 1 ) );
		$float   = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', array( 'value' => 1.0 ) );

		self::assertNotSame( $integer->fingerprint(), $float->fingerprint() );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Creates the baseline definition used by fingerprint assertions.
	 *
	 * @return  Schedule
	 */
	private function schedule(): Schedule {
		return new Schedule( name: 'nightly', recurrence: Recurrence::every( 300 ), job: 'refresh-index', args: array( 'site_id' => 7 ), );
	}

	// endregion.
}
