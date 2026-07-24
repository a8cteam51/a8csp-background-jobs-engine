<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Schedule;

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

	/**
	 * Maximum encoded JSON bytes accepted for persisted arguments.
	 *
	 * The public-model copy mirrors `Runtime\OwnerOperations::MAX_ARGUMENTS_BYTES` because models do
	 * not import `src/` internals.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_ARGUMENTS_BYTES = 8_192;

	/**
	 * Maximum bytes accepted for an owner-local name.
	 *
	 * The public-model copy mirrors `Boundary\JobIdentity::NAME_MAX_BYTES` because models do not
	 * import `src/` internals.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_NAME_BYTES = 64;

	/**
	 * Highest scheduler priority accepted by the schedule contract.
	 *
	 * The public-model copy mirrors `Runtime\Runs\Dispatcher::MAX_PRIORITY` because models do not
	 * import `src/` internals.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     int
	 */
	private const int MAX_PRIORITY = 255;

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
		self::validate_name( $this->name );
		self::validate_name( $this->job );
		$this->args = self::snapshot_arguments( $args, $this->name );
		self::assert_priority( $this->priority, $this->name );
		self::assert_portable_args( $this->args, $this->name );

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

	// region HELPERS

	/**
	 * Validates one owner-local job or schedule name.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Owner-local name.
	 *
	 * @throws  \InvalidArgumentException When the name violates the stable grammar.
	 *
	 * @return  void
	 */
	private static function validate_name( string $name ): void {
		if ( 1 === \preg_match( '/\A[a-z0-9_-]+\z/D', $name ) && self::MAX_NAME_BYTES >= \strlen( $name ) ) {
			return;
		}

		throw new \InvalidArgumentException( 'Background-work name is invalid; pass 1 to 64 bytes containing only lowercase letters, digits, underscores, and hyphens.' );
	}

	/**
	 * Validates one schedule priority.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int    $priority Schedule priority.
	 * @param   string $name     Schedule name for diagnostic context.
	 *
	 * @throws  \InvalidArgumentException When the priority is outside the supported range.
	 *
	 * @return  void
	 */
	private static function assert_priority( int $priority, string $name ): void {
		if ( 0 <= $priority && self::MAX_PRIORITY >= $priority ) {
			return;
		}

		// Exception values are diagnostic data, not rendered output.
		throw new \InvalidArgumentException( \sprintf( 'Schedule "%1$s" priority %2$d is invalid; pass a value from 0 through %3$d.', $name, $priority, self::MAX_PRIORITY ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Validates the portable argument tree and its persisted byte boundary.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   array<array-key, mixed> $arguments Arguments to validate.
	 * @param   string                  $name      Schedule name for diagnostic context.
	 *
	 * @throws  \InvalidArgumentException When the arguments are not portable or exceed the byte boundary.
	 *
	 * @return  void
	 */
	private static function assert_portable_args( array $arguments, string $name ): void {
		try {
			$encoded = \wp_json_encode( $arguments, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION );
		} catch ( \JsonException ) {
			$encoded = false;
		}

		if ( ! \is_string( $encoded ) || ! self::has_portable_values( $arguments ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Schedule "%s" arguments must be a JSON-encodable tree of scalars and arrays; use valid UTF-8 strings, finite numbers, and stable scalar identifiers without recursive or excessive nesting.', $name ) );
		}

		$actual_bytes = \strlen( $encoded );
		if ( self::MAX_ARGUMENTS_BYTES < $actual_bytes ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception values are diagnostic data, not rendered output.
			throw new \InvalidArgumentException( \sprintf( 'Schedule "%1$s" arguments contain %2$d JSON bytes; the limit is %3$d bytes.', $name, $actual_bytes, self::MAX_ARGUMENTS_BYTES ) );
		}
	}

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
	private static function has_portable_values( array $values, int $remaining_depth = 512 ): bool {
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
