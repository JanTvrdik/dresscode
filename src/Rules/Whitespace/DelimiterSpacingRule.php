<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\Nodes\{ArgumentListNode, CatchNode, ClosureUseListNode, DestructuringNode, ElseifNode};
use PhpSyntax\Nodes\Expression\{
	ArrayAccessNode, ArrayNode, ArrowFunctionNode, ClosureNode, EmptyNode, EvalNode, IssetNode, MatchNode, ParenthesizedNode,
};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyHookNode};
use PhpSyntax\Nodes\Scalar\{IntegerNode, StringNode};
use PhpSyntax\Nodes\Statement\{
	DoWhileNode, ForeachNode, ForNode, FunctionNode, HaltCompilerNode, IfNode, SwitchNode, UnsetNode, WhileNode,
};


/**
 * No whitespace just inside parentheses and brackets on a line: `foo($a)`, `if ($a)`, `[1, 2]`, not `foo( $a )`, or
 * with `required` a single space inside those holding something, `foo( $a )` and `foo()`; the parentheses of
 * arguments, of parameters, of the `use` of a closure, of a control structure and of a grouping, the brackets or
 * parentheses of an array and of a destructuring, and the brackets of an offset access are each a place of
 * `spacing.padding`. The brackets of an offset access stay on one line with nothing before them, `$a[$i]`, and with
 * `exceptLiterals` hug a string or an integer literal, `$a['b']` and `$a[0]`, while a single space surrounds any
 * other index.
 */
#[RuleInfo(Stage::Formatting)]
final class DelimiterSpacingRule extends GapRule
{
	/** a place of `spacing.padding` => the nodes whose delimiters it is, the slots of the delimiters, and what the decision says */
	private const Places = [
		'argument' => [
			[ArgumentListNode::class, IssetNode::class, EmptyNode::class, EvalNode::class, UnsetNode::class, HaltCompilerNode::class],
			['openParen', 'closeParen'],
			'The whitespace inside the parentheses of the arguments of a call, of `new` and of an attribute, and of `isset()`, `unset()`, `empty()` and `eval()`',
		],
		'parameter' => [
			[FunctionNode::class, MethodNode::class, ClosureNode::class, ArrowFunctionNode::class, PropertyHookNode::class],
			['openParen', 'closeParen'],
			'The whitespace inside the parentheses of the parameters of a function, a method, a closure, an arrow function and a property hook',
		],
		'closureUse' => [
			[ClosureUseListNode::class],
			['openParen', 'closeParen'],
			'The whitespace inside the parentheses of the `use` of a closure',
		],
		'controlStructure' => [
			[
				IfNode::class, ElseifNode::class, WhileNode::class, DoWhileNode::class, ForNode::class, ForeachNode::class,
				SwitchNode::class, CatchNode::class, MatchNode::class,
			],
			['openParen', 'closeParen'],
			'The whitespace inside the parentheses of `if`, `elseif`, `while`, `for`, `foreach`, `switch`, `match` and `catch`',
		],
		'grouping' => [
			[ParenthesizedNode::class],
			['openParen', 'closeParen'],
			'The whitespace inside the parentheses grouping an expression, `($a + $b) * $c`',
		],
		'array' => [
			[ArrayNode::class],
			['openDelimiter', 'closeDelimiter'],
			'The whitespace inside the brackets or the parentheses of an array',
		],
		'list' => [
			[DestructuringNode::class],
			['openDelimiter', 'closeDelimiter'],
			'The whitespace inside the brackets or the parentheses of a destructuring',
		],
	];

	private const Offset = 'spacing.padding.offset';

	/** @var array<string, array{Claim|\Closure(Gap): Claim|null, Claim|\Closure(Gap): Claim|null}>  place => the claims inside */
	private array $claims = [];

	/** the word of the brackets of an offset access, null where they are kept */
	private ?string $offset = null;


	public static function getDecisions(): array
	{
		return [
			...array_map(
				fn(string $place, array $entry) => new Decision("spacing.padding.$place", Padding::createDomain(), $entry[2]),
				array_keys(self::Places),
				self::Places,
			),
			new Decision(
				self::Offset,
				new Words([
					...Padding::createDomain()->words,
					'exceptLiterals' => 'a single space around an index other than a string or an integer literal, `$a[ $i ]` but `$a[0]`',
				]),
				'The whitespace inside the brackets of an offset access and before them',
			),
		];
	}


	public function configure(Values $values): void
	{
		foreach (array_keys(self::Places) as $place) {
			$path = "spacing.padding.$place";
			$this->claims[$place] = Padding::createClaims($path, $values->isKept($path) ? null : $values->get($path)->getWord());
		}

		$this->offset = $values->find(self::Offset)?->getWord();
	}


	public function getClaims(): array
	{
		$claims = [];
		foreach (self::Places as $place => [$classes, [$openSlot, $closeSlot]]) {
			[$open, $close] = $this->claims[$place] ?? [null, null];
			foreach ($classes as $class) {
				$claims[$class] = [$openSlot => [null, $open], $closeSlot => [$close, null]];
			}
		}

		if ($this->offset !== null) {
			$claims[ArrayAccessNode::class] = $this->createOffsetClaims($this->offset);
		}

		return $claims;
	}


	/**
	 * The claims around the brackets of an offset access: none before them, and inside as the word says.
	 * @return array<string, array{Claim|\Closure(Gap): ?Claim|null, Claim|\Closure(Gap): ?Claim|null}>
	 */
	private function createOffsetClaims(string $word): array
	{
		$hug = new Claim(Space::None, line: Line::Same, decision: self::Offset);
		[$open, $close] = Padding::createClaims(self::Offset, $word === 'forbidden' ? 'forbidden' : 'required', Line::Same);
		if ($word === 'exceptLiterals') {
			$isLiteral = fn(Gap $gap) => $gap->token->parent instanceof ArrayAccessNode
				&& ($gap->token->parent->index instanceof StringNode || $gap->token->parent->index instanceof IntegerNode);
			[$open, $close] = [
				fn(Gap $gap) => $isLiteral($gap) ? $hug : ($open instanceof \Closure ? $open($gap) : $open),
				fn(Gap $gap) => $isLiteral($gap) ? $hug : ($close instanceof \Closure ? $close($gap) : $close),
			];
		}

		return [
			'openBracket' => [$hug, $open],
			'closeBracket' => [$close, null],
		];
	}
}
