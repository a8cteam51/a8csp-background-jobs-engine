<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Registry;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins task identity validation and instance lookup.
 *
 */
#[CoversClass( TaskRegistry::class )]
final class TaskRegistryTest extends TestCase {

	/**
	 * Satisfies production boot guards before task contracts are autoloaded.
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
	 * Registration retains the exact task instance under its stable name.
	 *
	 * @return  void
	 */
	public function test_get_returns_the_registered_instance_and_null_for_an_unknown_name(): void {
		$task     = $this->task( 'refresh_index-2' );
		$registry = new TaskRegistry();

		$registry->register( $task );

		self::assertSame( $task, $registry->get( 'refresh_index-2' ) );
		self::assertNull( $registry->get( 'unknown' ) );
	}

	/**
	 * Registration accepts the storage-safe boundary and names the shortening fix beyond it.
	 *
	 * @return  void
	 */
	public function test_register_accepts_110_bytes_and_rejects_111_with_the_fix(): void {
		$accepted = $this->task( \str_repeat( 'a', 110 ) );
		$registry = new TaskRegistry();

		$registry->register( $accepted );
		self::assertSame( $accepted, $registry->get( \str_repeat( 'a', 110 ) ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs(
			'Task name must be at most 110 bytes; shorten the task name.'
		);

		$registry->register( $this->task( \str_repeat( 'a', 111 ) ) );
	}

	/**
	 * Invalid names identify the accepted spelling needed to register the task.
	 *
	 * @param   string $name Invalid task name.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_names' )]
	public function test_register_rejects_invalid_names_with_the_fix( string $name ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs(
			'Task name is invalid; return a non-empty name containing only lowercase letters, digits, underscores, and hyphens.'
		);

		( new TaskRegistry() )->register( $this->task( $name ) );
	}

	/**
	 * Supplies names outside the complete stable-name grammar.
	 *
	 * @return  array<string, array{name: string}>
	 */
	public static function invalid_names(): array {
		return array(
			'empty'     => array( 'name' => '' ),
			'uppercase' => array( 'name' => 'RefreshIndex' ),
			'space'     => array( 'name' => 'refresh index' ),
			'period'    => array( 'name' => 'refresh.index' ),
			'non-ASCII' => array( 'name' => 'réindex' ),
		);
	}

	/**
	 * Duplicate registration identifies the one-name-one-instance correction.
	 *
	 * @return  void
	 */
	public function test_register_rejects_a_duplicate_name_with_the_fix(): void {
		$registry = new TaskRegistry();
		$registry->register( $this->task( 'refresh-index' ) );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs(
			'Task name is already registered; register each task name exactly once.'
		);

		$registry->register( $this->task( 'refresh-index' ) );
	}

	/**
	 * Creates a task with the supplied identity.
	 *
	 * @param   string $name Task name.
	 *
	 * @return  TaskInterface
	 */
	private function task( string $name ): TaskInterface {
		return new class( $name ) implements TaskInterface {

			/**
			 * Constructor.
			 *
			 * @param   string $name Task name.
			 */
			public function __construct( private readonly string $name ) {}

			/** {@inheritDoc} */
			#[\Override]
			public function get_name(): string {
				return $this->name;
			}

			/** {@inheritDoc} */
			#[\Override]
			public function max_runtime(): int {
				return self::DEFAULT_MAX_RUNTIME;
			}

			/**
			 * Handles one unused registry-test invocation.
			 *
			 * @param   array<array-key, mixed> $args Invocation arguments.
			 *
			 * @return  void
			 */
			#[\Override]
			public function handle( array $args ): void {}

			/** {@inheritDoc} */
			#[\Override]
			public function get_retry_policy(): RetryPolicy {
				return new RetryPolicy();
			}
		};
	}
}
