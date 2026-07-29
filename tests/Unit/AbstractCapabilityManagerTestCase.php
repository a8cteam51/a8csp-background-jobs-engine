<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\ChunkedJobExecutionInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\ChunkedRunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\RunId;
use A8C\SpecialProjects\BackgroundJobsEngine\RunStatus;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use PHPUnit\Framework\TestCase;

/**
 * Supplies the deterministic production graph shared by capability-manager tests.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
abstract class AbstractCapabilityManagerTestCase extends TestCase {
	// region FIELDS AND CONSTANTS.

	protected const string MISSING_RUN_ID = '00000000001700000001-0000000000000000043';
	protected const int NOW               = 1_700_000_000;
	protected const string SCOPE          = 'engine-test';

	protected EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads the public functions, deterministic engine seams, and WordPress error stand-in.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
		require_once __DIR__ . '/wp-cron-stubs.php';
	}

	/**
	 * Publishes one isolated production graph.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->rig = EngineRig::set_up( self::NOW );
	}

	/**
	 * Clears the rig-owned WordPress state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function tearDown(): void {
		try {
			$this->rig->tear_down();
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Returns a minimal consumer-authored one-off job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param (\Closure(array<array-key, mixed>, RunContextInterface): void)|null $handler
	 *
	 * @param   string        $name    Stable scope-local job name.
	 * @param   \Closure|null $handler Optional invocation behavior.
	 *
	 * @return  JobDefinition
	 */
	protected static function job( string $name, ?\Closure $handler = null ): JobDefinition {
		return JobDefinition::closure( $name, $handler ?? static function (): void {} );
	}

	/**
	 * Returns a minimal consumer-authored chunked job.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Stable scope-local chunked job name.
	 *
	 * @return  JobDefinition
	 */
	protected static function chunked_job( string $name ): JobDefinition {
		$execution = new class() implements ChunkedJobExecutionInterface {
			/** {@inheritDoc} */
			#[\Override]
			public function generate_queue( array $start_args, RunContextInterface $context ): iterable {
				return array();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function process_chunk( array $chunk_args, ChunkedRunContextInterface $context ): void {}
		};

		return JobDefinition::chunked_job( $name, $execution );
	}

	/**
	 * Asserts one public run projection and returns it for identity chaining.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed      $value    Expected run value.
	 * @param   string     $identity Expected scope-qualified identity.
	 * @param   RunStatus  $status   Expected public lifecycle state.
	 * @param   RunId|null $id       Expected run identifier, or null to accept the generated identifier.
	 *
	 * @return  Run
	 */
	protected static function assert_run( mixed $value, string $identity, RunStatus $status, ?RunId $id = null ): Run {
		self::assertInstanceOf( Run::class, $value );
		self::assertSame( $identity, $value->identity );
		self::assertSame( $status, $value->status );
		self::assertNotSame( '', (string) $value->id );
		if ( null !== $id ) {
			self::assertSame( (string) $id, (string) $value->id );
		}

		return $value;
	}

	/**
	 * Asserts and returns one WordPress error result.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   mixed  $value Expected error value.
	 * @param   string $code  Expected stable error code.
	 *
	 * @return  \WP_Error
	 */
	protected static function assert_wp_error( mixed $value, string $code ): \WP_Error {
		self::assertInstanceOf( \WP_Error::class, $value );
		self::assertSame( $code, $value->get_error_code(), $value->get_error_message() );

		return $value;
	}

	/**
	 * Returns the latest scheduler call for one write verb.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   EngineRig $rig  Active production graph rig.
	 * @param   string    $verb Scheduler write verb.
	 *
	 * @return  array{verb: string, args: array<string, mixed>}
	 */
	protected static function latest_backend_call( EngineRig $rig, string $verb ): array {
		foreach ( \array_reverse( $rig->backend()->calls ) as $call ) {
			if ( $verb === $call['verb'] ) {
				return $call;
			}
		}

		self::fail( 'Expected a backend call for verb ' . $verb . '.' );
	}

	// endregion.
}
