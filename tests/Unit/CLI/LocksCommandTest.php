<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\CLI;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Commands\LocksCommand;
use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Output\LocksOutput;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\CliHarness;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\StoreFixtureBuilder;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises malformed-lock discovery and repair through the registered WP-CLI boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( LocksCommand::class )]
#[CoversClass( LocksOutput::class )]
final class LocksCommandTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ARGS_HASH     = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const string IDENTITY      = 'repair-tests:reports';
	private const int NOW              = 86_400;
	private const string OTHER_HASH    = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	private const string RUN_ID        = '00000000000000086400-0000000000000000001';
	private const string SECOND_RUN_ID = '00000000000000086400-0000000000000000002';

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
	 * Repair requests retain typed identities while their wire bytes stay unchanged.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_repair_request_retains_a_typed_identity(): void {
		$request = LocksCommand::request_from_args( array( 'repair', self::IDENTITY ), array( 'args-hash' => self::ARGS_HASH ) );

		self::assertSame( array( 'action', 'identity', 'args_hash' ), \array_keys( $request ) );
		self::assertSame( 'repair', $request['action'] );
		self::assertInstanceOf( Identity::class, $request['identity'] );
		self::assertSame( self::IDENTITY, (string) $request['identity'] );
		self::assertSame( self::ARGS_HASH, $request['args_hash'] );
	}

	/**
	 * JSON listing exposes owned and malformed lanes without exposing either raw value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_locks_list_shows_owned_and_malformed_lanes_with_redacted_correlation(): void {
		$malformed = 'secret-malformed-lock';
		$this->put_malformed_lock( self::ARGS_HASH, $malformed );
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
	 * Acknowledged repair fences the matching run, clears the lock, and reports redacted counts.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_locks_repair_yes_supersedes_the_run_and_skips_the_prompt(): void {
		$raw       = 'malformed-lock';
		$lock_name = $this->put_malformed_lock( self::ARGS_HASH, $raw );
		$this->put_fixture( $this->fixtures->run( self::RUN_ID, $this->running_state( self::ARGS_HASH ) ) );

		$result = CliHarness::run( 'locks', array( 'repair', self::IDENTITY ), array( 'yes' => true ) );

		self::assertSame( 0, $result->exit_code );
		self::assertSame( '', $result->stderr );
		self::assertStringContainsString( 'identity=' . self::IDENTITY . "\n", $result->stdout );
		self::assertStringContainsString( 'args_hash=' . self::ARGS_HASH . "\n", $result->stdout );
		self::assertStringContainsString( 'raw_length=' . \strlen( $raw ) . "\n", $result->stdout );
		self::assertStringContainsString( 'raw_sha256=' . \substr( \hash( 'sha256', $raw ), 0, 16 ) . "\n", $result->stdout );
		self::assertStringContainsString( "runs_superseded=1\n", $result->stdout );
		self::assertStringContainsString( "lock_cleared=true\n", $result->stdout );
		self::assertStringContainsString( 'Success: Cleared malformed execution-overlap lock', $result->stdout );
		self::assertStringNotContainsString( '[y/n]', $result->stdout );
		self::assertStringNotContainsString( $raw, $result->stdout );
		self::assertArrayNotHasKey( $lock_name, $this->rig->wpdb()->rows );
		$run       = new RunStore( self::IDENTITY, $this->rig->clock(), new OptionRows( $this->rig->wpdb() ) );
		$inspected = $run->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $inspected );
		self::assertIsArray( $inspected->value );
		$state = $inspected->value['state'] ?? null;
		self::assertInstanceOf( RunState::class, $state );
		self::assertSame( RunStatus::Superseded, $state->status );
	}

	/**
	 * No malformed lane reports an idempotent no-op before confirmation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_locks_repair_reports_nothing_to_repair_without_mutation(): void {
		$owned = $this->fixtures->lock( self::ARGS_HASH, self::RUN_ID, self::NOW, self::NOW );
		$this->put_fixture( $owned );

		$result = CliHarness::run( 'locks', array( 'repair', self::IDENTITY ) );

		self::assertSame( 0, $result->exit_code );
		self::assertSame( 'Nothing to repair for identity "' . self::IDENTITY . '".' . "\n", $result->stdout );
		self::assertSame( '', $result->stderr );
		self::assertSame( $owned[1], $this->rig->wpdb()->rows[ $owned[0] ] ?? null );
	}

	/**
	 * Ambiguous malformed lanes are listed and require an explicit hash without mutation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_locks_repair_lists_ambiguous_lanes_and_requires_an_argument_hash(): void {
		$first  = $this->put_malformed_lock( self::ARGS_HASH, 'malformed-first' );
		$second = $this->put_malformed_lock( self::OTHER_HASH, 'malformed-second' );

		$result = CliHarness::run( 'locks', array( 'repair', self::IDENTITY ) );

		self::assertSame( 1, $result->exit_code );
		self::assertStringContainsString( self::ARGS_HASH, $result->stdout );
		self::assertStringContainsString( self::OTHER_HASH, $result->stdout );
		self::assertStringNotContainsString( 'malformed-first', $result->stdout );
		self::assertStringNotContainsString( 'malformed-second', $result->stdout );
		self::assertSame( 'Error: Multiple malformed execution-overlap lock lanes exist for "' . self::IDENTITY . '"; re-run with --args-hash=<one shown>.' . "\n", $result->stderr );
		self::assertSame( 'malformed-first', $this->rig->wpdb()->rows[ $first ] ?? null );
		self::assertSame( 'malformed-second', $this->rig->wpdb()->rows[ $second ] ?? null );
	}

	/**
	 * Explicit selection repairs only the selected malformed lane.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_locks_repair_with_an_argument_hash_repairs_exactly_that_lane(): void {
		$first  = $this->put_malformed_lock( self::ARGS_HASH, 'malformed-first' );
		$second = $this->put_malformed_lock( self::OTHER_HASH, 'malformed-second' );

		$result = CliHarness::run(
			'locks',
			array( 'repair', self::IDENTITY ),
			array(
				'args-hash' => self::OTHER_HASH,
				'yes'       => true,
			)
		);

		self::assertSame( 0, $result->exit_code );
		self::assertSame( '', $result->stderr );
		self::assertStringContainsString( 'args_hash=' . self::OTHER_HASH, $result->stdout );
		self::assertSame( 'malformed-first', $this->rig->wpdb()->rows[ $first ] ?? null );
		self::assertArrayNotHasKey( $second, $this->rig->wpdb()->rows );
	}

	/**
	 * Interactive repair invokes the standard confirmation seam before any mutation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_locks_repair_without_yes_invokes_confirmation_before_mutation(): void {
		$result = CliHarness::run_interactive( 'locks-repair-declined', "n\n" );
		$probe  = \json_decode( $result->probe, true, 512, \JSON_THROW_ON_ERROR );

		self::assertSame( 0, $result->exit_code );
		self::assertSame( 'Repair identity "' . self::IDENTITY . '" lane "' . self::ARGS_HASH . '": supersede 1 Running run row, then delete the malformed execution-overlap lock. Continue? [y/n] ', $result->stdout );
		self::assertSame( '', $result->stderr );
		self::assertIsArray( $probe );
		self::assertSame( $probe['before'] ?? null, $probe['after'] ?? null );
	}

	/**
	 * A later run-CAS loss reports partial safe progress without clearing the malformed lock.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_locks_repair_reports_partial_failure_without_clearing_the_lock(): void {
		$raw       = 'malformed-lock-secret';
		$lock_name = $this->put_malformed_lock( self::ARGS_HASH, $raw );
		$this->put_fixture( $this->fixtures->run( self::RUN_ID, $this->running_state( self::ARGS_HASH ) ) );
		$this->put_fixture( $this->fixtures->run( self::SECOND_RUN_ID, $this->running_state( self::ARGS_HASH ) ) );
		$winner = $this->fixtures->run( self::SECOND_RUN_ID, $this->running_state( self::ARGS_HASH )->with_heartbeat_at( self::NOW + 1 ) );
		$this->rig->wpdb()->before_next( 'update', static function (): void {} );
		$this->rig->wpdb()->before_next(
			'update',
			static function ( WpdbLockSpy $wpdb ) use ( $winner ): void {
				$wpdb->put( $winner[0], $winner[1] );
			}
		);

		$result = CliHarness::run(
			'locks',
			array( 'repair', self::IDENTITY ),
			array(
				'args-hash' => self::ARGS_HASH,
				'yes'       => true,
			)
		);

		self::assertSame( 1, $result->exit_code );
		self::assertStringContainsString( 'raw_length=' . \strlen( $raw ) . "\n", $result->stdout );
		self::assertStringContainsString( 'raw_sha256=' . \substr( \hash( 'sha256', $raw ), 0, 16 ) . "\n", $result->stdout );
		self::assertStringContainsString( "runs_superseded=1\n", $result->stdout );
		self::assertStringContainsString( "lock_cleared=false\n", $result->stdout );
		self::assertSame( 'Error: Active-run row "' . self::SECOND_RUN_ID . '" changed during lock repair; re-run locks repair against fresh snapshots.' . "\n", $result->stderr );
		self::assertStringNotContainsString( $raw, $result->stdout . $result->stderr );
		self::assertSame( $raw, $this->rig->wpdb()->rows[ $lock_name ] ?? null );
		self::assertSame( $winner[1], $this->rig->wpdb()->rows[ $winner[0] ] ?? null );

		$first = ( new RunStore( self::IDENTITY, $this->rig->clock(), new OptionRows( $this->rig->wpdb() ) ) )->inspect( self::RUN_ID );
		self::assertInstanceOf( Success::class, $first );
		self::assertIsArray( $first->value );
		$first_state = $first->value['state'] ?? null;
		self::assertInstanceOf( RunState::class, $first_state );
		self::assertSame( RunStatus::Superseded, $first_state->status );
	}

	/**
	 * A valid selector cannot make an absent or healthy lane repairable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   bool $persist_owned Whether the selected lane exists with a valid owner.
	 *
	 * @return  void
	 */
	#[DataProvider( 'unrepairable_explicit_lanes' )]
	public function test_locks_repair_rejects_an_explicit_unrepairable_lane_without_writing( bool $persist_owned ): void {
		if ( $persist_owned ) {
			$this->put_fixture( $this->fixtures->lock( self::ARGS_HASH, self::RUN_ID, self::NOW, self::NOW ) );
		}
		$before                              = $this->rig->wpdb()->rows;
		$this->rig->wpdb()->recorded_queries = array();

		$result = CliHarness::run(
			'locks',
			array( 'repair', self::IDENTITY ),
			array(
				'args-hash' => self::ARGS_HASH,
				'yes'       => true,
			)
		);

		self::assertSame( 1, $result->exit_code );
		self::assertSame( '', $result->stdout );
		self::assertSame( 'Error: Execution-overlap lock lane "' . self::IDENTITY . '" / "' . self::ARGS_HASH . '" is absent or is not malformed; run locks list and select a malformed lane.' . "\n", $result->stderr );
		self::assertSame( $before, $this->rig->wpdb()->rows );
		foreach ( $this->rig->wpdb()->recorded_queries as $query ) {
			self::assertFalse( \str_starts_with( $query, 'UPDATE ' ) || \str_starts_with( $query, 'DELETE ' ), 'Repair selection must remain read-only before a malformed lane is selected.' );
		}
	}

	/**
	 * Every invalid lock command shape fails before storage mutation.
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
	 * Stores one malformed lock and returns its option name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $args_hash Lane argument hash.
	 * @param   string $raw       Malformed raw value.
	 *
	 * @return  string
	 */
	private function put_malformed_lock( string $args_hash, string $raw ): string {
		$option_name = OverlapGuard::OPTION_PREFIX . self::IDENTITY . '_' . $args_hash;
		$this->rig->wpdb()->put( $option_name, $raw );

		return $option_name;
	}

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

	/**
	 * Returns one Running state for the selected lane.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $args_hash Lane argument hash.
	 *
	 * @return  RunState
	 */
	private function running_state( string $args_hash ): RunState {
		return new RunState( status: RunStatus::Running, kind: 'job', executing: false, start_args: array(), args_hash: $args_hash, kind_state: array(), failed_attempts: 0, action_sequence: 1, created_at: self::NOW, heartbeat_at: self::NOW, pending: PendingAction::async( 'run', 10 ) );
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
		$list_usage   = 'Lock list accepts only --format; use wp a8csp-bgje locks list [--format=<table|json|csv|yaml>].';
		$repair_usage = 'Lock repair requires exactly one identity and accepts only --args-hash and --yes; use wp a8csp-bgje locks repair <identity> [--args-hash=<hash>] [--yes].';

		return array(
			'missing action'          => array(
				'args'       => array(),
				'assoc_args' => array(),
				'message'    => 'A lock action is required; use list or repair <identity>.',
			),
			'unknown action'          => array(
				'args'       => array( 'show' ),
				'assoc_args' => array(),
				'message'    => 'Lock action "show" is invalid; use list or repair.',
			),
			'list extra argument'     => array(
				'args'       => array( 'list', self::IDENTITY ),
				'assoc_args' => array(),
				'message'    => $list_usage,
			),
			'list yes'                => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'yes' => true ),
				'message'    => $list_usage,
			),
			'list count'              => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'count' ),
				'message'    => 'Lock list format is invalid; use table, json, csv, or yaml.',
			),
			'list negated format'     => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => false ),
				'message'    => 'Lock list format is invalid; use table, json, csv, or yaml.',
			),
			'repair missing identity' => array(
				'args'       => array( 'repair' ),
				'assoc_args' => array(),
				'message'    => $repair_usage,
			),
			'repair extra identity'   => array(
				'args'       => array( 'repair', self::IDENTITY, 'other:job' ),
				'assoc_args' => array(),
				'message'    => $repair_usage,
			),
			'repair format'           => array(
				'args'       => array( 'repair', self::IDENTITY ),
				'assoc_args' => array( 'format' => 'json' ),
				'message'    => $repair_usage,
			),
			'repair invalid identity' => array(
				'args'       => array( 'repair', 'reports' ),
				'assoc_args' => array(),
				'message'    => 'Lock repair identity is invalid; use a composed {owner}:{name} identity.',
			),
			'repair short hash'       => array(
				'args'       => array( 'repair', self::IDENTITY ),
				'assoc_args' => array( 'args-hash' => 'abc' ),
				'message'    => 'Lock repair argument hash is invalid; copy one lowercase SHA-256 hash from locks list.',
			),
			'repair uppercase hash'   => array(
				'args'       => array( 'repair', self::IDENTITY ),
				'assoc_args' => array( 'args-hash' => \str_repeat( 'A', 64 ) ),
				'message'    => 'Lock repair argument hash is invalid; copy one lowercase SHA-256 hash from locks list.',
			),
			'repair negated hash'     => array(
				'args'       => array( 'repair', self::IDENTITY ),
				'assoc_args' => array( 'args-hash' => false ),
				'message'    => 'Lock repair argument hash is invalid; copy one lowercase SHA-256 hash from locks list.',
			),
			'repair string yes'       => array(
				'args'       => array( 'repair', self::IDENTITY ),
				'assoc_args' => array( 'yes' => 'yes' ),
				'message'    => $repair_usage,
			),
		);
	}

	/**
	 * Supplies absent and healthy explicit selector states.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{persist_owned: bool}>
	 */
	public static function unrepairable_explicit_lanes(): array {
		return array(
			'absent lane' => array(
				'persist_owned' => false,
			),
			'owned lane'  => array(
				'persist_owned' => true,
			),
		);
	}

	// endregion.
}
