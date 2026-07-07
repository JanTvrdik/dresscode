<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{Expression, OperatorNode};


/**
 * The symbolic `&&` and `||` instead of the wordy `and` and `or`; an operand binding between the two precedence
 * levels, whose meaning the symbol would change, is reported and left. `xor` has no symbolic equivalent and stays.
 */
#[RuleInfo(Stage::Structure)]
final class LogicalOperatorNotationRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'expressions.wordLogicalOperator',
			Domain::state('forbidden'),
			'`and`, `or` bind weaker than `=` and are written `&&`, `||`',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\BinaryOpNode || ($symbol = self::findSymbol($node)) === null) {
			return;
		}

		$message = "The `{$node->operator->text}` operator must be written `$symbol`";
		$rewritable = self::canRewrite($node, $symbol);
		if ($rewritable === false) {
			$context->report($node->operator, "$message, but only its low precedence holds an operand together.", fixable: false);
		} elseif ($rewritable && $context->report($node->operator, "$message.")) {
			$node->operator->replaceWith(Token::fromText($symbol));
		}
	}


	/** The symbol of an `and` or an `or`; null for another operator. */
	private static function findSymbol(Expression\BinaryOpNode $node): ?string
	{
		return match (true) {
			$node->operator->is(Token::LogicalAnd) => '&&',
			$node->operator->is(Token::LogicalOr) => '||',
			default => null,
		};
	}


	/**
	 * Whether the operator may be written as the symbol: true where every operand holds together under it, false where
	 * one never will, null where one is an `and` or an `or` this rule rewrites first and that holds together then.
	 */
	private static function canRewrite(Expression\BinaryOpNode $node, string $symbol): ?bool
	{
		$precedence = self::getPrecedence($symbol);
		$res = true;
		foreach ([$node->left, $node->right] as $i => $operand) {
			if (!$operand instanceof OperatorNode || !self::bindsLooser($operand->precedence, $precedence, right: $i === 1)) {
				continue;
			}

			$inner = $operand instanceof Expression\BinaryOpNode ? self::findSymbol($operand) : null;
			if (
				$inner === null
				|| self::canRewrite($operand, $inner) === false
				|| self::bindsLooser(self::getPrecedence($inner), $precedence, right: $i === 1)
			) {
				return false;
			}

			$res = null;
		}

		return $res;
	}


	/**
	 * Whether an operand of the precedence is held together only by the low precedence of `and`/`or`: under the symbolic
	 * operator it would parse differently. Both lean to the left, so an operand of the same precedence may stand on the
	 * left and not on the right.
	 */
	private static function bindsLooser(int $own, int $precedence, bool $right): bool
	{
		return $right ? $own <= $precedence : $own < $precedence;
	}


	private static function getPrecedence(string $symbol): int
	{
		static $precedences = [];
		return $precedences[$symbol] ??= (new Builder)->binary(0, $symbol, 0)->precedence;
	}
}
