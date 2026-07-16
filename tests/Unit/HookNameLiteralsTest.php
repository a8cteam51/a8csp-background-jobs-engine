<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards static WordPress hook name searchability in production source.
 *
 */
final class HookNameLiteralsTest extends TestCase {
	/**
	 * Required source literals keyed by their documented names.
	 *
	 * The trailing slash pins dynamic prefixes without constraining their runtime segments.
	 *
	 * @var     array<string, string>
	 */
	private const HOOK_LITERALS = array(
		'started'          => 'a8csp_background_tasks/started',
		'completed'        => 'a8csp_background_tasks/completed',
		'failed'           => 'a8csp_background_tasks/failed',
		'cancelled'        => 'a8csp_background_tasks/cancelled',
		'retry_scheduled'  => 'a8csp_background_tasks/retry_scheduled',
		'superseded'       => 'a8csp_background_tasks/superseded',
		'misfire_skipped'  => 'a8csp_background_tasks/misfire_skipped',
		'log'              => 'a8csp_background_tasks/log',
		'log_to_error_log' => 'a8csp_background_tasks/log_to_error_log',
		'queue'            => 'a8csp_background_tasks/queue/',
		'continue_delay'   => 'a8csp_background_tasks/continue_delay',
		'lock_staleness'   => 'a8csp_background_tasks/lock_staleness/',
		'history_size'     => 'a8csp_background_tasks/history_size',
		'retry_policy'     => 'a8csp_background_tasks/retry_policy/',
		'misfire_grace'    => 'a8csp_background_tasks/misfire_grace/',
		'start'            => 'a8csp_background_tasks/start',
		'continue'         => 'a8csp_background_tasks/continue',
		'run'              => 'a8csp_background_tasks/run',
		'cleanup'          => 'a8csp_background_tasks/cleanup',
		'schedule_due'     => 'a8csp_background_tasks/schedule_due',
	);

	/**
	 * Every documented static name must be a literal in production source.
	 *
	 * @return  void
	 */
	public function test_hook_names_are_literal_in_production_source(): void {
		$strings = '';
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( \dirname( __DIR__, 2 ) . '/src', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local production source is the subject of this WP-less unit test.
			$contents = \file_get_contents( $file->getPathname() );
			self::assertNotFalse( $contents );

			// Only string-literal tokens count: a name surviving solely in a comment is not code.
			foreach ( \token_get_all( $contents ) as $token ) {
				if ( \is_array( $token ) && \T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$strings .= $token[1] . "\n";
				}
			}
		}

		foreach ( self::HOOK_LITERALS as $name => $literal ) {
			self::assertTrue( \str_contains( $strings, "'" . $literal . "'" ) || \str_contains( $strings, '"' . $literal . '"' ), \sprintf( 'Hook %s is not a production string literal.', $name ) );
		}
	}
}
