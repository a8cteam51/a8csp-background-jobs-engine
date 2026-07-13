<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Records PSR-3 calls without interpreting their context.
 */
final class RecordingLogger extends AbstractLogger {
	/**
	 * Calls in invocation order.
	 *
	 * @var list<array{level: mixed, message: string, context: array<array-key, mixed>}>
	 */
	public array $records = array();

	/**
	 * Records a log call.
	 *
	 * @param   mixed                   $level   Log level.
	 * @param   string|\Stringable      $message Log message.
	 * @param   array<array-key, mixed> $context Log context.
	 *
	 * @return  void
	 */
	#[\Override]
	public function log( mixed $level, string|\Stringable $message, array $context = array() ): void {
		$this->records[] = array(
			'level'   => $level,
			'message' => (string) $message,
			'context' => $context,
		);
	}
}
