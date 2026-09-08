<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Unit\Runtime;

use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\ScheduledActionLabels;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Runs\ActionDeliveries;
use A8C\SpecialProjects\BackgroundJobsEngine\Runtime\Schedules\OccurrenceDelivery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises engine argument labelling through the registered Action Scheduler list-table filter.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
#[CoversClass( ScheduledActionLabels::class )]
final class ScheduledActionLabelsTest extends TestCase {
	// region FIELDS AND CONSTANTS.

	private const string FILTER   = 'action_scheduler_list_table_column_args';
	private const string IDENTITY = 'admin-tests:email-digest';
	private const string RUN_ID   = '00000000001700000000-0000000000000000042';

	/** Default rendering a foreign or unlabelled row keeps. */
	private const string DEFAULT_HTML = '<ul><li><code>0 => &#039;something&#039;</code></li></ul>';

	// endregion.

	// region LIFECYCLE.

	/**
	 * Loads the guarded WordPress seams this filter renders through.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__ ) . '/wp-hook-stubs.php';
		require_once __DIR__ . '/wp-esc-html-stub.php';
	}

	/**
	 * Empties the request-local hook ledgers the filter stub reads.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['a8csp_bgje_test_hooks']                = array();
		$GLOBALS['a8csp_bgje_test_filter_registrations'] = array();
		$GLOBALS['a8csp_bgje_test_filter_values']        = array();
	}

	/**
	 * Releases the request-local hook ledgers.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	#[\Override]
	protected function tearDown(): void {
		try {
			unset(
				$GLOBALS['a8csp_bgje_test_hooks'],
				$GLOBALS['a8csp_bgje_test_filter_registrations'],
				$GLOBALS['a8csp_bgje_test_filter_values'],
			);
		} finally {
			parent::tearDown();
		}
	}

	// endregion.

	// region TESTS.

	/**
	 * A delivery row names its identity, run, and sequence instead of numbering them.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_delivery_arguments_render_under_their_engine_names(): void {
		ScheduledActionLabels::register_hooks();

		$rendered = $this->render( ActionDeliveries::DELIVER_HOOK, array( self::IDENTITY, self::RUN_ID, 3 ) );

		self::assertSame(
			'<ul>'
			. '<li><code>&#039;identity&#039; => &#039;' . self::IDENTITY . '&#039;</code></li>'
			. '<li><code>&#039;run_id&#039; => &#039;' . self::RUN_ID . '&#039;</code></li>'
			. '<li><code>&#039;action_sequence&#039; => 3</code></li>'
			. '</ul>',
			$rendered
		);
	}

	/**
	 * A schedule tick names its single argument.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_schedule_tick_argument_renders_under_its_engine_name(): void {
		ScheduledActionLabels::register_hooks();

		$rendered = $this->render( OccurrenceDelivery::SCHEDULE_HOOK, array( self::IDENTITY ) );

		self::assertSame(
			'<ul><li><code>&#039;schedule_identity&#039; => &#039;' . self::IDENTITY . '&#039;</code></li></ul>',
			$rendered
		);
	}

	/**
	 * A row belonging to another plugin keeps the list table's own rendering.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_a_foreign_hook_keeps_the_default_rendering(): void {
		ScheduledActionLabels::register_hooks();

		self::assertSame( self::DEFAULT_HTML, $this->render( 'woocommerce/other_plugin_action', array( 'something' ) ) );
	}

	/**
	 * An engine hook carrying an unexpected argument count is never relabelled.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_an_unexpected_argument_count_keeps_the_default_rendering(): void {
		ScheduledActionLabels::register_hooks();

		self::assertSame( self::DEFAULT_HTML, $this->render( ActionDeliveries::DELIVER_HOOK, array( self::IDENTITY, self::RUN_ID ) ) );
	}

	/**
	 * An engine hook whose arguments already carry keys is never relabelled.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_already_keyed_arguments_keep_the_default_rendering(): void {
		ScheduledActionLabels::register_hooks();

		$args = array(
			'identity' => self::IDENTITY,
			'run_id'   => self::RUN_ID,
			'sequence' => 3,
		);

		self::assertSame( self::DEFAULT_HTML, $this->render( ActionDeliveries::DELIVER_HOOK, $args ) );
	}

	/**
	 * A row with no arguments keeps the list table's own empty rendering.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public function test_an_argumentless_row_keeps_the_default_rendering(): void {
		ScheduledActionLabels::register_hooks();

		self::assertSame( '', $this->render( ActionDeliveries::DELIVER_HOOK, array(), '' ) );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Applies the registered filter over one list-table row.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string                  $hook         Hook the row was scheduled on.
	 * @param   array<array-key, mixed> $args         Arguments the row carries.
	 * @param   string|null             $default_html Rendering the list table produced, when it differs from the fixture.
	 *
	 * @return  mixed
	 */
	private function render( string $hook, array $args, ?string $default_html = null ): mixed {
		return \apply_filters(
			self::FILTER,
			$default_html ?? self::DEFAULT_HTML,
			array(
				'hook'  => $hook,
				'args'  => $args,
				'group' => self::IDENTITY,
			)
		);
	}

	// endregion.
}
