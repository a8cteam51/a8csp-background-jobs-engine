<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine;

\defined( 'ABSPATH' ) || exit;

/**
 * Complete declarative definition of one recurring job schedule.
 *
 * @api
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class Schedule {
	// region FIELDS AND CONSTANTS

	/** Mirrors `Boundary\PortableArguments::MAX_ARGUMENTS_JSON_DEPTH`. */
	private const int MAX_ARGUMENTS_JSON_DEPTH = 512;

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
	 * Stable identity of the fields that determine the engine-owned recurring chain.
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
	 * @param   string                  $job        Stable target job name.
	 * @param   array<array-key, mixed> $args       Target job arguments.
	 * @param   CatchUpPolicy           $catch_up   Missed-occurrence policy.
	 * @param   int|null                $priority   Advisory priority from 0 through 255, or null to defer to the job default.
	 *
	 * @throws  \InvalidArgumentException When the argument snapshot is not portable or the definition is not JSON-encodable.
	 */
	public function __construct(
		public string $name,
		public Recurrence $recurrence,
		public string $job,
		array $args = array(),
		public CatchUpPolicy $catch_up = CatchUpPolicy::RunOnce,
		public ?int $priority = null,
	) {
		$this->args = self::snapshot_arguments( $args, $this->name );

		try {
			$encoded = \wp_json_encode(
				array(
					'name'       => $this->name,
					'recurrence' => $this->recurrence->fingerprint_value(),
					'job'        => $this->job,
					'args'       => $this->args,
					'catch_up'   => $this->catch_up->value,
				),
				\JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( \JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Schedule "%s" definition must be JSON-encodable; use valid UTF-8 in the name, target job, and arguments, and finite numbers in the arguments.', $this->name ) );
		}

		if ( ! \is_string( $encoded ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Schedule "%s" definition must be JSON-encodable; use valid UTF-8 in the name, target job, and arguments, and finite numbers in the arguments.', $this->name ) );
		}

		$this->fingerprint = \hash( 'sha256', $encoded );
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the stable SHA-256 recurring-chain identity.
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

	// region HELPERS

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
		if ( ! self::has_portable_values( $arguments ) ) {
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
		if ( ! \is_array( $snapshot ) || ! self::has_portable_values( $snapshot ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Schedule "%s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.', $name ) );
		}

		return self::without_references( $snapshot );
	}

	/**
	 * Returns whether values satisfy the portability rule within the permitted array depth.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $values          Values to inspect.
	 * @param   int                     $remaining_depth Array levels still permitted.
	 *
	 * @return  bool
	 */
	private static function has_portable_values( array $values, int $remaining_depth = self::MAX_ARGUMENTS_JSON_DEPTH ): bool {
		if ( 1 > $remaining_depth ) {
			return false;
		}

		return \array_all( $values, static fn ( mixed $value ): bool => \is_array( $value ) ? self::has_portable_values( $value, $remaining_depth - 1 ) : ( null === $value || \is_scalar( $value ) ) );
	}

	/**
	 * Rebuilds an argument tree by value without PHP reference containers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $values Values to rebuild.
	 *
	 * @return  array<array-key, mixed>
	 */
	private static function without_references( array $values ): array {
		$snapshot = array();
		foreach ( $values as $key => $value ) {
			$snapshot[ $key ] = \is_array( $value ) ? self::without_references( $value ) : $value;
		}

		return $snapshot;
	}

	// endregion
}
