<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the owner-bound handle to three capability-manager portals with suffix-free verbs.
 *
 * @load-bearing structural-guard
 * @pin-rationale The plural namespace grain names the managers; each verb lives on its concept portal, so no verb repeats its portal's noun as a suffix.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversNothing]
final class CapabilityManagerShapeTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ROOT_NAMESPACE = 'A8C\\SpecialProjects\\BackgroundJobsEngine\\';

	/**
	 * Every public verb, keyed by manager, as `name => [parameter types, return type]`.
	 */
	private const array MANAGER_VERBS = array(
		'Jobs'      => array(
			'register'          => array( array( self::ROOT_NAMESPACE . 'Job\\JobInterface' ), 'WP_Error|true' ),
			'register_callable' => array( array( 'string', 'callable', 'int', self::ROOT_NAMESPACE . 'Job\\RetryPolicy', self::ROOT_NAMESPACE . 'Job\\OverlapPolicy', 'callable', 'callable', 'callable' ), 'WP_Error|true' ),
			'enqueue'           => array( array( 'string', 'array', 'int', 'int' ), self::ROOT_NAMESPACE . 'Run\\Run|WP_Error' ),
			'start'             => array( array( 'string', 'array', 'int' ), self::ROOT_NAMESPACE . 'Run\\Run|WP_Error' ),
		),
		'Schedules' => array(
			'sync'     => array( array( self::ROOT_NAMESPACE . 'Schedule\\Schedule' ), 'WP_Error|true' ),
			'dispatch' => array( array( 'string' ), self::ROOT_NAMESPACE . 'Run\\Run|WP_Error' ),
		),
		'Runs'      => array(
			'inspect'        => array( array( 'string', 'string' ), self::ROOT_NAMESPACE . 'Run\\Run|WP_Error' ),
			'last_completed' => array( array( 'string' ), self::ROOT_NAMESPACE . 'Run\\Run|WP_Error|null' ),
			'retry_failed'   => array( array( 'string', 'string' ), self::ROOT_NAMESPACE . 'Run\\Run|WP_Error' ),
			'cancel'         => array( array( 'string', 'string' ), self::ROOT_NAMESPACE . 'Run\\Run|WP_Error' ),
		),
	);

	// endregion.

	// region LIFECYCLE.

	/**
	 * Allows guarded declarations to autoload during reflection.
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

		require_once \dirname( __DIR__, 2 ) . '/functions.php';
	}

	// endregion.

	// region TESTS.

	/**
	 * The handle and its three managers are final root-namespace services.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_handle_and_managers_are_final_services(): void {
		foreach ( array( 'Engine', 'Jobs', 'Schedules', 'Runs' ) as $short_name ) {
			$type = self::ROOT_NAMESPACE . $short_name;
			self::assertTrue( \class_exists( $type ), 'The service layer must declare ' . $type );

			self::assertTrue( ( new \ReflectionClass( $type ) )->isFinal() );
		}
	}

	/**
	 * The handle exposes exactly the three concept portals and no verbs of its own.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_handle_exposes_exactly_the_three_portals(): void {
		$reflection = new \ReflectionClass( self::ROOT_NAMESPACE . 'Engine' );

		$public_methods = array();
		foreach ( $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( ! $method->isConstructor() ) {
				$public_methods[] = $method->getName();
			}
		}
		\sort( $public_methods );
		self::assertSame( array( 'jobs', 'runs', 'schedules' ), $public_methods, 'The handle must expose exactly the three portals.' );

		$portals = array(
			'jobs'      => 'Jobs',
			'schedules' => 'Schedules',
			'runs'      => 'Runs',
		);
		foreach ( $portals as $portal => $manager ) {
			$method = $reflection->getMethod( $portal );
			self::assertCount( 0, $method->getParameters(), $portal . '() must take no arguments.' );

			$return = $method->getReturnType();
			self::assertInstanceOf( \ReflectionNamedType::class, $return );
			self::assertSame( self::ROOT_NAMESPACE . $manager, $return->getName(), $portal . '() must return the ' . $manager . ' manager.' );
		}
	}

	/**
	 * Every manager exposes exactly its suffix-free verbs with the WP_Error edge preserved.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_each_manager_exposes_exactly_its_suffix_free_verbs(): void {
		foreach ( self::MANAGER_VERBS as $manager => $verbs ) {
			$reflection = new \ReflectionClass( self::ROOT_NAMESPACE . $manager );

			$public_methods = array();
			foreach ( $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
				if ( ! $method->isConstructor() ) {
					$public_methods[] = $method->getName();
				}
			}
			\sort( $public_methods );
			$expected = \array_keys( $verbs );
			\sort( $expected );
			self::assertSame( $expected, $public_methods, $manager . ' must expose exactly its concept verbs.' );

			foreach ( $verbs as $verb => $signature ) {
				$method   = $reflection->getMethod( $verb );
				$location = $manager . '::' . $verb . '()';

				self::assertNotEmpty( $method->getAttributes( \NoDiscard::class ), $location . ' must mark its result as must-handle.' );

				$parameters = $method->getParameters();
				self::assertCount( \count( $signature[0] ), $parameters, $location . ' parameter count.' );
				foreach ( $signature[0] as $position => $expected_type ) {
					$type = $parameters[ $position ]->getType();
					self::assertInstanceOf( \ReflectionNamedType::class, $type );
					self::assertSame( $expected_type, $type->getName(), $location . ' parameter #' . ( $position + 1 ) );
				}

				self::assertSame( $signature[1], self::type_signature( $method->getReturnType() ), $location . ' return type.' );
			}
		}
	}

	/**
	 * The sole global door still returns the owner-bound handle.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_global_door_returns_the_handle(): void {
		$reflection = new \ReflectionFunction( 'a8csp_bgje' );

		$return = $reflection->getReturnType();
		self::assertInstanceOf( \ReflectionNamedType::class, $return );
		self::assertSame( self::ROOT_NAMESPACE . 'Engine', $return->getName(), 'a8csp_bgje() must return the owner-bound handle.' );
	}

	/**
	 * No handle verb carries its portal's noun as a suffix.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_no_manager_verb_repeats_its_concept_noun(): void {
		foreach ( self::MANAGER_VERBS as $manager => $verbs ) {
			$noun = \strtolower( \rtrim( $manager, 's' ) );
			foreach ( \array_keys( $verbs ) as $verb ) {
				self::assertStringNotContainsString( $noun, $verb, $manager . '::' . $verb . '() repeats its concept noun.' );
			}
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Renders a reflected return type as an order-insensitive pipe-joined signature string.
	 *
	 * Reflection reports union members in engine-canonical order, not source order, so the
	 * members are sorted before joining and expectations are written pre-sorted.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   \ReflectionType|null $type Reflected return type.
	 *
	 * @return  string
	 */
	private static function type_signature( ?\ReflectionType $type ): string {
		if ( null === $type ) {
			return '';
		}

		$members = array();
		if ( $type instanceof \ReflectionNamedType ) {
			$members[] = $type->getName();
			if ( $type->allowsNull() && 'null' !== $type->getName() && 'mixed' !== $type->getName() ) {
				$members[] = 'null';
			}
		} elseif ( $type instanceof \ReflectionUnionType ) {
			foreach ( $type->getTypes() as $member ) {
				self::assertInstanceOf( \ReflectionNamedType::class, $member );
				$members[] = $member->getName();
			}
		} else {
			self::fail( 'Unsupported reflection type composition: ' . $type::class );
		}

		$members = \array_values( \array_unique( $members ) );
		\sort( $members );

		return \implode( '|', $members );
	}

	// endregion.
}
