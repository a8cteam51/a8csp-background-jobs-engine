<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\ScalarTree;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Complete declarative definition of one recurring task schedule.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Schedule {
	// region FIELDS AND CONSTANTS

	/**
	 * Stable identity of every field that changes scheduled behavior.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     string
	 */
	private string $fingerprint;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $name       Stable schedule name.
	 * @param   Recurrence              $recurrence Recurrence definition.
	 * @param   string                  $task       Stable target task name.
	 * @param   array<array-key, mixed> $args       Target task arguments.
	 * @param   OverlapPolicy           $overlap    Overlapping-run policy.
	 * @param   CatchUpPolicy           $catch_up   Missed-occurrence policy.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @throws  \InvalidArgumentException When the definition is not portable or violates a boundary.
	 */
	public function __construct(
		public string $name,
		public Recurrence $recurrence,
		public string $task,
		public array $args = array(),
		public OverlapPolicy $overlap = OverlapPolicy::Skip,
		public CatchUpPolicy $catch_up = CatchUpPolicy::RunOnce,
		public int $priority = 10,
	) {
		WorkIdentity::validate_name( $this->name );

		if ( 0 > $this->priority || 255 < $this->priority ) {
			// Exception values are diagnostic data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \InvalidArgumentException(
				\sprintf(
					'Schedule "%1$s" priority %2$d is invalid; pass a value from 0 through 255.',
					$this->name,
					$this->priority
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		try {
			$encoded_args = \wp_json_encode(
				$this->args,
				\JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( \JsonException ) {
			$encoded_args = false;
		}

		if ( ! \is_string( $encoded_args ) || ! ScalarTree::is_valid( $this->args ) ) {
			// Exception values are diagnostic data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \InvalidArgumentException(
				\sprintf(
					'Schedule "%s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.',
					$this->name
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		try {
			$encoded = \wp_json_encode(
				array(
					'name'       => $this->name,
					'recurrence' => $this->recurrence->fingerprint_value(),
					'task'       => $this->task,
					'args'       => $this->args,
					'overlap'    => $this->overlap->value,
					'catch_up'   => $this->catch_up->value,
					'priority'   => $this->priority,
				),
				\JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( \JsonException ) {
			throw new \InvalidArgumentException(
				'Schedule definition must be JSON-encodable; pass valid UTF-8 task and recurrence strings.'
			);
		}

		if ( ! \is_string( $encoded ) ) {
			throw new \InvalidArgumentException(
				'Schedule definition must be JSON-encodable; pass valid UTF-8 task and recurrence strings.'
			);
		}

		$this->fingerprint = \hash( 'sha256', $encoded );
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the stable SHA-256 definition identity.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  string
	 */
	public function fingerprint(): string {
		return $this->fingerprint;
	}

	// endregion
}
