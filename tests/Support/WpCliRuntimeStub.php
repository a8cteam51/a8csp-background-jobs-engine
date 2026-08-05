<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Tests\Support;

use A8C\SpecialProjects\BackgroundJobsEngine\CLI\Component;
use WP_CLI\Dispatcher\CompositeCommand;
use WP_CLI\Dispatcher\Subcommand;
use WP_CLI\ExitException;

\defined( 'WP_CLI_ROOT' ) || \define( 'WP_CLI_ROOT', \dirname( __DIR__, 2 ) . '/vendor/wp-cli/wp-cli' );
\defined( 'WP_CLI' ) || \define( 'WP_CLI', true );
require_once \dirname( __DIR__, 2 ) . '/vendor/wp-cli/wp-cli/php/utils.php';
require_once \dirname( __DIR__, 2 ) . '/vendor/wp-cli/wp-cli/php/dispatcher.php';

/**
 * Carries one command's process-style exit status and rendered streams.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final readonly class CliResult {
	// region MAGIC METHODS.

	/**
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   int    $exit_code Process exit code.
	 * @param   string $stdout    Rendered standard output.
	 * @param   string $stderr    Rendered standard error.
	 * @param   string $probe     Isolated child-process evidence.
	 */
	public function __construct(
		public int $exit_code,
		public string $stdout,
		public string $stderr,
		public string $probe = '',
	) {}

	// endregion.
}

/**
 * Records output that the real WP-CLI runtime routes through its logger.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class CliLogger {
	// region FIELDS AND CONSTANTS.

	public string $stderr = '';

	public string $stdout = '';

	// endregion.

	// region METHODS.

	/**
	 * Records unadorned output.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $message Rendered message.
	 *
	 * @return  void
	 */
	public function info( string $message ): void {
		$this->stdout .= $message . "\n";
	}

	/**
	 * Records successful output.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $message Rendered message.
	 *
	 * @return  void
	 */
	public function success( string $message ): void {
		$this->stdout .= 'Success: ' . $message . "\n";
	}

	/**
	 * Records warning output.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $message Rendered message.
	 *
	 * @return  void
	 */
	public function warning( string $message ): void {
		$this->stderr .= 'Warning: ' . $message . "\n";
	}

	/**
	 * Records error output.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $message Rendered message.
	 *
	 * @return  void
	 */
	public function error( string $message ): void {
		$this->stderr .= 'Error: ' . $message . "\n";
	}

	/**
	 * Accepts debug output without adding it to either process stream.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string      $message Rendered message.
	 * @param   string|bool $group   Debug group, when supplied.
	 *
	 * @return  void
	 */
	public function debug( string $message, string|bool $group = false ): void {}

	// endregion.
}

/**
 * Executes callbacks resolved from the production WP-CLI command tree.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
final class CliHarness {
	// region LIFECYCLE.

	/**
	 * Registers the production command surface once in WP-CLI's real dispatcher.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  void
	 */
	public static function set_up(): void {
		if ( null === self::root_command() ) {
			$component = new Component();
			$component->initialize();
			$component->register_hooks();
		}
	}

	// endregion.

	// region GETTERS.

	/**
	 * Returns every method registered beneath the canonical command root.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  list<string>
	 */
	public static function registered_subcommands(): array {
		$root = self::root_command();
		if ( null === $root ) {
			return array();
		}

		return \array_keys( $root->get_subcommands() );
	}

	/**
	 * Returns one registered subcommand's parsed long description.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $subcommand Spoken subcommand below a8csp-bgje.
	 *
	 * @return  string
	 */
	public static function registered_subcommand_description( string $subcommand ): string {
		return self::subcommand( $subcommand )->get_longdesc();
	}

	// endregion.

	// region METHODS.

	/**
	 * Runs one registered subcommand callback and returns its process contract.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string               $subcommand Spoken subcommand below a8csp-bgje.
	 * @param   array                $args       Positional arguments delivered to the command.
	 * @param   array<string, mixed> $assoc_args Named arguments delivered to the command.
	 *
	 * @return  CliResult
	 *
	 * @phpstan-param list<string> $args
	 */
	public static function run( string $subcommand, array $args = array(), array $assoc_args = array() ): CliResult {
		$command  = self::subcommand( $subcommand );
		$property = new \ReflectionProperty( Subcommand::class, 'when_invoked' );
		$callback = $property->getValue( $command );
		if ( ! \is_callable( $callback ) ) {
			throw new \LogicException( \sprintf( 'Registered command "%s" has no callable dispatcher.', $subcommand ) );
		}

		$logger = new CliLogger();
		\WP_CLI::set_logger( $logger );
		$capture_exit = new \ReflectionProperty( \WP_CLI::class, 'capture_exit' );
		$capture_exit->setValue( null, true );
		$exit_code = 0;
		\ob_start();
		try {
			$callback( $args, $assoc_args );
		} catch ( ExitException $exit ) {
			$exit_code = $exit->getCode();
		} finally {
			$direct_stdout = \ob_get_clean();
			$capture_exit->setValue( null, false );
		}
		if ( ! \is_string( $direct_stdout ) ) {
			throw new \LogicException( 'The WP-CLI output buffer could not be collected.' );
		}

		return new CliResult( $exit_code, $direct_stdout . $logger->stdout, $logger->stderr );
	}

	/**
	 * Runs a CSV scenario in a child process whose native streams are capturable.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scenario Worker fixture scenario.
	 *
	 * @return  CliResult
	 */
	public static function run_csv( string $scenario ): CliResult {
		return self::run_worker( $scenario, '' );
	}

	/**
	 * Runs an interactive worker scenario with explicit standard input.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scenario Worker fixture scenario.
	 * @param   string $stdin    Complete standard input delivered to the child.
	 *
	 * @return  CliResult
	 */
	public static function run_interactive( string $scenario, string $stdin ): CliResult {
		return self::run_worker( $scenario, $stdin );
	}

	// endregion.

	// region HELPERS.

	/**
	 * Runs one command worker with isolated process streams.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $scenario Worker fixture scenario.
	 * @param   string $stdin    Complete standard input delivered to the child.
	 *
	 * @return  CliResult
	 */
	private static function run_worker( string $scenario, string $stdin ): CliResult {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
			3 => array( 'pipe', 'w' ),
		);
		$pipes       = array();
		$process     = \proc_open( array( PHP_BINARY, __DIR__ . '/cli-runtime-worker.php', $scenario ), $descriptors, $pipes, \dirname( __DIR__, 2 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Native process streams make WP-CLI's direct writes observable.
		if ( ! \is_resource( $process ) || ! isset( $pipes[0], $pipes[1], $pipes[2], $pipes[3] ) || ! \is_resource( $pipes[0] ) || ! \is_resource( $pipes[1] ) || ! \is_resource( $pipes[2] ) || ! \is_resource( $pipes[3] ) ) {
			throw new \RuntimeException( 'The WP-CLI worker could not be started.' );
		}

		\fwrite( $pipes[0], $stdin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The child process requires native standard input.
		\fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open pipes are native process resources, not WordPress files.
		$stdout = \stream_get_contents( $pipes[1] );
		$stderr = \stream_get_contents( $pipes[2] );
		$probe  = \stream_get_contents( $pipes[3] );
		\fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open pipes are native process resources, not WordPress files.
		\fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open pipes are native process resources, not WordPress files.
		\fclose( $pipes[3] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open pipes are native process resources, not WordPress files.
		$exit_code = \proc_close( $process );
		if ( false === $stdout || false === $stderr || false === $probe ) {
			throw new \RuntimeException( 'The WP-CLI worker streams could not be read.' );
		}

		return new CliResult( $exit_code, $stdout, $stderr, $probe );
	}

	/**
	 * Returns the registered canonical command root, when present.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  CompositeCommand|null
	 */
	private static function root_command(): ?CompositeCommand {
		$root = \WP_CLI::get_root_command();
		if ( ! $root instanceof CompositeCommand ) {
			throw new \LogicException( 'WP-CLI did not publish a composite root command.' );
		}

		$args    = array( 'a8csp-bgje' );
		$command = $root->find_subcommand( $args );

		return $command instanceof CompositeCommand ? $command : null;
	}

	/**
	 * Returns one registered leaf command.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $name Registered leaf name.
	 *
	 * @return  Subcommand
	 */
	private static function subcommand( string $name ): Subcommand {
		$root = self::root_command();
		if ( null === $root ) {
			throw new \LogicException( 'The a8csp-bgje command root is not registered.' );
		}

		$args    = array( $name );
		$command = $root->find_subcommand( $args );
		if ( ! $command instanceof Subcommand ) {
			throw new \LogicException( \sprintf( 'The a8csp-bgje command "%s" is not registered.', $name ) );
		}

		return $command;
	}

	// endregion.
}
