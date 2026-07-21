<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

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
	private const array HOOK_LITERALS = array(
		'started'              => 'a8csp_jobs_engine/started',
		'completed'            => 'a8csp_jobs_engine/completed',
		'failed'               => 'a8csp_jobs_engine/failed',
		'cancelled'            => 'a8csp_jobs_engine/cancelled',
		'retry_scheduled'      => 'a8csp_jobs_engine/retry_scheduled',
		'superseded'           => 'a8csp_jobs_engine/superseded',
		'misfire_skipped'      => 'a8csp_jobs_engine/misfire_skipped',
		'log'                  => 'a8csp_jobs_engine/log',
		'log_to_error_log'     => 'a8csp_jobs_engine/log_to_error_log',
		'queue'                => 'a8csp_jobs_engine/queue/',
		'continue_delay'       => 'a8csp_jobs_engine/continue_delay',
		'lock_staleness'       => 'a8csp_jobs_engine/lock_staleness/',
		'history_size'         => 'a8csp_jobs_engine/history_size',
		'retry_policy'         => 'a8csp_jobs_engine/retry_policy/',
		'misfire_grace'        => 'a8csp_jobs_engine/misfire_grace/',
		'start_chunked_job'    => 'a8csp_jobs_engine/start_chunked_job',
		'continue_chunked_job' => 'a8csp_jobs_engine/continue_chunked_job',
		'run_job'              => 'a8csp_jobs_engine/run_job',
		'cleanup_chunked_job'  => 'a8csp_jobs_engine/cleanup_chunked_job',
		'schedule_due'         => 'a8csp_jobs_engine/schedule_due',
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
