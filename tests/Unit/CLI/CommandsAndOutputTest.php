<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\CLI;

use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Commands\RunsCommand;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Commands\SchedulesCommand;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Output\FailedRunOutput;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Output\RunOutput;
use A8C\SpecialProjects\BackgroundTasksEngine\CLI\Output\ScheduleOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the WP-free command decisions and failed-run row formatting.
 */
#[CoversClass( RunsCommand::class )]
#[CoversClass( SchedulesCommand::class )]
#[CoversClass( FailedRunOutput::class )]
#[CoversClass( RunOutput::class )]
#[CoversClass( ScheduleOutput::class )]
final class CommandsAndOutputTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies the production boot guard before the handler is autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * The exact cancel identity maps to the execution decision without lexical reinterpretation.
	 *
	 * @return  void
	 */
	public function test_cancel_request_is_parsed(): void {
		self::assertSame(
			array(
				'action' => 'cancel',
				'name'   => 'consumer-plugin:email-digest',
				'run_id' => 'run-1',
			),
			RunsCommand::cancel_request_from_args(
				array( 'consumer-plugin:email-digest', 'run-1' ),
				array()
			)
		);
	}

	/**
	 * Cancel accepts only the canonical owner-qualified identity.
	 *
	 * @return  void
	 */
	public function test_cancel_rejects_an_unqualified_identity(): void {
		self::assertSame(
			array(
				'action'  => 'error',
				'message' => 'Cancel identity is invalid; use a composed {owner}:{name} identity.',
			),
			RunsCommand::cancel_request_from_args( array( 'email-digest', 'run-1' ), array() )
		);
	}

	/**
	 * Every malformed cancel form names the exact command usage.
	 *
	 * @phpstan-param list<string> $args
	 *
	 * @param   array                $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_cancel_requests' )]
	public function test_invalid_cancel_requests_name_the_usage( array $args, array $assoc_args ): void {
		self::assertSame(
			array(
				'action'  => 'error',
				'message' => 'Cancel requires exactly an identity and run_id; use wp background-tasks cancel <identity> <run_id>.',
			),
			RunsCommand::cancel_request_from_args( $args, $assoc_args )
		);
	}

	/**
	 * Rows expose owner and composed identity before the retained failure fields.
	 *
	 * @return  void
	 */
	public function test_rows_are_shaped_and_ordered_deterministically(): void {
		$rows = FailedRunOutput::rows_from_entries(
			array(
				'owner-z:zeta-task'  => array(
					array(
						'run_id'     => 'run-z',
						'failed_at'  => 0,
						'start_args' => array( 'ignored' => true ),
						'attempts'   => 4,
						'error'      => array(
							'class'   => \RuntimeException::class,
							'message' => 'Zeta failure.',
						),
					),
				),
				'owner-a:alpha-task' => array(
					array(
						'run_id'     => 'run-b',
						'failed_at'  => 2,
						'start_args' => array(),
						'attempts'   => 2,
						'error'      => array(
							'class'   => \LogicException::class,
							'message' => 'Second alpha failure.',
						),
					),
					array(
						'run_id'     => 'run-c',
						'failed_at'  => 1,
						'start_args' => array(),
						'attempts'   => 1,
						'error'      => array(
							'class'   => null,
							'message' => 'First alpha failure.',
						),
					),
					array(
						'run_id'     => 'run-a',
						'failed_at'  => 2,
						'start_args' => array(),
						'attempts'   => 3,
						'error'      => array(
							'class'   => null,
							'message' => 'Tied alpha failure.',
						),
					),
				),
			)
		);

		self::assertSame(
			array(
				array(
					'owner'         => 'owner-a',
					'name'          => 'owner-a:alpha-task',
					'run_id'        => 'run-c',
					'failed_at'     => '1970-01-01T00:00:01+00:00',
					'attempts'      => 1,
					'error_class'   => null,
					'error_message' => 'First alpha failure.',
				),
				array(
					'owner'         => 'owner-a',
					'name'          => 'owner-a:alpha-task',
					'run_id'        => 'run-a',
					'failed_at'     => '1970-01-01T00:00:02+00:00',
					'attempts'      => 3,
					'error_class'   => null,
					'error_message' => 'Tied alpha failure.',
				),
				array(
					'owner'         => 'owner-a',
					'name'          => 'owner-a:alpha-task',
					'run_id'        => 'run-b',
					'failed_at'     => '1970-01-01T00:00:02+00:00',
					'attempts'      => 2,
					'error_class'   => \LogicException::class,
					'error_message' => 'Second alpha failure.',
				),
				array(
					'owner'         => 'owner-z',
					'name'          => 'owner-z:zeta-task',
					'run_id'        => 'run-z',
					'failed_at'     => '1970-01-01T00:00:00+00:00',
					'attempts'      => 4,
					'error_class'   => \RuntimeException::class,
					'error_message' => 'Zeta failure.',
				),
			),
			$rows
		);
	}

	/**
	 * The failed-list owner filter is an exact namespace match.
	 *
	 * @return  void
	 */
	public function test_rows_are_filtered_to_the_exact_owner(): void {
		self::assertSame(
			array(
				array(
					'owner'         => 'owner-a',
					'name'          => 'owner-a:task',
					'run_id'        => 'run-a',
					'failed_at'     => '1970-01-01T00:00:01+00:00',
					'attempts'      => 1,
					'error_class'   => null,
					'error_message' => 'Owner A failure.',
				),
			),
			FailedRunOutput::rows_from_entries(
				array(
					'owner-a:task'  => array(
						array(
							'run_id'     => 'run-a',
							'failed_at'  => 1,
							'start_args' => array(),
							'attempts'   => 1,
							'error'      => array(
								'class'   => null,
								'message' => 'Owner A failure.',
							),
						),
					),
					'owner-ab:task' => array(
						array(
							'run_id'     => 'run-ab',
							'failed_at'  => 2,
							'start_args' => array(),
							'attempts'   => 1,
							'error'      => array(
								'class'   => null,
								'message' => 'Owner AB failure.',
							),
						),
					),
				),
				'owner-a'
			)
		);
	}

	/**
	 * No retained entries produce no output rows.
	 *
	 * @return  void
	 */
	public function test_empty_entries_produce_an_empty_row_list(): void {
		self::assertSame( array(), FailedRunOutput::rows_from_entries( array() ) );
	}

	/**
	 * Discovery accepts only composed identities under the exact failed-run option prefix.
	 *
	 * @return  void
	 */
	public function test_discovered_option_names_are_filtered_deduplicated_and_sorted(): void {
		self::assertSame(
			array( 'a8csp-bgte:maintenance', 'alpha:alpha-task', 'alpha:alpha_task', 'zeta:task' ),
			RunsCommand::names_from_option_names(
				array(
					'a8csp_bgte_failed_zeta:task',
					42,
					'other_failed_alpha:alpha-task',
					'a8csp_bgte_failed_',
					'a8csp_bgte_failed_Alpha:task',
					'a8csp_bgte_failed_alpha:task/more',
					'a8csp_bgte_failed_alpha-task',
					'a8csp_bgte_failed_alpha:task:extra',
					'a8csp_bgte_failed_alpha:alpha-task',
					'a8csp_bgte_failed_alpha:alpha_task',
					'a8csp_bgte_failed_alpha:alpha-task',
					'a8csp_bgte_failed_a8csp-bgte:maintenance',
				)
			)
		);
	}

	/**
	 * Valid action spellings map to exact command decisions.
	 *
	 * @phpstan-param list<string> $args
	 *
	 * @param   array                $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 * @param   array<string, mixed> $expected   Expected command decision.
	 *
	 * @return  void
	 */
	#[DataProvider( 'valid_requests' )]
	public function test_valid_requests_are_parsed( array $args, array $assoc_args, array $expected ): void {
		self::assertSame( $expected, RunsCommand::failed_runs_request_from_args( $args, $assoc_args ) );
	}

	/**
	 * Invalid action forms name the exact correction.
	 *
	 * @phpstan-param list<string> $args
	 *
	 * @param   array                $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 * @param   string               $message    Expected corrective message.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_requests' )]
	public function test_invalid_requests_name_the_fix( array $args, array $assoc_args, string $message ): void {
		self::assertSame(
			array(
				'action'  => 'error',
				'message' => $message,
			),
			RunsCommand::failed_runs_request_from_args( $args, $assoc_args )
		);
	}

	/**
	 * Every accepted schedule-list form maps to the exact execution decision.
	 *
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 * @param   array<string, mixed> $expected   Expected command decision.
	 *
	 * @return  void
	 */
	#[DataProvider( 'valid_schedule_requests' )]
	public function test_valid_schedule_requests_are_parsed( array $assoc_args, array $expected ): void {
		self::assertSame(
			$expected,
			SchedulesCommand::request_from_args( array( 'list' ), $assoc_args )
		);
	}

	/**
	 * Every malformed schedule-list form names the exact correction.
	 *
	 * @phpstan-param list<string> $args
	 *
	 * @param   array                $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 * @param   string               $message    Expected corrective message.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_schedule_requests' )]
	public function test_invalid_schedule_requests_name_the_fix( array $args, array $assoc_args, string $message ): void {
		self::assertSame(
			array(
				'action'  => 'error',
				'message' => $message,
			),
			SchedulesCommand::request_from_args( $args, $assoc_args )
		);
	}

	/**
	 * Every accepted runs-list format preserves the exact validated name.
	 *
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 * @param   string               $format     Expected output format.
	 *
	 * @return  void
	 */
	#[DataProvider( 'valid_run_formats' )]
	public function test_valid_run_requests_are_parsed( array $assoc_args, string $format ): void {
		self::assertSame(
			array(
				'action' => 'list',
				'name'   => 'consumer-plugin:email_digest-2',
				'format' => $format,
			),
			RunsCommand::runs_request_from_args(
				array( 'list', 'consumer-plugin:email_digest-2' ),
				$assoc_args
			)
		);
	}

	/**
	 * Every malformed runs-list form names the exact correction.
	 *
	 * @phpstan-param list<string> $args
	 *
	 * @param   array                $args       Positional command arguments.
	 * @param   array<string, mixed> $assoc_args Named command arguments.
	 * @param   string               $message    Expected corrective message.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_run_requests' )]
	public function test_invalid_run_requests_name_the_fix( array $args, array $assoc_args, string $message ): void {
		self::assertSame(
			array(
				'action'  => 'error',
				'message' => $message,
			),
			RunsCommand::runs_request_from_args( $args, $assoc_args )
		);
	}

	/**
	 * Schedule rows preserve exact columns, wording, and owner/name ordering.
	 *
	 * @return  void
	 */
	public function test_schedule_rows_shape_honest_declared_orphan_and_lock_wording(): void {
		$rows = ScheduleOutput::rows_from_entries(
			array(
				array(
					'owner'      => 'owner-b',
					'name'       => 'owner-b:orphaned',
					'recurrence' => null,
					'next_due'   => 1_699_996_400,
					'last_fired' => null,
					'misfires'   => 2,
					'skips'      => 3,
					'scheduled'  => false,
					'lock'       => array( 'state' => 'not_declared' ),
				),
				array(
					'owner'      => 'owner-a',
					'name'       => 'owner-a:nightly',
					'recurrence' => 300,
					'next_due'   => 1_700_000_060,
					'last_fired' => 1_699_999_999,
					'misfires'   => 0,
					'skips'      => 1,
					'scheduled'  => true,
					'lock'       => array(
						'state'  => 'held',
						'run_id' => 'run-a',
						'stale'  => true,
					),
				),
			),
			1_700_000_000
		);

		self::assertSame(
			array(
				array(
					'owner'      => 'owner-a',
					'name'       => 'owner-a:nightly',
					'recurrence' => 300,
					'next_due'   => '2023-11-14T22:14:20+00:00 (in 1m)',
					'last_fired' => '2023-11-14T22:13:19+00:00',
					'misfires'   => 0,
					'skips'      => 1,
					'scheduled'  => 'yes',
					'lock'       => 'held by run-a (stale)',
				),
				array(
					'owner'      => 'owner-b',
					'name'       => 'owner-b:orphaned',
					'recurrence' => 'unknown (not declared this request)',
					'next_due'   => '2023-11-14T21:13:20+00:00 (overdue 1h)',
					'last_fired' => 'never',
					'misfires'   => 2,
					'skips'      => 3,
					'scheduled'  => 'no',
					'lock'       => 'unknown (not declared this request)',
				),
			),
			$rows
		);
		self::assertSame(
			'note: a scheduling backend is not ready; dormant occurrences are not visible.',
			ScheduleOutput::dormant_backend_note( true )
		);
		self::assertNull( ScheduleOutput::dormant_backend_note( false ) );
	}

	/**
	 * Lock labels distinguish every observable state and reserve free for confirmed absence.
	 *
	 * @return  void
	 */
	public function test_schedule_lock_labels_are_discriminated(): void {
		self::assertSame( 'free', ScheduleOutput::lock_label( array( 'state' => 'free' ) ) );
		self::assertSame(
			'unknown (not declared this request)',
			ScheduleOutput::lock_label( array( 'state' => 'not_declared' ) )
		);
		self::assertSame(
			'unknown (lock read failed)',
			ScheduleOutput::lock_label( array( 'state' => 'read_failed' ) )
		);
		self::assertSame(
			'not blocking (overlap allowed)',
			ScheduleOutput::lock_label( array( 'state' => 'overlap_allowed' ) )
		);
		self::assertSame(
			'unknown (invalid lock row)',
			ScheduleOutput::lock_label( array( 'state' => 'invalid' ) )
		);
	}

	/**
	 * Live and history rows preserve phase, queue, staleness, and retention wording.
	 *
	 * @return  void
	 */
	public function test_run_rows_shape_live_and_history_wording(): void {
		self::assertSame(
			array(
				array(
					'run_id'    => 'run-executing',
					'status'    => 'running',
					'phase'     => 'executing',
					'attempts'  => 2,
					'queue'     => 3,
					'heartbeat' => '4s ago',
				),
				array(
					'run_id'    => 'run-waiting',
					'status'    => 'running',
					'phase'     => 'waiting',
					'attempts'  => 1,
					'queue'     => '—',
					'heartbeat' => '15m ago (stale)',
				),
				array(
					'run_id'    => 'run-orphaned',
					'status'    => 'running',
					'phase'     => 'waiting',
					'attempts'  => 0,
					'queue'     => 2,
					'heartbeat' => '5s ago',
				),
			),
			RunOutput::live_rows_from_entries(
				array(
					array(
						'run_id'       => 'run-executing',
						'kind'         => 'batch',
						'status'       => 'running',
						'executing'    => true,
						'attempts'     => 2,
						'queue_depth'  => 3,
						'heartbeat_at' => 1_699_999_996,
						'stale'        => false,
					),
					array(
						'run_id'       => 'run-waiting',
						'kind'         => 'task',
						'status'       => 'running',
						'executing'    => false,
						'attempts'     => 1,
						'queue_depth'  => null,
						'heartbeat_at' => 1_699_999_099,
						'stale'        => true,
					),
					array(
						'run_id'       => 'run-orphaned',
						'kind'         => 'unknown',
						'status'       => 'running',
						'executing'    => false,
						'attempts'     => 0,
						'queue_depth'  => 2,
						'heartbeat_at' => 1_699_999_995,
						'stale'        => false,
					),
				),
				1_700_000_000
			)
		);
		self::assertSame(
			array(
				array(
					'run_id'   => 'run-failed',
					'outcome'  => 'failed',
					'retained' => 'failed store',
				),
				array(
					'run_id'   => 'run-started',
					'outcome'  => 'started',
					'retained' => '—',
				),
			),
			RunOutput::history_rows_from_entries(
				array(
					array(
						'run_id'   => 'run-failed',
						'outcome'  => 'failed',
						'retained' => true,
					),
					array(
						'run_id'   => 'run-started',
						'outcome'  => 'started',
						'retained' => false,
					),
				)
			)
		);
	}

	/**
	 * Compromised and truncated live listings carry explicit corrective wording.
	 *
	 * @return  void
	 */
	public function test_live_run_listing_honesty_messages_are_explicit(): void {
		self::assertSame(
			'Live-run state is unknown (run enumeration failed); resolve the database error and try again.',
			RunOutput::error_message( 'enumeration_failed' )
		);
		self::assertSame(
			'Live-run state is unknown (run read failed); resolve the database error and try again.',
			RunOutput::error_message( 'read_failed' )
		);
		self::assertNull( RunOutput::error_message( null ) );
		self::assertSame(
			'Showing first 20 matching run rows; 4 more were not inspected.',
			RunOutput::truncation_message( 20, 4 )
		);
		self::assertSame(
			'Showing first 20 matching run rows; 1 more was not inspected.',
			RunOutput::truncation_message( 20, 1 )
		);
		self::assertNull( RunOutput::truncation_message( 20, 0 ) );
	}

	/**
	 * Relative heartbeat output changes units only at the exact elapsed boundaries.
	 *
	 * @param   int    $heartbeat_at Persisted heartbeat timestamp.
	 * @param   string $expected     Expected relative label.
	 *
	 * @return  void
	 */
	#[DataProvider( 'heartbeat_boundaries' )]
	public function test_heartbeat_time_boundaries( int $heartbeat_at, string $expected ): void {
		self::assertSame(
			$expected,
			RunOutput::heartbeat_label( $heartbeat_at, 86_400, false )
		);
	}

	/**
	 * Due output distinguishes future, exact-due, and overdue instants without local time.
	 *
	 * @return  void
	 */
	public function test_schedule_due_time_boundaries_are_utc_and_directional(): void {
		self::assertSame(
			'1970-01-02T00:01:00+00:00 (in 1m)',
			ScheduleOutput::due_label( 86_460, 86_400 )
		);
		self::assertSame(
			'1970-01-02T00:00:00+00:00 (due now)',
			ScheduleOutput::due_label( 86_400, 86_400 )
		);
		self::assertSame(
			'1970-01-01T23:00:00+00:00 (overdue 1h)',
			ScheduleOutput::due_label( 82_800, 86_400 )
		);
	}

	/**
	 * Future clock skew clamps heartbeat age to zero and extreme ages saturate safely.
	 *
	 * @return  void
	 */
	public function test_heartbeat_clock_skew_and_integer_extremes_are_safe(): void {
		self::assertSame( '0s ago', RunOutput::heartbeat_label( 86_401, 86_400, false ) );
		self::assertSame(
			\intdiv( \PHP_INT_MAX, 86_400 ) . 'd ago (stale)',
			RunOutput::heartbeat_label( \PHP_INT_MIN, \PHP_INT_MAX, true )
		);
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Supplies every schedule-list output format and the optional exact owner filter.
	 *
	 * @return  array<string, array{
	 *     assoc_args: array<string, mixed>,
	 *     expected: array<string, mixed>
	 * }>
	 */
	public static function valid_schedule_requests(): array {
		return array(
			'default' => array(
				'assoc_args' => array(),
				'expected'   => array(
					'action' => 'list',
					'owner'  => null,
					'format' => 'table',
				),
			),
			'owner'   => array(
				'assoc_args' => array( 'owner' => 'consumer-plugin' ),
				'expected'   => array(
					'action' => 'list',
					'owner'  => 'consumer-plugin',
					'format' => 'table',
				),
			),
			'csv'     => array(
				'assoc_args' => array( 'format' => 'csv' ),
				'expected'   => array(
					'action' => 'list',
					'owner'  => null,
					'format' => 'csv',
				),
			),
			'json'    => array(
				'assoc_args' => array( 'format' => 'json' ),
				'expected'   => array(
					'action' => 'list',
					'owner'  => null,
					'format' => 'json',
				),
			),
			'count'   => array(
				'assoc_args' => array( 'format' => 'count' ),
				'expected'   => array(
					'action' => 'list',
					'owner'  => null,
					'format' => 'count',
				),
			),
			'yaml'    => array(
				'assoc_args' => array( 'format' => 'yaml' ),
				'expected'   => array(
					'action' => 'list',
					'owner'  => null,
					'format' => 'yaml',
				),
			),
		);
	}

	/**
	 * Supplies rejected schedule-list forms and their corrective messages.
	 *
	 * @return  array<string, array{
	 *     args: list<string>,
	 *     assoc_args: array<string, mixed>,
	 *     message: string
	 * }>
	 */
	public static function invalid_schedule_requests(): array {
		return array(
			'missing action'   => array(
				'args'       => array(),
				'assoc_args' => array(),
				'message'    => 'A schedule action is required; use list.',
			),
			'unknown action'   => array(
				'args'       => array( 'show' ),
				'assoc_args' => array(),
				'message'    => 'Schedule action "show" is invalid; use list.',
			),
			'extra positional' => array(
				'args'       => array( 'list', 'extra' ),
				'assoc_args' => array(),
				'message'    => 'Schedule list accepts only --owner and --format; use wp background-tasks schedules list [--owner=<owner>] [--format=<format>].',
			),
			'stray flag'       => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => 'Schedule list accepts only --owner and --format; use wp background-tasks schedules list [--owner=<owner>] [--format=<format>].',
			),
			'negated owner'    => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'owner' => false ),
				'message'    => 'Schedule list owner is invalid; pass a value with --owner=<owner>.',
			),
			'invalid owner'    => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'owner' => 'Consumer-Plugin' ),
				'message'    => 'Schedule list owner is invalid; pass a canonical owner with --owner=<owner>.',
			),
			'invalid format'   => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'ids' ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
			'negated format'   => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => false ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
		);
	}

	/**
	 * Supplies every accepted runs-list output format.
	 *
	 * @return  array<string, array{assoc_args: array<string, mixed>, format: string}>
	 */
	public static function valid_run_formats(): array {
		return array(
			'table' => array(
				'assoc_args' => array(),
				'format'     => 'table',
			),
			'csv'   => array(
				'assoc_args' => array( 'format' => 'csv' ),
				'format'     => 'csv',
			),
			'json'  => array(
				'assoc_args' => array( 'format' => 'json' ),
				'format'     => 'json',
			),
			'count' => array(
				'assoc_args' => array( 'format' => 'count' ),
				'format'     => 'count',
			),
			'yaml'  => array(
				'assoc_args' => array( 'format' => 'yaml' ),
				'format'     => 'yaml',
			),
		);
	}

	/**
	 * Supplies rejected runs-list forms and their corrective messages.
	 *
	 * @return  array<string, array{
	 *     args: list<string>,
	 *     assoc_args: array<string, mixed>,
	 *     message: string
	 * }>
	 */
	public static function invalid_run_requests(): array {
		return array(
			'missing action' => array(
				'args'       => array(),
				'assoc_args' => array(),
				'message'    => 'A run action is required; use list <identity>.',
			),
			'unknown action' => array(
				'args'       => array( 'show', 'consumer-plugin:email-digest' ),
				'assoc_args' => array(),
				'message'    => 'Run action "show" is invalid; use list.',
			),
			'missing name'   => array(
				'args'       => array( 'list' ),
				'assoc_args' => array(),
				'message'    => 'Run list requires exactly one identity and accepts only --format; use wp background-tasks runs list <identity> [--format=<format>].',
			),
			'extra name'     => array(
				'args'       => array( 'list', 'consumer-plugin:email-digest', 'extra' ),
				'assoc_args' => array(),
				'message'    => 'Run list requires exactly one identity and accepts only --format; use wp background-tasks runs list <identity> [--format=<format>].',
			),
			'stray flag'     => array(
				'args'       => array( 'list', 'consumer-plugin:email-digest' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => 'Run list requires exactly one identity and accepts only --format; use wp background-tasks runs list <identity> [--format=<format>].',
			),
			'invalid name'   => array(
				'args'       => array( 'list', 'email-digest' ),
				'assoc_args' => array(),
				'message'    => 'Run identity is invalid; use a composed {owner}:{name} identity.',
			),
			'invalid format' => array(
				'args'       => array( 'list', 'consumer-plugin:email-digest' ),
				'assoc_args' => array( 'format' => 'ids' ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
			'negated format' => array(
				'args'       => array( 'list', 'consumer-plugin:email-digest' ),
				'assoc_args' => array( 'format' => false ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
		);
	}

	/**
	 * Supplies exact relative-age unit transitions.
	 *
	 * @return  array<string, array{heartbeat_at: int, expected: string}>
	 */
	public static function heartbeat_boundaries(): array {
		return array(
			'zero'         => array(
				'heartbeat_at' => 86_400,
				'expected'     => '0s ago',
			),
			'last second'  => array(
				'heartbeat_at' => 86_341,
				'expected'     => '59s ago',
			),
			'first minute' => array(
				'heartbeat_at' => 86_340,
				'expected'     => '1m ago',
			),
			'last minute'  => array(
				'heartbeat_at' => 82_801,
				'expected'     => '59m ago',
			),
			'first hour'   => array(
				'heartbeat_at' => 82_800,
				'expected'     => '1h ago',
			),
			'last hour'    => array(
				'heartbeat_at' => 1,
				'expected'     => '23h ago',
			),
			'first day'    => array(
				'heartbeat_at' => 0,
				'expected'     => '1d ago',
			),
		);
	}

	/**
	 * Supplies malformed cancel identities, including WP-CLI's negated-flag value shape.
	 *
	 * @return  array<string, array{
	 *     args: list<string>,
	 *     assoc_args: array<string, mixed>
	 * }>
	 */
	public static function invalid_cancel_requests(): array {
		return array(
			'missing identity' => array(
				'args'       => array(),
				'assoc_args' => array(),
			),
			'missing run_id'   => array(
				'args'       => array( 'email-digest' ),
				'assoc_args' => array(),
			),
			'extra positional' => array(
				'args'       => array( 'email-digest', 'run-1', 'extra' ),
				'assoc_args' => array(),
			),
			'stray flag'       => array(
				'args'       => array( 'email-digest', 'run-1' ),
				'assoc_args' => array( 'force' => true ),
			),
			'negated flag'     => array(
				'args'       => array( 'email-digest', 'run-1' ),
				'assoc_args' => array( 'force' => false ),
			),
		);
	}

	/**
	 * Supplies every accepted failed-run action form.
	 *
	 * @return  array<string, array{
	 *     args: list<string>,
	 *     assoc_args: array<string, mixed>,
	 *     expected: array<string, mixed>
	 * }>
	 */
	public static function valid_requests(): array {
		return array(
			'list default' => array(
				'args'       => array( 'list' ),
				'assoc_args' => array(),
				'expected'   => array(
					'action' => 'list',
					'owner'  => null,
					'format' => 'table',
				),
			),
			'list owner'   => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'owner' => 'consumer-plugin' ),
				'expected'   => array(
					'action' => 'list',
					'owner'  => 'consumer-plugin',
					'format' => 'table',
				),
			),
			'list csv'     => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'csv' ),
				'expected'   => array(
					'action' => 'list',
					'owner'  => null,
					'format' => 'csv',
				),
			),
			'list json'    => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'json' ),
				'expected'   => array(
					'action' => 'list',
					'owner'  => null,
					'format' => 'json',
				),
			),
			'list count'   => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'count' ),
				'expected'   => array(
					'action' => 'list',
					'owner'  => null,
					'format' => 'count',
				),
			),
			'list yaml'    => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'yaml' ),
				'expected'   => array(
					'action' => 'list',
					'owner'  => null,
					'format' => 'yaml',
				),
			),
			'retry'        => array(
				'args'       => array( 'retry', 'consumer-plugin:email-digest', 'run-1' ),
				'assoc_args' => array(),
				'expected'   => array(
					'action' => 'retry',
					'name'   => 'consumer-plugin:email-digest',
					'run_id' => 'run-1',
				),
			),
			'purge name'   => array(
				'args'       => array( 'purge', 'consumer-plugin:email_digest-2' ),
				'assoc_args' => array(),
				'expected'   => array(
					'action' => 'purge',
					'name'   => 'consumer-plugin:email_digest-2',
				),
			),
			'purge all'    => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array( 'all' => true ),
				'expected'   => array(
					'action' => 'purge',
					'name'   => null,
				),
			),
		);
	}

	/**
	 * Supplies rejected action forms and their corrective messages.
	 *
	 * @return  array<string, array{
	 *     args: list<string>,
	 *     assoc_args: array<string, mixed>,
	 *     message: string
	 * }>
	 */
	public static function invalid_requests(): array {
		return array(
			'missing action'         => array(
				'args'       => array(),
				'assoc_args' => array(),
				'message'    => 'A failed-run action is required; use list, retry <identity> <run_id>, purge <identity>, or purge --all.',
			),
			'unknown action'         => array(
				'args'       => array( 'remove' ),
				'assoc_args' => array(),
				'message'    => 'Failed-run action "remove" is invalid; use list, retry, or purge.',
			),
			'list positional'        => array(
				'args'       => array( 'list', 'consumer-plugin:email-digest' ),
				'assoc_args' => array(),
				'message'    => 'List accepts only --owner and --format; use wp background-tasks failed-runs list [--owner=<owner>] [--format=<format>].',
			),
			'list flag'              => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => 'List accepts only --owner and --format; use wp background-tasks failed-runs list [--owner=<owner>] [--format=<format>].',
			),
			'list owner type'        => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'owner' => false ),
				'message'    => 'List owner is invalid; pass a value with --owner=<owner>.',
			),
			'list invalid owner'     => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'owner' => 'Consumer-Plugin' ),
				'message'    => 'List owner is invalid; pass a canonical owner with --owner=<owner>.',
			),
			'list format'            => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'ids' ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
			'list format type'       => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => true ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
			'retry missing run_id'   => array(
				'args'       => array( 'retry', 'consumer-plugin:email-digest' ),
				'assoc_args' => array(),
				'message'    => 'Retry requires exactly an identity and run_id; use wp background-tasks failed-runs retry <identity> <run_id>.',
			),
			'retry flag'             => array(
				'args'       => array( 'retry', 'consumer-plugin:email-digest', 'run-1' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => 'Retry requires exactly an identity and run_id; use wp background-tasks failed-runs retry <identity> <run_id>.',
			),
			'retry invalid identity' => array(
				'args'       => array( 'retry', 'email-digest', 'run-1' ),
				'assoc_args' => array(),
				'message'    => 'Retry identity is invalid; use a composed {owner}:{name} identity.',
			),
			'bare purge'             => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array(),
				'message'    => 'Purge requires exactly one identity or --all; use wp background-tasks failed-runs purge <identity> or purge --all.',
			),
			'purge name and all'     => array(
				'args'       => array( 'purge', 'consumer-plugin:email-digest' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => 'Purge requires exactly one identity or --all; use wp background-tasks failed-runs purge <identity> or purge --all.',
			),
			'purge negated all'      => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array( 'all' => false ),
				'message'    => 'Purge requires exactly one identity or --all; use wp background-tasks failed-runs purge <identity> or purge --all.',
			),
			'purge string all'       => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array( 'all' => 'false' ),
				'message'    => 'Purge requires exactly one identity or --all; use wp background-tasks failed-runs purge <identity> or purge --all.',
			),
			'purge name stray all'   => array(
				'args'       => array( 'purge', 'consumer-plugin:email-digest' ),
				'assoc_args' => array( 'all' => false ),
				'message'    => 'Purge requires exactly one identity or --all; use wp background-tasks failed-runs purge <identity> or purge --all.',
			),
			'purge extra name'       => array(
				'args'       => array( 'purge', 'consumer-plugin:email-digest', 'other' ),
				'assoc_args' => array(),
				'message'    => 'Purge requires exactly one identity or --all; use wp background-tasks failed-runs purge <identity> or purge --all.',
			),
			'purge invalid identity' => array(
				'args'       => array( 'purge', 'email-digest' ),
				'assoc_args' => array(),
				'message'    => 'Purge identity is invalid; use a composed {owner}:{name} identity.',
			),
			'purge flag'             => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array( 'format' => 'json' ),
				'message'    => 'Purge accepts only --all; use wp background-tasks failed-runs purge <identity> or purge --all.',
			),
		);
	}

	// endregion.
}
