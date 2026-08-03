<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\CLI;

use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands\LocksCommand;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\LocksOutput;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\CliHarness;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises persisted-lock inspection through the registered WP-CLI boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( LocksCommand::class )]
#[CoversClass( LocksOutput::class )]
final class LocksCommandTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ARGS_HASH  = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const string IDENTITY   = 'inspection-tests:reports';
	private const int NOW           = 86_400;
	private const string OTHER_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	private const string RUN_ID     = '00000000000000086400-0000000000000000001';

	private StoreFixtureBuilder $fixtures;
	private EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads the production command dispatcher and engine seams.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
		require_once \dirname( __DIR__, 2 ) . '/Support/WpCliRuntimeStub.php';
	}

	/**
	 * Boots one request-local graph beneath the registered command tree.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig      = EngineRig::set_up( self::NOW );
		$this->fixtures = StoreFixtureBuilder::for_identity( self::IDENTITY );
		CliHarness::set_up();
	}

	/**
	 * Releases request-local engine state after each command scenario.
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
	 * Lock listing exposes operational states and only redacted malformed correlation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_locks_list_shows_owned_and_malformed_lanes_with_redacted_correlation(): void {
		$malformed = 'secret-malformed-lock';
		$this->rig->wpdb()->put( OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . self::ARGS_HASH, $malformed );
		$this->put_fixture( $this->fixtures->lock( self::OTHER_HASH, self::RUN_ID, self::NOW, self::NOW ) );

		$result = CliHarness::run( 'locks', array( 'list' ), array( 'format' => 'json' ) );
		$rows   = \json_decode( $result->stdout, true, 512, \JSON_THROW_ON_ERROR );

		self::assertSame( 0, $result->exit_code );
		self::assertSame( '', $result->stderr );
		self::assertIsArray( $rows );
		self::assertCount( 2, $rows );
		$malformed_row = $rows[0] ?? null;
		$owned_row     = $rows[1] ?? null;
		self::assertIsArray( $malformed_row );
		self::assertIsArray( $owned_row );
		self::assertSame( array( 'identity', 'args_hash', 'state', 'raw_length', 'raw_sha256' ), \array_keys( $malformed_row ) );
		self::assertSame( 'malformed', $malformed_row['state'] ?? null );
		self::assertSame( \strlen( $malformed ), $malformed_row['raw_length'] ?? null );
		self::assertSame( \substr( \hash( 'sha256', $malformed ), 0, 16 ), $malformed_row['raw_sha256'] ?? null );
		self::assertSame( 'owned', $owned_row['state'] ?? null );
		self::assertNull( $owned_row['raw_length'] ?? null );
		self::assertNull( $owned_row['raw_sha256'] ?? null );
		self::assertStringNotContainsString( $malformed, $result->stdout );
	}

	/**
	 * CSV listing uses the same fixed public columns.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_locks_list_csv_uses_the_documented_columns(): void {
		$result = CliHarness::run_csv( 'locks' );

		self::assertSame( 0, $result->exit_code );
		self::assertSame( '', $result->stderr );
		self::assertSame( 'identity,args_hash,state,raw_length,raw_sha256', \strtok( $result->stdout, "\n" ) );
	}

	/**
	 * Lock listing reports an unavailable inspection service before consulting storage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_locks_list_reports_an_unavailable_inspection_service(): void {
		$property   = new \ReflectionProperty( Component::class, 'lock_inspection' );
		$inspection = $property->getValue();
		$property->setValue( null, null );
		try {
			$result = CliHarness::run( 'locks', array( 'list' ) );
		} finally {
			$property->setValue( null, $inspection );
		}

		self::assertSame( 1, $result->exit_code );
		self::assertSame( '', $result->stdout );
		self::assertSame( "Error: The background jobs lock-inspection service is unavailable; run the command after plugins_loaded.\n", $result->stderr );
	}

	/**
	 * Lock listing reports an authoritative lane-enumeration failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_locks_list_reports_an_inspection_failure(): void {
		$this->rig->wpdb()->before_next(
			'scan',
			static function ( WpdbLockSpy $wpdb ): void {
				$wpdb->last_error = 'lock enumeration failed';
			}
		);

		$result = CliHarness::run( 'locks', array( 'list' ) );

		self::assertSame( 1, $result->exit_code );
		self::assertSame( '', $result->stdout );
		self::assertSame( "Error: Authoritative option-name read failed; repair WordPress option reads and retry.\n", $result->stderr );
	}

	/**
	 * Every unsupported lock command shape fails before storage mutation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<string>         $args       Positional arguments.
	 * @param   array<string, mixed> $assoc_args Named arguments.
	 * @param   string               $message    Corrective error.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_lock_requests' )]
	public function test_locks_command_rejects_every_invalid_form( array $args, array $assoc_args, string $message ): void {
		$result = CliHarness::run( 'locks', $args, $assoc_args );

		self::assertSame( 1, $result->exit_code );
		self::assertSame( '', $result->stdout );
		self::assertSame( 'Error: ' . $message . "\n", $result->stderr );
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

	// region DATA PROVIDERS.

	/**
	 * Supplies every rejected lock request shape.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{args: list<string>, assoc_args: array<string, mixed>, message: string}>
	 */
	public static function invalid_lock_requests(): array {
		$list_usage = 'Lock list accepts only --format; use wp a8csp-bgje locks list [--format=<table|json|csv|yaml>].';

		return array(
			'missing action'      => array(
				'args'       => array(),
				'assoc_args' => array(),
				'message'    => 'A lock action is required; use list.',
			),
			'invalid repair verb' => array(
				'args'       => array( 'repair', self::IDENTITY ),
				'assoc_args' => array(),
				'message'    => 'Lock action "repair" is invalid; use list.',
			),
			'unknown action'      => array(
				'args'       => array( 'show' ),
				'assoc_args' => array(),
				'message'    => 'Lock action "show" is invalid; use list.',
			),
			'list extra argument' => array(
				'args'       => array( 'list', self::IDENTITY ),
				'assoc_args' => array(),
				'message'    => $list_usage,
			),
			'list yes'            => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'yes' => true ),
				'message'    => $list_usage,
			),
			'list count'          => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'count' ),
				'message'    => 'Lock list format is invalid; use table, json, csv, or yaml.',
			),
			'list negated format' => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => false ),
				'message'    => 'Lock list format is invalid; use table, json, csv, or yaml.',
			),
		);
	}

	// endregion.
}
