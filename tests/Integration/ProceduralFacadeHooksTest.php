<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Integration;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\NonRetryableException;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Exercises the procedural lifecycle-listener seam against live WordPress hooks.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversFunction( 'a8csp_bgte_run_on_completed' )]
#[CoversFunction( 'a8csp_bgte_run_on_failed' )]
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
	public function test_run_on_completed_registers_and_fires_the_composed_hook(): void {
		$name     = 'completed-task';
		$identity = self::OWNER . ':' . $name;
		$args     = array( 'site_id' => 7 );
		/** @var list<array{string, array<array-key, mixed>}> $observed */
		$observed = array();
		$listener = static function ( string $run_id, array $start_args ) use ( &$observed ): void {
			$observed[] = array( $run_id, $start_args );
		};

		\a8csp_bgte_run_on_completed( self::OWNER, $name, $listener );
		self::assertSame( 10, \has_action( 'a8csp_background_tasks/completed/' . $identity, $listener ) );
		self::assertTrue( \a8csp_bgte_task_register( self::OWNER, $name, static function ( array $handler_args ): void {} ) );
		$this->expect_option( 'a8csp_bgte_latest_run_' . $identity );

		$run_id = \a8csp_bgte_task_enqueue( self::OWNER, $name, $args );
		self::assertIsString( $run_id );
		self::assertSame( 1, $this->run_next_engine_action() );

		self::assertSame( array( array( $run_id, $args ) ), $observed );
	}

	/**
	 * The failed listener uses the owner-qualified hook and receives the documented payload.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_run_on_failed_registers_and_fires_the_composed_hook(): void {
		$this->expectOutputRegex( '/Run failed permanently; correct the cause/' );
		$name     = 'failed-task';
		$identity = self::OWNER . ':' . $name;
		$args     = array( 'site_id' => 8 );
		/** @var list<array{string, array<array-key, mixed>, RunFailure}> $observed */
		$observed = array();
		$listener = static function ( string $run_id, array $start_args, RunFailure $failure ) use ( &$observed ): void {
			$observed[] = array( $run_id, $start_args, $failure );
		};

		\a8csp_bgte_run_on_failed( self::OWNER, $name, $listener );
		self::assertSame( 10, \has_action( 'a8csp_background_tasks/failed/' . $identity, $listener ) );
		self::assertTrue(
			\a8csp_bgte_task_register(
				self::OWNER,
				$name,
				static function ( array $handler_args ): void {
					throw new NonRetryableException( 'Permanent failure.' );
				}
			)
		);
		$this->expect_option( 'a8csp_bgte_latest_run_' . $identity );
		$this->expect_option( 'a8csp_bgte_failed_runs_' . $identity );

		$run_id = \a8csp_bgte_task_enqueue( self::OWNER, $name, $args );
		self::assertIsString( $run_id );
		self::assertSame( 1, $this->run_next_engine_action() );

		self::assertCount( 1, $observed );
		self::assertSame( $run_id, $observed[0][0] );
		self::assertSame( $args, $observed[0][1] );
		self::assertSame( $identity, $observed[0][2]->identity );
	}

	// endregion.
}
