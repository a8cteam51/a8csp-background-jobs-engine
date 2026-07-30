<?php declare( strict_types=1 );

/**
 * Dispatches one job from an isolated WP-CLI process so a lane faces two real contenders.
 *
 * Every interleaving the suite otherwise expresses is scripted inside one process, where a
 * hook callback runs to completion and no actor can be held mid-flight. This process is a
 * genuine second contender: its own request, its own object cache, the same database. The
 * lane, the overlap policy and an optional barrier gate arrive through the environment
 * because WP-CLI's positional arguments are local to the scope that includes this file.
 *
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\BoundaryError;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\JobOptions;
use A8C\SpecialProjects\BackgroundJobsEngine\OverlapPolicy;
use A8C\SpecialProjects\BackgroundJobsEngine\Run;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Component;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\ContentionBarrier;
use A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\RecordingJob;

$a8csp_bgje_setting = static function ( string $name ): string {
	$value = \getenv( $name );

	return \is_string( $value ) ? $value : '';
};

$a8csp_bgje_scope   = $a8csp_bgje_setting( 'A8CSP_BGJE_CONTENTION_SCOPE' );
$a8csp_bgje_name    = $a8csp_bgje_setting( 'A8CSP_BGJE_CONTENTION_NAME' );
$a8csp_bgje_overlap = OverlapPolicy::tryFrom( $a8csp_bgje_setting( 'A8CSP_BGJE_CONTENTION_OVERLAP' ) );
$a8csp_bgje_token   = $a8csp_bgje_setting( 'A8CSP_BGJE_CONTENTION_TOKEN' );

if ( '' === $a8csp_bgje_scope || '' === $a8csp_bgje_name || null === $a8csp_bgje_overlap ) {
	throw new \InvalidArgumentException( 'A contention dispatch requires a scope, a name and an overlap policy.' );
}

$a8csp_bgje_operations = Component::operations( $a8csp_bgje_scope );
$a8csp_bgje_operations->register( ( new RecordingJob( $a8csp_bgje_name ) )->definition( new JobOptions( overlap: $a8csp_bgje_overlap ) ) );

// The staleness filter fires only once a claim finds an incumbent lock, and it fires before this
// dispatch reads the incumbent run row or writes anything. Parking here holds a real contender
// inside contended admission, holding nothing, while the peer process moves the lane underneath it.
if ( '' !== $a8csp_bgje_token ) {
	$a8csp_bgje_barrier = new ContentionBarrier( $a8csp_bgje_token );
	$a8csp_bgje_parked  = false;

	\add_filter(
		'a8csp_bgje/lock_staleness',
		static function ( int $seconds ) use ( $a8csp_bgje_barrier, &$a8csp_bgje_parked ): int {
			if ( true !== $a8csp_bgje_parked ) {
				$a8csp_bgje_parked = true;
				$a8csp_bgje_barrier->arrive( ContentionBarrier::ADMISSION_GATE );
			}

			return $seconds;
		},
		10,
		1
	);
}

$a8csp_bgje_result = $a8csp_bgje_operations->dispatch( $a8csp_bgje_name );
$a8csp_bgje_report = array( 'outcome' => 'unexpected' );

if ( $a8csp_bgje_result instanceof Success && $a8csp_bgje_result->value instanceof Run ) {
	$a8csp_bgje_report = array(
		'outcome' => 'success',
		'run_id'  => (string) $a8csp_bgje_result->value->id,
	);
} elseif ( $a8csp_bgje_result instanceof Failure && $a8csp_bgje_result->error instanceof BoundaryError ) {
	$a8csp_bgje_report = array(
		'outcome' => 'failure',
		'code'    => $a8csp_bgje_result->error->code->value,
	);
}

$a8csp_bgje_json = \wp_json_encode( $a8csp_bgje_report );

// A sentinel keeps the verdict readable when WordPress writes notices to the same stream.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML escaping would corrupt the JSON the peer process parses; this stream is a process boundary, not markup.
echo 'A8CSP_BGJE_CONTENTION_RESULT:' . ( \is_string( $a8csp_bgje_json ) ? $a8csp_bgje_json : '{"outcome":"unencodable"}' ) . "\n";
