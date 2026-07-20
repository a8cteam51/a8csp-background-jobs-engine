<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\AdmissionValidator;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\JobIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Complete declarative definition of one recurring job schedule.
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
	 * @param   string                  $job       Stable target job name.
	 * @param   array<array-key, mixed> $args       Target job arguments.
	 * @param   CatchUpPolicy           $catch_up   Missed-occurrence policy.
	 * @param   int                     $priority   Advisory priority from 0 through 255.
	 *
	 * @throws  \InvalidArgumentException When a schedule or target job name is invalid, or the definition is not portable or violates a boundary.
	 */
	public function __construct(
		public string $name,
		public Recurrence $recurrence,
		public string $job,
		public array $args = array(),
		public CatchUpPolicy $catch_up = CatchUpPolicy::RunOnce,
		public int $priority = 10,
	) {
		JobIdentity::validate_name( $this->name );
		JobIdentity::validate_name( $this->job );
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
					'job'        => $this->job,
					'args'       => $this->args,
					'catch_up'   => $this->catch_up->value,
					'priority'   => $this->priority,
				),
				\JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( \JsonException ) {
			throw new \InvalidArgumentException( 'Schedule definition must be JSON-encodable; pass valid UTF-8 job and recurrence strings.' );
		}

		if ( ! \is_string( $encoded ) ) {
			throw new \InvalidArgumentException( 'Schedule definition must be JSON-encodable; pass valid UTF-8 job and recurrence strings.' );
		}

		$this->fingerprint = \hash( 'sha256', $encoded );
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the stable SHA-256 definition identity.
	 *
	 * @internal Engine change-detection seam; the hash construction is not client contract.
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
