<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Bridge;

use A8C\SpecialProjects\BackgroundJobsEngine\Api\Error\RunFailure as InternalFailure;
use A8C\SpecialProjects\BackgroundJobsEngine\Api\RetryPolicy as InternalRetry;

\defined( 'ABSPATH' ) || exit;

/**
 * Builds the internal retry value declared by a consumer job.
 *
 * @internal Adapter shim support.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   array<array-key, mixed> $retry Consumer retry declaration.
 *
 * @throws  \InvalidArgumentException When a field name, type, or invariant is invalid.
 *
 * @return  InternalRetry
 */
function retry_policy( array $retry ): InternalRetry {
	foreach ( $retry as $field => $value ) {
		if ( ! \is_string( $field ) || ! \in_array( $field, array( 'max_attempts', 'base_delay', 'multiplier', 'max_delay' ), true ) || ! \is_int( $value ) ) {
			throw new \InvalidArgumentException( 'Retry declarations accept only integer max_attempts, base_delay, multiplier, and max_delay fields.' );
		}
	}

	$defaults = new InternalRetry();
	if ( array() === $retry ) {
		return $defaults;
	}

	return new InternalRetry(
		max_attempts: $retry['max_attempts'] ?? $defaults->max_attempts,
		base_delay: $retry['base_delay'] ?? $defaults->base_delay,
		multiplier: $retry['multiplier'] ?? $defaults->multiplier,
		max_delay: $retry['max_delay'] ?? $defaults->max_delay,
	);
}

/**
 * Converts one internal terminal failure to consumer data.
 *
 * @internal Adapter shim support.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @param   InternalFailure $failure Internal terminal failure.
 *
 * @phpstan-return array{run_id: string, attempts: int, stage: string, code: string, summary: string, failed_chunk: array<array-key, mixed>|null}
 *
 * @return  array
 */
function failure_to_array( InternalFailure $failure ): array {
	return array(
		'run_id'       => $failure->run_id,
		'attempts'     => $failure->attempts,
		'stage'        => $failure->stage->value,
		'code'         => $failure->code->value,
		'summary'      => $failure->summary,
		'failed_chunk' => $failure->failed_chunk,
	);
}
