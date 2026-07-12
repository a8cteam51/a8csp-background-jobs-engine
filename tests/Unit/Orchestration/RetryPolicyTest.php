<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Orchestration;

use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RandomizerInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Orchestration\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins retry-policy validation, exponential-delay ceilings, and full-jitter bounds.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( RetryPolicy::class )]
final class RetryPolicyTest extends TestCase {

	/**
	 * Loads WordPress constants before the retry policy is first instantiated.
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

	/**
	 * The constructor defaults match the engine's retry contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_defaults_match_the_retry_contract(): void {
		$policy = new RetryPolicy();

		self::assertSame( 3, $policy->max_attempts );
		self::assertSame( \MINUTE_IN_SECONDS, $policy->base_delay );
		self::assertSame( 2, $policy->multiplier );
		self::assertSame( \HOUR_IN_SECONDS, $policy->max_delay );
	}

	/**
	 * At least one attempt is required.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_rejects_fewer_than_one_attempt(): void {
		$this->expect_invalid_argument_with_message( 'Retry policy requires at least one attempt and a positive base delay.' );

		new RetryPolicy( max_attempts: 0 );
	}

	/**
	 * A negative attempt ceiling is invalid.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_rejects_a_negative_attempt_ceiling(): void {
		$this->expect_invalid_argument_with_message( 'Retry policy requires at least one attempt and a positive base delay.' );

		new RetryPolicy( max_attempts: -1 );
	}

	/**
	 * The base delay must be positive.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_rejects_a_non_positive_base_delay(): void {
		$this->expect_invalid_argument_with_message( 'Retry policy requires at least one attempt and a positive base delay.' );

		new RetryPolicy( base_delay: 0 );
	}

	/**
	 * A negative base delay is invalid.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_rejects_a_negative_base_delay(): void {
		$this->expect_invalid_argument_with_message( 'Retry policy requires at least one attempt and a positive base delay.' );

		new RetryPolicy( base_delay: -1 );
	}

	/**
	 * The exponential multiplier cannot reduce later delay ceilings.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_rejects_a_multiplier_below_one(): void {
		$this->expect_invalid_argument_with_message( 'Retry policy requires a multiplier of at least one.' );

		new RetryPolicy( multiplier: 0 );
	}

	/**
	 * A negative multiplier is invalid.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_rejects_a_negative_multiplier(): void {
		$this->expect_invalid_argument_with_message( 'Retry policy requires a multiplier of at least one.' );

		new RetryPolicy( multiplier: -1 );
	}

	/**
	 * The maximum delay cannot truncate the first attempt below its base delay.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_rejects_a_maximum_delay_below_the_base_delay(): void {
		$this->expect_invalid_argument_with_message( 'Retry policy requires the maximum delay to be at least the base delay.' );

		new RetryPolicy( base_delay: 60, max_delay: 59 );
	}

	/**
	 * Every validation boundary accepts its minimum legal value.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_accepts_all_constructor_boundaries(): void {
		$policy = new RetryPolicy(
			max_attempts: 1,
			base_delay: 1,
			multiplier: 1,
			max_delay: 1,
		);

		self::assertSame( 1, $policy->max_attempts );
		self::assertSame( 1, $policy->base_delay );
		self::assertSame( 1, $policy->multiplier );
		self::assertSame( 1, $policy->max_delay );
	}

	/**
	 * Attempt zero cannot identify a failed attempt before a retry.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_rejects_attempt_zero(): void {
		$this->expect_invalid_argument_with_message(
			'Retry delay requires $attempt to be the one-indexed just-failed attempt number in the range 1 <= $attempt < max_attempts so a next attempt exists.'
		);

		$policy = new RetryPolicy( max_attempts: 3 );

		$policy->delay_for_attempt( 0, self::randomizer_returning( 0 ) );
	}

	/**
	 * The final permitted attempt has no next attempt to delay.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_rejects_the_maximum_attempt(): void {
		$this->expect_invalid_argument_with_message(
			'Retry delay requires $attempt to be the one-indexed just-failed attempt number in the range 1 <= $attempt < max_attempts so a next attempt exists.'
		);

		$policy = new RetryPolicy( max_attempts: 3 );

		$policy->delay_for_attempt( 3, self::randomizer_returning( 0 ) );
	}

	/**
	 * Each attempt passes a zero floor and the capped exponential ceiling to the jitter source.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_delay_math_reaches_and_remains_at_the_cap(): void {
		$randomizer = new class() implements RandomizerInterface {

			/**
			 * Recorded inclusive bounds.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @var list<array{min: int, max: int}>
			 */
			public array $calls = array();

			/**
			 * Records the bounds and returns the upper boundary.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   int $min Inclusive lower boundary.
			 * @param   int $max Inclusive upper boundary.
			 *
			 * @return  int
			 */
			#[\Override]
			public function int( int $min, int $max ): int {
				$this->calls[] = array(
					'min' => $min,
					'max' => $max,
				);

				return $max;
			}

		};
		$policy     = new RetryPolicy(
			max_attempts: 6,
			base_delay: 10,
			multiplier: 3,
			max_delay: 100,
		);
		$delays     = array();

		for ( $attempt = 1; $attempt < $policy->max_attempts; ++$attempt ) {
			$delays[] = $policy->delay_for_attempt( $attempt, $randomizer );
		}

		self::assertSame( array( 10, 30, 90, 100, 100 ), $delays );
		self::assertSame(
			array(
				array(
					'min' => 0,
					'max' => 10,
				),
				array(
					'min' => 0,
					'max' => 30,
				),
				array(
					'min' => 0,
					'max' => 90,
				),
				array(
					'min' => 0,
					'max' => 100,
				),
				array(
					'min' => 0,
					'max' => 100,
				),
			),
			$randomizer->calls
		);
	}

	/**
	 * The returned delay is the exact sample supplied by the jitter source.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_returns_the_scripted_full_jitter_sample(): void {
		$policy = new RetryPolicy(
			max_attempts: 2,
			base_delay: 10,
			max_delay: 10,
		);

		self::assertSame( 7, $policy->delay_for_attempt( 1, self::randomizer_returning( 7 ) ) );
	}

	/**
	 * A multiplier of one keeps every retry ceiling at the base delay.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_multiplier_one_keeps_the_ceiling_constant(): void {
		$randomizer = new class() implements RandomizerInterface {

			/**
			 * Returns the upper boundary.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   int $min Inclusive lower boundary.
			 * @param   int $max Inclusive upper boundary.
			 *
			 * @return  int
			 */
			#[\Override]
			public function int( int $min, int $max ): int {
				return $max;
			}

		};
		$policy     = new RetryPolicy(
			max_attempts: 4,
			base_delay: 7,
			multiplier: 1,
			max_delay: 100,
		);

		self::assertSame( 7, $policy->delay_for_attempt( 1, $randomizer ) );
		self::assertSame( 7, $policy->delay_for_attempt( 2, $randomizer ) );
		self::assertSame( 7, $policy->delay_for_attempt( 3, $randomizer ) );
	}

	/**
	 * An exponential step that equals the maximum delay uses that exact ceiling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_delay_ceiling_can_land_exactly_on_the_cap(): void {
		$randomizer = new class() implements RandomizerInterface {

			/**
			 * Returns the upper boundary.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   int $min Inclusive lower boundary.
			 * @param   int $max Inclusive upper boundary.
			 *
			 * @return  int
			 */
			#[\Override]
			public function int( int $min, int $max ): int {
				return $max;
			}

		};
		$policy     = new RetryPolicy(
			max_attempts: 4,
			base_delay: 10,
			multiplier: 3,
			max_delay: 90,
		);

		self::assertSame( 90, $policy->delay_for_attempt( 3, $randomizer ) );
	}

	/**
	 * A product below a non-divisible cap does not saturate early.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_delay_ceiling_remains_below_a_non_divisible_cap(): void {
		$randomizer = new class() implements RandomizerInterface {

			/**
			 * Returns the upper boundary.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   int $min Inclusive lower boundary.
			 * @param   int $max Inclusive upper boundary.
			 *
			 * @return  int
			 */
			#[\Override]
			public function int( int $min, int $max ): int {
				return $max;
			}

		};
		$policy     = new RetryPolicy(
			max_attempts: 3,
			base_delay: 33,
			multiplier: 3,
			max_delay: 100,
		);

		self::assertSame( 99, $policy->delay_for_attempt( 2, $randomizer ) );
	}

	/**
	 * Large attempt numbers saturate before integer multiplication can overflow.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_large_attempt_saturates_with_an_integer_ceiling(): void {
		$randomizer = new class() implements RandomizerInterface {

			/**
			 * Last received upper boundary.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @var int|null
			 */
			public ?int $max = null;

			/**
			 * Records and returns the upper boundary.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   int $min Inclusive lower boundary.
			 * @param   int $max Inclusive upper boundary.
			 *
			 * @return  int
			 */
			#[\Override]
			public function int( int $min, int $max ): int {
				$this->max = $max;

				return $max;
			}

		};
		$policy     = new RetryPolicy(
			max_attempts: \PHP_INT_MAX,
			base_delay: 2,
			multiplier: \PHP_INT_MAX,
			max_delay: \PHP_INT_MAX,
		);

		$policy->delay_for_attempt( \PHP_INT_MAX - 1, $randomizer );

		self::assertIsInt( $randomizer->max );
		self::assertSame( \PHP_INT_MAX, $randomizer->max );
	}

	/**
	 * Real random draws stay within the inclusive full-jitter bounds.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_random_delays_remain_within_the_full_jitter_bounds(): void {
		$randomizer = new class() implements RandomizerInterface {

			/**
			 * Returns a cryptographically secure integer inside the requested bounds.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   int $min Inclusive lower boundary.
			 * @param   int $max Inclusive upper boundary.
			 *
			 * @return  int
			 */
			#[\Override]
			public function int( int $min, int $max ): int {
				return \random_int( $min, $max );
			}

		};
		$policy     = new RetryPolicy(
			max_attempts: 5,
			base_delay: 5,
			multiplier: 3,
			max_delay: 40,
		);
		$ceilings   = array( 5, 15, 40, 40 );

		foreach ( $ceilings as $index => $ceiling ) {
			$attempt = $index + 1;

			for ( $draw = 0; $draw < 250; ++$draw ) {
				$delay = $policy->delay_for_attempt( $attempt, $randomizer );

				self::assertGreaterThanOrEqual( 0, $delay );
				self::assertLessThanOrEqual( $ceiling, $delay );
			}
		}
	}

	/**
	 * Expects an invalid-argument failure with one exact message.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $message Expected exception message.
	 *
	 * @return  void
	 */
	private function expect_invalid_argument_with_message( string $message ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/^' . \preg_quote( $message, '/' ) . '$/D' );
	}

	/**
	 * Returns a scripted integer source.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int $sample Scripted return value.
	 *
	 * @return  RandomizerInterface
	 */
	private static function randomizer_returning( int $sample ): RandomizerInterface {
		return new readonly class( $sample ) implements RandomizerInterface {

			/**
			 * Constructor.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   int $sample Scripted return value.
			 */
			public function __construct( private int $sample ) {}

			/**
			 * Returns the scripted sample.
			 *
			 * @since   1.0.0
			 * @version 1.0.0
			 *
			 * @param   int $min Inclusive lower boundary.
			 * @param   int $max Inclusive upper boundary.
			 *
			 * @return  int
			 */
			#[\Override]
			public function int( int $min, int $max ): int {
				return $this->sample;
			}

		};
	}
}
