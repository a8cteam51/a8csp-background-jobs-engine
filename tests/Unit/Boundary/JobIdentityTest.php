<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Boundary;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the identity storage ceiling through owner-bound public facades.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( JobIdentity::class )]
final class JobIdentityTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const int NOW = 1_700_000_000;

	private EngineRig $rig;

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
	 * Boots one deterministic production graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig = EngineRig::set_up( self::NOW );
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
	 * Maximum public identities keep every resulting option row inside Core's 191-character boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_maximum_identity_stays_within_the_option_name_boundary_end_to_end(): void {
		$owner         = \str_repeat( 'o', 32 );
		$job_name      = \str_repeat( 't', 64 );
		$schedule_name = \str_repeat( 's', 64 );
		$client        = $this->rig->operations( $owner );
		$client->register( ( new RecordingJob( $job_name ) )->definition() );

		$enqueued = $client->enqueue( $job_name, array( 'site_id' => 7 ) );
		$synced   = $client->sync( array( new Schedule( $schedule_name, Recurrence::every( 300 ), $job_name ) ) );

		self::assertInstanceOf( Success::class, $enqueued );
		self::assertInstanceOf( Success::class, $synced );
		self::assertSame( 97, \strlen( $owner . ':' . $job_name ) );
		self::assertSame( 97, \strlen( $owner . ':' . $schedule_name ) );
		self::assertNotEmpty( $this->rig->wpdb()->rows );
		$longest = '';
		foreach ( \array_keys( $this->rig->wpdb()->rows ) as $option_name ) {
			self::assertLessThanOrEqual( 191, \strlen( $option_name ), $option_name . ' exceeds option_name' );
			$longest = \strlen( $option_name ) > \strlen( $longest ) ? $option_name : $longest;
		}
		self::assertStringStartsWith( OverlapGuard::OPTION_PREFIX, $longest );
	}

	// endregion.
}
