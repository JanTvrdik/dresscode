<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\{CombinedAssignmentNode, PostfixOpNode, PrefixOpNode};
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Statement\{ExpressionStatementNode, ForNode};


/**
 * An increment or a decrement whose value nobody reads, a statement or a step of `for`, is written in the form of
 * `expressions.assignment.increment`, `++$i` or `$i++`; one inside an expression gives its value and stays as it is.
 * `$a += 1` and `$a -= 1` are written as an increment and a decrement of that form, postfix where it is kept, only as
 * a whole statement, where the value of the expression cannot be observed. That is risky: null and a string
 * that is no number count differently, `null--` staying null and `'a'++` being `'b'`, and a number is not told from
 * them.
 */
#[RuleInfo(Stage::Structure)]
final class IncrementRule extends NodeRule
{
	private const Increment = 'expressions.assignment.increment';
	private const AddingOne = 'expressions.assignment.addingOne';

	/** the form of an increment, null where it is kept */
	private ?string $form = null;

	private bool $addingOne = true;


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::Increment,
				new Words(['prefix' => '`++$i`, `--$i`', 'postfix' => '`$i++`, `$i--`']),
				'An increment or a decrement whose value is not read, a statement or a step of `for`; inside an expression it stays as it is',
			),
			new Decision(
				self::AddingOne,
				Domain::state('forbidden'),
				'`$i += 1`, which is `$i++`, or `++$i` by `expressions.assignment.increment`',
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->form = $values->find(self::Increment)?->getWord();
		$this->addingOne = !$values->isKept(self::AddingOne);
	}


	public function getVisitedNodes(): array
	{
		return [
			...($this->addingOne ? [CombinedAssignmentNode::class] : []),
			...match ($this->form) {
				'prefix' => [PostfixOpNode::class],
				'postfix' => [PrefixOpNode::class],
				default => [],
			},
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof CombinedAssignmentNode) {
			$this->replaceAddingOne($node, $context);
		} elseif ($node instanceof PostfixOpNode || $node instanceof PrefixOpNode) {
			$this->replaceIncrement($node, $context);
		}
	}


	/** An increment or a decrement whose value nobody reads written in the other form. */
	private function replaceIncrement(PostfixOpNode|PrefixOpNode $node, RuleContext $context): void
	{
		if (!$node->operator->is(['++', '--']) || !self::isUnread($node) || $node->hasInnerComment()) {
			return;
		}

		$operator = $node->operator->text;
		$written = $this->renderIncrement($operator, $node->target->text);
		if ($context->report($node, "The `$node->text` whose value is not read must be written `$written`.", decision: self::Increment)) {
			$node->replaceWith($this->buildIncrement($operator, $node->target));
		}
	}


	private static function isUnread(PostfixOpNode|PrefixOpNode $node): bool
	{
		$parent = $node->parent;
		return $parent instanceof ExpressionStatementNode
			|| ($parent?->parent instanceof ForNode && $parent === $parent->parent->updates);
	}


	/** `$a += 1` and `$a -= 1` as a whole statement written as an increment and a decrement. */
	private function replaceAddingOne(CombinedAssignmentNode $node, RuleContext $context): void
	{
		if (
			!$node->parent instanceof ExpressionStatementNode
			|| !$node->operator->is(['+=', '-='])
			|| !$node->expression instanceof IntegerNode
			|| $node->expression->token->text !== '1'
		) {
			return;
		}

		$operator = $node->operator->is('+=') ? '++' : '--';
		$written = $this->renderIncrement($operator, $node->target->text);
		$last = $node->target->getLastToken();
		if (
			$last->hasCommentUpTo($node->expression->token)
			|| !$context->report(
				$node,
				"The `{$node->operator->text} 1` assignment must be written `$written`.",
				decision: self::AddingOne,
				risk: Risk::TypeUnknown,
				because: "the target may be a string, which `$operator` changes as text",
			)
		) {
			return;
		}

		$node->replaceWith($this->buildIncrement($operator, $node->target));
	}


	private function renderIncrement(string $operator, string $target): string
	{
		return $this->form === 'prefix' ? $operator . $target : $target . $operator;
	}


	private function buildIncrement(string $operator, ExpressionNode $target): ExpressionNode
	{
		return (new Builder)->expression($this->form === 'prefix' ? $operator . '$target' : '$target' . $operator, target: $target);
	}
}
