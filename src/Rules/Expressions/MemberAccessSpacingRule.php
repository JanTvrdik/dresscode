<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\Member\{TraitAliasNode, TraitPrecedenceNode};


/**
 * No whitespace around the operator of an access to a member, nor inside the braces of a dynamic name. Around `->`
 * and `?->` on a line, `$a->{$b}`, the name staying on the line of the operator; a line break before the operator is
 * `MultilineChainRule`'s. Around `::`, after its `$` and inside its braces, `A::{$b}()`, `A::$$b`, `A::${$b}`, all of
 * it on one line.
 */
#[RuleInfo(Stage::Formatting)]
final class MemberAccessSpacingRule extends GapRule
{
	private const ObjectOperator = 'spacing.objectOperator';
	private const DoubleColon = 'spacing.doubleColon';

	private bool $objectOperator = true;
	private bool $doubleColon = true;


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::ObjectOperator,
				new Shapes(['compact' => ['$a->b()', 'none around']]),
				'The whitespace around the object operator',
			),
			new Decision(
				self::DoubleColon,
				new Shapes(['compact' => ['Foo::bar()', 'none around']]),
				'The whitespace around the double colon',
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->objectOperator = !$values->isKept(self::ObjectOperator);
		$this->doubleColon = !$values->isKept(self::DoubleColon);
	}


	public function getClaims(): array
	{
		$claims = [];
		if ($this->objectOperator) {
			$hug = new Claim(Space::None, line: Line::Same, decision: self::ObjectOperator);
			$slots = [
				'operator' => [Claim::noSpace()->withDecision(self::ObjectOperator), $hug],
				'openBrace' => [null, $hug],
				'closeBrace' => [$hug, null],
			];
			$claims[MethodCallNode::class] = $slots;
			$claims[PropertyFetchNode::class] = $slots;
		}

		if ($this->doubleColon) {
			$hug = new Claim(Space::None, line: Line::Same, decision: self::DoubleColon);
			$colon = ['doubleColon' => [$hug, $hug]];
			$braces = ['openBrace' => [null, $hug], 'closeBrace' => [$hug, null]];
			$claims[StaticMethodCallNode::class] = $colon + $braces;
			$claims[ClassConstantFetchNode::class] = $colon + $braces;
			$claims[StaticPropertyFetchNode::class] = $colon + $braces + ['dollar' => [null, $hug]];
			$claims[TraitPrecedenceNode::class] = $colon;
			$claims[TraitAliasNode::class] = $colon;
		}

		return $claims;
	}
}
