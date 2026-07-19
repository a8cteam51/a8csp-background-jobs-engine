<?php declare( strict_types=1 );

use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\BatchInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Api\Batch\ExistingRunPolicy;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers one batch for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string         $owner Client plugin owner.
 * @param   BatchInterface $batch Batch to register.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  true|\WP_Error
 */
#[\NoDiscard( 'a batch-registration failure must be handled, not dropped' )]
function a8csp_bgte_batch_register( string $owner, BatchInterface $batch ): true|\WP_Error {
	try {
		$client = \a8csp_bgte( $owner );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	try {
		$client->batches()->register( $batch );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	} catch ( \LogicException $exception ) {
		return new \WP_Error( 'already_registered', $exception->getMessage() );
	}

	return true;
}

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- Local validation exceptions are translated to WP_Error before crossing the procedural boundary.
/**
 * Creates and schedules one batch run for an owner.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   string                  $owner      Client plugin owner.
 * @param   string                  $name       Owner-local batch name.
 * @param   array<array-key, mixed> $start_args Arguments supplied when the run starts.
 * @param   string                  $existing   Existing-run policy: reject or replace.
 * @param   int                     $priority   Advisory priority from 0 through 255.
 *
 * @throws  \LogicException When called before the earliest safe hook or engine wiring fails.
 *
 * @return  string|\WP_Error
 */
#[\NoDiscard( 'a batch-start failure must be handled, not dropped' )]
function a8csp_bgte_batch_start( string $owner, string $name, array $start_args = array(), string $existing = 'reject', int $priority = 10 ): string|\WP_Error {
	try {
		$policy = ExistingRunPolicy::tryFrom( $existing );
		if ( null === $policy ) {
			throw new \InvalidArgumentException( 'existing must be reject or replace' );
		}

		$result = \a8csp_bgte( $owner )->batches()->start( $name, $start_args, $policy, $priority );
	} catch ( \InvalidArgumentException $exception ) {
		return new \WP_Error( 'invalid_argument', $exception->getMessage() );
	}

	if ( $result->is_failure() ) {
		return new \WP_Error( $result->error->code->value, $result->error->message, $result->error->context );
	}

	return $result->value;
}
// phpcs:enable
