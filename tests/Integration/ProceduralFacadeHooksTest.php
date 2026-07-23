<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Run\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Exercises terminal lifecycle-hook delivery against live WordPress hooks.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversFunction( 'a8csp_bgje_register_job' )]
#[CoversFunction( 'a8csp_bgje_dispatch_job' )]
final class ProceduralFacadeHooksTest extends IntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	private const string OWNER = 'procedural-listeners';

	// endregion.

	// region TESTS.

	/**
	 * The completed listener uses the owner-qualified hook and receives the documented payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_hook_registers_and_fires_with_the_predecessor_payload(): void {
		$name     = 'completed-job';
		$identity = self::OWNER . ':' . $name;
		$args     = array( 'site_id' => 7 );
		/** @var list<array{string, array<array-key, mixed>, string|null}> $observed */
		$observed = array();
		$listener = static function ( RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ) use ( &$observed ): void {
			$observed[] = array( (string) $run_id, $start_args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
		};

		\add_action( 'a8csp_jobs_engine/completed/' . $identity, $listener, 10, 3 );
		self::assertSame( 10, \has_action( 'a8csp_jobs_engine/completed/' . $identity, $listener ) );
		self::assertTrue( \a8csp_bgje_register_job( self::OWNER, JobDefinition::closure( $name, static function ( array $start_args, RunContextInterface $context ): void {} ) ) );
		$this->expect_option( 'a8csp_bgje_latest_run_' . $identity );

		$run = \a8csp_bgje_dispatch_job( self::OWNER, $name, $args );
		self::assertInstanceOf( Run::class, $run );
		self::assertSame( 1, $this->run_next_engine_action() );

		self::assertSame( array( array( (string) $run->id, $args, null ) ), $observed );
	}

	/**
	 * The failed listener receives one self-identifying failure value through the generic hook.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_hook_registers_and_fires_with_the_run_failure_object(): void {
		$this->expectOutputRegex( '/Run failed permanently; correct the cause/' );
		$name     = 'failed-job';
		$identity = self::OWNER . ':' . $name;
		$args     = array( 'site_id' => 8 );
		/** @var list<RunFailure> $observed */
		$observed = array();
		/** @var list<list<mixed>> $observed_extra_args */
		$observed_extra_args = array();
		$observed_arity      = null;
		$listener            = static function ( RunFailure $failure, mixed ...$extra_args ) use ( &$observed, &$observed_extra_args, &$observed_arity ): void {
			$observed[]            = $failure;
			$observed_extra_args[] = $extra_args;
			$observed_arity        = \func_num_args();
		};

		\add_action( 'a8csp_jobs_engine/failed', $listener, 10, 4 );
		self::assertSame( 10, \has_action( 'a8csp_jobs_engine/failed', $listener ) );
		self::assertTrue(
			\a8csp_bgje_register_job(
				self::OWNER,
				JobDefinition::closure(
					$name,
					static function ( array $start_args, RunContextInterface $context ): void {
						throw new NonRetryableException( 'Permanent failure.' );
					}
				)
			)
		);
		$this->expect_option( 'a8csp_bgje_latest_run_' . $identity );
		$this->expect_option( 'a8csp_bgje_failed_runs_' . $identity );

		$run = \a8csp_bgje_dispatch_job( self::OWNER, $name, $args );
		self::assertInstanceOf( Run::class, $run );
		self::assertSame( 1, $this->run_next_engine_action() );

		self::assertCount( 1, $observed );
		self::assertSame( 1, $observed_arity );
		self::assertSame( array( array() ), $observed_extra_args );
		self::assertSame( $identity, $observed[0]->identity );
		self::assertSame( (string) $run->id, (string) $observed[0]->run_id );
		self::assertSame( 1, $observed[0]->attempts );
		self::assertSame( 'execution', $observed[0]->stage->value );
		self::assertSame( 'execution_failed', $observed[0]->code->value );
		self::assertSame( \sprintf( 'Background-work execution failed because %s was thrown.', NonRetryableException::class ), $observed[0]->summary );
		self::assertNull( $observed[0]->details );
		self::assertSame( 0, \did_action( 'a8csp_jobs_engine/failed/' . $identity ) );
	}

	// endregion.
}
