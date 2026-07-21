<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit;

use A8C\SpecialProjects\BackgroundJobsEngine\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins retry-policy validation and exponential-delay ceilings.
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

		require_once __DIR__ . '/wp-time-constant-stubs.php';
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
		$policy = new RetryPolicy( max_attempts: 1, base_delay: 1, multiplier: 1, max_delay: 1, );

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
		$this->expect_invalid_argument_with_message( 'Retry delay requires $attempt to be the one-indexed just-failed attempt number in the range 1 <= $attempt < max_attempts so a next attempt exists.' );

		$policy = new RetryPolicy( max_attempts: 3 );

		$policy->delay_ceiling_for_attempt( 0 );
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
		$this->expect_invalid_argument_with_message( 'Retry delay requires $attempt to be the one-indexed just-failed attempt number in the range 1 <= $attempt < max_attempts so a next attempt exists.' );

		$policy = new RetryPolicy( max_attempts: 3 );

		$policy->delay_ceiling_for_attempt( 3 );
	}

	/**
	 * Each attempt returns its capped exponential ceiling.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_delay_math_reaches_and_remains_at_the_cap(): void {
		$policy   = new RetryPolicy( max_attempts: 6, base_delay: 10, multiplier: 3, max_delay: 100, );
		$ceilings = array();

		for ( $attempt = 1; $attempt < $policy->max_attempts; ++$attempt ) {
			$ceilings[] = $policy->delay_ceiling_for_attempt( $attempt );
		}

		self::assertSame( array( 10, 30, 90, 100, 100 ), $ceilings );
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
		$policy = new RetryPolicy( max_attempts: 4, base_delay: 7, multiplier: 1, max_delay: 100, );

		self::assertSame( 7, $policy->delay_ceiling_for_attempt( 1 ) );
		self::assertSame( 7, $policy->delay_ceiling_for_attempt( 2 ) );
		self::assertSame( 7, $policy->delay_ceiling_for_attempt( 3 ) );
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
		$policy = new RetryPolicy( max_attempts: 4, base_delay: 10, multiplier: 3, max_delay: 90, );

		self::assertSame( 90, $policy->delay_ceiling_for_attempt( 3 ) );
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
		$policy = new RetryPolicy( max_attempts: 3, base_delay: 33, multiplier: 3, max_delay: 100, );

		self::assertSame( 99, $policy->delay_ceiling_for_attempt( 2 ) );
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
		$policy = new RetryPolicy( max_attempts: \PHP_INT_MAX, base_delay: 2, multiplier: \PHP_INT_MAX, max_delay: \PHP_INT_MAX, );

		self::assertSame( \PHP_INT_MAX, $policy->delay_ceiling_for_attempt( \PHP_INT_MAX - 1 ) );
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
}
