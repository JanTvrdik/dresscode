<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\Expression\{CastNode, PostfixOpNode, PrefixOpNode, UnaryOpNode, VariableNode};
use function count;


/**
 * The whitespace between an operator and its operand, which stay on one line: none for a unary operator, `-$b`,
 * `$i++`, for `&`, `&$a`, `function &f()`, and for `...` in arguments, parameters and arrays alike, nor inside
 * a variable variable, `$$a` and `${'a'}`. After `!` it is what `spacing.logicalNot` says, where a tolerance passes each
 * of its shapes as written, and after a cast a single space or none, as `spacing.cast` says.
 */
#[RuleInfo(Stage::Formatting)]
final class OperandSpacingRule extends GapRule
{
	private const Unary = 'spacing.unaryOperator';
	private const LogicalNot = 'spacing.logicalNot';
	private const Cast = 'spacing.cast';
	private const Reference = 'spacing.reference';
	private const Spread = 'spacing.spread';

	private bool $unary = true;

	/** @var list<string>  the shapes after `!` that pass, the first written; none where it is kept */
	private array $logicalNot = [];

	/** the space after a cast, after `&` and after `...`, null where it is kept */
	private ?Claim $cast;
	private ?Claim $reference;
	private ?Claim $spread;


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::Unary,
				new Shapes(['compact' => ['-$x', 'no space after the operator']]),
				'The whitespace between a unary operator other than `!` and its operand, which stay on one line, and inside a variable variable, `$$a` and `${\'a\'}`',
			),
			new Decision(self::LogicalNot, new Shapes([
				'compact' => ['!$x', 'no space after the operator'],
				'spaced' => ['! $x', 'a single space after the operator'],
			], tolerance: true), 'The whitespace between `!` and its operand, which stay on one line'),
			new Decision(self::Cast, new Shapes([
				'spaced' => ['(int) $x', 'a single space after the cast'],
				'compact' => ['(int)$x', 'no space after the cast'],
			]), 'The space between a cast and its operand, which stays on the line of the cast'),
			new Decision(
				self::Reference,
				new Shapes(['compact' => ['&$x', 'none between']]),
				'The whitespace between `&` and its operand',
			),
			new Decision(
				self::Spread,
				new Shapes(['compact' => ['...$x', 'none between']]),
				'The whitespace between `...` and its operand',
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->unary = !$values->isKept(self::Unary);
		$this->logicalNot = $values->isKept(self::LogicalNot) ? [] : $values->get(self::LogicalNot)->getShapes();
		$this->cast = match ($values->find(self::Cast)?->getShape()) {
			'spaced' => new Claim(Space::Single, line: Line::Same, decision: self::Cast),
			'compact' => new Claim(Space::None, line: Line::Same, decision: self::Cast),
			default => null,
		};
		$this->reference = $values->isKept(self::Reference) ? null : new Claim(Space::None, line: Line::Same, decision: self::Reference);
		$this->spread = $values->isKept(self::Spread) ? null : new Claim(Space::None, line: Line::Same, decision: self::Spread);
	}


	public function getClaims(): array
	{
		$claims = [];
		$hug = null;
		if ($this->unary) {
			$hug = new Claim(Space::None, line: Line::Same, decision: self::Unary);
			$claims[VariableNode::class] = [
				'dollar' => [null, $hug],
				'openBrace' => [null, $hug],
				'closeBrace' => [$hug, null],
			];
			$claims[PrefixOpNode::class] = ['operator' => [null, $hug]];
			$claims[PostfixOpNode::class] = ['operator' => [$hug, null]];
		}

		$not = $this->claimLogicalNot();
		if ($hug !== null || $not !== null) {
			$claims[UnaryOpNode::class] = ['operator' => [
				null,
				fn(Gap $gap) => $gap->token->text === '!' ? ($not instanceof \Closure ? $not($gap) : $not) : $hug,
			]];
		}

		if ($this->cast !== null) {
			$claims[CastNode::class] = ['operator' => [null, $this->cast]];
		}

		if ($this->reference !== null) {
			$claims['*']['ampersand'] = [null, $this->reference];
		}

		if ($this->spread !== null) {
			$claims['*']['ellipsis'] = [null, $this->spread];
		}

		return $claims;
	}


	/**
	 * The claim after `!`: the one of its shape, or of a tolerance the shape written where it passes and the first
	 * where none does; null where it is kept.
	 * @return Claim|\Closure(Gap): Claim|null
	 */
	private function claimLogicalNot(): Claim|\Closure|null
	{
		$claims = [];
		foreach ($this->logicalNot as $shape) {
			$claims[$shape] = new Claim($shape === 'compact' ? Space::None : Space::Single, line: Line::Same, decision: self::LogicalNot);
		}

		if ($claims === []) {
			return null;
		}

		$first = reset($claims);
		return count($claims) === 1 ? $first : fn(Gap $gap) => match ($gap->token->getTrailingSpace()) {
			'' => $claims['compact'] ?? $first,
			' ' => $claims['spaced'] ?? $first,
			default => $first,
		};
	}
}
