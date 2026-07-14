<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\AbstractResult;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Failure;
use A8C\SpecialProjects\BackgroundTasksEngine\Utilities\Result\Success;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\BackendInterface;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\Errors\SchedulingError;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Scheduling\SchedulingErrorReason;

/**
 * Call-routing spy with scriptable outcomes.
 *
 * It records facade calls without interpreting them. Result-only assertions against unscripted
 * defaults prove nothing about a real backend.
 */
final class RecordingBackend implements BackendInterface {
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
	 *     unschedule?: AbstractResult<true, SchedulingError>
	 * }
	 */
	public array $results = array();

	/** Whether queries report a matching scheduled hook. */
	public bool $scheduled = false;

	/** Scripted next-run timestamp. */
	public ?int $next_scheduled = null;

	/** Whether the backend reports itself ready. */
	public bool $ready = true;

	/** Whether the backend adapter exposes calendar cron expressions. */
	public bool $cron_supported = false;

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
	 * @param   bool     $unique              Whether the request is unique.
	 * @param   int      $priority            Advisory priority.
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function schedule_recurring( string $hook, int $interval, array $args = array(), ?int $first_run_timestamp = null, string $group = '', bool $unique = false, int $priority = 10 ): AbstractResult {
		$this->calls[] = array(
			'verb' => 'schedule_recurring',
			'args' => array(
				'hook'                => $hook,
				'interval'            => $interval,
				'args'                => $args,
				'first_run_timestamp' => $first_run_timestamp,
				'group'               => $group,
				'unique'              => $unique,
				'priority'            => $priority,
			),
		);

		return $this->result_for( 'schedule_recurring' );
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

		return $this->result_for( 'schedule_single' );
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
	 * @param   bool   $unique   Whether the request is unique.
	 * @param   int    $priority Advisory priority.
	 *
	 * @return  AbstractResult
	 */
	#[\Override]
	#[\NoDiscard( 'a scheduling failure must be handled, not dropped' )]
	public function enqueue_async( string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): AbstractResult {
		$this->calls[] = array(
			'verb' => 'enqueue_async',
			'args' => array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'unique'   => $unique,
				'priority' => $priority,
			),
		);

		return $this->result_for( 'enqueue_async' );
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

		return $this->result_for( 'unschedule' );
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
	public function supports_cron_expressions(): bool {
		$this->calls[] = array(
			'verb' => 'supports_cron_expressions',
			'args' => array(),
		);

		return $this->cron_supported;
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
	 * Returns the scripted result for a write verb.
	 *
	 * @param   'schedule_recurring'|'schedule_single'|'enqueue_async'|'unschedule' $verb Write verb.
	 *
	 * @return  AbstractResult<true, SchedulingError>
	 */
	private function result_for( string $verb ): AbstractResult {
		if ( array() !== $this->readiness_results && ! $this->is_ready() ) {
			return new Failure(
				new SchedulingError(
					SchedulingErrorReason::BackendNotReady,
					'Select another ready backend before retrying the scheduling write.'
				)
			);
		}

		return $this->results[ $verb ] ?? new Success( true );
	}
}
