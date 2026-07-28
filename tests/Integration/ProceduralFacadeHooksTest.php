<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\NonRetryableException;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Exercises terminal lifecycle-hook delivery against live WordPress hooks.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversFunction( 'a8csp_bgje_register_job' )]
#[CoversFunction( 'a8csp_bgje_dispatch_job' )]
final class ProceduralFacadeHooksTest extends AbstractIntegrationTestCase {
	// region FIELDS AND CONSTANTS.

	private const string SCOPE = 'procedural-listeners';

	// endregion.

	// region TESTS.

	/**
	 * The completed listener uses the scope-qualified hook and receives the documented payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_completed_hook_registers_and_fires_with_the_predecessor_payload(): void {
		$name     = 'completed-job';
		$identity = self::SCOPE . ':' . $name;
		$args     = array( 'site_id' => 7 );
		/** @var list<array{string, array<array-key, mixed>, string|null}> $observed */
		$observed = array();
		$listener = static function ( RunId $run_id, array $start_args, ?RunId $previous_completed_run_id ) use ( &$observed ): void {
			$observed[] = array( (string) $run_id, $start_args, null === $previous_completed_run_id ? null : (string) $previous_completed_run_id );
		};

		\add_action( 'a8csp_bgje/completed/' . $identity, $listener, 10, 3 );
		self::assertSame( 10, \has_action( 'a8csp_bgje/completed/' . $identity, $listener ) );
		self::assertTrue( \a8csp_bgje_register_job( self::SCOPE, JobDefinition::closure( $name, static function ( array $start_args, RunContextInterface $context ): void {} ) ) );
		$this->expect_option( 'a8csp_bgje_latest_run_' . $identity );

		$run = \a8csp_bgje_dispatch_job( self::SCOPE, $name, $args );
		self::assertInstanceOf( Run::class, $run );
		self::assertSame( 1, $this->run_next_engine_action() );

		self::assertSame( array( array( (string) $run->id, $args, null ) ), $observed );
	}

	/**
	 * Failed listeners receive one shared failure value in identity-specific-to-generic order.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_failed_hooks_fire_identity_specific_then_generic_with_the_same_run_failure(): void {
		$this->expectOutputRegex( '/Run failed permanently; correct the cause/' );
		$name     = 'failed-job';
		$identity = self::SCOPE . ':' . $name;
		$args     = array( 'site_id' => 8 );
		/** @var list<RunFailure> $observed */
		$observed = array();
		/** @var list<string> $observed_hooks */
		$observed_hooks = array();
		/** @var list<list<mixed>> $observed_extra_args */
		$observed_extra_args = array();
		/** @var list<int> $observed_arities */
		$observed_arities  = array();
		$specific_listener = static function ( RunFailure $failure, mixed ...$extra_args ) use ( &$observed, &$observed_hooks, &$observed_extra_args, &$observed_arities ): void {
			$observed[]            = $failure;
			$observed_hooks[]      = 'specific';
			$observed_extra_args[] = $extra_args;
			$observed_arities[]    = \func_num_args();
		};
		$generic_listener  = static function ( RunFailure $failure, mixed ...$extra_args ) use ( &$observed, &$observed_hooks, &$observed_extra_args, &$observed_arities ): void {
			$observed[]            = $failure;
			$observed_hooks[]      = 'generic';
			$observed_extra_args[] = $extra_args;
			$observed_arities[]    = \func_num_args();
		};

		\add_action( 'a8csp_bgje/failed/' . $identity, $specific_listener, 10, 4 );
		\add_action( 'a8csp_bgje/failed', $generic_listener, 10, 4 );
		self::assertSame( 10, \has_action( 'a8csp_bgje/failed/' . $identity, $specific_listener ) );
		self::assertSame( 10, \has_action( 'a8csp_bgje/failed', $generic_listener ) );
		self::assertTrue(
			\a8csp_bgje_register_job(
				self::SCOPE,
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

		$run = \a8csp_bgje_dispatch_job( self::SCOPE, $name, $args );
		self::assertInstanceOf( Run::class, $run );
		self::assertSame( 1, $this->run_next_engine_action() );

		self::assertSame( array( 'specific', 'generic' ), $observed_hooks );
		self::assertCount( 2, $observed );
		self::assertSame( $observed[0], $observed[1], 'Both failed hooks must receive the same RunFailure instance' );
		self::assertSame( array( 1, 1 ), $observed_arities );
		self::assertSame( array( array(), array() ), $observed_extra_args );
		self::assertSame( $identity, $observed[0]->identity );
		self::assertSame( (string) $run->id, (string) $observed[0]->run_id );
		self::assertSame( 1, $observed[0]->attempts );
		self::assertSame( 'execution', $observed[0]->stage->value );
		self::assertSame( 'execution_failed', $observed[0]->code->value );
		self::assertSame( \sprintf( 'Background-work execution failed because %s was thrown.', NonRetryableException::class ), $observed[0]->summary );
		self::assertNull( $observed[0]->details );
		self::assertSame( 1, \did_action( 'a8csp_bgje/failed/' . $identity ) );
		self::assertSame( 1, \did_action( 'a8csp_bgje/failed' ) );
	}

	// endregion.
}
