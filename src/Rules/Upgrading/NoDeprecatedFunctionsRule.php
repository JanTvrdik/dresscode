<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{PhpDoc, PhpSymbols, Types};
use DressCode\{Decision, Domain, NodeRule, QualifiedNames, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\NodeHelpers;
use DressCode\Upgrading\MemberMapGrammar;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, Node, SymbolKind, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;


/**
 * Calls of functions their declaration deprecates, `format_old($s)` where `format_old()` says `@deprecated use
 * format()`: an unqualified name is the function of the namespace where one is declared, as PHP calls it, and the
 * global one otherwise. Where the deprecation names a function that exists, the call is rewritten to it, written the
 * way the scope reaches it, a risky fix, the deprecation naming what to use and not saying it behaves alike; any other
 * call is reported with what the deprecation says. A function of PHP is left to its upgrading data, and so is one the
 * map of replacedFunctions or of forbiddenFunctions has, and a call inside a declaration deprecated itself, which goes
 * together with it.
 */
#[RuleInfo(
	Stage::Structure,
	typesRequired: true,
	reads: [MemberMapGrammar::ReplacedFunctions, MemberMapGrammar::ForbiddenFunctions],
	analyses: [Types::class, PhpSymbols::class, PhpDoc::class, NameResolver::class],
)]
final class NoDeprecatedFunctionsRule extends NodeRule
{
	private const Because = 'the deprecation names the replacement, not that it behaves alike';

	/** @var list<string>  lowercased, fully qualified, a pattern with `*` among them, the functions a map of the libraries has */
	private array $mapped = [];


	public static function getDecisions(): array
	{
		return [new Decision(
			'upgrading.declarations.deprecatedFunction',
			Domain::state(),
			'A function whose declaration is `@deprecated`, replaced by the function the deprecation names, reported where it names none',
		)];
	}


	public function configure(Values $values): void
	{
		foreach ([MemberMapGrammar::ReplacedFunctions, MemberMapGrammar::ForbiddenFunctions] as $path) {
			foreach (array_keys($values->readMap($path)) as $name) {
				$this->mapped[] = strtolower(ltrim((string) $name, '\\'));
			}
		}
	}


	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FunctionCallNode || !$node->name instanceof NameNode) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$function = self::findFunction($node->name, $types, $context);
		$deprecation = $function === null ? null : $types->findFunctionDeprecation($function);
		if (
			$function === null
			|| $deprecation === null
			|| $context->getAnalysis(PhpSymbols::class)->isBuiltinFunction($function)
			|| array_any($this->mapped, fn(string $pattern) => fnmatch($pattern, strtolower($function)))
			|| NodeHelpers::isInDeprecatedDeclaration($node, $context)
		) {
			return;
		}

		$replacement = $deprecation->replacementName;
		$message = "Function `$function()` is deprecated" . $deprecation->formatDescription() . '.';
		if ($replacement === null || strcasecmp($replacement, $function) === 0) {
			$context->report($node->name, $message, fixable: false);
		} elseif ($context->report($node->name, $message, risk: Risk::BehaviorChanges, because: self::Because)) {
			$node->name->text = self::spellReplacement($replacement, $function, $node->name, $context);
		}
	}


	/**
	 * The function the name calls: an unqualified name nothing imports calls the function of the namespace where one is
	 * declared there and the global one otherwise, as PHP decides it.
	 */
	private static function findFunction(NameNode $name, Types $types, RuleContext $context): ?string
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$resolved = $resolver->resolveFunction($name);
		$namespaced = $resolver->getNamespace($name) . '\\' . $name->text;
		return $name->form === NameForm::Unqualified
			&& (strcasecmp($resolved, $namespaced) === 0 || strcasecmp($resolved, $name->text) === 0)
			? $types->findFunctionName(ltrim($namespaced, '\\')) ?? $types->findFunctionName($name->text)
			: $types->findFunctionName($resolved);
	}


	/**
	 * The name of the replacement written the way the call wrote the deprecated one where that reaches it too: bare where
	 * both functions are of the namespace of the file, which PHP looks in first.
	 */
	private static function spellReplacement(string $replacement, string $function, NameNode $name, RuleContext $context): string
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$namespace = $resolver->getNamespace($name);
		return match (true) {
			$name->form === NameForm::FullyQualified => '\\' . $replacement,
			$name->form === NameForm::Unqualified
				&& $namespace !== ''
				&& strcasecmp(QualifiedNames::extractNamespace($function), $namespace) === 0
				&& strcasecmp(QualifiedNames::extractNamespace($replacement), $namespace) === 0
				=> QualifiedNames::stripNamespace($replacement),
			default => $resolver->shortenName($replacement, SymbolKind::Function, $name),
		};
	}
}
