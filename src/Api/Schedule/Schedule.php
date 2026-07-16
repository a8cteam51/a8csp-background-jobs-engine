<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Api\Schedule;

use A8C\SpecialProjects\BackgroundTasksEngine\Api\AdmissionValidator;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\WorkIdentity;

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
	 * @throws  \InvalidArgumentException When a schedule or target task name is invalid, or the definition is not portable or violates a boundary.
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
		WorkIdentity::validate_name( $this->task );
		AdmissionValidator::assert_priority( $this->priority, \sprintf( 'Schedule "%s"', $this->name ) );
		$payload_error = AdmissionValidator::assert_portable_args( $this->args, \sprintf( 'Schedule "%s"', $this->name ) );
		if ( null !== $payload_error ) {
			// Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( $payload_error->message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
			throw new \InvalidArgumentException( 'Schedule definition must be JSON-encodable; pass valid UTF-8 task and recurrence strings.' );
		}

		if ( ! \is_string( $encoded ) ) {
			throw new \InvalidArgumentException( 'Schedule definition must be JSON-encodable; pass valid UTF-8 task and recurrence strings.' );
		}

		$this->fingerprint = \hash( 'sha256', $encoded );
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the stable SHA-256 definition identity.
	 *
	 * @internal Engine change-detection seam; the hash construction is not consumer contract.
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
