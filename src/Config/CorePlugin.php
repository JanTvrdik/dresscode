<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Analyses\IndentationPlan;
use DressCode\{Decision, DecisionKind, Domain, Plugin, PluginManifest, Rules};
use DressCode\Domains\{Count, Names, Shapes, Words};


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
				Rules\Arrays\MultilineArrayRule::class,
				Rules\Arrays\TrailingCommaRule::class,
				Rules\Classes\ClassHeadSpacingRule::class,
				Rules\Classes\MultilineImplementsRule::class,
				Rules\Comments\CommentSpacingRule::class,
				Rules\Comments\NoEmptyCommentsRule::class,
				Rules\Comments\NoHashCommentsRule::class,
				Rules\ControlFlow\EarlyExitForTrailingIfRule::class,
				Rules\ControlFlow\ElseifNotationRule::class,
				Rules\ControlFlow\MultilineConditionRule::class,
				Rules\ControlFlow\MultilineForRule::class,
				Rules\ControlFlow\NoAlternativeSyntaxRule::class,
				Rules\ControlFlow\NoBracelessBodiesRule::class,
				Rules\ControlFlow\NoContinueInSwitchRule::class,
				Rules\ControlFlow\NoEmptyStatementsRule::class,
				Rules\ControlFlow\ReturnForBooleanIfRule::class,
				Rules\ControlFlow\SwitchCaseNotationRule::class,
				Rules\ControlFlow\SwitchCaseSpacingRule::class,
				Rules\ControlFlow\TernaryForIfRule::class,
				Rules\ControlFlow\UselessBracesRule::class,
				Rules\ControlFlow\UselessElseRule::class,
				Rules\ControlFlow\UselessReturnRule::class,
				Rules\Expressions\BinaryOperatorSpacingRule::class,
				Rules\Expressions\CastCanonicalTypeRule::class,
				Rules\Expressions\CombinedAssignmentForRepeatedTargetRule::class,
				Rules\Expressions\ExplicitPrecedenceRequiredRule::class,
				Rules\Expressions\IncrementRule::class,
				Rules\Expressions\InstantiationWrappingRule::class,
				Rules\Expressions\LogicalOperatorNotationRule::class,
				Rules\Expressions\MemberAccessSpacingRule::class,
				Rules\Expressions\MultilineBinaryOperatorRule::class,
				Rules\Expressions\MultilineChainRule::class,
				Rules\Expressions\MultilineMatchArmRule::class,
				Rules\Expressions\MultilineMatchRule::class,
				Rules\Expressions\MultilineTernaryRule::class,
				Rules\Expressions\NoDoubleNegationsRule::class,
				Rules\Expressions\NoLooseComparisonsRule::class,
				Rules\Expressions\NotEqualsNotationRule::class,
				Rules\Expressions\NullCoalescingForNullTernaryRule::class,
				Rules\Expressions\OperandSpacingRule::class,
				Rules\Expressions\ShortTernaryRule::class,
				Rules\Expressions\TernaryOperatorSpacingRule::class,
				Rules\Expressions\UselessParenthesesAfterConstructRule::class,
				Rules\Expressions\UselessTernaryOperatorRule::class,
				Rules\Expressions\YodaRule::class,
				Rules\Files\DeclareSpacingRule::class,
				Rules\Files\FinalLineEndingsRule::class,
				Rules\Files\LineEndingRule::class,
				Rules\Files\LineLengthRule::class,
				Rules\Files\NoBomRule::class,
				Rules\Files\NoClosingTagRule::class,
				Rules\Files\NoInvisibleCharactersRule::class,
				Rules\Files\NoTrailingWhitespaceRule::class,
				Rules\Files\OpeningTagNotationRule::class,
				Rules\Files\StrictTypesRequiredRule::class,
				Rules\Functions\FunctionNameSpacingRule::class,
				Rules\Functions\MultilineCallRule::class,
				Rules\Functions\MultilineClosureUseRule::class,
				Rules\Functions\MultilineSignatureRule::class,
				Rules\Functions\NamedArgumentSpacingRule::class,
				Rules\Literals\BuiltinCasingRule::class,
				Rules\Literals\HeredocIndentationRule::class,
				Rules\Types\TypeDeclarationSpacingRule::class,
				Rules\Variables\NoGlobalStatementsRule::class,
				Rules\Whitespace\AttributePositionRule::class,
				Rules\Whitespace\AttributeSpacingRule::class,
				Rules\Whitespace\BlockBlankLinesRule::class,
				Rules\Whitespace\BracesPositionRule::class,
				Rules\Whitespace\CommaSpacingRule::class,
				Rules\Whitespace\ConstructSpacingRule::class,
				Rules\Whitespace\DelimiterSpacingRule::class,
				Rules\Whitespace\IndentationRule::class,
				Rules\Whitespace\MemberBlankLinesRule::class,
				Rules\Whitespace\MultilineAttributeRule::class,
				Rules\Whitespace\NoStatementsSharingLineRule::class,
				Rules\Whitespace\SemicolonSpacingRule::class,
				Rules\Whitespace\SingleLevelIndentationRule::class,
				Rules\Whitespace\StatementBlankLinesRule::class,
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
					'file.lineLength',
					new Count(1, range: false, words: ['none' => 'no line is too wide']),
					'The widest line, by which what spreads over lines is split',
					kind: DecisionKind::Parameter,
					default: 'none',
				),
				new Decision('file.lineLengthExcept', new Names([
					'imports' => 'a `use` import other than a group use on one line, which cannot be split',
					'strings' => 'a line that would fit were each of its string literals an empty pair of quotes, a string being never split',
					'argument' => 'the arguments of a call, of `new` and of an attribute, the variables of `unset()` and `isset()`',
					'parameter' => 'the parameters of a function or a method',
					'closureUse' => 'the variables of the `use` of a closure',
					'attribute' => 'the attributes of one group',
					'condition' => 'a condition of `if`, `elseif`, `while` and `do-while`',
					'chain' => 'a chain of method calls',
					'ternary' => 'a ternary',
					'for' => 'the header of a `for`',
					'logical' => 'the operands of a boolean operator outside a condition',
					'concatenation' => 'the operands of `.`',
					'arithmetic' => 'the operands of `+`, `-`, `*`, `/`, `%` and `**`',
					'bitwise' => 'the operands of `|`, `&`, `^`, `<<` and `>>`',
					'coalesce' => 'the operands of `??`',
					'pipe' => 'the operands of `|>`',
					'array' => 'the items of an array, which `multiline.maxWidth.array` spreads all the same',
					'list' => 'the items of `list()`',
					'implements' => 'the interfaces a class implements or an interface extends',
					'matchCondition' => 'the conditions of a `match` arm',
				], regularExpressions: true), 'What a line too wide leaves as it is: a construct named is not spread over lines for its width, and a line it stands on, one a regular expression matches, or with `strings` one wide only for its string literals, is not reported', kind: DecisionKind::Parameter, default: ['imports']),
				new Decision('indentation.unit', new Words([
					'tab' => 'one tab per level',
					'4 spaces' => 'four spaces per level',
					'2 spaces' => 'two spaces per level',
				]), 'Every line indented by the construct it continues, one level per nesting, the level being this unit; under `keep` a line stays where it is'),
				new Decision(
					'indentation.tabWidth',
					new Count(1, 8, range: false),
					'How many columns a tab counts for in the width of a line',
					kind: DecisionKind::Parameter,
					default: 4,
				),
				...self::createIndentationDecisions(),
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
				// where a comparison stands, which the rule of the conditions splits a condition that is one at
				new Decision(
					'multiline.operatorPosition.comparison',
					Domain::operatorPosition(),
					'Where a comparison stands at a line break: one ending its line moves only where the line joined after it would be too wide, one opening its line joins the line before it, what follows staying on its line unless it would be too wide',
				),

				// the lines below a doc comment, of a member as of a declaration among statements
				new Decision(
					'blankLines.afterPhpdoc',
					Domain::blankLines(),
					'Between a doc comment and the declaration it belongs to, or the first attribute of the declaration',
				),
			],
		);
	}


	/** @return list<Decision>  the levels of the lines continuing a construct, which a rule deciding by the width of a line waits for */
	private static function createIndentationDecisions(): array
	{
		$level = new Count(0, 1, range: false);
		return [
			new Decision(
				IndentationPlan::Binary,
				$level,
				'The levels a line opened by `&&`, `+`, `.` steps in by from the start of its expression, 1 at least where the expression shares its first line',
			),
			new Decision(IndentationPlan::Ternary, $level, 'The levels `?` and `:` opening a line step in by'),
			new Decision(IndentationPlan::SwitchCase, $level, 'The levels `case` steps in by from `switch`'),
			new Decision(IndentationPlan::Chain, new Words([
				'flat' => 'every link one level below the start',
				'nested' => 'a link one level deeper or shallower than the link before it',
			]), 'Where the links of a chain spread over lines stand'),
		];
	}
}
