<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Decision, Domain, Plugin, PluginManifest, Rules};
use DressCode\Domains\{Shapes, Words};


/**
 * What the core of DressCode brings: its rules, whose pages are on dresscode.run, and the decisions no single rule of
 * it owns.
 * @internal
 */
final class CorePlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		// built once, since nothing of it changes
		static $manifest;
		return $manifest ??= new PluginManifest(
			rules: [
				Rules\Arrays\ArraySyntaxRule::class,
				Rules\Comments\NoEmptyCommentsRule::class,
				Rules\Comments\NoHashCommentsRule::class,
				Rules\ControlFlow\ElseifNotationRule::class,
				Rules\ControlFlow\NoEmptyStatementsRule::class,
				Rules\Expressions\BinaryOperatorSpacingRule::class,
				Rules\Expressions\MemberAccessSpacingRule::class,
				Rules\Expressions\NotEqualsNotationRule::class,
				Rules\Expressions\OperandSpacingRule::class,
				Rules\Expressions\TernaryOperatorSpacingRule::class,
				Rules\Files\FinalLineEndingsRule::class,
				Rules\Files\LineEndingRule::class,
				Rules\Files\NoBomRule::class,
				Rules\Files\NoClosingTagRule::class,
				Rules\Files\NoInvisibleCharactersRule::class,
				Rules\Files\NoTrailingWhitespaceRule::class,
				Rules\Files\OpeningTagNotationRule::class,
				Rules\Files\StrictTypesRequiredRule::class,
				Rules\Literals\BuiltinCasingRule::class,
				Rules\Variables\NoGlobalStatementsRule::class,
				Rules\Whitespace\CommaSpacingRule::class,
				Rules\Whitespace\ConstructSpacingRule::class,
				Rules\Whitespace\DelimiterSpacingRule::class,
				Rules\Whitespace\SemicolonSpacingRule::class,
			],
			// the decisions no single rule owns, each turning on the rules that name it in `RuleInfo::$decisions`
			decisions: [
				// the style of the run, which the engine reads and every rule writing code takes
				new Decision('file.lineEnding', new Words([
					'LF' => 'every line ends with LF',
					'CRLF' => 'every line ends with CRLF',
					'majority' => 'every line ends as most lines of the file do, LF on a tie',
				]), 'The line ending of every line, which the code written new takes too; under `keep` that follows the file'),
				new Decision(
					'spacing.keyword',
					new Shapes(['spaced' => ['return $x', 'a single space']]),
					'The space after a keyword followed by code on its line, `return`, `new`, `if`, `function`, a modifier and the words of the head of a class among them, and around a keyword continuing a statement, `else`, `catch`, `finally`, the `while` of a `do`, `as`, `insteadof` and the `use` of a closure, which stay on the line of what is before them',
				),
				new Decision(
					'multiline.operatorPosition.ternary',
					Domain::operatorPosition(),
					'Where `?`, `:` and `?:` of a ternary stand at a line break, of a ternary spread over lines too',
				),
				new Decision(
					'multiline.operatorPosition.comparison',
					Domain::operatorPosition(),
					'Where a comparison stands at a line break: one ending its line moves only where the line joined after it would be too wide, one opening its line joins the line before it, what follows staying on its line unless it would be too wide',
				),
			],
		);
	}
}
