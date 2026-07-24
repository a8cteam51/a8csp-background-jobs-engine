<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs;

\defined( 'ABSPATH' ) || exit;

/**
 * Carries one classified terminal snapshot refresh and its trustworthy snapshot when present.
 *
 * @internal
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class TerminalSnapshotRefreshResult {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param array{raw: string, state: RunState}|null $snapshot
	 *
	 * @param   TerminalSnapshotRefreshOutcome $outcome  Refresh classification.
	 * @param   array|null                     $snapshot Exact refreshed row bytes and terminal state, or null without a trustworthy snapshot.
	 */
	private function __construct(
		public TerminalSnapshotRefreshOutcome $outcome,
		public ?array $snapshot,
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns one trustworthy refreshed snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string   $raw   Exact refreshed row bytes.
	 * @param   RunState $state Refreshed terminal state.
	 *
	 * @return  self
	 */
	public static function refreshed( string $raw, RunState $state ): self {
		return new self(
			TerminalSnapshotRefreshOutcome::Refreshed,
			array(
				'raw'   => $raw,
				'state' => $state,
			)
		);
	}

	/**
	 * Returns one outcome without a trustworthy authoritative snapshot.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function untrusted(): self {
		return new self( TerminalSnapshotRefreshOutcome::Untrusted, null );
	}

	/**
	 * Returns one outcome whose run was already finished by another worker.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  self
	 */
	public static function already_finished(): self {
		return new self( TerminalSnapshotRefreshOutcome::AlreadyFinished, null );
	}

	// endregion
}
