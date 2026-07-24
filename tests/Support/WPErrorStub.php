<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

/**
 * Runtime WordPress error stand-in for unit tests that do not load WordPress.
 */
final readonly class WPErrorStub {
	// region MAGIC METHODS.

	/**
	 * Constructor.
	 *
	 * @param   string|int $code    Error code.
	 * @param   string     $message Error message.
	 * @param   mixed      $data    Error data.
	 */
	public function __construct(
		public string|int $code = '',
		private string $message = '',
		private mixed $data = null,
	) {}

	// endregion.

	// region GETTERS.

	/**
	 * Returns the stored message.
	 *
	 * @param   string|int $code Optional error code.
	 *
	 * @return  string
	 */
	public function get_error_message( string|int $code = '' ): string {
		return $this->message;
	}

	/**
	 * Returns the stored error code.
	 *
	 * @return  string|int
	 */
	public function get_error_code(): string|int {
		return $this->code;
	}

	/**
	 * Returns the stored error data.
	 *
	 * @param   string|int $code Optional error code.
	 *
	 * @return  mixed
	 */
	public function get_error_data( string|int $code = '' ): mixed {
		return $this->data;
	}

	/**
	 * Reports whether the stub contains an error code.
	 *
	 * @return  bool
	 */
	public function has_errors(): bool {
		return '' !== $this->code && 0 !== $this->code;
	}

	// endregion.
}
