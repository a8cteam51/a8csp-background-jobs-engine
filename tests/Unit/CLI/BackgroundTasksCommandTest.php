<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\CLI;

use A8C\SpecialProjects\BackgroundTasksEngine\CLI\BackgroundTasksCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the WP-free command decisions and failed-run row formatting.
 */
#[CoversClass( BackgroundTasksCommand::class )]
final class BackgroundTasksCommandTest extends TestCase {
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
				'name'   => 'email-digest',
				'run_id' => 'run-1',
			),
			BackgroundTasksCommand::cancel_request_from_args(
				array( 'email-digest', 'run-1' ),
				array()
			)
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
				'message' => 'Cancel requires exactly a name and run_id; use wp background-tasks cancel <name> <run_id>.',
			),
			BackgroundTasksCommand::cancel_request_from_args( $args, $assoc_args )
		);
	}

	/**
	 * Rows expose the exact fields in name, timestamp, and run-identifier order.
	 *
	 * @return  void
	 */
	public function test_rows_are_shaped_and_ordered_deterministically(): void {
		$rows = BackgroundTasksCommand::rows_from_entries(
			array(
				'zeta-task'  => array(
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
				'alpha-task' => array(
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
					'name'          => 'alpha-task',
					'run_id'        => 'run-c',
					'failed_at'     => '1970-01-01T00:00:01+00:00',
					'attempts'      => 1,
					'error_class'   => null,
					'error_message' => 'First alpha failure.',
				),
				array(
					'name'          => 'alpha-task',
					'run_id'        => 'run-a',
					'failed_at'     => '1970-01-01T00:00:02+00:00',
					'attempts'      => 3,
					'error_class'   => null,
					'error_message' => 'Tied alpha failure.',
				),
				array(
					'name'          => 'alpha-task',
					'run_id'        => 'run-b',
					'failed_at'     => '1970-01-01T00:00:02+00:00',
					'attempts'      => 2,
					'error_class'   => \LogicException::class,
					'error_message' => 'Second alpha failure.',
				),
				array(
					'name'          => 'zeta-task',
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
	 * No retained entries produce no output rows.
	 *
	 * @return  void
	 */
	public function test_empty_entries_produce_an_empty_row_list(): void {
		self::assertSame( array(), BackgroundTasksCommand::rows_from_entries( array() ) );
	}

	/**
	 * Discovery accepts only stable suffixes under the exact failed-run option prefix.
	 *
	 * @return  void
	 */
	public function test_discovered_option_names_are_filtered_deduplicated_and_sorted(): void {
		self::assertSame(
			array( 'alpha-task', 'alpha_task', 'zeta' ),
			BackgroundTasksCommand::names_from_option_names(
				array(
					'a8csp_bgte_failed_zeta',
					42,
					'other_failed_alpha-task',
					'a8csp_bgte_failed_',
					'a8csp_bgte_failed_Alpha',
					'a8csp_bgte_failed_alpha/task',
					'a8csp_bgte_failed_alpha-task',
					'a8csp_bgte_failed_alpha_task',
					'a8csp_bgte_failed_alpha-task',
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
		self::assertSame( $expected, BackgroundTasksCommand::request_from_args( $args, $assoc_args ) );
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
			BackgroundTasksCommand::request_from_args( $args, $assoc_args )
		);
	}

	// endregion.

	// region DATA PROVIDERS.

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
					'format' => 'table',
				),
			),
			'list csv'     => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'csv' ),
				'expected'   => array(
					'action' => 'list',
					'format' => 'csv',
				),
			),
			'list json'    => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'json' ),
				'expected'   => array(
					'action' => 'list',
					'format' => 'json',
				),
			),
			'list count'   => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'count' ),
				'expected'   => array(
					'action' => 'list',
					'format' => 'count',
				),
			),
			'list yaml'    => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'yaml' ),
				'expected'   => array(
					'action' => 'list',
					'format' => 'yaml',
				),
			),
			'retry'        => array(
				'args'       => array( 'retry', 'email-digest', 'run-1' ),
				'assoc_args' => array(),
				'expected'   => array(
					'action' => 'retry',
					'name'   => 'email-digest',
					'run_id' => 'run-1',
				),
			),
			'purge name'   => array(
				'args'       => array( 'purge', 'email_digest-2' ),
				'assoc_args' => array(),
				'expected'   => array(
					'action' => 'purge',
					'name'   => 'email_digest-2',
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
			'missing action'       => array(
				'args'       => array(),
				'assoc_args' => array(),
				'message'    => 'A failed-run action is required; use list, retry <name> <run_id>, purge <name>, or purge --all.',
			),
			'unknown action'       => array(
				'args'       => array( 'remove' ),
				'assoc_args' => array(),
				'message'    => 'Failed-run action "remove" is invalid; use list, retry, or purge.',
			),
			'list positional'      => array(
				'args'       => array( 'list', 'email-digest' ),
				'assoc_args' => array(),
				'message'    => 'List accepts only --format; use wp background-tasks failed list [--format=<format>].',
			),
			'list flag'            => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => 'List accepts only --format; use wp background-tasks failed list [--format=<format>].',
			),
			'list format'          => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => 'ids' ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
			'list format type'     => array(
				'args'       => array( 'list' ),
				'assoc_args' => array( 'format' => true ),
				'message'    => 'List format is invalid; use table, csv, json, count, or yaml.',
			),
			'retry missing run_id' => array(
				'args'       => array( 'retry', 'email-digest' ),
				'assoc_args' => array(),
				'message'    => 'Retry requires exactly a name and run_id; use wp background-tasks failed retry <name> <run_id>.',
			),
			'retry flag'           => array(
				'args'       => array( 'retry', 'email-digest', 'run-1' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => 'Retry requires exactly a name and run_id; use wp background-tasks failed retry <name> <run_id>.',
			),
			'bare purge'           => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array(),
				'message'    => 'Purge requires exactly one name or --all; use wp background-tasks failed purge <name> or purge --all.',
			),
			'purge name and all'   => array(
				'args'       => array( 'purge', 'email-digest' ),
				'assoc_args' => array( 'all' => true ),
				'message'    => 'Purge requires exactly one name or --all; use wp background-tasks failed purge <name> or purge --all.',
			),
			'purge negated all'    => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array( 'all' => false ),
				'message'    => 'Purge requires exactly one name or --all; use wp background-tasks failed purge <name> or purge --all.',
			),
			'purge string all'     => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array( 'all' => 'false' ),
				'message'    => 'Purge requires exactly one name or --all; use wp background-tasks failed purge <name> or purge --all.',
			),
			'purge name stray all' => array(
				'args'       => array( 'purge', 'email-digest' ),
				'assoc_args' => array( 'all' => false ),
				'message'    => 'Purge requires exactly one name or --all; use wp background-tasks failed purge <name> or purge --all.',
			),
			'purge extra name'     => array(
				'args'       => array( 'purge', 'email-digest', 'other' ),
				'assoc_args' => array(),
				'message'    => 'Purge requires exactly one name or --all; use wp background-tasks failed purge <name> or purge --all.',
			),
			'purge invalid name'   => array(
				'args'       => array( 'purge', 'Email Digest' ),
				'assoc_args' => array(),
				'message'    => 'Purge name is invalid; use lowercase letters, digits, underscores, and hyphens.',
			),
			'purge flag'           => array(
				'args'       => array( 'purge' ),
				'assoc_args' => array( 'format' => 'json' ),
				'message'    => 'Purge accepts only --all; use wp background-tasks failed purge <name> or purge --all.',
			),
		);
	}

	// endregion.
}
