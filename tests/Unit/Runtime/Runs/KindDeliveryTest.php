<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Inspection;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\EngineRig;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins persisted kind hydration and generic lifecycle-delivery admission.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ActionDeliveries::class )]
#[CoversClass( Inspection::class )]
#[CoversClass( RunStore::class )]
final class KindDeliveryTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string DELIVER_HOOK = 'a8csp_jobs_engine/deliver';
	private const string IDENTITY     = self::OWNER . ':' . self::NAME;
	private const string NAME         = 'export';
	private const int NOW             = 1_700_000_000;
	private const string OWNER        = 'kind-tests';

	private EngineRig $rig;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads guarded WordPress seams before the production graph is built.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		EngineRig::bootstrap();
	}

	/**
	 * Boots one deterministic production graph.
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
	 * Releases request-local engine state after each scenario.
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

	// region TESTS.

	/**
	 * A grammar-valid unregistered kind remains inspectable and cannot mutate during delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_unknown_kind_hydrates_and_delivery_drops_without_mutating_state(): void {
		$run_id = $this->enqueue_job();
		$this->replace_run_field( $run_id, 'kind', 'acme.export' );

		$state = $this->run_store()->get( $run_id );
		self::assertNotNull( $state );
		self::assertSame( 'acme.export', $state->kind );
		$inspection = $this->rig->inspection()->runs( self::IDENTITY );
		self::assertSame( 'acme.export', $inspection['live'][0]['kind'] ?? null );
		self::assertFalse( $inspection['live'][0]['queue_known'] ?? true );
		self::assertSame( 0, $inspection['live_unreadable'] );

		$before                       = $this->raw_run( $run_id );
		$this->rig->logger()->records = array();
		\do_action( self::DELIVER_HOOK, self::IDENTITY, $run_id, $state->action_sequence );

		self::assertSame( $before, $this->raw_run( $run_id ) );
		$this->assert_warning_logged( 'handler', 'acme.export' );
	}

	/**
	 * A malformed persisted kind remains corruption and cannot mutate during delivery.
	 *
	 * @param   string $kind Malformed kind value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[DataProvider( 'malformed_kind_provider' )]
	public function test_malformed_kind_uses_the_corrupt_path( string $kind ): void {
		$run_id = $this->enqueue_job();
		$this->replace_run_field( $run_id, 'kind', $kind );

		self::assertNull( $this->run_store()->get( $run_id ) );
		$inspection = $this->rig->inspection()->runs( self::IDENTITY );
		self::assertSame( array(), $inspection['live'] );
		self::assertSame( 1, $inspection['live_unreadable'] );

		$before                       = $this->raw_run( $run_id );
		$this->rig->logger()->records = array();
		\do_action( self::DELIVER_HOOK, self::IDENTITY, $run_id, 1 );

		self::assertSame( $before, $this->raw_run( $run_id ) );
		$this->assert_warning_logged( 'corrupt' );
	}

	/**
	 * A known handler rejects a lexically valid stage it does not own before any fence mutates state.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_handler_unowned_stage_drops_without_mutating_state(): void {
		$run_id = $this->enqueue_job();
		$this->replace_pending_stage( $run_id, 'continue' );
		$state = $this->run_store()->get( $run_id );
		self::assertNotNull( $state );

		$before                       = $this->raw_run( $run_id );
		$this->rig->logger()->records = array();
		\do_action( self::DELIVER_HOOK, self::IDENTITY, $run_id, $state->action_sequence );

		self::assertSame( $before, $this->raw_run( $run_id ) );
		$this->assert_warning_logged( 'stage', 'continue' );
	}

	/**
	 * The graph registers and schedules one generic three-argument lifecycle delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_one_deliver_hook_is_registered_and_enqueued_with_the_run_group(): void {
		$registrations = $GLOBALS['a8csp_bgje_test_action_registrations'] ?? null;
		self::assertIsArray( $registrations );
		$lifecycle_hooks = array( self::DELIVER_HOOK, 'a8csp_jobs_engine/run_job', 'a8csp_jobs_engine/start_chunked_job', 'a8csp_jobs_engine/continue_chunked_job', 'a8csp_jobs_engine/cleanup_chunked_job' );
		$registered      = \array_values( \array_filter( $registrations, static fn ( mixed $registration ): bool => \is_array( $registration ) && \in_array( $registration['hook_name'] ?? null, $lifecycle_hooks, true ) ) );

		self::assertCount( 1, $registered );
		self::assertSame( self::DELIVER_HOOK, $registered[0]['hook_name'] ?? null );
		self::assertSame( 3, $registered[0]['accepted_args'] ?? null );
		$callback = $registered[0]['callback'] ?? null;
		self::assertIsArray( $callback );
		self::assertIsCallable( $callback );
		self::assertSame( 'handle_deliver_action', $callback[1] );

		$run_id = $this->enqueue_job();
		$calls  = \array_values( \array_filter( $this->rig->backend()->calls, static fn ( array $call ): bool => self::DELIVER_HOOK === ( $call['args']['hook'] ?? null ) ) );
		self::assertCount( 1, $calls );
		self::assertSame( array( self::IDENTITY, $run_id, 1 ), $calls[0]['args']['args'] ?? null );
		self::assertSame( self::IDENTITY . '|' . $run_id, $calls[0]['args']['group'] ?? null );
	}

	// endregion.

	// region DATA PROVIDERS.

	/**
	 * Returns malformed persisted kind values.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  iterable<string, array{string}>
	 */
	public static function malformed_kind_provider(): iterable {
		yield 'punctuation' => array( 'Job!' );
		yield 'empty' => array( '' );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Creates one canonical pending job run.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function enqueue_job(): string {
		$client = $this->rig->operations( self::OWNER );
		$client->register( ( new RecordingJob( self::NAME ) )->definition() );
		$result = $client->dispatch( self::NAME );
		self::assertInstanceOf( Success::class, $result );
		self::assertIsString( $result->value );

		return $result->value;
	}

	/**
	 * Returns a run store bound to the staged identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  RunStore
	 */
	private function run_store(): RunStore {
		return new RunStore( self::IDENTITY, $this->rig->clock(), new OptionRows( $this->rig->wpdb() ) );
	}

	/**
	 * Replaces one top-level field in a persisted run row.
	 *
	 * @param   string $run_id Run identifier.
	 * @param   string $field  Persisted field name.
	 * @param   mixed  $value  Replacement value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function replace_run_field( string $run_id, string $field, mixed $value ): void {
		$state = \maybe_unserialize( $this->raw_run( $run_id ) );
		self::assertIsArray( $state );
		$state[ $field ] = $value;
		$raw             = \maybe_serialize( $state );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( RunIdentity::option_name( self::IDENTITY, $run_id ), $raw );
	}

	/**
	 * Replaces the stage in a persisted pending action.
	 *
	 * @param   string $run_id Run identifier.
	 * @param   string $stage  Replacement lifecycle stage.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function replace_pending_stage( string $run_id, string $stage ): void {
		$state = \maybe_unserialize( $this->raw_run( $run_id ) );
		self::assertIsArray( $state );
		self::assertIsArray( $state['pending'] ?? null );
		$state['pending']['stage'] = $stage;
		$raw                       = \maybe_serialize( $state );
		self::assertIsString( $raw );
		$this->rig->wpdb()->put( RunIdentity::option_name( self::IDENTITY, $run_id ), $raw );
	}

	/**
	 * Returns the exact authoritative run bytes.
	 *
	 * @param   string $run_id Run identifier.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	private function raw_run( string $run_id ): string {
		$option_name = RunIdentity::option_name( self::IDENTITY, $run_id );
		$raw         = $this->rig->wpdb()->rows[ $option_name ] ?? null;
		if ( null === $raw ) {
			$options = $GLOBALS['a8csp_bgje_test_options'] ?? null;
			self::assertIsArray( $options );
			$raw = $options[ $option_name ] ?? null;
		}
		if ( \is_array( $raw ) ) {
			$raw = \maybe_serialize( $raw );
		}
		self::assertIsString( $raw );

		return $raw;
	}

	/**
	 * Asserts that delivery emitted one warning carrying the requested evidence.
	 *
	 * @param   string      $message_fragment Required message fragment.
	 * @param   string|null $context_value    Required context value, or null.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	private function assert_warning_logged( string $message_fragment, ?string $context_value = null ): void {
		$warnings = \array_values(
			\array_filter(
				$this->rig->logger()->records,
				static fn ( array $record ): bool => 'warning' === $record['level'] && \str_contains( \strtolower( $record['message'] ), \strtolower( $message_fragment ) )
			)
		);
		self::assertNotSame( array(), $warnings );
		if ( null === $context_value ) {
			return;
		}

		self::assertTrue( \array_any( $warnings, static fn ( array $record ): bool => \in_array( $context_value, $record['context'], true ) ) );
	}

	// endregion.
}
