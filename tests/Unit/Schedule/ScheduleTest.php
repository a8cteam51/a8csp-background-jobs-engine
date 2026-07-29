<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Schedule;

use A8C\SpecialProjects\BackgroundJobsEngine\CatchUpPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
use PHPUnit\Framework\Attributes\CoversClass;
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
		$this->expectExceptionMessageIs( 'Schedule "nightly" definition must be JSON-encodable; use valid UTF-8 in the name, target job, and arguments, and finite numbers in the arguments.' );

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
	 * Consumer delivery priority does not alter the engine-owned recurring chain identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_priority_does_not_change_the_schedule_fingerprint(): void {
		$unspecified = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index' );
		$explicit    = new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', priority: 10 );
		$encoded     = '{"name":"nightly","recurrence":{"kind":"every","interval":300},"job":"refresh-index","args":[],"catch_up":"run_once"}';

		self::assertNull( $unspecified->priority );
		self::assertSame( \hash( 'sha256', $encoded ), $unspecified->fingerprint() );
		self::assertSame( $explicit->fingerprint(), $unspecified->fingerprint() );
	}

	/**
	 * Every recurring-chain field contributes to the stable fingerprint.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_each_recurring_chain_field_change_changes_the_fingerprint(): void {
		$baseline = $this->schedule();
		$changed  = array(
			new Schedule( 'nightly-2', Recurrence::every( 300 ), 'refresh-index', array( 'site_id' => 7 ) ),
			new Schedule( 'nightly', Recurrence::every( 301 ), 'refresh-index', array( 'site_id' => 7 ) ),
			new Schedule( 'nightly', Recurrence::every_anchored( 300, 1 ), 'refresh-index', array( 'site_id' => 7 ) ),
			new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index-2', array( 'site_id' => 7 ) ),
			new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', array( 'site_id' => 8 ) ),
			new Schedule( 'nightly', Recurrence::every( 300 ), 'refresh-index', array( 'site_id' => 7 ), catch_up: CatchUpPolicy::Skip ),
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

	/**
	 * The documented maximum argument depth is accepted, and one level beyond it is refused.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_arguments_accept_the_documented_maximum_depth(): void {
		$accepted = new Schedule( name: 'nightly', recurrence: Recurrence::every( 300 ), job: 'refresh-index', args: array( 'tree' => self::nested( 511 ) ), );

		self::assertNotSame( '', $accepted->fingerprint() );

		$this->expectException( \InvalidArgumentException::class );

		new Schedule( name: 'nightly', recurrence: Recurrence::every( 300 ), job: 'refresh-index', args: array( 'tree' => self::nested( 512 ) ), );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Builds an argument subtree of the requested array depth.
	 *
	 * @param   int $levels Array levels to build.
	 *
	 * @return  array<array-key, mixed>
	 */
	private static function nested( int $levels ): array {
		$tree = array( 'leaf' );
		for ( $index = 1; $index < $levels; $index++ ) {
			$tree = array( $tree );
		}

		return $tree;
	}

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
