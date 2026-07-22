<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the schedule representation to public typed value objects consumed by a typed sync().
 *
 * @load-bearing structural-guard
 * @pin-rationale The handle surface speaks typed value objects only; the import-free array dialect belongs exclusively to the procedural facade.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversNothing]
final class TypedScheduleShapeTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string ROOT_NAMESPACE = 'A8C\\SpecialProjects\\BackgroundJobsEngine\\';

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
	 * The schedule concept is public representation under the singular Schedule namespace.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_schedule_concept_is_public_representation(): void {
		foreach ( array( 'Schedule', 'Recurrence', 'CatchUpPolicy' ) as $short_name ) {
			$type = self::ROOT_NAMESPACE . 'Schedule\\' . $short_name;
			self::assertTrue( \class_exists( $type ) || \enum_exists( $type ), 'The schedule concept must declare ' . $type );
		}

		self::assertTrue( ( new \ReflectionClass( self::ROOT_NAMESPACE . 'Schedule\\Schedule' ) )->isFinal() );
		self::assertTrue( ( new \ReflectionClass( self::ROOT_NAMESPACE . 'Schedule\\Recurrence' ) )->isFinal() );
		self::assertTrue( \enum_exists( self::ROOT_NAMESPACE . 'Schedule\\CatchUpPolicy' ), 'CatchUpPolicy must remain a native enum.' );
	}

	/**
	 * Recurrence construction speaks through its named constructors.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_recurrence_offers_the_named_constructors(): void {
		$reflection = new \ReflectionClass( self::ROOT_NAMESPACE . 'Schedule\\Recurrence' );

		foreach ( array( 'every', 'every_anchored' ) as $constructor ) {
			self::assertTrue( $reflection->hasMethod( $constructor ), 'Recurrence must offer ' . $constructor . '().' );
			$method = $reflection->getMethod( $constructor );
			self::assertTrue( $method->isStatic() && $method->isPublic(), 'Recurrence::' . $constructor . '() must be a public named constructor.' );

			$return = $method->getReturnType();
			self::assertInstanceOf( \ReflectionNamedType::class, $return );
			self::assertContains( $return->getName(), array( 'self', 'static', self::ROOT_NAMESPACE . 'Schedule\\Recurrence' ), 'Recurrence::' . $constructor . '() must return a Recurrence.' );
		}
	}

	/**
	 * The schedules manager synchronizes on the typed value objects, variadically.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_sync_accepts_the_typed_schedules_variadically(): void {
		$method     = new \ReflectionMethod( self::ROOT_NAMESPACE . 'Schedules', 'sync' );
		$parameters = $method->getParameters();

		self::assertCount( 1, $parameters );
		self::assertTrue( $parameters[0]->isVariadic(), 'sync() must accept any number of typed schedules.' );

		$type = $parameters[0]->getType();
		self::assertInstanceOf( \ReflectionNamedType::class, $type );
		self::assertSame( self::ROOT_NAMESPACE . 'Schedule\\Schedule', $type->getName(), 'sync() must accept the public Schedule value object.' );
	}

	/**
	 * The callable-job registration speaks typed values instead of an options bag.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_register_callable_speaks_typed_values(): void {
		$method     = new \ReflectionMethod( self::ROOT_NAMESPACE . 'Jobs', 'register_callable' );
		$parameters = $method->getParameters();

		$expected = array(
			'name'         => 'string',
			'handler'      => 'callable',
			'max_runtime'  => 'int',
			'retry'        => self::ROOT_NAMESPACE . 'Job\\RetryPolicy',
			'overlap'      => self::ROOT_NAMESPACE . 'Job\\OverlapPolicy',
			'overlap_key'  => 'callable',
			'on_completed' => 'callable',
			'on_failed'    => 'callable',
		);

		self::assertCount( \count( $expected ), $parameters );

		$position = 0;
		foreach ( $expected as $name => $type_name ) {
			$parameter = $parameters[ $position ];
			self::assertSame( $name, $parameter->getName(), 'register_callable() parameter #' . ( $position + 1 ) . ' name.' );

			$type = $parameter->getType();
			self::assertInstanceOf( \ReflectionNamedType::class, $type );
			self::assertSame( $type_name, $type->getName(), 'register_callable() $' . $name . ' type.' );

			if ( $position >= 2 ) {
				self::assertTrue( $type->allowsNull() && $parameter->isDefaultValueAvailable(), 'register_callable() $' . $name . ' must be an optional typed value.' );
			}
			++$position;
		}
	}

	/**
	 * The import-free array dialect stays exclusively on the procedural facade.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_procedural_facade_keeps_the_import_free_array_dialect(): void {
		$sync = new \ReflectionFunction( 'a8csp_bgje_sync_schedules' );
		self::assertCount( 2, $sync->getParameters() );
		$specifications = $sync->getParameters()[1]->getType();
		self::assertInstanceOf( \ReflectionNamedType::class, $specifications );
		self::assertSame( 'array', $specifications->getName(), 'a8csp_bgje_sync_schedules() must keep array specifications.' );

		$register_callable = new \ReflectionFunction( 'a8csp_bgje_register_callable' );
		$last_parameter    = $register_callable->getParameters()[ $register_callable->getNumberOfParameters() - 1 ];
		$options           = $last_parameter->getType();
		self::assertInstanceOf( \ReflectionNamedType::class, $options );
		self::assertSame( 'array', $options->getName(), 'a8csp_bgje_register_callable() must keep the array options bag.' );
	}

	/**
	 * The public schedule invariants stay equal to the spine's admission invariants.
	 *
	 * The boundary ban keeps the public value object from importing the spine validators, so the
	 * ceilings exist twice; this pin is what keeps the two copies from drifting apart.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_the_public_schedule_invariants_match_the_spine(): void {
		$schedule  = new \ReflectionClass( self::ROOT_NAMESPACE . 'Schedule\\Schedule' );
		$admission = new \ReflectionClass( self::ROOT_NAMESPACE . 'Internal\\AdmissionValidator' );
		$identity  = new \ReflectionClass( self::ROOT_NAMESPACE . 'Internal\\JobIdentity' );

		self::assertSame( $admission->getConstant( 'MAX_ARGUMENTS_BYTES' ), $schedule->getConstant( 'MAX_ARGUMENTS_BYTES' ), 'Argument-payload ceilings must not drift between the public schedule and the admission spine.' );
		self::assertSame( $admission->getConstant( 'MAX_PRIORITY' ), $schedule->getConstant( 'MAX_PRIORITY' ), 'Priority ceilings must not drift between the public schedule and the admission spine.' );
		self::assertSame( $identity->getConstant( 'NAME_MAX_BYTES' ), $schedule->getConstant( 'MAX_NAME_BYTES' ), 'Name-length ceilings must not drift between the public schedule and the identity spine.' );
	}

	/**
	 * No schedule value object remains declarable in the machinery spine.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_no_schedule_value_object_remains_in_the_spine(): void {
		foreach ( array( 'Schedule', 'Recurrence', 'CatchUpPolicy' ) as $short_name ) {
			self::assert_type_absent( self::ROOT_NAMESPACE . 'Internal\\Schedule\\' . $short_name );
		}

		self::assertTrue( \class_exists( self::ROOT_NAMESPACE . 'Internal\\Schedule\\Schedules' ), 'The internal schedules spine must survive the promotion.' );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Asserts one fully qualified name resolves to no declaration of any kind.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $type Fully qualified type name.
	 *
	 * @return  void
	 */
	private static function assert_type_absent( string $type ): void {
		self::assertFalse( \class_exists( $type ) || \interface_exists( $type ) || \enum_exists( $type ) || \trait_exists( $type ), $type . ' must not be declarable.' );
	}

	// endregion.
}
