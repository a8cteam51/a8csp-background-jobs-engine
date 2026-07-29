<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\RunState;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\Stores\RunStore;

/**
 * Reads typed run state through the production inspection result.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class RunStoreInspector {
	// region METHODS.

	/**
	 * Returns one typed state while surfacing an authoritative storage failure.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   RunStore $store  Run store.
	 * @param   string   $run_id Run identifier.
	 *
	 * @return  RunState|null
	 */
	public static function state( RunStore $store, string $run_id ): ?RunState {
		$inspected = $store->inspect( $run_id );
		if ( $inspected->is_failure() ) {
			throw new \UnexpectedValueException( 'The test could not inspect the authoritative run row: ' . $inspected->error->message );
		}

		return $inspected->value['state'] ?? null;
	}

	// endregion.
}
