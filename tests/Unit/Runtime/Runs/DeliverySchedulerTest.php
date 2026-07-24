<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime\Runs;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\DeliveryScheduler;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\PendingAction;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins lifecycle delivery composition from persisted pending-action descriptors.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( DeliveryScheduler::class )]
final class DeliverySchedulerTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string IDENTITY = 'delivery-tests:catalog-sync';
	private const int NOW         = 1_700_000_000;
	private const string RUN_ID   = '00000000001700000000-0000000000000000042';

	private RecordingBackend $backend;
	private DeliveryScheduler $scheduler;

	// endregion.

	// region LIFECYCLE.

	/**
	 * Satisfies production file guards before first autoload.
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
	}

	/**
	 * Builds deterministic scheduling collaborators.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->backend   = new RecordingBackend();
		$this->scheduler = new DeliveryScheduler( $this->backend, new FixedClock( self::NOW ) );
	}

	// endregion.

	// region TESTS.

	/**
	 * An asynchronous descriptor supplies the canonical hook, arguments, group, and priority.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedule_composes_an_asynchronous_delivery_from_the_pending_descriptor(): void {
		$result = $this->scheduler->schedule( self::IDENTITY, self::RUN_ID, 7, PendingAction::async( 'continue', 42 ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				array(
					'verb' => 'enqueue_async',
					'args' => array(
						'hook'     => ActionDeliveries::DELIVER_HOOK,
						'args'     => array( self::IDENTITY, self::RUN_ID, 7 ),
						'group'    => self::IDENTITY . '|' . self::RUN_ID,
						'priority' => 42,
					),
				),
			),
			$this->backend->calls
		);
	}

	/**
	 * A future single descriptor supplies the canonical delivery fields and its fire time.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedule_composes_a_future_single_delivery_from_the_pending_descriptor(): void {
		$result = $this->scheduler->schedule( self::IDENTITY, self::RUN_ID, 11, PendingAction::single( 'cleanup', self::NOW + 75, 31 ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame(
			array(
				array(
					'verb' => 'schedule_single',
					'args' => array(
						'hook'      => ActionDeliveries::DELIVER_HOOK,
						'timestamp' => self::NOW + 75,
						'args'      => array( self::IDENTITY, self::RUN_ID, 11 ),
						'group'     => self::IDENTITY . '|' . self::RUN_ID,
						'priority'  => 31,
					),
				),
			),
			$this->backend->calls
		);
	}

	/**
	 * A past single descriptor is clamped to the scheduling clock.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedule_clamps_a_past_single_delivery_to_now(): void {
		$result = $this->scheduler->schedule( self::IDENTITY, self::RUN_ID, 13, PendingAction::single( 'continue', self::NOW - 75, 19 ) );

		self::assertInstanceOf( Success::class, $result );
		self::assertSame( self::NOW, $this->backend->calls[0]['args']['timestamp'] ?? null );
	}

	// endregion.
}
