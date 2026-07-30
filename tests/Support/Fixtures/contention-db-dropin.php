<?php declare( strict_types=1 );

/**
 * Holds a dispatch between the two compare-and-swap writes a lane takeover performs.
 *
 * Taking over a lane supersedes the incumbent's run row and then transfers the overlap lock. No
 * engine hook fires between those writes, and none should: every consumer callback in the engine is
 * replayed from the effects layer precisely so third-party code never runs inside a linearization.
 * WordPress's own database drop-in is the seam that reaches this window from outside, at the storage
 * layer, without the engine offering one.
 *
 * WordPress loads `class-wpdb.php` before this file and creates the standard `$wpdb` only when this
 * file leaves it unset, so returning early is a complete no-op for every process that does not ask
 * to be parked.
 *
 * @package A8C\SpecialProjects\BackgroundJobsEngine
 */

$a8csp_bgje_park_token = (string) \getenv( 'A8CSP_BGJE_CONTENTION_PARK_TOKEN' );
if ( '' === $a8csp_bgje_park_token || ! \class_exists( 'wpdb', false ) ) {
	return;
}

/**
 * Parks the first active-run compare-and-swap of a takeover until a peer process releases it.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
// phpcs:ignore PEAR.NamingConventions.ValidClassName, Squiz.Classes.ValidClassName -- A database drop-in loads before any autoloader, so it cannot be namespaced.
class A8CSP_BGJE_Takeover_Window_Wpdb extends wpdb {
	// region FIELDS AND CONSTANTS.

	/** Barrier token shared with the peer process. */
	private string $token = '';

	/** Whether the one park this process performs has already happened. */
	private bool $parked = false;

	// endregion.

	// region METHODS.

	/**
	 * Binds the barrier token the peer process releases this write through.
	 *
	 * @param   string $token Barrier token.
	 *
	 * @return  void
	 */
	public function set_park_token( string $token ): void {
		$this->token = $token;
	}

	/**
	 * Runs a query, parking after the supersession write a takeover performs first.
	 *
	 * The active-run write is the takeover's first linearization point and the overlap-lock write is
	 * its second. Parking after the former and before the latter is the only way to hold a real
	 * process inside a window the engine deliberately keeps adjacent.
	 *
	 * @param   string $query Query.
	 *
	 * @return  int|bool
	 */
	#[\Override]
	public function query( $query ) { // phpcs:ignore Squiz.Commenting.FunctionComment.ScalarTypeHintMissing -- The parent signature is untyped.
		$result = parent::query( $query );

		if ( $this->parked || '' === $this->token || ! \is_string( $query ) ) {
			return $result;
		}

		// Only the supersession write parks. A lock write means the window has already closed, and a
		// later run write belongs to the replacement's own state rather than to the takeover.
		if ( ! \str_starts_with( \strtoupper( \ltrim( $query ) ), 'UPDATE' ) || ! \str_contains( $query, 'a8csp_bgje_active_run_' ) ) {
			return $result;
		}

		$this->parked = true;

		// Only the engine writes this row, so its autoloader is registered by the time this runs even
		// though it was not when this file loaded. An unautoloadable barrier is therefore a broken
		// harness, and a fatal here says so rather than degrading into a peer's barrier timeout.
		$barrier = new \A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support\ContentionBarrier( $this->token );
		$barrier->arrive( $barrier::TAKEOVER_GATE );

		return $result;
	}

	// endregion.
}

$wpdb = new A8CSP_BGJE_Takeover_Window_Wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$wpdb->set_park_token( $a8csp_bgje_park_token );
