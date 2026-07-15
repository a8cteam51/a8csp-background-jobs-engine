<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundTasksEngine\Tests\Unit\Engine\Support;

use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Locks\OverlapGuard;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\CleanupIntents;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Occurrences\OccurrenceLease;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\RunIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\FailedRunStore;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\LatestRunPointer;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Runs\Stores\RunHistory;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Storage\OptionRows;
use A8C\SpecialProjects\BackgroundTasksEngine\Engine\Support\WorkIdentity;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\FixedClock;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\RecordingLogger;
use A8C\SpecialProjects\BackgroundTasksEngine\Tests\Support\WpdbLockSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the complete storage-safe background-work identity grammar.
 *
 */
#[CoversClass( WorkIdentity::class )]
final class WorkIdentityTest extends TestCase {
	/**
	 * Satisfies the production boot guard before the helper is autoloaded.
	 *
	 * @return  void
	 */
	#[\Override]
	public static function setUpBeforeClass(): void {
		if ( ! \defined( 'ABSPATH' ) ) {
			\define( 'ABSPATH', __DIR__ . '/' );
		}

		require_once \dirname( __DIR__, 2 ) . '/wp-lock-stubs.php';
	}

	/**
	 * Valid consumer owners compose with one validated local name.
	 *
	 * @param   string $owner Valid consumer owner.
	 *
	 * @return  void
	 */
	#[DataProvider( 'valid_owners' )]
	public function test_compose_accepts_the_complete_consumer_owner_grammar( string $owner ): void {
		self::assertSame( $owner . ':sync_job', WorkIdentity::compose( $owner, 'sync_job' ) );
	}

	/**
	 * Supplies the complete valid owner boundary table.
	 *
	 * @return  array<string, array{owner: string}>
	 */
	public static function valid_owners(): array {
		return array(
			'one byte'           => array( 'owner' => 'a' ),
			'digit first'        => array( 'owner' => '1-plugin' ),
			'hyphenated'         => array( 'owner' => 'consumer-plugin' ),
			'trailing hyphen'    => array( 'owner' => 'consumer-' ),
			'consecutive hyphen' => array( 'owner' => 'consumer--plugin' ),
			'thirty-two byte'    => array( 'owner' => \str_repeat( 'a', 32 ) ),
		);
	}

	/**
	 * Invalid and engine-reserved owners are deterministic contract violations.
	 *
	 * @param   string $owner Invalid consumer owner.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_consumer_owners' )]
	public function test_compose_rejects_invalid_or_engine_reserved_consumer_owners( string $owner ): void {
		$this->expectException( \InvalidArgumentException::class );

		WorkIdentity::compose( $owner, 'sync' );
	}

	/**
	 * Supplies rejected consumer owners, including the reserved prefix.
	 *
	 * @return  array<string, array{owner: string}>
	 */
	public static function invalid_consumer_owners(): array {
		return array(
			'empty'              => array( 'owner' => '' ),
			'uppercase'          => array( 'owner' => 'Consumer' ),
			'colon'              => array( 'owner' => 'consumer:plugin' ),
			'underscore'         => array( 'owner' => 'consumer_plugin' ),
			'leading hyphen'     => array( 'owner' => '-consumer' ),
			'thirty-three bytes' => array( 'owner' => \str_repeat( 'a', 33 ) ),
			'reserved owner'     => array( 'owner' => 'a8csp-bgte' ),
			'reserved prefix'    => array( 'owner' => 'a8csp-bgte-addon' ),
		);
	}

	/**
	 * The engine can explicitly compose its reserved maintenance identity.
	 *
	 * @return  void
	 */
	public function test_compose_accepts_the_reserved_owner_only_for_engine_work(): void {
		self::assertSame(
			'a8csp-bgte:maintenance',
			WorkIdentity::compose( 'a8csp-bgte', 'maintenance', true )
		);
	}

	/**
	 * Names accept exactly the pinned grammar and 64-byte storage boundary.
	 *
	 * @return  void
	 */
	public function test_compose_accepts_64_name_bytes_and_rejects_65(): void {
		$name = \str_repeat( 'n', 64 );
		self::assertSame( 'consumer:' . $name, WorkIdentity::compose( 'consumer', $name ) );

		$this->expectException( \InvalidArgumentException::class );
		WorkIdentity::compose( 'consumer', $name . 'n' );
	}

	/**
	 * Invalid local names cannot escape into storage or hook identities.
	 *
	 * @param   string $name Invalid local name.
	 *
	 * @return  void
	 */
	#[DataProvider( 'invalid_names' )]
	public function test_compose_rejects_names_outside_the_shared_grammar( string $name ): void {
		$this->expectException( \InvalidArgumentException::class );

		WorkIdentity::compose( 'consumer', $name );
	}

	/**
	 * Supplies names outside the shared grammar.
	 *
	 * @return  array<string, array{name: string}>
	 */
	public static function invalid_names(): array {
		return array(
			'empty'     => array( 'name' => '' ),
			'uppercase' => array( 'name' => 'Sync' ),
			'colon'     => array( 'name' => 'sync:now' ),
			'space'     => array( 'name' => 'sync now' ),
			'non-ASCII' => array( 'name' => 'synchronisé' ),
		);
	}

	/**
	 * Complete identities split only when both components satisfy the shared rules.
	 *
	 * @return  void
	 */
	public function test_parts_accepts_valid_consumer_and_engine_identities_only(): void {
		self::assertSame( array( 'consumer-plugin', 'sync_job' ), WorkIdentity::parts( 'consumer-plugin:sync_job' ) );
		self::assertSame( array( 'a8csp-bgte', 'maintenance' ), WorkIdentity::parts( 'a8csp-bgte:maintenance' ) );
		self::assertNull( WorkIdentity::parts( 'consumer-plugin' ) );
		self::assertNull( WorkIdentity::parts( 'consumer-plugin:sync:extra' ) );
		self::assertNull( WorkIdentity::parts( 'Consumer:sync' ) );
		self::assertNull( WorkIdentity::parts( 'consumer:' . \str_repeat( 'n', 65 ) ) );
	}

	/**
	 * The longest legal identity keeps every direct per-work option key below Core's boundary.
	 *
	 * @return  void
	 */
	public function test_longest_identity_keeps_every_derived_store_key_within_191_characters(): void {
		$identity = WorkIdentity::compose( \str_repeat( 'o', 32 ), \str_repeat( 'n', 64 ) );
		$run_id   = \str_repeat( '9', 20 ) . '-' . \str_repeat( '9', 19 );
		$hash     = \str_repeat( 'f', 64 );
		$rows     = new OptionRows( new WpdbLockSpy() );
		$clock    = new FixedClock( 0 );
		$keys     = array(
			'lock'           => self::private_string( new OverlapGuard( $clock, new RecordingLogger(), $rows ), 'option_name', $identity, $hash ),
			'run'            => RunIdentity::option_name( $identity, $run_id ),
			'failed'         => self::private_string( new FailedRunStore( $identity, $rows ), 'option_name' ),
			'history'        => self::private_string( new RunHistory( $identity, $rows ), 'option_name' ),
			'latest'         => self::private_string( new LatestRunPointer( $identity, $rows ), 'option_name' ),
			'lease'          => self::private_string( OccurrenceLease::class, 'option_name', $identity ),
			'cleanup intent' => self::private_string( CleanupIntents::class, 'intent_option_name', $identity ),
		);

		self::assertSame( 97, \strlen( $identity ) );
		self::assertSame(
			array(
				'lock'           => 178,
				'run'            => 153,
				'failed'         => 115,
				'history'        => 116,
				'latest'         => 115,
				'lease'          => 81,
				'cleanup intent' => 83,
			),
			\array_map( 'strlen', $keys )
		);
		foreach ( $keys as $store => $key ) {
			self::assertLessThanOrEqual( 191, \strlen( $key ), $store . ' option key exceeds option_name' );
		}
	}

	/**
	 * Invokes one production key builder hidden behind its owning store.
	 *
	 * @param   object|class-string $target    Store instance or class name.
	 * @param   string              $method    Private key-builder method.
	 * @param   mixed               ...$args   Key-builder arguments.
	 *
	 * @return  string
	 */
	private static function private_string( object|string $target, string $method, mixed ...$args ): string {
		$reflection = new \ReflectionMethod( $target, $method );
		$value      = $reflection->invokeArgs( \is_object( $target ) ? $target : null, $args );

		self::assertIsString( $value );

		return $value;
	}
}
