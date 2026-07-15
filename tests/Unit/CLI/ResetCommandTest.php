<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\CLI;

use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Commands\ResetCommand;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Output\ResetOutput;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Backends\SchedulerFacade;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceDelivery;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\ScheduleRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingBackend;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the irreversible development reset, confirmation gate, and category counts.
 */
#[CoversClass( ResetCommand::class )]
#[CoversClass( ResetOutput::class )]
#[UsesClass( SchedulerFacade::class )]
#[UsesClass( OptionRows::class )]
#[UsesClass( RecordingBackend::class )]
final class ResetCommandTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	/** Option outside every engine-owned prefix. */
	private const UNRELATED_OPTION = 'consumer_plugin_state';

	/** Backend hook outside the engine namespace. */
	private const UNRELATED_HOOK = 'consumer_plugin/background_work';

	/** @var list<array{question: string, assoc_args: array<string, mixed>}> */
	private array $confirmations = array();

	/** @var list<string> */
	private array $lines = array();

	/** @var list<string> */
	private array $successes = array();

	/** @var list<string> */
	private array $errors = array();

	/** Whether interactive confirmation is accepted. */
	private bool $confirmation = true;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded storage functions.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/wp-lock-stubs.php';
	}

	/**
	 * Resets the current site and command interaction ledger.
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgte_test_blog_id']     = 1;
		$GLOBALS['a8csp_bgte_test_cache']       = array();
		$GLOBALS['a8csp_bgte_test_cache_calls'] = array();

		$this->confirmations = array();
		$this->lines         = array();
		$this->successes     = array();
		$this->errors        = array();
		$this->confirmation  = true;
	}

	// endregion.

	// region TESTS.

	/**
	 * Every owner constant participates in the canonical reset prefix census.
	 *
	 * @return  void
	 */
	public function test_option_prefixes_are_derived_from_their_owning_classes(): void {
		self::assertSame(
			array(
				ScheduleRegistry::OPTION_NAME,
				RunStore::OPTION_PREFIX,
				FailedRunStore::OPTION_PREFIX,
				RunHistory::OPTION_PREFIX,
				LatestRunPointer::OPTION_PREFIX,
				OverlapGuard::OPTION_PREFIX,
				OccurrenceLease::OPTION_PREFIX,
				CleanupIntents::INTENT_PREFIX,
			),
			ResetCommand::option_prefixes()
		);
	}

	/**
	 * Affirmative reset removes every persisted category and pending engine action from both backends.
	 *
	 * @return  void
	 */
	public function test_yes_purges_all_engine_state_and_reports_category_counts(): void {
		$wpdb          = $this->seed_option_rows();
		$first_backend = new RecordingBackend();
		$last_backend  = new RecordingBackend();

		$first_backend->pending_actions = array(
			ActionDeliveries::START_HOOK => 1,
			ActionDeliveries::RUN_HOOK   => 2,
			self::UNRELATED_HOOK         => 4,
		);
		$last_backend->pending_actions  = array(
			ActionDeliveries::CONTINUE_HOOK   => 1,
			ActionDeliveries::CLEANUP_HOOK    => 1,
			OccurrenceDelivery::SCHEDULE_HOOK => 2,
		);

		$command = new ResetCommand(
			new OptionRows( $wpdb ),
			new SchedulerFacade( array( $first_backend, $last_backend ) ),
			$this->reset_output()
		);

		$this->confirmation = false;
		$command->reset( array(), array( 'yes' => true ) );

		self::assertSame( array( self::UNRELATED_OPTION => 'keep' ), $wpdb->rows );
		self::assertSame( array( self::UNRELATED_HOOK => 4 ), $first_backend->pending_actions );
		self::assertSame( array(), $last_backend->pending_actions );
		self::assertSame(
			array(
				'Option rows deleted: 8',
				'Pending backend actions unscheduled: 7',
			),
			$this->lines
		);
		self::assertSame( array( 'Background tasks development state reset.' ), $this->successes );
		self::assertSame( array(), $this->errors );
		self::assertSame( array( 'yes' => true ), $this->confirmations[0]['assoc_args'] ?? null );
	}

	/**
	 * Declining the interactive gate leaves option rows and backend actions untouched.
	 *
	 * @return  void
	 */
	public function test_declined_confirmation_prevents_every_mutation(): void {
		$wpdb    = $this->seed_option_rows();
		$backend = new RecordingBackend();

		$backend->pending_actions = array( ActionDeliveries::RUN_HOOK => 2 );

		$command = new ResetCommand(
			new OptionRows( $wpdb ),
			new SchedulerFacade( array( $backend ) ),
			$this->reset_output()
		);

		$before_rows        = $wpdb->rows;
		$this->confirmation = false;

		try {
			$command->reset( array(), array() );
			self::fail( 'A declined destructive reset must stop at confirmation.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'Confirmation declined.', $exception->getMessage() );
		}

		self::assertSame( $before_rows, $wpdb->rows );
		self::assertSame( array( ActionDeliveries::RUN_HOOK => 2 ), $backend->pending_actions );
		self::assertSame( array(), $this->lines );
		self::assertSame( array(), $this->successes );
		self::assertSame( array(), $this->confirmations[0]['assoc_args'] ?? null );
	}

	/**
	 * A dormant backend aborts the reset before any row is deleted.
	 *
	 * @return  void
	 */
	public function test_dormant_backend_clearance_failure_prevents_every_row_deletion(): void {
		$wpdb    = $this->seed_option_rows();
		$backend = new RecordingBackend();

		$backend->ready           = false;
		$backend->pending_actions = array( ActionDeliveries::RUN_HOOK => 2 );

		$command = new ResetCommand(
			new OptionRows( $wpdb ),
			new SchedulerFacade( array( $backend ) ),
			$this->reset_output()
		);

		$before_rows = $wpdb->rows;

		try {
			$command->reset( array(), array( 'yes' => true ) );
			self::fail( 'A dormant backend must abort the reset before any deletion.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'backend', \strtolower( $exception->getMessage() ) );
		}

		self::assertSame( $before_rows, $wpdb->rows );
		self::assertSame( array( ActionDeliveries::RUN_HOOK => 2 ), $backend->pending_actions );
		self::assertSame( array(), $this->successes );
	}

	/**
	 * The decision seam accepts only the documented flag shape.
	 *
	 * @return  void
	 */
	public function test_request_parser_accepts_only_the_documented_yes_flag(): void {
		self::assertSame( array( 'action' => 'reset' ), ResetCommand::request_from_args( array(), array() ) );
		self::assertSame(
			array( 'action' => 'reset' ),
			ResetCommand::request_from_args( array(), array( 'yes' => true ) )
		);
		self::assertSame(
			array(
				'action'  => 'error',
				'message' => 'Reset accepts only --yes; use wp background-tasks reset [--yes].',
			),
			ResetCommand::request_from_args( array( 'extra' ), array() )
		);
		self::assertSame(
			array(
				'action'  => 'error',
				'message' => 'Reset accepts only --yes; use wp background-tasks reset [--yes].',
			),
			ResetCommand::request_from_args( array(), array( 'yes' => 'true' ) )
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Seeds one row under every canonical prefix plus one unrelated option.
	 *
	 * @return  WpdbLockSpy
	 */
	private function seed_option_rows(): WpdbLockSpy {
		$wpdb = new WpdbLockSpy();
		foreach ( ResetCommand::option_prefixes() as $prefix ) {
			$option_name = ScheduleRegistry::OPTION_NAME === $prefix ? $prefix : $prefix . 'fixture';
			$wpdb->put( $option_name, 'raw-' . $prefix );
		}
		$wpdb->put( self::UNRELATED_OPTION, 'keep' );

		return $wpdb;
	}

	/**
	 * Builds the reset output boundary over this test's interaction ledgers.
	 *
	 * @return  ResetOutput
	 */
	private function reset_output(): ResetOutput {
		return new ResetOutput(
			function ( string $question, array $assoc_args ): void {
				$this->confirmations[] = array(
					'question'   => $question,
					'assoc_args' => $assoc_args,
				);
				if ( true !== ( $assoc_args['yes'] ?? false ) && ! $this->confirmation ) {
					throw new \RuntimeException( 'Confirmation declined.' );
				}
			},
			function ( string $message ): void {
				$this->lines[] = $message;
			},
			function ( string $message ): void {
				$this->successes[] = $message;
			},
			function ( string $message ): void {
				$this->errors[] = $message;
				throw new \RuntimeException( $message );
			}
		);
	}

	// endregion.
}
