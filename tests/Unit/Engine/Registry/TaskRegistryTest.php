<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Registry;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Task\TaskInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\RetryPolicy;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\TaskRegistry;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Registry\WorkRegistry;
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
	 * Registration retains the exact task instance under its complete identity.
	 *
	 * @return  void
	 */
	public function test_get_returns_the_registered_instance_and_null_for_an_unknown_name(): void {
		$task     = $this->task( 'refresh_index-2' );
		$registry = new TaskRegistry( new WorkRegistry() );

		$registry->register( 'consumer:refresh_index-2', $task );

		self::assertSame( $task, $registry->get( 'consumer:refresh_index-2' ) );
		self::assertNull( $registry->get( 'consumer:unknown' ) );
	}

	/**
	 * Registration accepts the shared local-name boundary and rejects a longer declaration.
	 *
	 * @return  void
	 */
	public function test_register_accepts_64_name_bytes_and_rejects_65(): void {
		$accepted = $this->task( \str_repeat( 'a', 64 ) );
		$registry = new TaskRegistry( new WorkRegistry() );

		$registry->register( 'consumer:' . \str_repeat( 'a', 64 ), $accepted );
		self::assertSame( $accepted, $registry->get( 'consumer:' . \str_repeat( 'a', 64 ) ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		$registry->register( 'consumer:valid', $this->task( \str_repeat( 'a', 65 ) ) );
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
		$this->expectExceptionMessageIs( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );

		( new TaskRegistry( new WorkRegistry() ) )->register( 'consumer:valid', $this->task( $name ) );
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
		$registry = new TaskRegistry( new WorkRegistry() );
		$registry->register( 'consumer:refresh-index', $this->task( 'refresh-index' ) );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessageIs( 'Task name is already registered; register each task name exactly once.' );

		$registry->register( 'consumer:refresh-index', $this->task( 'refresh-index' ) );
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
