<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\Chunked\ChunkedJobExecution;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobDefinition;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobExecution;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobKind;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RetryPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Job\RunContext;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\DuplicateRegistrationException;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\JobRegistry;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingChunkedJob;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins definition storage and the shared background-work identity namespace.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( JobRegistry::class )]
#[UsesClass( JobDefinition::class )]
#[UsesClass( JobKind::class )]
#[UsesClass( JobOptions::class )]
#[UsesClass( RetryPolicy::class )]
final class JobRegistryTest extends TestCase {
	// region LIFECYCLE.

	/**
	 * Satisfies production boot guards and loads WordPress time constants.
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
	 * Registration retains each definition's execution, options, and kind by exact identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registration_retains_definition_data_and_unknown_lookups_return_null(): void {
		$job_options     = new JobOptions(
			max_runtime: 42,
			retry: new RetryPolicy( max_attempts: 1, base_delay: 5, multiplier: 1, max_delay: 5 ),
			overlap: OverlapPolicy::Allow,
		);
		$chunked_options = new JobOptions( max_runtime: 84, overlap: OverlapPolicy::Replace );
		$job             = new RecordingJob( 'refresh_index-2' );
		$chunked_job     = new RecordingChunkedJob( 'rebuild-index' );
		$registry        = new JobRegistry();

		$registry->register( 'consumer:refresh_index-2', $job->definition( $job_options ) );
		$registry->register( 'consumer:rebuild-index', $chunked_job->definition( $chunked_options ) );

		self::assertSame( $job, $registry->execution( 'consumer:refresh_index-2' ) );
		self::assertSame( $job_options, $registry->options( 'consumer:refresh_index-2' ) );
		self::assertSame( 'job', $registry->kind( 'consumer:refresh_index-2' ) );
		self::assertSame( $chunked_job, $registry->execution( 'consumer:rebuild-index' ) );
		self::assertSame( $chunked_options, $registry->options( 'consumer:rebuild-index' ) );
		self::assertSame( 'chunked_job', $registry->kind( 'consumer:rebuild-index' ) );
		self::assertNull( $registry->execution( 'consumer:unknown' ) );
		self::assertNull( $registry->options( 'consumer:unknown' ) );
		self::assertNull( $registry->kind( 'consumer:unknown' ) );
	}

	/**
	 * The registry trusts the resolved handler's execution-compatibility decision.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_registration_does_not_revalidate_execution_compatibility(): void {
		$execution  = new \stdClass();
		$definition = JobDefinition::for_kind( 'sync', JobKind::job(), $execution );
		$registry   = new JobRegistry();

		$registry->register( 'consumer:sync', $definition );

		self::assertSame( $execution, $registry->execution( 'consumer:sync' ) );
		self::assertSame( 'job', $registry->kind( 'consumer:sync' ) );
	}

	/**
	 * Both installed kinds accept the 64-byte local-name boundary and reject 65 bytes.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'chunked_job'|'job' $kind Definition kind.
	 *
	 * @return  void
	 */
	#[DataProvider( 'work_kinds' )]
	public function test_registration_accepts_64_name_bytes_and_rejects_65( string $kind ): void {
		$name         = \str_repeat( 'a', 64 );
		$accepted     = self::registration( $kind, $name );
		$too_long     = self::registration( $kind, \str_repeat( 'a', 65 ) );
		$registry     = new JobRegistry();
		$accepted_key = 'consumer:' . $name;

		$registry->register( $accepted_key, $accepted['definition'] );
		self::assertSame( $accepted['execution'], $registry->execution( $accepted_key ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		$registry->register( 'consumer:valid', $too_long['definition'] );
	}

	/**
	 * Definition names outside the canonical local-name grammar are rejected uniformly.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'chunked_job'|'job' $kind Definition kind.
	 * @param   string              $name Invalid local name.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_names' )]
	public function test_registration_rejects_invalid_definition_names( string $kind, string $name ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		$registry = new JobRegistry();
		$registry->register( 'consumer:valid', self::registration( $kind, $name )['definition'] );
	}

	/**
	 * Non-canonical and name-mismatched identities share one definition-centric diagnostic.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'chunked_job'|'job' $kind     Definition kind.
	 * @param   string              $identity Invalid or mismatched identity.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_identities' )]
	public function test_registration_rejects_invalid_or_mismatched_identities( string $kind, string $identity ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work identity must be canonical and end with the definition\'s declared local name.' );

		$registry = new JobRegistry();
		$registry->register( $identity, self::registration( $kind, 'valid' )['definition'] );
	}

	/**
	 * A same-kind duplicate is rejected without replacing the first definition data.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'chunked_job'|'job' $kind Definition kind.
	 *
	 * @return  void
	 */
	#[DataProvider( 'work_kinds' )]
	public function test_registration_rejects_a_same_kind_duplicate_without_replacement( string $kind ): void {
		$first    = self::registration( $kind, 'sync', new JobOptions( max_runtime: 42 ) );
		$second   = self::registration( $kind, 'sync', new JobOptions( max_runtime: 84 ) );
		$registry = new JobRegistry();
		$registry->register( 'consumer:sync', $first['definition'] );

		try {
			$registry->register( 'consumer:sync', $second['definition'] );
			self::fail( 'A same-kind duplicate must be rejected.' );
		} catch ( DuplicateRegistrationException $exception ) {
			self::assertSame( $kind . ' name is already registered; register each background-work name exactly once.', $exception->getMessage() );
		}

		self::assertSame( $first['execution'], $registry->execution( 'consumer:sync' ) );
		self::assertSame( $first['definition']->options, $registry->options( 'consumer:sync' ) );
		self::assertSame( $kind, $registry->kind( 'consumer:sync' ) );
	}

	/**
	 * Cross-kind registration is rejected without replacing the first definition data.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'chunked_job'|'job' $existing_kind Existing definition kind.
	 * @param   'chunked_job'|'job' $incoming_kind Incoming definition kind.
	 *
	 * @return  void
	 */
	#[DataProvider( 'cross_kind_orders' )]
	public function test_registration_rejects_a_cross_kind_collision_without_replacement( string $existing_kind, string $incoming_kind ): void {
		$first    = self::registration( $existing_kind, 'sync', new JobOptions( max_runtime: 42 ) );
		$second   = self::registration( $incoming_kind, 'sync', new JobOptions( max_runtime: 84 ) );
		$registry = new JobRegistry();
		$registry->register( 'consumer:sync', $first['definition'] );

		try {
			$registry->register( 'consumer:sync', $second['definition'] );
			self::fail( 'A cross-kind collision must be rejected.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( \sprintf( 'Background-work identity "consumer:sync" is already registered as a %1$s; it cannot also be registered as a %2$s.', $existing_kind, $incoming_kind ), $exception->getMessage() );
		}

		self::assertSame( $first['execution'], $registry->execution( 'consumer:sync' ) );
		self::assertSame( $first['definition']->options, $registry->options( 'consumer:sync' ) );
		self::assertSame( $existing_kind, $registry->kind( 'consumer:sync' ) );
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
		$job         = self::registration( 'job', 'sync' );
		$chunked_job = self::registration( 'chunked_job', 'sync' );
		$registry    = new JobRegistry();

		$registry->register( 'owner-a:sync', $job['definition'] );
		$registry->register( 'owner-b:sync', $chunked_job['definition'] );

		self::assertSame( $job['execution'], $registry->execution( 'owner-a:sync' ) );
		self::assertSame( 'job', $registry->kind( 'owner-a:sync' ) );
		self::assertSame( $chunked_job['execution'], $registry->execution( 'owner-b:sync' ) );
		self::assertSame( 'chunked_job', $registry->kind( 'owner-b:sync' ) );
	}

	/**
	 * A dual-role execution retains the kind selected by its typed definition.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   'chunked_job'|'job' $kind Definition kind.
	 *
	 * @return  void
	 */
	#[DataProvider( 'work_kinds' )]
	public function test_dual_role_execution_uses_the_definition_kind( string $kind ): void {
		$execution  = self::dual_execution();
		$options    = new JobOptions( max_runtime: 42 );
		$definition = 'job' === $kind
			? JobDefinition::job( 'sync', $execution, $options )
			: JobDefinition::chunked_job( 'sync', $execution, $options );
		$registry   = new JobRegistry();

		$registry->register( 'consumer:sync', $definition );

		self::assertSame( $execution, $registry->execution( 'consumer:sync' ) );
		self::assertSame( $options, $registry->options( 'consumer:sync' ) );
		self::assertSame( $kind, $registry->kind( 'consumer:sync' ) );
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Supplies the installed definition kinds.
	 *
	 * @return  array<string, array{kind: 'chunked_job'|'job'}>
	 */
	public static function work_kinds(): array {
		return array(
			'job'         => array( 'kind' => 'job' ),
			'chunked job' => array( 'kind' => 'chunked_job' ),
		);
	}

	/**
	 * Supplies invalid names for both installed definition kinds.
	 *
	 * @return  array<string, array{kind: 'chunked_job'|'job', name: string}>
	 */
	public static function invalid_names(): array {
		$cases = array();
		foreach ( self::work_kinds() as $kind_label => $kind_row ) {
			foreach ( array( '', 'RefreshIndex', 'refresh index', 'refresh.index', 'réindex' ) as $name ) {
				$name_label = '' === $name ? 'empty' : $name;

				$cases[ $kind_label . ': ' . $name_label ] = array(
					'kind' => $kind_row['kind'],
					'name' => $name,
				);
			}
		}

		return $cases;
	}

	/**
	 * Supplies non-canonical and declared-name-mismatched identities for both kinds.
	 *
	 * @return  array<string, array{kind: 'chunked_job'|'job', identity: string}>
	 */
	public static function invalid_identities(): array {
		return array(
			'job: non-canonical'         => array(
				'kind'     => 'job',
				'identity' => 'not-canonical',
			),
			'job: name mismatch'         => array(
				'kind'     => 'job',
				'identity' => 'consumer:other',
			),
			'chunked job: non-canonical' => array(
				'kind'     => 'chunked_job',
				'identity' => 'not-canonical',
			),
			'chunked job: name mismatch' => array(
				'kind'     => 'chunked_job',
				'identity' => 'consumer:other',
			),
		);
	}

	/**
	 * Supplies both cross-kind registration orders.
	 *
	 * @return  array<string, array{existing_kind: 'chunked_job'|'job', incoming_kind: 'chunked_job'|'job'}>
	 */
	public static function cross_kind_orders(): array {
		return array(
			'job then chunked job' => array(
				'existing_kind' => 'job',
				'incoming_kind' => 'chunked_job',
			),
			'chunked job then job' => array(
				'existing_kind' => 'chunked_job',
				'incoming_kind' => 'job',
			),
		);
	}

	// endregion.

	// region HELPERS.

	/**
	 * Composes one installed-kind definition and returns its exact execution object.
	 *
	 * @param   'chunked_job'|'job' $kind    Definition kind.
	 * @param   string              $name    Declared local name.
	 * @param   JobOptions|null     $options Optional policy declaration.
	 *
	 * @return  array{definition: JobDefinition, execution: JobExecution|ChunkedJobExecution}
	 */
	private static function registration( string $kind, string $name, ?JobOptions $options = null ): array {
		if ( 'job' === $kind ) {
			$execution = new RecordingJob( $name );

			return array(
				'definition' => $execution->definition( $options ),
				'execution'  => $execution,
			);
		}

		$execution = new RecordingChunkedJob( $name );

		return array(
			'definition' => $execution->definition( $options ),
			'execution'  => $execution,
		);
	}

	/**
	 * Creates one execution object implementing both installed roles.
	 *
	 * @return  JobExecution&ChunkedJobExecution
	 */
	private static function dual_execution(): JobExecution&ChunkedJobExecution {
		return new class() implements JobExecution, ChunkedJobExecution {
			/** {@inheritDoc} */
			#[\Override]
			public function handle( array $args, RunContext $context ): void {}

			/** {@inheritDoc} */
			#[\Override]
			public function generate_queue( array $start_args, RunContext $context ): iterable {
				return array();
			}

			/** {@inheritDoc} */
			#[\Override]
			public function process_chunk( array $chunk_args, ChunkContext $context ): void {}
		};
	}

	// endregion.
}
