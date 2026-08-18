<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{CodeWriter, Decision, Domain, GlobalCalls, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{Expression, ExpressionNode};
use function count;


/**
 * Whether an array is a list is what `array_is_list()` of PHP 8.1 answers, so the two ways of asking it by
 * hand give way to it: `$a === array_values($a)`, which holds only for keys counting from zero in order, and
 * `array_keys($a) === range(0, count($a) - 1)`, which spells the same keys out. The array must be the same
 * expression on both sides and free of side effects, because the call reads it once.
 *
 * The form with `range()` is risky, and for one array: `range(0, -1)` is not empty but counts backwards, so
 * for an empty array the comparison says it is no list, while `array_is_list([])` says it is.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.1'], analyses: [NameResolver::class])]
final class NoManualListTestsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'upgrading.functions.array_is_list',
			Domain::state('required'),
			'`array_is_list()` for a hand-written test of a list',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\BinaryOpNode
			|| !$node->operator->is([Token::IsIdentical, Token::IsNotIdentical])
			|| (!$node->left instanceof Expression\FunctionCallNode && !$node->right instanceof Expression\FunctionCallNode)
		) {
			return;
		}

		[$array, $call, $risky] = self::readTest($node->left, $node->right, $context)
			?? self::readTest($node->right, $node->left, $context)
			?? [null, null, false];
		if ($array === null || $call === null || $node->hasInnerComment()) {
			return;
		}

		$uncertainty = GlobalCalls::findUncertaintyOfRewrite($node, [$array], $context);
		$because = $risky ? '`array_is_list()` takes an empty array for a list, the comparison with `range()` does not' : null;
		if (!$context->report(
			$node,
			'The test for a list must be written with `array_is_list()`.',
			risk: NodeHelpers::findRisk($because, $uncertainty),
			because: $because ?? $uncertainty,
		)) {
			return;
		}

		$positive = $node->operator->is(Token::IsIdentical);
		$spelling = CodeWriter::spellFunction('array_is_list', $call->name, $context);
		$test = (new Builder)->call($spelling, [$array]);
		$node->replaceWith($positive ? $test : NodeHelpers::negate($test));
	}


	/**
	 * The array the two sides ask about together, the call that named it, and whether the answer may differ
	 * from the one `array_is_list()` gives; null where they ask about something else.
	 * @return ?array{ExpressionNode, Expression\FunctionCallNode, bool}
	 */
	private static function readTest(ExpressionNode $call, ExpressionNode $compared, RuleContext $context): ?array
	{
		$argument = NodeHelpers::findSingleArgument($call, 'array_values', $context);
		if ($argument !== null) {
			assert($call instanceof Expression\FunctionCallNode);
			return NodeHelpers::isRepeatedRead($argument, $compared) ? [$compared, $call, false] : null;
		}

		$keys = NodeHelpers::findSingleArgument($call, 'array_keys', $context);
		$arguments = $compared instanceof Expression\FunctionCallNode
			&& GlobalCalls::findFunction($compared, ['range' => true], $context) !== null
			? $compared->arguments->getPlainValues()
			: null;
		if ($keys === null || $arguments === null || count($arguments) !== 2 || !NodeHelpers::isInteger($arguments[0], 0)) {
			return null;
		}

		assert($call instanceof Expression\FunctionCallNode);
		$counted = NodeHelpers::findCountedArray($arguments[1], $context);
		return NodeHelpers::isRepeatedRead($counted, $keys) ? [$keys, $call, true] : null;
	}
}
