<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{CodeWriter, Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{Expression, ExpressionNode, IdentifierNode};


/**
 * `is_object($v) ? get_class($v) : gettype($v)` asks what a value is in three calls, which is what
 * `get_debug_type()` of PHP 8.0 answers in one; `$v::class` stands for `get_class($v)`, and the negated ternary
 * is read the same way.
 *
 * The two do not spell the same answer: `gettype()` says `integer`, `double`, `boolean` and `NULL` where
 * `get_debug_type()` says `int`, `float`, `bool` and `null`, `get_class()` names an object of an anonymous class
 * with the file that declares it, and a message or a comparison built on those words says something else
 * afterwards, so the fix is risky.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.0'], analyses: [NameResolver::class])]
final class GetDebugTypeForTernaryRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'upgrading.functions.get_debug_type',
			Domain::state('required'),
			'`get_debug_type($x)` for `is_object($x) ? get_class($x) : gettype($x)`',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\TernaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\TernaryNode || $node->then === null || $node->hasInnerComment()) {
			return;
		}

		$condition = $node->condition;
		$negated = $condition instanceof Expression\UnaryOpNode && $condition->operator->is('!');
		$test = $negated ? $condition->expression : $condition;
		[$class, $type] = $negated ? [$node->else, $node->then] : [$node->then, $node->else];
		$subject = NodeHelpers::findSingleArgument($test, 'is_object', $context);
		if (
			$subject === null
			|| !$subject->isRepeatableRead()
			|| ($named = self::readClassOfValue($class, $context)) === null
			|| !$named->matches($subject)
			|| ($typed = NodeHelpers::findSingleArgument($type, 'gettype', $context)) === null
			|| !$typed->matches($subject)
		) {
			return;
		}

		assert($test instanceof Expression\FunctionCallNode);
		if (!$context->report(
			$node,
			'The type of the value must be asked for with `get_debug_type()`.',
			risk: Risk::TypeUnknown,
			because: 'the value may be one whose type `gettype()` or `get_class()` spells another way',
		)) {
			return;
		}

		$spelling = CodeWriter::spellFunction('get_debug_type', $test->name, $context);
		$node->replaceWith((new Builder)->call($spelling, [$subject]));
	}


	/** The value whose class `get_class($v)` or `$v::class` gives, null for any other expression. */
	private static function readClassOfValue(?ExpressionNode $expression, RuleContext $context): ?ExpressionNode
	{
		return $expression instanceof Expression\ClassConstantFetchNode
			&& $expression->class instanceof ExpressionNode
			&& $expression->name instanceof IdentifierNode
			&& $expression->name->equals('class')
			? $expression->class
			: NodeHelpers::findSingleArgument($expression, 'get_class', $context);
	}
}
