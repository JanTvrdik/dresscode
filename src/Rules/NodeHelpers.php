<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use PhpSyntax\Nodes\Expression;
use PhpSyntax\Token;


/**
 * Queries and constructions over the tree that rules share.
 * @internal
 */
final class NodeHelpers
{
	/**
	 * The place of the operator in the words of the catalogue: `logical`, `concatenation`, `arithmetic`, `bitwise`,
	 * `coalesce`, `pipe` or `comparison`; null for an operator none of them names.
	 */
	public static function findOperatorPlace(Expression\BinaryOpNode $operation): ?string
	{
		$operator = $operation->operator;
		return match (true) {
			$operation->isLogical() => 'logical',
			$operator->is('.') => 'concatenation',
			$operator->is(['+', '-', '*', '/', '%', '**']) => 'arithmetic',
			$operator->is(['|', '&', '^', '<<', '>>']) => 'bitwise',
			$operator->is('??') => 'coalesce',
			$operator->is('|>') => 'pipe',
			$operator->is(['==', '!=', '<>', '===', '!==', '<', '<=', '>', '>=', '<=>']) => 'comparison',
			default => null,
		};
	}


	/**
	 * Whether the operator at a line break stands on the other side of it than the position says: ending its line where
	 * operators open lines (`lineStart`), opening its line where they end them (`lineEnd`); a comment between the operator
	 * and the break keeps it where it is.
	 */
	public static function isOperatorMisplaced(Token $operator, string $position): bool
	{
		return $position === 'lineEnd'
			? $operator->startsLine() && !($operator->getPrevious()?->hasCommentUpTo($operator) ?? true)
			: $operator->isFollowedByLineEnding();
	}
}
