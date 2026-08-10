<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the complete steady-state option footprint after successful job and chunked job runs.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[Group( 'degraded' )]
final class OptionsHygieneTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	/** Public scope unique to this integration-test graph. */
	private const string SCOPE = 'integration-options-hygiene';

	/** Job identity unique within the request-persistent integration registry. */
	private const string JOB_NAME = 'integration-options-job';

	/** Scope-qualified job identity persisted by the engine. */
	private const string JOB_IDENTITY = self::SCOPE . ':' . self::JOB_NAME;

	/** Chunked Job identity unique within the request-persistent integration registry. */
	private const string CHUNKED_JOB_NAME = 'integration-options-chunked-job';

	/** Scope-qualified chunked job identity persisted by the engine. */
	private const string CHUNKED_JOB_IDENTITY = self::SCOPE . ':' . self::CHUNKED_JOB_NAME;

	// endregion.

	// region TESTS.

	/**
	 * Complete job and chunked job lifecycles retain only non-autoloaded latest and history rows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_lifecycles_leave_only_bounded_non_autoloaded_options(): void {
		$job_args           = array( 'scope' => 'job-census' );
		$job                = new RecordingJob( self::JOB_NAME );
		$chunked_job_args   = array( 'scope' => 'chunked-job-census' );
		$chunked_job        = new RecordingChunkedJob( self::CHUNKED_JOB_NAME );
		$chunked_job->queue = array( array( 'chunk' => 'only' ) );

		$client = \A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component::operations( self::SCOPE );
		$client->register( $job->definition() );
		$client->register( $chunked_job->definition() );

		$this->expect_option( 'a8csp_bgje_latest_run_' . self::JOB_IDENTITY );
		$this->expect_option( 'a8csp_bgje_latest_run_' . self::CHUNKED_JOB_IDENTITY );
		\add_filter( 'a8csp_bgje/continue_delay', static fn ( int $delay, string $name, string $run_id ): int => 0, 10, 3 );

		$chunked_completed = array();
		\add_action(
			'a8csp_bgje/completed/' . self::CHUNKED_JOB_IDENTITY,
			static function ( RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ) use ( &$chunked_completed ): void {
				$chunked_completed[] = array( (string) $run_id, $start_args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
			},
			10,
			3
		);

		$job_result = $client->dispatch( self::JOB_NAME, $job_args );
		self::assertInstanceOf( Run::class, $job_result, 'The census job must enqueue through the public API' );
		$job_run_id = (string) $job_result->id;
		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must complete the census job' );

		$chunked_job_result = $client->dispatch( self::CHUNKED_JOB_NAME, $chunked_job_args );
		self::assertInstanceOf( Run::class, $chunked_job_result, 'The census chunked job must start through the public API' );
		$chunked_job_run_id = (string) $chunked_job_result->id;
		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must generate the census chunked job queue' );
		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must process the census chunked job chunk inline' );
		self::assertSame( 1, $this->run_next_engine_action(), 'The available scheduler must observe the drained census chunked job queue and complete the run' );

		self::assertSame( array( $job_args ), $job->calls, 'The census job must complete its full lifecycle' );
		self::assertCount( 1, $chunked_job->process_calls, 'The census chunked job must process its only chunk exactly once' );
		self::assertSame( array( 'chunk' => 'only' ), $chunked_job->process_calls[0]['chunk_args'] ?? null );
		self::assertSame(
			array(
				array( $chunked_job_run_id, $chunked_job_args, null ),
			),
			$chunked_completed,
			'The census chunked job must publish its completed lifecycle payload'
		);

		$rows = $this->engine_option_rows();
		self::assertCount( 4, $rows, 'Two completed identities must retain a bounded four-row engine footprint' );

		$autoloaded_values = \wp_autoload_values_to_autoload();
		foreach ( $rows as $row ) {
			self::assertStringStartsWith( 'a8csp_bgje_', $row['option_name'], 'Every retained row must stay inside the documented engine ownership prefix' );
			self::assertNotContains( $row['autoload'], $autoloaded_values, \sprintf( 'Engine option "%s" must persist with autoload=false', $row['option_name'] ) );
		}

		$job_latest = $client->last_completed_run( self::JOB_NAME );
		self::assertInstanceOf( Run::class, $job_latest );
		self::assertSame( $job_run_id, (string) $job_latest->id );
		$chunked_job_latest = $client->last_completed_run( self::CHUNKED_JOB_NAME );
		self::assertInstanceOf( Run::class, $chunked_job_latest );
		self::assertSame( $chunked_job_run_id, (string) $chunked_job_latest->id );
	}

	// endregion.
}
