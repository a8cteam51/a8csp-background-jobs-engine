<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

/**
 * Runtime WordPress error stand-in for unit tests that do not load WordPress.
 */
final readonly class WPErrorStub {
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
		public mixed $data = '',
	) {}

	/**
	 * Returns the stored message for the matching code.
	 *
	 * @param   string|int $code Optional error code.
	 *
	 * @return  string
	 */
	public function get_error_message( string|int $code = '' ): string {
		return '' === $code || $this->code === $code ? $this->message : '';
	}

	/**
	 * Returns the stored error code.
	 *
	 * @return  string|int
	 */
	public function get_error_code(): string|int {
		return $this->code;
	}
}
