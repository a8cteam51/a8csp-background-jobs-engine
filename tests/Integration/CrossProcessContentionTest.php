<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Identity;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\ErrorCode;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\ContentionBarrier;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;

/**
 * Verifies lane admission when two operating-system processes contend for one lane.
 *
 * Every other interleaving in the suite is scripted inside a single process, where a hook
 * callback runs to completion and one actor can never be held mid-flight while another
 * advances. These contenders are real requests against the same database, so the outcome
 * is decided by the engine's compare-and-swap writes rather than by a fake's call order.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class CrossProcessContentionTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Client scope isolated to cross-process contention coverage. */
	private const string SCOPE = 'integration-contention';

	/** Job identity isolated to held-lane rejection. */
	private const string REJECT_NAME = 'integration-contention-reject';

	/** Job identity isolated to cross-process takeover. */
	private const string REPLACE_NAME = 'integration-contention-replace';

	/** Job identity isolated to the parked contender whose incumbent completes underneath it. */
	private const string PARKED_NAME = 'integration-contention-parked';

	/** Job identity isolated to the parked contender whose incumbent stays put. */
	private const string HELD_NAME = 'integration-contention-held';

	/** Job identity isolated to the contender parked between the takeover's two writes. */
	private const string WINDOW_NAME = 'integration-contention-window';

	/** WordPress root inside the integration environment. */
	private const string WP_PATH = '/var/www/html';

	/** Test-only WP-CLI script that dispatches one job from its own process. */
	private const string WORKER = self::WP_PATH . '/wp-content/plugins/a8csp-background-jobs-engine/tests/Support/Fixtures/cli-contention-dispatch.php';

	/** Park mode: the contender runs straight through. */
	private const string PARK_NONE = '';

	/** Park mode: the lock-staleness filter holds the contender inside contended admission. */
	private const string PARK_ADMISSION = 'admission';

	/** Park mode: the database drop-in holds the contender between the takeover's two writes. */
	private const string PARK_TAKEOVER = 'takeover';

	/** Line prefix the worker writes its verdict behind. */
	private const string REPORT_SENTINEL = 'A8CSP_BGJE_CONTENTION_RESULT:';

	/** Upper bound on runner drives while settling a lane, so a stuck queue fails instead of hanging. */
	private const int MAX_RUNNER_DRIVES = 10;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Declares the registry row a contending request writes when it boots the engine.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->expect_option( ScheduleRegistry::OPTION_PREFIX . Identity::ENGINE_SCOPE );
	}

	// endregion.

	// region TESTS.

	/**
	 * A live incumbent's lane rejects a second process, which executes nothing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_second_process_cannot_admit_a_run_while_a_live_incumbent_holds_the_lane(): void {
		$job = $this->register( self::REJECT_NAME, OverlapPolicy::Reject );
		$this->expect_option( LatestRunPointer::OPTION_PREFIX . self::identity( self::REJECT_NAME ) );

		$incumbent = $this->dispatch_here( self::REJECT_NAME );
		$contender = $this->dispatch_from_another_process( self::REJECT_NAME, OverlapPolicy::Reject );

		self::assertSame( 'failure', $contender['outcome'] ?? null, 'A held lane must refuse a second process' );
		self::assertSame( ErrorCode::OverlapHeld->value, $contender['code'] ?? null, 'A held lane must refuse with overlap_held' );
		self::assertSame( $incumbent, $this->lock_owner( self::REJECT_NAME ), 'A rejected contender must leave the incumbent holding the lock' );
		self::assertSame( array( $incumbent ), $this->live_run_ids( self::REJECT_NAME ), 'A rejected contender must not add a run to the lane' );

		self::assertSame( 1, $this->run_next_engine_action(), 'The incumbent delivery must still execute after the rejection' );
		self::assertSame( array( array() ), $job->calls, 'The lane must execute exactly once across both processes' );
		self::assertSame( array(), $this->live_run_ids( self::REJECT_NAME ), 'A completed incumbent must leave no live run' );
		self::assertNull( $this->lock_owner( self::REJECT_NAME ), 'A completed incumbent must release its lock' );
	}

	/**
	 * A second process takes a lane from a live incumbent, whose delivery then executes nothing.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_second_process_takes_the_lane_from_a_live_incumbent_under_replace(): void {
		$job = $this->register( self::REPLACE_NAME, OverlapPolicy::Replace );
		$this->expect_option( LatestRunPointer::OPTION_PREFIX . self::identity( self::REPLACE_NAME ) );

		$incumbent = $this->dispatch_here( self::REPLACE_NAME );
		$contender = $this->dispatch_from_another_process( self::REPLACE_NAME, OverlapPolicy::Replace );

		self::assertSame( 'success', $contender['outcome'] ?? null, 'A replace lane must admit a second process' );
		$replacement = $contender['run_id'] ?? null;
		self::assertIsString( $replacement );
		self::assertNotSame( $incumbent, $replacement, 'A takeover must admit its own run' );
		self::assertSame( $replacement, $this->lock_owner( self::REPLACE_NAME ), 'A completed takeover must own the lane lock' );
		self::assertSame( array( $replacement ), $this->live_run_ids( self::REPLACE_NAME ), 'A completed takeover must leave exactly one live run' );

		// Both processes queued a delivery. Only the run that still owns the lane may execute.
		$this->settle_lane();
		self::assertSame( array( array() ), $job->calls, 'A superseded incumbent delivery must not execute after a cross-process takeover' );
		self::assertSame( array(), $this->live_run_ids( self::REPLACE_NAME ), 'A settled lane must leave no live run' );
		self::assertNull( $this->lock_owner( self::REPLACE_NAME ), 'A settled lane must hold no lock' );
	}

	/**
	 * A contender parked inside contended admission cannot strand a lane its incumbent has left.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_contender_parked_in_contended_admission_is_readmitted_onto_the_lane_its_incumbent_left(): void {
		$job = $this->register( self::PARKED_NAME, OverlapPolicy::Replace );
		$this->expect_option( LatestRunPointer::OPTION_PREFIX . self::identity( self::PARKED_NAME ) );

		$incumbent = $this->dispatch_here( self::PARKED_NAME );

		$contender = $this->dispatch_from_another_process(
			self::PARKED_NAME,
			OverlapPolicy::Replace,
			self::PARK_ADMISSION,
			function () use ( $incumbent, $job ): void {
				// Proving the park point here is what makes the rest of this test evidence about the
				// engine: a contender that had already written would make any later verdict vacuous.
				self::assertSame( $incumbent, $this->lock_owner( self::PARKED_NAME ), 'A parked contender must not yet have touched the lane lock' );
				self::assertSame( array( $incumbent ), $this->live_run_ids( self::PARKED_NAME ), 'A parked contender must not yet have written a run row' );
				self::assertSame( array( $incumbent => 'running' ), $this->run_row_statuses( self::PARKED_NAME ), 'A parked contender must not yet have superseded the incumbent' );

				// The incumbent finishes normally, so the parked contender resumes holding a snapshot
				// of a lane that no longer exists.
				self::assertSame( 1, $this->run_next_engine_action(), 'The incumbent delivery must execute while the contender is parked' );
				self::assertSame( array( array() ), $job->calls, 'The incumbent must execute exactly once' );
				self::assertSame( array(), $this->run_row_statuses( self::PARKED_NAME ), 'A completed incumbent must leave no run row behind' );
				self::assertNull( $this->lock_owner( self::PARKED_NAME ), 'A completed incumbent must release the lane before the contender resumes' );
			}
		);

		// The lane is idle by the time the contender resumes: the incumbent completed, and its lock and
		// run row are both gone. Its first attempt therefore compares against bytes no row carries and
		// loses, and the attempt that follows finds an unheld lane and admits. Nothing is dropped.
		self::assertSame( 'success', $contender['outcome'] ?? null, 'A contender resuming onto a freed lane must be re-admitted rather than told the lane is held' );
		self::assertIsString( $contender['run_id'] ?? null );
		self::assertNotSame( $incumbent, $contender['run_id'], 'A re-admitted contender must carry its own run' );

		$this->settle_lane();

		self::assertSame( array(), $this->live_run_ids( self::PARKED_NAME ), 'A settled lane must leave no live run' );
		self::assertNull( $this->lock_owner( self::PARKED_NAME ), 'A settled lane must hold no lock' );
		self::assertSame( array(), $this->run_row_statuses( self::PARKED_NAME ), 'A settled lane must leave no run row' );
		self::assertSame( array( array(), array() ), $job->calls, 'Both the incumbent and the re-admitted contender must execute, once each' );
	}

	/**
	 * A contender parked inside contended admission still takes a lane whose incumbent stays put.
	 *
	 * Without this case the parked interleaving proves nothing: an outcome that arrives whether or not
	 * the incumbent moves is caused by the park, not by the interleaving under test.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_contender_parked_in_contended_admission_still_takes_a_lane_its_incumbent_holds(): void {
		$job = $this->register( self::HELD_NAME, OverlapPolicy::Replace );
		$this->expect_option( LatestRunPointer::OPTION_PREFIX . self::identity( self::HELD_NAME ) );

		$incumbent = $this->dispatch_here( self::HELD_NAME );

		$contender = $this->dispatch_from_another_process(
			self::HELD_NAME,
			OverlapPolicy::Replace,
			self::PARK_ADMISSION,
			function () use ( $incumbent ): void {
				self::assertSame( $incumbent, $this->lock_owner( self::HELD_NAME ), 'A parked contender must not yet have touched the lane lock' );
				self::assertSame( array( $incumbent => 'running' ), $this->run_row_statuses( self::HELD_NAME ), 'A parked contender must not yet have superseded the incumbent' );
			}
		);

		self::assertSame( 'success', $contender['outcome'] ?? null, 'A contender released onto an unchanged lane must complete its takeover' );
		$replacement = $contender['run_id'] ?? null;
		self::assertIsString( $replacement );
		self::assertNotSame( $incumbent, $replacement, 'A takeover must admit its own run' );
		self::assertSame( $replacement, $this->lock_owner( self::HELD_NAME ), 'A completed takeover must own the lane lock' );

		$this->settle_lane();
		self::assertSame( array( array() ), $job->calls, 'A superseded incumbent delivery must not execute alongside the run that replaced it' );
		self::assertSame( array(), $this->run_row_statuses( self::HELD_NAME ), 'A settled lane must leave no run row' );
		self::assertNull( $this->lock_owner( self::HELD_NAME ), 'A settled lane must hold no lock' );
	}

	/**
	 * A takeover held between its two writes yields the lane to a rival that completes one.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_takeover_parked_between_its_two_writes_is_readmitted_over_the_rival_that_overtook_it(): void {
		$job = $this->register( self::WINDOW_NAME, OverlapPolicy::Replace );
		$this->expect_option( LatestRunPointer::OPTION_PREFIX . self::identity( self::WINDOW_NAME ) );

		$incumbent = $this->dispatch_here( self::WINDOW_NAME );
		$rival     = null;

		$contender = $this->dispatch_from_another_process(
			self::WINDOW_NAME,
			OverlapPolicy::Replace,
			self::PARK_TAKEOVER,
			function () use ( $incumbent, &$rival ): void {
				// The park sits between the takeover's two linearization points: the incumbent's run row
				// is already superseded and the lock has not yet changed hands. Proving that here is what
				// makes the rest of this test evidence about the window rather than about the harness.
				self::assertSame( 'superseded', $this->run_row_statuses( self::WINDOW_NAME )[ $incumbent ] ?? null, 'A parked takeover must already have superseded the incumbent run row' );
				self::assertSame( $incumbent, $this->lock_owner( self::WINDOW_NAME ), 'A parked takeover must not yet have transferred the lane lock' );

				// A rival completes a takeover of its own while the first is held mid-flight.
				$result = Component::operations( self::SCOPE )->dispatch( self::WINDOW_NAME );
				$rival  = $result instanceof Success && $result->value instanceof Run ? (string) $result->value->id : 'failure';
			}
		);

		self::assertIsString( $rival );
		self::assertNotSame( 'failure', $rival, 'A rival takeover against a lane whose lock is still free to move must be admitted' );
		self::assertNotSame( $incumbent, $rival, 'A rival takeover must admit its own run' );

		// The parked takeover loses the transfer the rival won, and is then admitted again. Under
		// Replace that means it takes the lane back: the caller asked for a takeover, and a lost race
		// does not withdraw the request. This is the churn Replace buys, made bounded and visible.
		self::assertSame( 'success', $contender['outcome'] ?? null, 'An overtaken takeover must be re-admitted rather than handed back a conflict' );
		$readmitted = $contender['run_id'] ?? null;
		self::assertIsString( $readmitted );
		self::assertNotSame( $rival, $readmitted, 'A re-admitted takeover must carry its own run' );

		// Whatever the order, one claim survives and it is the lock owner's.
		self::assertSame( $readmitted, $this->lock_owner( self::WINDOW_NAME ), 'The re-admitted takeover must own the lane lock' );
		self::assertSame( array( $readmitted => 'running' ), $this->run_row_statuses( self::WINDOW_NAME ), 'A resolved window must leave exactly one run row, belonging to the lock owner' );

		$this->settle_lane();
		self::assertSame( array( array() ), $job->calls, 'Only the run that ends up owning the lane may execute' );
		self::assertSame( array(), $this->run_row_statuses( self::WINDOW_NAME ), 'A settled lane must leave no run row' );
		self::assertNull( $this->lock_owner( self::WINDOW_NAME ), 'A settled lane must hold no lock' );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Registers one job in this process under an overlap policy.
	 *
	 * @param   string        $name    Stable job name.
	 * @param   OverlapPolicy $overlap Overlap policy the lane admits under.
	 *
	 * @return  RecordingJob
	 */
	private function register( string $name, OverlapPolicy $overlap ): RecordingJob {
		$job = new RecordingJob( $name );
		Component::operations( self::SCOPE )->register( $job->definition( new JobOptions( overlap: $overlap ) ) );

		return $job;
	}

	/**
	 * Dispatches one job from this process and returns its run identifier.
	 *
	 * @param   string $name Stable job name.
	 *
	 * @return  string
	 */
	private function dispatch_here( string $name ): string {
		$result = Component::operations( self::SCOPE )->dispatch( $name );
		self::assertInstanceOf( Success::class, $result, 'The incumbent must be admitted through the public API' );
		self::assertInstanceOf( Run::class, $result->value );

		return (string) $result->value->id;
	}

	/**
	 * Dispatches the same job from its own WP-CLI process, optionally parked mid-admission.
	 *
	 * @phpstan-param null|callable(): void $while_parked
	 *
	 * @param   string        $name         Stable job name.
	 * @param   OverlapPolicy $overlap      Overlap policy the contender registers under.
	 * @param   string        $park         Park mode deciding where, if anywhere, the contender is held.
	 * @param   callable|null $while_parked Interleaving to perform while the contender is parked.
	 *
	 * @throws  \RuntimeException When the contender cannot be started.
	 *
	 * @return  array<string, mixed> Decoded contender verdict.
	 */
	private function dispatch_from_another_process( string $name, OverlapPolicy $overlap, string $park = self::PARK_NONE, ?callable $while_parked = null ): array {
		$barrier     = new ContentionBarrier( $name );
		$environment = \getenv();

		$environment['A8CSP_BGJE_CONTENTION_SCOPE']      = self::SCOPE;
		$environment['A8CSP_BGJE_CONTENTION_NAME']       = $name;
		$environment['A8CSP_BGJE_CONTENTION_OVERLAP']    = $overlap->value;
		$environment['A8CSP_BGJE_CONTENTION_TOKEN']      = self::PARK_ADMISSION === $park ? $name : '';
		$environment['A8CSP_BGJE_CONTENTION_PARK_TOKEN'] = self::PARK_TAKEOVER === $park ? $name : '';

		$gate = self::PARK_TAKEOVER === $park ? ContentionBarrier::TAKEOVER_GATE : ContentionBarrier::ADMISSION_GATE;

		$barrier->clear( $gate );
		$pipes = array();
		// A second contender needs its own request, which only a separate process provides.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
		$process = \proc_open(
			array( 'wp', '--path=' . self::WP_PATH, '--no-color', 'eval-file', self::WORKER ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			self::WP_PATH,
			$environment
		);
		if ( ! \is_resource( $process ) ) {
			throw new \RuntimeException( 'The integration environment must expose the WP-CLI executable to a contending process.' );
		}

		try {
			self::assertCount( 3, $pipes );
			$stdin = $pipes[0] ?? null;
			self::assertIsResource( $stdin );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipes are native streams with no WP_Filesystem equivalent.
			\fclose( $stdin );

			if ( self::PARK_NONE !== $park ) {
				try {
					$barrier->await_arrival( $gate );
				} catch ( \RuntimeException $never_arrived ) {
					// A contender that never announces itself is almost always a missing park mechanism
					// rather than a slow one, and the bare timeout does not say which.
					throw new \RuntimeException(
						\sprintf(
							'%1$s The %2$s park never engaged. %3$s',
							$never_arrived->getMessage(),
							$park,
							self::PARK_TAKEOVER === $park
								? 'That park needs the database drop-in installed at wp-content/db.php; recreate the environment with `npm run wp-env:tests:start`.'
								: 'That park needs the contender to register the lock-staleness filter.'
						),
						0,
						$never_arrived
					);
				}

				if ( null !== $while_parked ) {
					$while_parked();
				}

				$barrier->release( $gate );
			}

			return self::read_report( $pipes );
		} finally {
			\proc_terminate( $process );
			\proc_close( $process );
			$barrier->clear( $gate );
		}
	}

	/**
	 * Reads and decodes one contender's verdict from its output streams.
	 *
	 * @param   array<int, mixed> $pipes Open process pipes.
	 *
	 * @return  array<string, mixed>
	 */
	private static function read_report( array $pipes ): array {
		$stdout = $pipes[1] ?? null;
		$stderr = $pipes[2] ?? null;
		self::assertIsResource( $stdout );
		self::assertIsResource( $stderr );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$output = \stream_get_contents( $stdout );
		$errors = \stream_get_contents( $stderr );
		\fclose( $stdout );
		\fclose( $stderr );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		self::assertIsString( $output );
		self::assertIsString( $errors );

		$verdict = null;
		foreach ( \explode( "\n", $output ) as $line ) {
			if ( \str_starts_with( $line, self::REPORT_SENTINEL ) ) {
				$verdict = \json_decode( \substr( $line, \strlen( self::REPORT_SENTINEL ) ), true );
			}
		}

		self::assertIsArray( $verdict, \sprintf( 'The contending process must report a verdict. Output: %1$s Errors: %2$s', $output, $errors ) );

		$report = array();
		foreach ( $verdict as $key => $value ) {
			if ( \is_string( $key ) ) {
				$report[ $key ] = $value;
			}
		}

		return $report;
	}

	/**
	 * Drives due engine actions until the queue is empty.
	 *
	 * @return  void
	 */
	private function settle_lane(): void {
		for ( $drive = 0; $drive < self::MAX_RUNNER_DRIVES; $drive++ ) {
			if ( 0 === $this->run_next_engine_action() ) {
				return;
			}
		}

		self::fail( 'A contended lane must settle within a bounded number of runner drives.' );
	}

	/**
	 * Returns the run identifier currently holding one lane's overlap lock.
	 *
	 * @param   string $name Stable job name.
	 *
	 * @return  string|null Lock owner, or null while the lane holds no lock.
	 */
	private function lock_owner( string $name ): ?string {
		$raw = self::raw_option( OverlapGuard::OPTION_PREFIX . self::identity( $name ) . '_' . self::args_hash( array() ) );
		if ( null === $raw ) {
			return null;
		}

		$lock = \maybe_unserialize( $raw );

		return \is_array( $lock ) && \is_string( $lock['run_id'] ?? null ) ? $lock['run_id'] : null;
	}

	/**
	 * Returns the running run identifiers the inspection portal reports for one lane.
	 *
	 * @param   string $name Stable job name.
	 *
	 * @return  list<string>
	 */
	private function live_run_ids( string $name ): array {
		$run_ids = array();
		foreach ( $this->inspection()->runs( Identity::compose( self::SCOPE, $name ) )['live'] as $entry ) {
			$run_ids[] = $entry['run_id'];
		}

		return $run_ids;
	}

	/**
	 * Returns the persisted status of every run row a lane still carries, terminal rows included.
	 *
	 * A terminal row is invisible to the inspection portal's live view, which is exactly where a
	 * stranded takeover would hide.
	 *
	 * @param   string $name Stable job name.
	 *
	 * @return  array<string, string> Persisted statuses keyed by run identifier.
	 */
	private function run_row_statuses( string $name ): array {
		global $wpdb;

		$prefix = RunIdentity::raw_option_name_prefix( self::identity( $name ) );

		/** @var \wpdb $wpdb */
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT `option_name`, `option_value` FROM %i WHERE `option_name` LIKE %s ORDER BY `option_name` ASC', $wpdb->options, $wpdb->esc_like( $prefix ) . '%' ), \ARRAY_A );
		if ( ! \is_array( $rows ) ) {
			return array();
		}

		$statuses = array();
		foreach ( $rows as $row ) {
			if ( ! \is_array( $row ) || ! \is_string( $row['option_name'] ?? null ) || ! \is_string( $row['option_value'] ?? null ) ) {
				continue;
			}

			$state = \maybe_unserialize( $row['option_value'] );
			if ( \is_array( $state ) && \is_string( $state['status'] ?? null ) ) {
				$statuses[ \substr( $row['option_name'], \strlen( $prefix ) ) ] = $state['status'];
			}
		}

		return $statuses;
	}

	/**
	 * Reads one option row past the option cache, so a peer process's write is visible.
	 *
	 * @param   string $name Option name.
	 *
	 * @return  string|null Raw option value, or null while the row is absent.
	 */
	private static function raw_option( string $name ): ?string {
		global $wpdb;

		/** @var \wpdb $wpdb */
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT `option_value` FROM %i WHERE `option_name` = %s', $wpdb->options, $name ) );

		return \is_string( $value ) ? $value : null;
	}

	/**
	 * Composes one lane's scope-qualified identity.
	 *
	 * @param   string $name Stable job name.
	 *
	 * @return  string
	 */
	private static function identity( string $name ): string {
		return self::SCOPE . ':' . $name;
	}

	// endregion.
}
