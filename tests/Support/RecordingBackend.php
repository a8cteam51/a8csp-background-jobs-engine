<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Failure;
use A8C\SpecialProjects\BackgroundJobsEngine\Boundary\Result\Success;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Backends\BackendInterface;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingError;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Error\SchedulingErrorReason;
use PHPUnit\Framework\Assert;

/**
 * Call-routing spy with scriptable outcomes.
 *
 * It records facade calls without interpreting them. Result-only assertions against unscripted
 * defaults prove nothing about a real backend.
 */
final class RecordingBackend implements BackendInterface {
	/**
	 * Deliveries accepted by successful scheduling writes.
	 *
	 * @var list<array{hook: string, args: list<mixed>, group: string, timestamp: int|null, interval: int|null, priority: int, sequence: int}>
	 */
	private array $deliveries = array();

	/** Next stable delivery insertion sequence. */
	private int $delivery_sequence = 0;

	/**
	 * Interleavings run after the next matching write is recorded and before its result resolves.
	 *
	 * @var array<string, list<callable(self): void>>
	 */
	private array $before_writes = array();

	/**
	 * Calls in invocation order.
	 *
	 * @var list<array{verb: string, args: array<string, mixed>}>
	 */
	public array $calls = array();

	/**
	 * Scripted write results keyed by verb.
	 *
	 * @var array{
	 *     schedule_recurring?: AbstractResult<true, SchedulingError>,
	 *     schedule_single?: AbstractResult<true, SchedulingError>,
	 *     enqueue_async?: AbstractResult<true, SchedulingError>,
	 *     unschedule?: AbstractResult<true, SchedulingError>,
	 *     unschedule_group?: AbstractResult<true, SchedulingError>,
	 *     unschedule_hooks?: AbstractResult<int, SchedulingError>
	 * }
	 */
	public array $results = array();

	/** Whether queries report a matching scheduled hook. */
	public bool $scheduled = false;

	/** Scripted next-run timestamp. */
	public ?int $next_scheduled = null;

	/** Whether the backend reports itself ready. */
	public bool $ready = true;

	/** Whether the backend candidate has no runtime implementation. */
	public bool $absent = false;

	/**
	 * Pending action counts keyed by hook.
	 *
	 * @var array<string, int>
	 */
	public array $pending_actions = array();

	/**
	 * Readiness answers returned in call order before the stable readiness value.
	 *
	 * @var list<bool>
	 */
	public array $readiness_results = array();

	/**
	 * Records a recurring-schedule request and returns its scripted result.
	 *
	 * @phpstan-param list<mixed> $args
	 * @phpstan-return AbstractResult<true, SchedulingError>
	 *
	 * @param   string   $hook                Hook to run.
	 * @param   int      $interval            Interval in seconds.
	 * @param   array    $args                Hook arguments.
	 * @param   int|null $first_run_timestamp First-run timestamp.
	 * @param   string   $group               Group name.
	 * @param   int      $priority            Advisory priority.
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function schedule_recurring( string $hook, int $interval, array $args = array(), ?int $first_run_timestamp = null, string $group = '', int $priority = 10 ): AbstractResult {
		$this->calls[] = array(
			'verb' => 'schedule_recurring',
			'args' => array(
				'hook'                => $hook,
				'interval'            => $interval,
				'args'                => $args,
				'first_run_timestamp' => $first_run_timestamp,
				'group'               => $group,
				'priority'            => $priority,
			),
		);
		$this->run_before( 'schedule_recurring' );

		$result = $this->result_for( 'schedule_recurring' );
		if ( $result->is_success() ) {
			$this->record_delivery( $hook, $args, $group, $first_run_timestamp, $interval, $priority );
		}

		return $result;
	}

	/**
	 * Records a single-schedule request and returns its scripted result.
	 *
	 * @phpstan-param list<mixed> $args
	 * @phpstan-return AbstractResult<true, SchedulingError>
	 *
	 * @param   string $hook      Hook to run.
	 * @param   int    $timestamp Run timestamp.
	 * @param   array  $args      Hook arguments.
	 * @param   string $group     Group name.
	 * @param   int    $priority  Advisory priority.
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function schedule_single( string $hook, int $timestamp, array $args = array(), string $group = '', int $priority = 10 ): AbstractResult {
		$this->calls[] = array(
			'verb' => 'schedule_single',
			'args' => array(
				'hook'      => $hook,
				'timestamp' => $timestamp,
				'args'      => $args,
				'group'     => $group,
				'priority'  => $priority,
			),
		);
		$this->run_before( 'schedule_single' );

		$result = $this->result_for( 'schedule_single' );
		if ( $result->is_success() ) {
			$this->record_delivery( $hook, $args, $group, $timestamp, null, $priority );
		}

		return $result;
	}

	/**
	 * Records an async-enqueue request and returns its scripted result.
	 *
	 * @phpstan-param list<mixed> $args
	 * @phpstan-return AbstractResult<true, SchedulingError>
	 *
	 * @param   string $hook     Hook to run.
	 * @param   array  $args     Hook arguments.
	 * @param   string $group    Group name.
	 * @param   int    $priority Advisory priority.
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function enqueue_async( string $hook, array $args = array(), string $group = '', int $priority = 10 ): AbstractResult {
		$this->calls[] = array(
			'verb' => 'enqueue_async',
			'args' => array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'priority' => $priority,
			),
		);
		$this->run_before( 'enqueue_async' );

		$result = $this->result_for( 'enqueue_async' );
		if ( $result->is_success() ) {
			$this->record_delivery( $hook, $args, $group, null, null, $priority );
		}

		return $result;
	}

	/**
	 * Records an unschedule request and returns its scripted result.
	 *
	 * @phpstan-param list<mixed> $args
	 * @phpstan-return AbstractResult<true, SchedulingError>
	 *
	 * @param   string $hook  Hook to unschedule.
	 * @param   array  $args  Hook arguments.
	 * @param   string $group Group name.
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule( string $hook, array $args = array(), string $group = '' ): AbstractResult {
		$this->calls[] = array(
			'verb' => 'unschedule',
			'args' => array(
				'hook'  => $hook,
				'args'  => $args,
				'group' => $group,
			),
		);
		$this->run_before( 'unschedule' );

		$result = $this->result_for( 'unschedule' );
		if ( $result->is_success() ) {
			$this->deliveries = \array_values( \array_filter( $this->deliveries, static fn ( array $delivery ): bool => $hook !== $delivery['hook'] || $args !== $delivery['args'] || $group !== $delivery['group'] ) );
		}

		return $result;
	}

	/**
	 * Records group-wide clearance and removes every matching accepted delivery.
	 *
	 * @phpstan-return AbstractResult<true, SchedulingError>
	 *
	 * @param   string $group Group name.
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule_group( string $group ): AbstractResult {
		$this->calls[] = array(
			'verb' => 'unschedule_group',
			'args' => array( 'group' => $group ),
		);
		$this->run_before( 'unschedule_group' );

		$result = $this->result_for( 'unschedule_group' );
		if ( $result->is_success() ) {
			$this->deliveries = \array_values( \array_filter( $this->deliveries, static fn ( array $delivery ): bool => $group !== $delivery['group'] ) );
		}

		return $result;
	}

	/**
	 * Records hook-wide clearance and removes every matching pending action.
	 *
	 * @phpstan-param list<non-empty-string> $hooks
	 *
	 * @param   array $hooks Hooks to unschedule.
	 *
	 * @return  AbstractResult<int, SchedulingError>
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function unschedule_hooks( array $hooks ): AbstractResult {
		$this->calls[] = array(
			'verb' => 'unschedule_hooks',
			'args' => array( 'hooks' => $hooks ),
		);
		$this->run_before( 'unschedule_hooks' );

		$result = $this->results['unschedule_hooks'] ?? null;
		if ( null !== $result && $result->is_failure() ) {
			return $result;
		}

		$count = 0;
		foreach ( \array_unique( $hooks ) as $hook ) {
			$count += $this->pending_actions[ $hook ] ?? 0;
			unset( $this->pending_actions[ $hook ] );
		}

		return $result ?? new Success( $count );
	}

	/**
	 * Registers an interleaving before the next matching write result resolves.
	 *
	 * @phpstan-param 'schedule_recurring'|'schedule_single'|'enqueue_async'|'unschedule'|'unschedule_group'|'unschedule_hooks' $verb
	 *
	 * @param   string               $verb     Write verb.
	 * @param   callable(self): void $callback Interleaving callback.
	 *
	 * @return  void
	 */
	public function before_next( string $verb, callable $callback ): void {
		$this->before_writes[ $verb ][] = $callback;
	}

	/**
	 * Records a pending-occurrence count and returns exact matching accepted deliveries.
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   string $hook  Hook to query.
	 * @param   array  $args  Hook arguments.
	 * @param   string $group Group name.
	 *
	 * @return  int<0, max>
	 */
	#[\Override]
	public function scheduled_count( string $hook, array $args = array(), string $group = '' ): int {
		$this->calls[] = array(
			'verb' => 'scheduled_count',
			'args' => array(
				'hook'  => $hook,
				'args'  => $args,
				'group' => $group,
			),
		);

		return \count( \array_filter( $this->deliveries, static fn ( array $delivery ): bool => $hook === $delivery['hook'] && $args === $delivery['args'] && $group === $delivery['group'] ) );
	}

	/**
	 * Records one bulk pending-occurrence read and buckets exact schedule identities.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string       $hook       Hook to query.
	 * @param   list<string> $identities Schedule identities to query.
	 *
	 * @return  array<string, int<0, max>>
	 */
	#[\Override]
	public function scheduled_counts( string $hook, array $identities ): array {
		$this->calls[] = array(
			'verb' => 'scheduled_counts',
			'args' => array(
				'hook'       => $hook,
				'identities' => $identities,
			),
		);

		$counts = array();
		foreach ( $identities as $identity ) {
			$counts[ $identity ] = 0;
		}

		foreach ( $this->deliveries as $delivery ) {
			$args = $delivery['args'];
			if ( $hook !== $delivery['hook'] || 1 !== \count( $args ) || ! isset( $args[0] ) || ! \is_string( $args[0] ) || array( $args[0] ) !== $args ) {
				continue;
			}

			$identity = $args[0];
			if ( ! \array_key_exists( $identity, $counts ) || $identity !== $delivery['group'] ) {
				continue;
			}

			++$counts[ $identity ];
		}

		return $counts;
	}

	/**
	 * Records a scheduled-state query and returns its scripted value.
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   string $hook  Hook to query.
	 * @param   array  $args  Hook arguments.
	 * @param   string $group Group name.
	 *
	 * @return  bool
	 */
	#[\Override]
	public function is_scheduled( string $hook, array $args = array(), string $group = '' ): bool {
		$this->calls[] = array(
			'verb' => 'is_scheduled',
			'args' => array(
				'hook'  => $hook,
				'args'  => $args,
				'group' => $group,
			),
		);

		return $this->scheduled;
	}

	/**
	 * Records a next-run query and returns its scripted value.
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   string $hook  Hook to query.
	 * @param   array  $args  Hook arguments.
	 * @param   string $group Group name.
	 *
	 * @return  int|null
	 */
	#[\Override]
	public function get_next_scheduled( string $hook, array $args = array(), string $group = '' ): ?int {
		$this->calls[] = array(
			'verb' => 'get_next_scheduled',
			'args' => array(
				'hook'  => $hook,
				'args'  => $args,
				'group' => $group,
			),
		);

		return $this->next_scheduled;
	}

	/** {@inheritDoc} */
	#[\Override]
	public function is_ready(): bool {
		$this->calls[] = array(
			'verb' => 'is_ready',
			'args' => array(),
		);

		$readiness = \array_shift( $this->readiness_results );

		return null === $readiness ? $this->ready : $readiness;
	}

	/** {@inheritDoc} */
	#[\Override]
	public function is_absent(): bool {
		$this->calls[] = array(
			'verb' => 'is_absent',
			'args' => array(),
		);

		return $this->absent;
	}

	/** {@inheritDoc} */
	#[\Override]
	public function register_hooks(): void {
		$this->calls[] = array(
			'verb' => 'register_hooks',
			'args' => array(),
		);
	}

	/**
	 * Removes and returns the chronologically next accepted delivery.
	 *
	 * Recurring deliveries advance by one interval and remain pending.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  array{hook: string, args: list<mixed>, group: string, timestamp: int|null, interval: int|null, priority: int, sequence: int}|null
	 */
	public function take_next_delivery(): ?array {
		if ( array() === $this->deliveries ) {
			return null;
		}

		$index = 0;
		foreach ( $this->deliveries as $candidate_index => $candidate ) {
			$current      = $this->deliveries[ $index ];
			$candidate_at = $candidate['timestamp'] ?? \PHP_INT_MIN;
			$current_at   = $current['timestamp'] ?? \PHP_INT_MIN;
			if ( $candidate_at < $current_at || ( $candidate_at === $current_at && $candidate['sequence'] < $current['sequence'] ) ) {
				$index = $candidate_index;
			}
		}

		$delivery = $this->deliveries[ $index ];
		if ( null === $delivery['interval'] ) {
			\array_splice( $this->deliveries, $index, 1 );
		} else {
			$timestamp = $delivery['timestamp'];
			if ( null === $timestamp || $timestamp > \PHP_INT_MAX - $delivery['interval'] ) {
				\array_splice( $this->deliveries, $index, 1 );
			} else {
				$this->deliveries[ $index ]['timestamp'] = $timestamp + $delivery['interval'];
			}
		}

		return $delivery;
	}

	/**
	 * Asserts that one work or schedule identity retains an accepted delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete work or schedule identity.
	 *
	 * @return  void
	 */
	public function assert_scheduled( string $identity ): void {
		Assert::assertNotEmpty(
			\array_filter( $this->deliveries, static fn ( array $delivery ): bool => $identity === $delivery['group'] || \str_starts_with( $delivery['group'], $identity . '|' ) ),
			\sprintf( 'Expected an accepted backend delivery for identity "%s".', $identity )
		);
	}

	/**
	 * Asserts that one work or schedule identity retains no accepted delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $identity Complete work or schedule identity.
	 *
	 * @return  void
	 */
	public function assert_not_scheduled( string $identity ): void {
		Assert::assertSame(
			array(),
			\array_values( \array_filter( $this->deliveries, static fn ( array $delivery ): bool => $identity === $delivery['group'] || \str_starts_with( $delivery['group'], $identity . '|' ) ) ),
			\sprintf( 'Expected no accepted backend delivery for identity "%s".', $identity )
		);
	}

	/**
	 * Asserts that no exact hook, argument, and group delivery is pending twice.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function assert_no_duplicate(): void {
		foreach ( $this->deliveries as $index => $delivery ) {
			foreach ( \array_slice( $this->deliveries, $index + 1 ) as $candidate ) {
				Assert::assertFalse(
					$delivery['hook'] === $candidate['hook'] && $delivery['args'] === $candidate['args'] && $delivery['group'] === $candidate['group'],
					'The backend retained duplicate deliveries for one hook, argument list, and group.'
				);
			}
		}
	}

	/**
	 * Returns the scripted result for a write verb.
	 *
	 * @phpstan-param 'schedule_recurring'|'schedule_single'|'enqueue_async'|'unschedule'|'unschedule_group' $verb
	 *
	 * @param   string $verb Write verb.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function result_for( string $verb ): AbstractResult {
		if ( array() !== $this->readiness_results && ! $this->is_ready() ) {
			return new Failure( new SchedulingError( SchedulingErrorReason::BackendNotReady, 'Select another ready backend before retrying the scheduling write.' ) );
		}

		return $this->results[ $verb ] ?? new Success( true );
	}

	/**
	 * Retains one successful scheduling write for behavioral delivery.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @phpstan-param list<mixed> $args
	 *
	 * @param   string   $hook      Hook to deliver.
	 * @param   array    $args      Hook arguments.
	 * @param   string   $group     Scheduler group.
	 * @param   int|null $timestamp Scheduled Unix timestamp, or null for async work.
	 * @param   int|null $interval  Recurrence interval, or null for one-shot work.
	 * @param   int      $priority  Advisory priority.
	 *
	 * @return  void
	 */
	private function record_delivery( string $hook, array $args, string $group, ?int $timestamp, ?int $interval, int $priority ): void {
		$this->deliveries[] = array(
			'hook'      => $hook,
			'args'      => \array_values( $args ),
			'group'     => $group,
			'timestamp' => $timestamp,
			'interval'  => $interval,
			'priority'  => $priority,
			'sequence'  => $this->delivery_sequence++,
		);
	}

	/**
	 * Runs and consumes the next matching interleaving callback.
	 *
	 * @phpstan-param 'schedule_recurring'|'schedule_single'|'enqueue_async'|'unschedule'|'unschedule_group'|'unschedule_hooks' $verb
	 *
	 * @param   string $verb Write verb.
	 *
	 * @return  void
	 */
	private function run_before( string $verb ): void {
		$callback = isset( $this->before_writes[ $verb ] )
			? \array_shift( $this->before_writes[ $verb ] )
			: null;
		if ( null !== $callback ) {
			$callback( $this );
		}
	}
}
