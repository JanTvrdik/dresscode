<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Upgrading\MemberMapGrammar;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\{NameNode, UseItemNode};


/**
 * References of the classes, interfaces and enums, calls of the functions and uses of the constants a project says
 * its code must not have, each reported with what the map says to do instead where it says it; nothing is rewritten,
 * which is what the maps are for where nothing else could simply stand for the symbol.
 * A class is reported wherever its name stands, a type, an instantiation, a static access, an attribute, but not in an
 * import, which names nothing once the references are gone and goes with them, and so is a constant. The name of a
 * function or a constant is resolved the way PHP does, so `var_dump()` inside a namespace is the global function
 * unless the file declares or imports another one; a pattern with `*` matches any part of the name and one with
 * a backslash the fully qualified name, a constant in its letter case.
 */
#[RuleInfo(
	Stage::Structure,
	decisions: [self::Classes, self::Functions, self::Constants],
	analyses: [NameResolver::class],
)]
final class ForbiddenSymbolsRule extends NodeRule
{
	public const Classes = MemberMapGrammar::ForbiddenClasses;
	public const Functions = MemberMapGrammar::ForbiddenFunctions;
	public const Constants = MemberMapGrammar::ForbiddenConstants;

	/** @var array<string, array{string, string}>  lowercased class => the class as the map spells it and the end of the message */
	private array $classes = [];

	/** @var list<array{string, ?string}>  regular expression, what to do instead */
	private array $functions = [];

	/** @var array<string, true>  lowercased functions withdrawn from the map, which no pattern reaches */
	private array $withdrawnFunctions = [];

	/** @var list<array{string, ?string}>  regular expression, what to do instead */
	private array $constants = [];

	/** @var array<string, true>  constants withdrawn from the map, which no pattern reaches */
	private array $withdrawnConstants = [];


	public function configure(Values $values): void
	{
		$this->classes = [];
		foreach ($values->readMap(self::Classes) as $class => $message) {
			$this->classes[strtolower(ltrim((string) $class, '\\'))] = [
				ltrim((string) $class, '\\'),
				$message === null ? '' : ": $message",
			];
		}

		[$this->functions, $this->withdrawnFunctions] = self::readPatterns($values, self::Functions, caseInsensitive: true);
		[$this->constants, $this->withdrawnConstants] = self::readPatterns($values, self::Constants, caseInsensitive: false);
	}


	/**
	 * The patterns of a map of names with what to do instead, and the names withdrawn from it.
	 * @return array{list<array{string, ?string}>, array<string, true>}
	 */
	private static function readPatterns(Values $values, string $path, bool $caseInsensitive): array
	{
		$patterns = [];
		foreach ($values->readMap($path) as $pattern => $sentence) {
			$patterns[] = [
				'~^' . str_replace('\*', '.*', preg_quote(ltrim((string) $pattern, '\\'), '~')) . '$~' . ($caseInsensitive ? 'i' : ''),
				$sentence,
			];
		}

		$withdrawn = array_map(fn($name) => ltrim((string) $name, '\\'), $values->findWithdrawnNames($path));
		return [$patterns, array_fill_keys($caseInsensitive ? array_map(strtolower(...), $withdrawn) : $withdrawn, true)];
	}


	public function getVisitedNodes(): array
	{
		return [
			...($this->classes !== [] || $this->constants !== [] ? [NameNode::class] : []),
			...($this->functions !== [] ? [FunctionCallNode::class] : []),
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof NameNode && $node->symbolKind === SymbolKind::ClassLike) {
			$this->checkClass($node, $context);
		} elseif ($node instanceof NameNode && $node->symbolKind === SymbolKind::Constant) {
			$this->checkConstant($node, $context);
		} elseif ($node instanceof FunctionCallNode) {
			$this->checkFunction($node, $context);
		}
	}


	private function checkClass(NameNode $node, RuleContext $context): void
	{
		if ($this->classes === [] || $node->parent instanceof UseItemNode || !$node->isReference()) {
			return;
		}

		$entry = $this->classes[strtolower($context->getAnalysis(NameResolver::class)->resolveClass($node))] ?? null;
		if ($entry !== null) {
			$context->report($node, "Class `$entry[0]` is forbidden$entry[1].", decision: self::Classes, fixable: false);
		}
	}


	private function checkFunction(FunctionCallNode $node, RuleContext $context): void
	{
		if (!$node->name instanceof NameNode || $node->name->isKeyword()) {
			return;
		}

		$resolved = $context->getAnalysis(NameResolver::class)->resolveFunction($node->name);
		$end = isset($this->withdrawnFunctions[strtolower($resolved)]) ? null : self::findMessageEnd($this->functions, $resolved);
		if ($end !== null) {
			$context->report($node->name, "Function `$resolved()` is forbidden$end.", decision: self::Functions, fixable: false);
		}
	}


	private function checkConstant(NameNode $node, RuleContext $context): void
	{
		if ($this->constants === [] || $node->parent instanceof UseItemNode || !$node->isReference() || $node->isKeyword()) {
			return;
		}

		$resolved = $context->getAnalysis(NameResolver::class)->resolveConstant($node);
		$end = isset($this->withdrawnConstants[$resolved]) ? null : self::findMessageEnd($this->constants, $resolved);
		if ($end !== null) {
			$context->report($node, "Constant `$resolved` is forbidden$end.", decision: self::Constants, fixable: false);
		}
	}


	/**
	 * The end of the message of the first pattern the name matches, empty where its entry says nothing to do instead;
	 * null where none matches.
	 * @param  list<array{string, ?string}>  $patterns
	 */
	private static function findMessageEnd(array $patterns, string $name): ?string
	{
		foreach ($patterns as [$pattern, $sentence]) {
			if (preg_match($pattern, $name)) {
				return $sentence === null ? '' : ": $sentence";
			}
		}

		return null;
	}
}
