<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Boundary;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Recurrence;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Schedule;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the internal identity value object and its storage ceiling.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( Identity::class )]
final class IdentityTest extends TestCase {
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
	 * A canonical identity wraps once and exposes its component values.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_compose_wraps_a_canonical_identity(): void {
		$identity = Identity::compose( 'client-plugin', 'daily_sync' );

		self::assertInstanceOf( \Stringable::class, $identity );
		self::assertSame( 'client-plugin:daily_sync', (string) $identity );
		self::assertSame( 'client-plugin', $identity->scope() );
		self::assertSame( 'daily_sync', $identity->name() );
	}

	/**
	 * Scope validation keeps its existing byte ceiling and diagnostic.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_compose_rejects_a_scope_over_the_byte_limit(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work scope is invalid; pass 1 to 32 bytes matching [a-z0-9][a-z0-9-]*.' );

		Identity::compose( \str_repeat( 'o', Identity::SCOPE_MAX_BYTES + 1 ), 'job' );
	}

	/**
	 * Name validation keeps its existing byte ceiling and diagnostic.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_compose_rejects_a_name_over_the_byte_limit(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		Identity::compose( 'client-plugin', \str_repeat( 'n', Identity::NAME_MAX_BYTES + 1 ) );
	}

	/**
	 * Client composition cannot claim the engine-reserved scope prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_compose_rejects_the_engine_reserved_scope_prefix_by_default(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work scope uses the engine-reserved "a8csp-bgje" prefix; use the client plugin slug.' );

		Identity::compose( Identity::ENGINE_SCOPE . '-client', 'job' );
	}

	/**
	 * Engine composition explicitly admits its reserved scope prefix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_compose_accepts_the_engine_reserved_scope_prefix_when_allowed(): void {
		$identity = Identity::compose( Identity::ENGINE_SCOPE, 'maintenance', true );

		self::assertSame( Identity::ENGINE_SCOPE . ':maintenance', (string) $identity );
		self::assertSame( Identity::ENGINE_SCOPE, $identity->scope() );
		self::assertSame( 'maintenance', $identity->name() );
	}

	/**
	 * Non-throwing construction wraps valid client and engine identities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_try_from_wraps_valid_client_and_engine_identities(): void {
		foreach ( array( 'client-plugin:daily_sync', Identity::ENGINE_SCOPE . ':maintenance' ) as $candidate ) {
			$identity = Identity::tryFrom( $candidate );

			self::assertInstanceOf( Identity::class, $identity );
			self::assertSame( $candidate, (string) $identity );
		}
	}

	/**
	 * Non-throwing construction rejects every malformed shape admitted by neither component grammar.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_try_from_returns_null_for_malformed_identities(): void {
		$malformed = array(
			'',
			'scope',
			'scope:name:extra',
			':name',
			'Scope:name',
			'-scope:name',
			'scope_plugin:name',
			\str_repeat( 'o', Identity::SCOPE_MAX_BYTES + 1 ) . ':name',
			'scope:',
			'scope:Name',
			'scope:bad.name',
			'scope:réindex',
			'scope:' . \str_repeat( 'n', Identity::NAME_MAX_BYTES + 1 ),
			\str_repeat( 'o', Identity::SCOPE_MAX_BYTES ) . ':' . \str_repeat( 'n', Identity::NAME_MAX_BYTES + 1 ),
		);

		foreach ( $malformed as $candidate ) {
			self::assertNull( Identity::tryFrom( $candidate ), '"' . $candidate . '" must not wrap.' );
		}
	}

	/**
	 * Maximum public identities keep every resulting option row inside Core's 191-character boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_maximum_identity_stays_within_the_option_name_boundary_end_to_end(): void {
		$scope         = \str_repeat( 'o', 32 );
		$job_name      = \str_repeat( 't', 64 );
		$schedule_name = \str_repeat( 's', 64 );
		$client        = $this->rig->operations( $scope );
		$client->register( ( new RecordingJob( $job_name ) )->definition() );

		$enqueued = $client->dispatch( $job_name, array( 'site_id' => 7 ) );
		$synced   = $client->sync( array( new Schedule( $schedule_name, Recurrence::every( 300 ), $job_name ) ) );

		self::assertInstanceOf( Success::class, $enqueued );
		self::assertInstanceOf( Success::class, $synced );
		self::assertSame( 97, \strlen( $scope . ':' . $job_name ) );
		self::assertSame( 97, \strlen( $scope . ':' . $schedule_name ) );
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
