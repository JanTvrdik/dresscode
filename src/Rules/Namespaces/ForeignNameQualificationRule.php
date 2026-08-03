<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\PhpDoc;
use DressCode\{CodeWriter, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\NodeHelpers;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, Node, SymbolKind, Token, Trivia};
use PhpSyntax\Nodes\{FileNode, NameNode};
use PhpSyntax\Nodes\Statement\NamespaceNode;
use function count;


/**
 * How far a name of a namespace other than the global one is written out: of another namespace `imported` or
 * `backslashed`, of the namespace of the file `bare` or `backslashed`. A key takes a word, or a list of them, every form
 * in it passing and the first written where none matches. The names are written occurrence by occurrence; a qualified
 * name stays, being relative to the import of its prefix or to the namespace.
 * A file that declares no namespace is the scope of its imports, as a namespace is. Both forms reach the same symbol,
 * so no fix is risky. An import the names written out leave unused goes, unless a doc comment names it. An import is
 * added only where its alias is free and takes over no name that resolves elsewhere; one the markup of the file leaves
 * no line for is reported to be written by hand. The namespace `PHPSTORM_META` of a metadata file of PhpStorm writes
 * its names fully qualified by convention and is left alone.
 * A class a doc comment names is written as `qualification.class.phpdoc` says, the shortest way the file reaches it, or
 * with the backslash where an import reaches it; a bare name no import reaches stays, being perhaps a type of the doc
 * comment itself, `list` or `scalar`.
 */
#[RuleInfo(Stage::Structure, analyses: [PhpDoc::class, NameResolver::class], modifiesComments: true)]
final class ForeignNameQualificationRule extends NodeRule
{
	private const Phpdoc = 'qualification.class.phpdoc';

	/** @var array<string, array{list<string>, string}>  name of the kind => the forms and the decision, a kind under `keep` left out */
	private array $decided = [];

	/** @var array<string, array{list<string>, string}>  the same of a name of the namespace of the file */
	private array $current = [];

	/** @var ?list<string>  the forms of a class a doc comment names, null where it is kept */
	private ?array $phpdoc = null;


	public static function getDecisions(): array
	{
		return [
			QualificationPolicy::createQualifiedDecision(
				'qualification.class.otherNamespace',
				'A class, interface, trait or enum of a namespace other than that of the file',
				'`use Acme\Shop\Order;` and `Order`',
				'`\Acme\Shop\Order`',
				'A name relative to an import or to the namespace, `Shop\Order`, stays as it is.',
			),
			QualificationPolicy::createQualifiedDecision(
				'qualification.function.otherNamespace',
				'A function of a namespace other than that of the file',
				'`use function Acme\Text\normalize;` and `normalize()`',
				'`\Acme\Text\normalize()`',
			),
			QualificationPolicy::createQualifiedDecision(
				'qualification.constant.otherNamespace',
				'A constant of a namespace other than that of the file',
				'`use const Acme\Shop\STATUS_PAID;` and `STATUS_PAID`',
				'`\Acme\Shop\STATUS_PAID`',
			),
			QualificationPolicy::createQualifiedDecision(
				self::Phpdoc,
				'A class, interface, trait or enum a doc comment names in a type, a class constant or the target of a tag',
				'`use Acme\Shop\Order;` and `@param Order $order`, a class of the namespace of the file bare, a global one where the file imports it',
				'`@param \Acme\Shop\Order $order`, a name an import reaches',
				'A name relative to an import or to the namespace, `Shop\Order`, stays as it is, and so does a bare one no import reaches, which a doc comment cannot tell from a type of its own such as `list`.',
			),
			QualificationPolicy::createCurrentNamespaceDecision(
				'qualification.class.currentNamespace',
				'A class, interface, trait or enum of the namespace of the file, `Order` in `namespace Acme\Shop`',
				'`Order`',
				'`\Acme\Shop\Order`',
			),
			QualificationPolicy::createCurrentNamespaceDecision(
				'qualification.function.currentNamespace',
				'A function of the namespace of the file, `normalize()` in `namespace Acme\Text`',
				'`normalize()`',
				'`\Acme\Text\normalize()`',
			),
			QualificationPolicy::createCurrentNamespaceDecision(
				'qualification.constant.currentNamespace',
				'A constant of the namespace of the file, `STATUS_PAID` in `namespace Acme\Shop`',
				'`STATUS_PAID`',
				'`\Acme\Shop\STATUS_PAID`',
			),
		];
	}


	public function configure(Values $values): void
	{
		foreach ([
			SymbolKind::ClassLike->name => 'class',
			SymbolKind::Function->name => 'function',
			SymbolKind::Constant->name => 'constant',
		] as $kind => $key) {
			foreach (['decided' => 'otherNamespace', 'current' => 'currentNamespace'] as $property => $place) {
				$decision = "qualification.$key.$place";
				$forms = $values->find($decision)?->getWords();
				if ($forms !== null) {
					$this->$property[$kind] = [$forms, $decision];
				}
			}
		}

		$this->phpdoc = $values->find(self::Phpdoc)?->getWords();
	}


	public function getVisitedNodes(): array
	{
		return [FileNode::class, NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		// a file that declares no namespace is a scope of imports of its own, as for the rules adding code
		if (
			($node instanceof NamespaceNode && $node->name !== null && !NodeHelpers::isInPhpStormMeta($node))
			|| ($node instanceof FileNode && CodeWriter::findImportScope($node) === $node)
		) {
			$this->process($node, $context);
		}
	}


	private function process(FileNode|NamespaceNode $scope, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$namespace = $scope instanceof NamespaceNode ? $resolver->getNamespace($scope) : '';
		$canImport = null;
		$targets = null;
		$imported = []; // name of the kind => key of the alias => the full name imported under it
		$unimported = []; // decision => name of the kind => key of the alias the names written out leave
		foreach ($scope->find(NameNode::class) as $name) {
			if (!$name->isReference() || $name->form === NameForm::Qualified || $name->form === NameForm::Relative) {
				continue;
			}

			$kind = $name->symbolKind;
			$full = $resolver->resolve($name);
			$separator = strrpos($full, '\\');
			if ($separator !== false && $namespace !== '' && strcasecmp(substr($full, 0, $separator), $namespace) === 0) {
				$this->writeCurrent($resolver, $name, $full, $context);
				continue;
			}

			$form = self::findForm($resolver, $name, $full);
			$decided = $form === null ? null : $this->decided[$kind->name] ?? null;
			if ($decided === null || in_array($form, $decided[0], true)) {
				continue;
			}

			[[$target], $decision] = $decided;
			if ($target === QualificationPolicy::Backslashed) {
				$written = '\\' . $full;
				if ($context->report(
					$name,
					"The imported name `{$name->text}` must be written fully qualified as `$written`.",
					decision: $decision,
				)) {
					$unimported[$decision][$kind->name][NameReferences::toKey($kind, $name->text)] = true;
					$name->text = $written;
				}

				continue;
			}

			// an import the file has
			$alias = $name->shortName;
			$key = NameReferences::toKey($kind, $alias);
			$short = $resolver->shortenName($full, $kind, $name);
			$earlier = $imported[$kind->name][$key] ?? null;
			if (!str_contains($short, '\\') || ($earlier !== null && strcasecmp($earlier, $full) === 0)) {
				$shortened = str_contains($short, '\\') ? $alias : $short;
				if ($context->report($name, "The fully qualified name `\\$full` must be written `$shortened`.", decision: $decision)) {
					$name->text = $shortened;
				}

				continue;
			}

			$targets ??= self::collectShortNameTargets($scope, $resolver, $context->getAnalysis(PhpDoc::class));
			if (
				$earlier !== null
				|| NameNode::fromText($alias)->isKeyword() // a keyword is no name a bare call or reference could stand on
				|| !$resolver->isAliasFree($alias, $kind, $name)
				|| array_any(array_keys($targets[$kind->name][$key] ?? []), fn(string $target) => strcasecmp($target, $full) !== 0)
			) {
				continue;
			} elseif (!($canImport ??= CodeWriter::canAddImport($scope))) {
				// the markup the namespace opens with leaves no line for the import, which is still one to write by hand
				$context->report($name, "The fully qualified name `\\$full` must be imported.", decision: $decision, fixable: false);
			} elseif ($context->report($name, "The fully qualified name `\\$full` must be imported.", decision: $decision)) {
				CodeWriter::addImport($scope, $kind, $full, $context);
				$imported[$kind->name][$key] = $full;
				$name->text = $alias;
			}
		}

		if ($this->phpdoc !== null) {
			self::writeDocNames($scope, $this->phpdoc, $imported, $unimported, $context);
		}

		foreach ($unimported as $decision => $aliases) {
			NameReferences::removeUnusedImports($scope, $aliases, $decision, $context);
		}
	}


	/** Writes a name of the namespace of the file bare, where the bare name reaches it, or fully qualified. */
	private function writeCurrent(NameResolver $resolver, NameNode $name, string $full, RuleContext $context): void
	{
		$kind = $name->symbolKind;
		[$forms, $decision] = $this->current[$kind->name] ?? [null, null];
		$form = $name->form === NameForm::FullyQualified ? QualificationPolicy::Backslashed : QualificationPolicy::Bare;
		if ($forms === null || in_array($form, $forms, true)) {
			return;
		} elseif ($forms[0] === QualificationPolicy::Backslashed) {
			if ($context->report($name, "The name `{$name->text}` must be written fully qualified as `\\$full`.", decision: $decision)) {
				$name->text = '\\' . $full;
			}

			return;
		}

		// the bare name reaches the symbol where no import takes it elsewhere
		$target = $resolver->getImports($kind, $name)[NameReferences::toKey($kind, $name->shortName)] ?? null;
		if (
			($target === null || strcasecmp($target, $full) === 0)
			&& !NameNode::fromText($name->shortName)->isKeyword()
			&& $context->report($name, "The fully qualified name `\\$full` must be written `{$name->shortName}`.", decision: $decision)
		) {
			$name->text = $name->shortName;
		}
	}


	/**
	 * Writes the classes the doc comments of the scope name the way `qualification.class.phpdoc` says, token by token so
	 * that a comment keeps its layout; an import is added as for a name of the code.
	 * @param  list<string>  $forms  the forms of `qualification.class.phpdoc`
	 * @param  array<string, array<string, string>>  $imported  name of the kind => key of the alias => the full name imported under it
	 * @param  array<string, array<string, array<string, true>>>  $unimported  decision => name of the kind => key of the alias left
	 */
	private static function writeDocNames(
		FileNode|NamespaceNode $scope,
		array $forms,
		array &$imported,
		array &$unimported,
		RuleContext $context,
	): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$kind = SymbolKind::ClassLike;
		// gathered before an import is added, which changes the tokens of the scope; a comment is parsed only where a name
		// to shorten may stand in it, one of a namespace, or a global one outside a namespace or one the file imports
		$shortens = !in_array(QualificationPolicy::Backslashed, $forms, true);
		$global = $scope instanceof FileNode;
		$mayShorten = fn(Token $token, Trivia $trivia) => preg_match_all(
			'~(?<![\w\\\\])\\\\(' . NameReferences::NamePattern . ')~',
			$trivia->text,
			$m,
		)
			&& array_any(
				$m[1],
				fn(string $full) => $global
					|| str_contains($full, '\\')
					|| isset($resolver->getImports($kind, $token)[strtolower($full)]),
			);
		// the tokens of the scope from the index of the file, which a walk of a namespace would collect anew every pass
		$fileIndex = $scope instanceof FileNode ? $scope->getIndex() : $scope->getFile()?->getIndex();
		$start = $fileIndex?->getOrdinal($scope->getFirstToken());
		$scopeTokens = $fileIndex === null || $start === null
			? $scope->getTokens()
			: array_slice($fileIndex->getTokens(), $start, $fileIndex->getOrdinal($scope->getLastToken()) - $start + 1);
		$comments = [];
		foreach ($scopeTokens as $token) {
			foreach ($token->getDocComments() as $trivia) {
				if (!$shortens || $mayShorten($token, $trivia)) {
					$comments[] = [$token, $trivia];
				}
			}
		}

		$targets = null;
		foreach ($comments as [$token, $trivia]) {
			$tokens = $phpDoc->getTokens($trivia);
			$changed = false;
			foreach (array_keys(NameReferences::findDocNames($phpDoc->parse($trivia), $tokens)) as $index) {
				$text = $tokens[$index][Lexer::VALUE_OFFSET];
				$line = substr_count(implode('', array_column(array_slice($tokens, 0, $index), Lexer::VALUE_OFFSET)), "\n");
				if (!str_starts_with($text, '\\')) {
					// a bare name only where an import reaches it, a type of the doc comment such as `list` being bare too
					$full = str_contains($text, '\\') ? null : $resolver->getImports($kind, $token)[strtolower($text)] ?? null;
					if (
						$full !== null
						&& !in_array(QualificationPolicy::Imported, $forms, true)
						&& $context->report(
							$token,
							"The imported name `$text` in the doc comment must be written fully qualified as `\\$full`.",
							decision: self::Phpdoc,
							trivia: $trivia,
							lineInTrivia: $line,
						)
					) {
						$unimported[self::Phpdoc][$kind->name][strtolower($text)] = true;
						$tokens[$index][Lexer::VALUE_OFFSET] = '\\' . $full;
						$changed = true;
					}

					continue;
				}

				if (!$shortens) {
					continue;
				}

				// the resolver knows the imports the file had, the ones added since are those of $imported
				$full = substr($text, 1);
				$alias = substr($full, (int) strrpos($full, '\\') + 1);
				$key = NameReferences::toKey($kind, $alias);
				$earlier = $imported[$kind->name][$key] ?? null;
				// as in the code, a name relative to the import of a prefix or to the namespace is imported rather than written
				$short = $earlier !== null && strcasecmp($earlier, $full) === 0 ? $alias : $resolver->shortenName($full, $kind, $token);
				if (!str_contains($short, '\\')) {
					if ($context->report(
						$token,
						"The fully qualified name `$text` in the doc comment must be written `$short`.",
						decision: self::Phpdoc,
						trivia: $trivia,
						lineInTrivia: $line,
					)) {
						$tokens[$index][Lexer::VALUE_OFFSET] = $short;
						$changed = true;
					}

					continue;
				}

				// a class of another namespace is imported, a global one stays where the file does not import it
				$targets ??= self::collectShortNameTargets($scope, $resolver, $phpDoc);
				if (
					!str_contains($full, '\\')
					|| $earlier !== null
					|| NameNode::fromText($alias)->isKeyword()
					|| !$resolver->isAliasFree($alias, $kind, $token)
					|| array_any(array_keys($targets[$kind->name][$key] ?? []), fn(string $target) => strcasecmp($target, $full) !== 0)
					|| !CodeWriter::canAddImport($scope)
				) {
					continue;
				} elseif ($context->report(
					$token,
					"The fully qualified name `$text` in the doc comment must be imported.",
					decision: self::Phpdoc,
					trivia: $trivia,
					lineInTrivia: $line,
				)) {
					CodeWriter::addImport($scope, $kind, $full, $context);
					$imported[$kind->name][$key] = $full;
					$tokens[$index][Lexer::VALUE_OFFSET] = $alias;
					$changed = true;
				}
			}

			if ($changed) {
				$token->replaceTrivia($trivia, $trivia->withText(implode('', array_column($tokens, Lexer::VALUE_OFFSET))));
			}
		}
	}


	/**
	 * How a name of a namespace other than the global one is written: fully qualified, or imported when it is the alias
	 * of an import of the whole name; null for a global name and for a qualified one, which is relative.
	 */
	private static function findForm(NameResolver $resolver, NameNode $name, string $full): ?string
	{
		if (!str_contains($full, '\\')) {
			return null;
		} elseif ($name->form === NameForm::FullyQualified) {
			return QualificationPolicy::Backslashed;
		} elseif ($name->form !== NameForm::Unqualified) {
			return null;
		}

		$target = $resolver->getImports($name->symbolKind, $name)[NameReferences::toKey($name->symbolKind, $name->shortName)] ?? null;
		return $target !== null && strcasecmp($target, $full) === 0 ? QualificationPolicy::Imported : null;
	}


	/**
	 * What each short name an import could take over resolves to in the scope, by kind: an unqualified name, which the
	 * import would redirect, a class name of a doc comment, and a fully qualified global class, a dropped leading
	 * backslash away from being one.
	 * @return array<string, array<string, array<string, true>>>  name of the kind => key of the alias => resolved names
	 */
	private static function collectShortNameTargets(FileNode|NamespaceNode $scope, NameResolver $resolver, PhpDoc $phpDoc): array
	{
		$targets = [SymbolKind::ClassLike->name => NameReferences::collectDocClassTargets($scope, $resolver, $phpDoc)];
		foreach ($scope->find(NameNode::class) as $name) {
			$kind = $name->symbolKind;
			$bare = $name->form === NameForm::Unqualified
				|| ($kind === SymbolKind::ClassLike && $name->form === NameForm::FullyQualified && count($name->parts) === 1);
			if (!$name->isReference()) {
				continue;
			} elseif ($name->form === NameForm::Qualified) {
				// the first part of a qualified name is read through the class imports
				$prefix = strtolower(NameReferences::resolvePrefix($resolver, $name));
				$targets[SymbolKind::ClassLike->name][strtolower($name->parts[0])][$prefix] = true;
				continue;
			} elseif (!$bare) {
				continue;
			}

			$targets[$kind->name][NameReferences::toKey($kind, $name->shortName)][strtolower($resolver->resolve($name))] = true;
		}

		return $targets;
	}
}
