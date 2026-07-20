<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Engine;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\ChunkedJob\ChunkedJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Run\RunContextInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\Job\OneOffJobInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Engine\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins registration channels, typed lookup, and the shared work-identity namespace.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( JobRegistry::class )]
final class JobRegistryTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies production boot guards before work contracts are autoloaded.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/wp-time-constant-stubs.php';
	}

	// endregion.

	// region TESTS.

	/**
	 * Typed lookups return exact registered instances and reject unknown or wrong-kind identities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_typed_lookups_return_registered_instances_and_null_for_unknown_or_wrong_kind(): void {
		$job         = new RecordingJob( 'refresh_index-2' );
		$chunked_job = new RecordingChunkedJob( 'rebuild-index' );
		$work        = new JobRegistry();

		$work->register_job( 'consumer:refresh_index-2', $job );
		$work->register_chunked_job( 'consumer:rebuild-index', $chunked_job );

		self::assertSame( $job, $work->job( 'consumer:refresh_index-2' ) );
		self::assertNull( $work->chunked_job( 'consumer:refresh_index-2' ) );
		self::assertSame( $chunked_job, $work->chunked_job( 'consumer:rebuild-index' ) );
		self::assertNull( $work->job( 'consumer:rebuild-index' ) );
		self::assertNull( $work->job( 'consumer:unknown' ) );
		self::assertNull( $work->chunked_job( 'consumer:unknown' ) );
	}

	/**
	 * Kind lookup returns the registration-channel tag and null for an unknown identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_kind_returns_the_registration_channel_tag_and_null_for_unknown_identity(): void {
		$work = new JobRegistry();

		$work->register_job( 'consumer:sync-job', new RecordingJob( 'sync-job' ) );
		$work->register_chunked_job( 'consumer:sync-chunked-job', new RecordingChunkedJob( 'sync-chunked-job' ) );

		self::assertSame( 'job', $work->kind( 'consumer:sync-job' ) );
		self::assertSame( 'chunked_job', $work->kind( 'consumer:sync-chunked-job' ) );
		self::assertNull( $work->kind( 'consumer:unknown' ) );
	}

	/**
	 * Job registration accepts the 64-byte local-name boundary and rejects 65 bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_job_accepts_64_name_bytes_and_rejects_65(): void {
		$name     = \str_repeat( 'a', 64 );
		$accepted = new RecordingJob( $name );
		$work     = new JobRegistry();

		$work->register_job( 'consumer:' . $name, $accepted );
		self::assertSame( $accepted, $work->job( 'consumer:' . $name ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		$work->register_job( 'consumer:valid', new RecordingJob( \str_repeat( 'a', 65 ) ) );
	}

	/**
	 * Chunked Job registration accepts the 64-byte local-name boundary and rejects 65 bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_chunked_job_accepts_64_name_bytes_and_rejects_65(): void {
		$name     = \str_repeat( 'a', 64 );
		$accepted = new RecordingChunkedJob( $name );
		$work     = new JobRegistry();

		$work->register_chunked_job( 'consumer:' . $name, $accepted );
		self::assertSame( $accepted, $work->chunked_job( 'consumer:' . $name ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		$work->register_chunked_job( 'consumer:valid', new RecordingChunkedJob( \str_repeat( 'a', 65 ) ) );
	}

	/**
	 * Both registration channels reject declarations outside the local-name grammar.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'chunked_job'|'job' $channel Registration channel under test.
	 * @param   string         $name    Invalid declared local name.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_names' )]
	public function test_registration_rejects_invalid_declared_names( string $channel, string $name ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		$work = new JobRegistry();
		if ( 'job' === $channel ) {
			$work->register_job( 'consumer:valid', new RecordingJob( $name ) );
			return;
		}

		$work->register_chunked_job( 'consumer:valid', new RecordingChunkedJob( $name ) );
	}

	/**
	 * Both channels reject non-canonical or name-mismatched identities with their exact diagnostics.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'chunked_job'|'job' $channel  Registration channel under test.
	 * @param   string         $identity Invalid or mismatched identity.
	 * @param   string         $message  Expected channel-specific diagnostic.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_identities' )]
	public function test_registration_rejects_invalid_or_mismatched_identities( string $channel, string $identity, string $message ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( $message );

		$work = new JobRegistry();
		if ( 'job' === $channel ) {
			$work->register_job( $identity, new RecordingJob( 'valid' ) );
			return;
		}

		$work->register_chunked_job( $identity, new RecordingChunkedJob( 'valid' ) );
	}

	/**
	 * A job identity rejects a second job registration without replacing the first.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_job_rejects_a_same_kind_duplicate(): void {
		$work = new JobRegistry();
		$work->register_job( 'consumer:sync', new RecordingJob( 'sync' ) );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs( 'Job name is already registered; register each job name exactly once.' );

		$work->register_job( 'consumer:sync', new RecordingJob( 'sync' ) );
	}

	/**
	 * A chunked job identity rejects a second chunked job registration without replacing the first.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_chunked_job_rejects_a_same_kind_duplicate(): void {
		$work = new JobRegistry();
		$work->register_chunked_job( 'consumer:sync', new RecordingChunkedJob( 'sync' ) );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs( 'Chunked Job name is already registered; register each chunked job name exactly once.' );

		$work->register_chunked_job( 'consumer:sync', new RecordingChunkedJob( 'sync' ) );
	}

	/**
	 * A job-owned identity cannot also be registered through the chunked job channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_job_then_chunked_job_cross_kind_collision_uses_the_existing_diagnostic(): void {
		$work = new JobRegistry();
		$work->register_job( 'consumer:sync', new RecordingJob( 'sync' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work identity "consumer:sync" is already registered as a job; it cannot also be registered as a chunked job.' );

		$work->register_chunked_job( 'consumer:sync', new RecordingChunkedJob( 'sync' ) );
	}

	/**
	 * A chunked-job-owned identity cannot also be registered through the job channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_chunked_job_then_job_cross_kind_collision_uses_the_existing_diagnostic(): void {
		$work = new JobRegistry();
		$work->register_chunked_job( 'consumer:sync', new RecordingChunkedJob( 'sync' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work identity "consumer:sync" is already registered as a chunked job; it cannot also be registered as a job.' );

		$work->register_job( 'consumer:sync', new RecordingJob( 'sync' ) );
	}

	/**
	 * Equal local names under different owners remain independent registrations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_different_owners_can_register_the_same_local_name(): void {
		$job         = new RecordingJob( 'sync' );
		$chunked_job = new RecordingChunkedJob( 'sync' );
		$work        = new JobRegistry();

		$work->register_job( 'owner-a:sync', $job );
		$work->register_chunked_job( 'owner-b:sync', $chunked_job );

		self::assertSame( $job, $work->job( 'owner-a:sync' ) );
		self::assertSame( $chunked_job, $work->chunked_job( 'owner-b:sync' ) );
		self::assertSame( 'job', $work->kind( 'owner-a:sync' ) );
		self::assertSame( 'chunked_job', $work->kind( 'owner-b:sync' ) );
	}

	/**
	 * A dual-interface contract registered as a job is visible only through the job channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dual_interface_contract_registered_as_job_uses_the_job_channel(): void {
		$dual = $this->dual_work( 'sync' );
		$work = new JobRegistry();

		$work->register_job( 'consumer:sync', $dual );

		self::assertSame( $dual, $work->job( 'consumer:sync' ) );
		self::assertNull( $work->chunked_job( 'consumer:sync' ) );
		self::assertSame( 'job', $work->kind( 'consumer:sync' ) );
	}

	/**
	 * A dual-interface contract registered as a chunked job is visible only through the chunked job channel.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_dual_interface_contract_registered_as_chunked_job_uses_the_chunked_job_channel(): void {
		$dual = $this->dual_work( 'sync' );
		$work = new JobRegistry();

		$work->register_chunked_job( 'consumer:sync', $dual );

		self::assertSame( $dual, $work->chunked_job( 'consumer:sync' ) );
		self::assertNull( $work->job( 'consumer:sync' ) );
		self::assertSame( 'chunked_job', $work->kind( 'consumer:sync' ) );
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Supplies invalid names for both registration channels.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{channel: 'chunked_job'|'job', name: string}>
	 */
	public static function invalid_names(): array {
		$cases = array();
		foreach ( array( '', 'RefreshIndex', 'refresh index', 'refresh.index', 'réindex' ) as $name ) {
			$key = '' === $name ? 'empty' : $name;

			$cases[ 'job-' . $key ]         = array(
				'channel' => 'job',
				'name'    => $name,
			);
			$cases[ 'chunked-job-' . $key ] = array(
				'channel' => 'chunked_job',
				'name'    => $name,
			);
		}

		return $cases;
	}

	/**
	 * Supplies non-canonical and declared-name-mismatched identities for both channels.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array<string, array{channel: 'chunked_job'|'job', identity: string, message: string}>
	 */
	public static function invalid_identities(): array {
		return array(
			'job-non-canonical'         => array(
				'channel'  => 'job',
				'identity' => 'not-canonical',
				'message'  => 'Job identity must be canonical and end with the job\'s declared local name.',
			),
			'job-name-mismatch'         => array(
				'channel'  => 'job',
				'identity' => 'consumer:other',
				'message'  => 'Job identity must be canonical and end with the job\'s declared local name.',
			),
			'chunked-job-non-canonical' => array(
				'channel'  => 'chunked_job',
				'identity' => 'not-canonical',
				'message'  => 'Chunked Job identity must be canonical and end with the chunked job\'s declared local name.',
			),
			'chunked-job-name-mismatch' => array(
				'channel'  => 'chunked_job',
				'identity' => 'consumer:other',
				'message'  => 'Chunked Job identity must be canonical and end with the chunked job\'s declared local name.',
			),
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Creates one contract implementing both work interfaces.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Declared local work name.
	 *
	 * @return  OneOffJobInterface&ChunkedJobInterface
	 */
	private function dual_work( string $name ): OneOffJobInterface&ChunkedJobInterface {
		return new class( $name ) implements OneOffJobInterface, ChunkedJobInterface {
			/**
			 * Constructor.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string $name Declared local work name.
			 */
			public function __construct(
				private readonly string $name,
			) {}

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return $this->name;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function max_callback_runtime(): int {
				return self::DEFAULT_MAX_CALLBACK_RUNTIME;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function overlap_policy(): OverlapPolicy {
				return OverlapPolicy::Reject;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function overlap_key( array $start_args ): ?string {
				return null;
			}

			/**
			 * Accepts an unused job invocation.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $args    Unused job arguments.
			 * @param   RunContextInterface     $context Unused run context.
			 *
			 * @return  void
			 */
			#[\Override]
			public function handle( array $args, RunContextInterface $context ): void {}

			/**
			 * Returns an empty queue for the registry-only contract.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $start_args Unused start arguments.
			 * @param   RunContextInterface     $context    Unused run context.
			 *
			 * @return  iterable<array<array-key, mixed>>
			 */
			#[\Override]
			public function generate_queue( array $start_args, RunContextInterface $context ): iterable {
				return array();
			}

			/**
			 * Accepts an unused chunked job chunk.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   array<array-key, mixed> $chunk_args Unused chunk arguments.
			 * @param   ChunkContextInterface   $context    Unused chunked job context.
			 *
			 * @return  void
			 */
			#[\Override]
			public function process_chunk( array $chunk_args, ChunkContextInterface $context ): void {}

			/**
			 * Accepts an unused chunked job completion.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $run_id                    Unused run identifier.
			 * @param   array<array-key, mixed> $start_args                Unused start arguments.
			 * @param   string|null             $previous_completed_run_id Unused previous completed run identifier.
			 *
			 * @return  void
			 */
			#[\Override]
			public function on_completed( string $run_id, array $start_args, ?string $previous_completed_run_id ): void {}

			/**
			 * Accepts an unused failed chunked job outcome.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   string                  $run_id     Unused run identifier.
			 * @param   array<array-key, mixed> $start_args Unused start arguments.
			 * @param   RunFailure              $failure    Unused terminal failure.
			 *
			 * @return  void
			 */
			#[\Override]
			public function on_failed( string $run_id, array $start_args, RunFailure $failure ): void {}

			/** {@inheritDoc} */
			#[\Override]
			public function get_retry_policy(): RetryPolicy {
				return new RetryPolicy();
			}
		};
	}

	// endregion.
}
