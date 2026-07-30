<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

/**
 * Rendezvous point two operating-system processes both reach through the options table.
 *
 * An in-process hook cannot express a cross-process interleaving: its callback runs to
 * completion, so it can never hold one actor mid-flight while another advances. The
 * database is the only medium a PHPUnit process and a WP-CLI subprocess share, and gate
 * reads go straight to it because a peer's write is invisible to this request's option
 * cache. The prefix sits outside the engine's own so option hygiene never sweeps a live
 * gate out from under a parked process.
 */
final class ContentionBarrier {
	// region FIELDS AND CONSTANTS.

	/** Option prefix outside the engine's, so option hygiene leaves live gates alone. */
	private const string OPTION_PREFIX = 'a8csp_contention_barrier_';

	/** Seconds between polls of a peer's gate row. */
	private const float POLL_INTERVAL = 0.01;

	/** Seconds a wait blocks before reporting that its peer never came. */
	public const float TIMEOUT = 30.0;

	// endregion.

	// region MAGIC METHODS.

	/**
	 * Binds a barrier to the token both processes name it by.
	 *
	 * @param   string $token Barrier token shared with the peer process.
	 */
	public function __construct( private readonly string $token ) {}

	// endregion.

	// region METHODS.

	/**
	 * Announces arrival at a gate, then blocks until the peer releases it.
	 *
	 * @param   string $gate    Gate name.
	 * @param   float  $timeout Seconds to block before giving up.
	 *
	 * @throws  \RuntimeException When the peer never releases the gate.
	 *
	 * @return  void
	 */
	public function arrive( string $gate, float $timeout = self::TIMEOUT ): void {
		\update_option( $this->row_name( $gate, 'arrived' ), '1', false );
		$this->await( $gate, 'released', $timeout );
	}

	/**
	 * Blocks until the peer announces arrival at a gate.
	 *
	 * @param   string $gate    Gate name.
	 * @param   float  $timeout Seconds to block before giving up.
	 *
	 * @throws  \RuntimeException When the peer never arrives.
	 *
	 * @return  void
	 */
	public function await_arrival( string $gate, float $timeout = self::TIMEOUT ): void {
		$this->await( $gate, 'arrived', $timeout );
	}

	/**
	 * Releases a parked peer.
	 *
	 * @param   string $gate Gate name.
	 *
	 * @return  void
	 */
	public function release( string $gate ): void {
		\update_option( $this->row_name( $gate, 'released' ), '1', false );
	}

	/**
	 * Deletes every row backing the named gates.
	 *
	 * @param   string ...$gates Gate names.
	 *
	 * @return  void
	 */
	public function clear( string ...$gates ): void {
		foreach ( $gates as $gate ) {
			\delete_option( $this->row_name( $gate, 'arrived' ) );
			\delete_option( $this->row_name( $gate, 'released' ) );
		}
	}

	// endregion.

	// region HELPERS.

	/**
	 * Polls one gate marker until it appears or the deadline passes.
	 *
	 * @param   string $gate    Gate name.
	 * @param   string $marker  Marker to wait for.
	 * @param   float  $timeout Seconds to block before giving up.
	 *
	 * @throws  \RuntimeException When the marker never appears.
	 *
	 * @return  void
	 */
	private function await( string $gate, string $marker, float $timeout ): void {
		$deadline = \microtime( true ) + $timeout;

		while ( null === $this->read( $this->row_name( $gate, $marker ) ) ) {
			if ( \microtime( true ) >= $deadline ) {
				throw new \RuntimeException( \sprintf( 'Barrier gate "%1$s" never reported "%2$s" within %3$.1F seconds.', $gate, $marker, $timeout ) );
			}

			\usleep( (int) ( self::POLL_INTERVAL * 1_000_000 ) );
		}
	}

	/**
	 * Reads one gate row past the option cache.
	 *
	 * @param   string $name Option name.
	 *
	 * @return  string|null Raw gate value, or null while the row is absent.
	 */
	private function read( string $name ): ?string {
		global $wpdb;

		/** @var \wpdb $wpdb */
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT `option_value` FROM %i WHERE `option_name` = %s', $wpdb->options, $name ) );

		return \is_string( $value ) ? $value : null;
	}

	/**
	 * Composes the option name backing one gate marker.
	 *
	 * @param   string $gate   Gate name.
	 * @param   string $marker Marker name.
	 *
	 * @return  string
	 */
	private function row_name( string $gate, string $marker ): string {
		return self::OPTION_PREFIX . $this->token . '_' . $gate . '_' . $marker;
	}

	// endregion.
}
