<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\PhpDoc;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Node as PhpDocAstNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\PhpDoc\{GenericTagValueNode, PhpDocTagNode, PhpDocTextNode};
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PhpSyntax\{NameForm, SymbolKind, Trivia};
use PhpSyntax\Nodes\{FileNode, NameNode};
use PhpSyntax\Nodes\Statement\NamespaceNode;
use function count, is_array, is_string;


/**
 * What the rules of names share: the references of the names of a namespace with the form each is written in, the
 * class names its doc comments write and the imports.
 * @internal
 */
final class NameReferences
{
	/** a qualified name without its leading backslash */
	public const NamePattern = '[A-Za-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)*';
	private const ClassPattern = '\\\\?' . self::NamePattern;


	/**
	 * The class names the doc comments of the scope write the way an import reaches them: in a type, in a class
	 * constant, as the name of an annotation and as the target of a tag or of an inline tag.
	 * @return list<string>
	 */
	public static function collectDocClassNames(FileNode|NamespaceNode $scope, PhpDoc $phpDoc): array
	{
		$names = [];
		foreach ([...$scope->getLeadingComments(), ...$scope->getInnerComments(), ...$scope->getTrailingComments()] as $trivia) {
			if ($trivia->is(Trivia::DocComment)) {
				foreach (self::collectIdentifiers($phpDoc->parse($trivia)) as $identifier) {
					if ($identifier !== '' && $identifier[0] !== '\\' && $identifier[0] !== '$') {
						$names[] = $identifier;
					}
				}
			}
		}

		return $names;
	}


	/** @return list<string> */
	private static function collectIdentifiers(PhpDocAstNode $node): array
	{
		$result = [];
		if ($node instanceof IdentifierTypeNode) {
			$result[] = $node->name;
		} elseif ($node instanceof ConstFetchNode && $node->className !== '') {
			// `Shape::TYPE_*` as a type or a constant as the argument of an annotation
			$result[] = $node->className;
		} elseif ($node instanceof GenericTagValueNode) {
			// a tag the parser knows no type for, such as `@see Foo::bar()`; the target is the first word
			$result = self::findReferences($node->value, target: true);
		} elseif ($node instanceof PhpDocTextNode) {
			$result = self::findReferences($node->text, target: false);
		} elseif (
			($node instanceof PhpDocTagNode || $node instanceof DoctrineAnnotation)
			&& preg_match('~^@([A-Z]|.*\\\\)~', $node->name)
		) {
			// an annotation such as `@DB\Entity`, `@Endpoint` or `@Index` nested in another, unlike `@param` or `@phpstan-return`
			$result[] = substr($node->name, 1);
		}

		foreach (get_object_vars($node) as $property => $value) {
			if ($property === 'description' && is_string($value)) {
				// the description of a tag the parser knows, such as `@param int $x {@see Foo}`
				$result = [...$result, ...self::findReferences($value, target: false)];
			}

			foreach (is_array($value) ? $value : [$value] as $child) {
				if ($child instanceof PhpDocAstNode) {
					$result = [...$result, ...self::collectIdentifiers($child)];
				}
			}
		}

		return $result;
	}


	/**
	 * The names a piece of a doc comment the parser left as text refers to: the target of every inline tag
	 * (`{@link Foo}`) and, when the piece is the value of a tag, the word it starts with. A member behind
	 * `::` and the parentheses of a method are cut off, so that `Foo::bar()` counts as a use of `Foo`.
	 * @return list<string>
	 */
	private static function findReferences(string $text, bool $target): array
	{
		$name = self::ClassPattern;
		$result = [];
		if ($target && preg_match("~^$name~", $text, $m)) {
			$result[] = $m[0];
		}

		preg_match_all("~\\{@\\w+\\s+($name)~", $text, $matches);
		return [...$result, ...$matches[1]];
	}


	/**
	 * The aliases the code of the scope uses: the first part of every name it writes unqualified or qualified.
	 * @return array<string, array<string, true>>  the name of the kind => the key of the alias => used
	 */
	public static function collectAliasUsages(FileNode|NamespaceNode $scope): array
	{
		$used = [SymbolKind::ClassLike->name => [], SymbolKind::Function->name => [], SymbolKind::Constant->name => []];
		foreach ($scope->find(NameNode::class) as $name) {
			if (
				$name->isDeclaration()
				|| $name->form === NameForm::FullyQualified
				|| $name->form === NameForm::Relative
				|| ($scope instanceof FileNode && $name->findAncestor(NamespaceNode::class))
			) {
				continue;
			}

			$parts = $name->parts;
			$kind = count($parts) > 1 ? SymbolKind::ClassLike : $name->symbolKind;
			$used[$kind->name][self::toKey($kind, $parts[0])] = true;
		}

		return $used;
	}


	/**
	 * The aliases of classes the doc comments of the scope use.
	 * @return array<string, true>  lowercased alias => used
	 */
	public static function collectDocAliasUsages(FileNode|NamespaceNode $scope, PhpDoc $phpDoc): array
	{
		$used = [];
		foreach (self::collectDocClassNames($scope, $phpDoc) as $name) {
			$used[strtolower(explode('\\', $name)[0])] = true;
		}

		return $used;
	}


	/** The key a name is looked up by: a constant is case-sensitive, a class and a function are not. */
	public static function toKey(SymbolKind $kind, string $name): string
	{
		return $kind === SymbolKind::Constant ? $name : strtolower($name);
	}
}
