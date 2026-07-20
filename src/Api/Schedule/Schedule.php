<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Api\Schedule;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\AdmissionValidator;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\JobIdentity;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\PortableArguments;

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
	 * Target job arguments.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<array-key, mixed>
	 */
	public array $args;

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
		array $args = array(),
		public CatchUpPolicy $catch_up = CatchUpPolicy::RunOnce,
		public int $priority = 10,
	) {
		JobIdentity::validate_name( $this->name );
		JobIdentity::validate_name( $this->job );
		$this->args = self::snapshot_arguments( $args, $this->name );
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

	// region METHODS

	/**
	 * Returns a portable argument snapshot without PHP reference containers.
	 *
	 * The preflight rejects values that PHP serialization normalizes, such as resources, before the
	 * serialization round trip creates the retained representation.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $arguments Arguments to snapshot.
	 * @param   string                  $name      Schedule name for diagnostic context.
	 *
	 * @throws  \InvalidArgumentException When the arguments cannot form a portable snapshot.
	 *
	 * @return  array<array-key, mixed>
	 */
	private static function snapshot_arguments( array $arguments, string $name ): array {
		if ( ! PortableArguments::is_valid( $arguments ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Schedule "%s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.', $name ) );
		}

		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- The round trip detaches the snapshot from caller-owned containers before recursive rebuilding removes repeated aliases.
			$snapshot = \unserialize( \serialize( $arguments ), array( 'allowed_classes' => false ) );
		} catch ( \Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Schedule "%s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.', $name ) );
		}
		if ( ! \is_array( $snapshot ) || ! PortableArguments::is_valid( $snapshot ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Schedule "%s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.', $name ) );
		}

		return PortableArguments::without_references( $snapshot );
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
