<?php declare( strict_types=1 );

namespace A8C\SpecialProjects\BackgroundJobsEngine\Build\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Keeps engine machinery behind the public native-signature boundary.
 *
 * @since   1.0.0
 * @version 1.0.0
 *
 * @implements Rule<Stmt>
 */
final class PublicSignatureBoundaryRule implements Rule {
	// region FIELDS AND CONSTANTS.

	private const string ROOT_NAMESPACE = 'A8C\\SpecialProjects\\BackgroundJobsEngine\\';

	private const array MACHINERY_NAMESPACES = array(
		self::ROOT_NAMESPACE . 'Internal\\',
		self::ROOT_NAMESPACE . 'Engine\\',
		self::ROOT_NAMESPACE . 'CLI\\',
	);

	/**
	 * Exact exceptions keep the genus seam narrow as machinery grows.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @var     array<class-string, true>
	 */
	private array $permitted_types;

	// endregion.

	// region MAGIC METHODS.

	/**
	 * Constructor.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<class-string> $permitted_types Machinery contracts permitted in public signatures.
	 */
	public function __construct( array $permitted_types ) {
		$this->permitted_types = \array_fill_keys( $permitted_types, true );
	}

	// endregion.

	// region METHODS.

	/**
	 * Selects declaration nodes that can contribute native public signatures.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Node  $node  Parsed statement.
	 * @param   Scope $scope Statement scope.
	 *
	 * @return  list<IdentifierRuleError>
	 */
	#[\Override]
	public function processNode( Node $node, Scope $scope ): array {
		if ( self::is_test_file( $scope ) ) {
			return array();
		}

		if ( $node instanceof ClassLike ) {
			return $this->process_class_like( $node );
		}

		if ( $node instanceof Function_ ) {
			return $this->process_function( $node );
		}

		return array();
	}

	/**
	 * Supplies the common statement ancestor so one gate covers types and global functions.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @return  class-string<Stmt>
	 */
	#[\Override]
	public function getNodeType(): string {
		return Stmt::class;
	}

	// endregion.

	// region HELPERS.

	/**
	 * Checks one shipped public-surface type declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ClassLike $node Type declaration.
	 *
	 * @return  list<IdentifierRuleError>
	 */
	private function process_class_like( ClassLike $node ): array {
		$namespaced_name = self::namespaced_name( $node );
		if ( null === $namespaced_name ) {
			return array();
		}

		$class_name = $namespaced_name->toString();
		if ( ! $this->is_public_surface_type( $class_name ) ) {
			return array();
		}

		$errors = array();
		if ( $node instanceof Class_ ) {
			if ( null !== $node->extends ) {
				$this->append_type_errors( $errors, $node->extends, $class_name . ' parent' );
			}
			foreach ( $node->implements as $interface ) {
				$this->append_type_errors( $errors, $interface, $class_name . ' interface' );
			}
		} elseif ( $node instanceof Interface_ ) {
			foreach ( $node->extends as $interface ) {
				$this->append_type_errors( $errors, $interface, $class_name . ' interface' );
			}
		} elseif ( $node instanceof Enum_ ) {
			foreach ( $node->implements as $interface ) {
				$this->append_type_errors( $errors, $interface, $class_name . ' interface' );
			}
		}

		foreach ( $node->getMethods() as $method ) {
			$method_location = $class_name . '::' . $method->name->toString() . '()';
			if ( $method->isPublic() ) {
				foreach ( $method->params as $position => $parameter ) {
					$this->append_type_errors( $errors, $parameter->type, $method_location . ' parameter #' . ( $position + 1 ) );
				}
				$this->append_type_errors( $errors, $method->getReturnType(), $method_location . ' return' );
			} elseif ( '__construct' === $method->name->toLowerString() ) {
				foreach ( $method->params as $position => $parameter ) {
					if ( $parameter->isPromoted() && $parameter->isPublic() ) {
						$this->append_type_errors( $errors, $parameter->type, $method_location . ' promoted property #' . ( $position + 1 ) );
					}
				}
			}
		}

		foreach ( $node->getProperties() as $property ) {
			if ( $property->isPublic() ) {
				$this->append_type_errors( $errors, $property->type, $class_name . ' public property' );
			}
		}

		foreach ( $node->getConstants() as $constant ) {
			if ( $constant->isPublic() ) {
				$this->append_type_errors( $errors, $constant->type, $class_name . ' public constant' );
			}
		}

		return $errors;
	}

	/**
	 * Checks one procedural facade declaration.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Function_ $node Function declaration.
	 *
	 * @return  list<IdentifierRuleError>
	 */
	private function process_function( Function_ $node ): array {
		$namespaced_name = self::namespaced_name( $node );
		if ( null === $namespaced_name ) {
			return array();
		}

		$function_name = $namespaced_name->toString();
		if ( \str_contains( $function_name, '\\' ) || ! \str_starts_with( $function_name, 'a8csp_bgje' ) ) {
			return array();
		}

		$errors   = array();
		$location = $function_name . '()';
		foreach ( $node->params as $position => $parameter ) {
			$this->append_type_errors( $errors, $parameter->type, $location . ' parameter #' . ( $position + 1 ) );
		}
		$this->append_type_errors( $errors, $node->getReturnType(), $location . ' return' );

		return $errors;
	}

	/**
	 * Reads the declaration name assigned by PHP-Parser's name-resolution pass.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   ClassLike|Function_ $node Type or function declaration.
	 *
	 * @return  Name|null
	 */
	private static function namespaced_name( ClassLike|Function_ $node ): ?Name {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP-Parser owns the public AST field name.
		return $node->namespacedName ?? null;
	}

	/**
	 * Keeps test-support declarations outside the shipped-surface gate.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   Scope $scope Statement scope.
	 *
	 * @return  bool
	 */
	private static function is_test_file( Scope $scope ): bool {
		$file = '/' . \ltrim( \str_replace( '\\', '/', $scope->getFile() ), '/' );

		return \str_contains( $file, '/tests/' );
	}

	/**
	 * Adds diagnostics for every machinery member of one native declaration type.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   list<IdentifierRuleError> $errors   Accumulated boundary diagnostics.
	 * @param   Node|null                 $type     Native declaration type.
	 * @param   string                    $location Public signature location.
	 *
	 * @return  void
	 */
	private function append_type_errors( array &$errors, ?Node $type, string $location ): void {
		if ( $type instanceof Name ) {
			$type_name = $type->toString();
			if ( $this->is_machinery_type( $type_name ) ) {
				$errors[] = RuleErrorBuilder::message( $location . ' exposes machinery type ' . $type_name . '.' )
					->identifier( 'backgroundJobsEngine.publicSignatureBoundary' )
					->line( $type->getStartLine() )
					->build();
			}

			return;
		}

		if ( $type instanceof Node\NullableType ) {
			$this->append_type_errors( $errors, $type->type, $location );

			return;
		}

		if ( $type instanceof Node\UnionType || $type instanceof Node\IntersectionType ) {
			foreach ( $type->types as $member ) {
				$this->append_type_errors( $errors, $member, $location );
			}
		}
	}

	/**
	 * Distinguishes public-surface types from machinery declarations.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $type Fully qualified declaration name.
	 *
	 * @return  bool
	 */
	private function is_public_surface_type( string $type ): bool {
		if ( ! \str_starts_with( $type, self::ROOT_NAMESPACE ) ) {
			return false;
		}

		foreach ( self::MACHINERY_NAMESPACES as $namespace ) {
			if ( \str_starts_with( $type, $namespace ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Keeps configured exceptions exact instead of permitting an Internal namespace subtree.
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 *
	 * @param   string $type Fully qualified referenced type name.
	 *
	 * @return  bool
	 */
	private function is_machinery_type( string $type ): bool {
		if ( isset( $this->permitted_types[ $type ] ) ) {
			return false;
		}

		foreach ( self::MACHINERY_NAMESPACES as $namespace ) {
			if ( \str_starts_with( $type, $namespace ) ) {
				return true;
			}
		}

		return false;
	}

	// endregion.
}
