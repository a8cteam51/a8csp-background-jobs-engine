<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\Randomization\RandomizerInterface;

/**
 * Returns one deterministic integer while recording requested boundaries.
 */
final class RecordingRandomizer implements RandomizerInterface {
	/**
	 * Requested boundaries in call order.
	 *
	 * @var list<array{min: int, max: int}>
	 */
	public array $calls = array();

	/**
	 * Constructor.
	 *
	 * @param   int $value Deterministic result.
	 */
	public function __construct( public int $value ) {}

	/**
	 * Returns the deterministic result within the requested boundaries.
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

		if ( $this->value < $min || $this->value > $max ) {
			throw new \UnexpectedValueException( 'Set the deterministic random value within the requested boundaries.' );
		}

		return $this->value;
	}
}
