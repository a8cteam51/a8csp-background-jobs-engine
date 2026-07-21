<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

// The top-level fixture components below link the guarded production interface at file-load
// time, before any setUpBeforeClass() can run — so the guard's constant must exist here.
\defined( 'ABSPATH' ) || \define( 'ABSPATH', __DIR__ . '/' );

use A8C\SpecialProjects\BackgroundJobsEngine\ComponentCollection;
use A8C\SpecialProjects\BackgroundJobsEngine\ComponentInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises component gating and the collection's two-phase lifecycle.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ComponentCollection::class )]
final class ComponentCollectionTest extends TestCase {
	/**
	 * Starts each test with empty construction and phase ledgers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		CollectionOpenComponent::$events   = array();
		CollectionClosedComponent::$events = array();
	}

	/**
	 * Gates run before construction and only open components enter the collection.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_assemble_gates_before_construction(): void {
		$components = ComponentCollection::assemble( array( CollectionOpenComponent::class, CollectionClosedComponent::class ) );

		self::assertTrue( $components->has( CollectionOpenComponent::class ) );
		self::assertFalse( $components->has( CollectionClosedComponent::class ) );
		self::assertSame( array( 'construct:open' ), CollectionOpenComponent::$events );
		self::assertSame( array(), CollectionClosedComponent::$events );
	}

	/**
	 * Every readiness phase completes before the first hook phase begins.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_readiness_completes_before_hook_registration(): void {
		$components = ComponentCollection::assemble( array( CollectionOpenComponent::class, CollectionSecondOpenComponent::class ) );

		$components->initialize();
		$components->register_hooks();

		self::assertSame(
			array( 'construct:open', 'initialize:open', 'initialize:second', 'hooks:open', 'hooks:second' ),
			CollectionOpenComponent::$events
		);
	}
}

/**
 * Records construction and lifecycle events for an open component.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class CollectionOpenComponent implements ComponentInterface {
	/** @var list<string> */
	public static array $events = array();

	/**
	 * Records component construction.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function __construct() {
		self::$events[] = 'construct:open';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function should_load(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function initialize(): void {
		self::$events[] = 'initialize:open';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function register_hooks(): void {
		self::$events[] = 'hooks:open';
	}
}

/**
 * Records whether a closed component was constructed.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class CollectionClosedComponent implements ComponentInterface {
	/** @var list<string> */
	public static array $events = array();

	/**
	 * Records any construction that crosses the closed gate.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function __construct() {
		self::$events[] = 'construct:closed';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function should_load(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function initialize(): void {}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function register_hooks(): void {}
}

/**
 * Records a second open component's lifecycle in the shared phase ledger.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class CollectionSecondOpenComponent implements ComponentInterface {
	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public static function should_load(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function initialize(): void {
		CollectionOpenComponent::$events[] = 'initialize:second';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	#[\Override]
	public function register_hooks(): void {
		CollectionOpenComponent::$events[] = 'hooks:second';
	}
}
