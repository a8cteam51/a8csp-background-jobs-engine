<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\AbstractComponent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the defaults-only base supplies an open gate and a no-op readiness phase.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( AbstractComponent::class )]
final class AbstractComponentTest extends TestCase {
	/**
	 * Satisfies the production files' `ABSPATH` boot guard before the class under test autoloads.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		parent::setUpBeforeClass();
	}

	/**
	 * An extending component inherits both defaults without attaching its behavior.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_defaults_hold_for_an_extending_component(): void {
		$component = new class() extends AbstractComponent {
			/** @var bool Whether the hook phase ran. */
			public bool $registered = false;

			/**
			 * {@inheritDoc}
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 */
			#[\Override]
			public function register_hooks(): void {
				$this->registered = true;
			}
		};

		self::assertTrue( $component::should_load() );

		$component->initialize();
		self::assertFalse( $component->registered );

		$component->register_hooks();
		self::assertTrue( $component->registered );
	}
}
