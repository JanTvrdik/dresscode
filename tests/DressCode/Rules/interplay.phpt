<?php declare(strict_types=1);

/**
 * Pairs of rules that pull at the same tokens in opposite directions: one adds what the other removes.
 * Each pair must converge, and its result must be the one the pair is meant to give. A pair where one rule
 * only makes work for the other belongs here too: the violation of the second must follow the first.
 */

use DressCode\{Analyses, Config, FileResult, Rules, Style, Violation};
use DressCode\Config\{PluginRegistry, RuleBuilder};
use DressCode\Engine\{PhpFileProcessor, ReportPolicy};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @param array<class-string<DressCode\Rule>, true|array<string, mixed>> $rules  the values of the decisions of each rule */
function interplay(
	array $rules,
	string $code,
	?string $expected = null,
	Analyses\Registry $analyses = new Analyses\Registry,
	bool $fixRisky = false,
): FileResult
{
	$registry = new PluginRegistry;
	$values = [];
	foreach ($rules as $class => $value) {
		$value = $value === true ? [] : $value;
		$values = [...$values, ...$value];
	}

	$resolved = RuleBuilder::resolveValues(array_keys($rules), $values);
	$instances = RuleBuilder::createRules(array_keys($rules), $resolved);
	$style = new Style;
	Analyses\IndentationPlan::register($analyses, $resolved, $style);

	$processor = new PhpFileProcessor(
		$instances,
		$analyses,
		Config::DefaultPhpVersion,
		$style,
		policy: new ReportPolicy($registry->expandSuppressedName(...), fixRisky: $fixRisky),
	);
	$result = $processor->process('interplay.php', $code);
	Assert::null($result->syntaxError);
	Assert::same($expected ?? $code, $result->output);
	$again = $processor->process('interplay.php', (string) $result->output);
	Assert::same($result->output, $again->output, 'the result is not stable');
	return $result;
}


/**
 * The three rules of blankLines with the counts most standards give them, under the values given.
 * @param  array<string, mixed>  $values
 * @return array<class-string<DressCode\Rule>, array<string, mixed>>
 */
function blankLinesRules(array $values): array
{
	return [
		Rules\Whitespace\StatementBlankLinesRule::class => blankLinesValues($values),
		Rules\Whitespace\MemberBlankLinesRule::class => [],
		Rules\Whitespace\BlockBlankLinesRule::class => [],
	];
}


/**
 * The decisions of blankLines with the counts most standards give them, under the values given.
 * @param  array<string, mixed>  $values
 * @return array<string, mixed>
 */
function blankLinesValues(array $values): array
{
	return $values + [
		'blankLines.afterOpeningTag' => 1, 'blankLines.beforeNamespace' => 1, 'blankLines.afterNamespace' => 1,
		'blankLines.afterImports' => 1, 'blankLines.beforeDeclaration' => 1, 'blankLines.betweenImportKinds' => 1,
		'blankLines.aroundDeclarations' => 2,
		'blankLines.aroundMethods' => 2, 'blankLines.aroundInterfaceMethods' => 1, 'blankLines.bodyStart.class' => 0,
		'blankLines.bodyStart.anonymousClass' => 0, 'blankLines.bodyEnd.class' => 0, 'blankLines.bodyEnd.anonymousClass' => 0,
		'blankLines.betweenTraitUses' => 0, 'blankLines.afterTraitUses' => 1, 'blankLines.betweenMembers' => '0-1',
		'blankLines.beforeDocumentedMember' => 1, 'blankLines.afterPhpdoc' => 0, 'blankLines.bodyStart.function' => 0,
		'blankLines.bodyStart.closure' => 0, 'blankLines.bodyStart.propertyHook' => 0, 'blankLines.bodyStart.controlStructure' => 0,
		'blankLines.beforeStatement' => ['return' => '1+'],
	];
}


test('the areas of blankLines never pull against one another', function () {
	// the first statement of a block gets no blank line before it: that gap belongs to the brace
	interplay(
		blankLinesRules(['blankLines.afterOpeningTag' => 'keep']),
		"<?php\nfunction f()\n{\n\n\treturn 1;\n}\n",
		"<?php\nfunction f()\n{\n\treturn 1;\n}\n",
	);

	// a declaration nested in a body is a declaration, not a statement of a kind
	interplay(
		blankLinesRules(['blankLines.afterOpeningTag' => 'keep', 'blankLines.afterStatement' => ['if' => 1]]),
		"<?php\nfunction f()\n{\n\tif (\$x) {\n\t}\n\tfunction g()\n\t{\n\t}\n}\n",
		"<?php\nfunction f()\n{\n\tif (\$x) {\n\t}\n\n\n\tfunction g()\n\t{\n\t}\n}\n",
	);

	// the statement after the imports is the header's business, whatever its kind asks for
	interplay(blankLinesRules(['blankLines.beforeStatement' => ['if' => 0]]), "<?php\n\nuse A;\n\nif (\$x) {\n}\n");

	// and so is a function after them, where the header and the declarations meet
	interplay(blankLinesRules([]), "<?php\n\nuse A;\n\n\nfunction f()\n{\n}\n", "<?php\n\nuse A;\n\nfunction f()\n{\n}\n");

	// two kinds whose counts exclude each other meet in some code, which no count could satisfy
	Assert::exception(
		fn() => RuleBuilder::createRule(Rules\Whitespace\StatementBlankLinesRule::class, [
			'blankLines.afterStatement' => ['if' => 0],
			'blankLines.beforeStatement' => ['return' => '1+'],
		]),
		DressCode\ConfigurationException::class,
		'Keys `blankLines.afterStatement` and `blankLines.beforeStatement` ask for 0 and 1+ blank lines between `if` and `return`, which exclude each other; make the two counts meet.',
	);
});


test('explicitPrecedenceRequired adds what uselessParenthesesAfterConstruct does not remove', function () {
	interplay([
		Rules\Expressions\ExplicitPrecedenceRequiredRule::class => true,
		Rules\Expressions\UselessParenthesesAfterConstructRule::class => true,
	], "<?php\nreturn \$a && \$b || \$c;\necho (\$a);\n", "<?php\nreturn (\$a && \$b) || \$c;\necho \$a;\n");
});


test('explicitPrecedenceRequired and logicalOperatorNotation agree on and/or', function () {
	interplay([
		Rules\Expressions\ExplicitPrecedenceRequiredRule::class => true,
		Rules\Expressions\LogicalOperatorNotationRule::class => true,
	], "<?php\nif (\$b and \$c or \$d) {\n}\n", "<?php\nif ((\$b && \$c) || \$d) {\n}\n");
});


test('indentation and multilineCall settle on one shape', function () {
	interplay([
		Rules\Whitespace\IndentationRule::class => [
			'indentation.unit' => 'tab', 'indentation.binaryOperator' => 0, 'indentation.ternary' => 1,
			'indentation.switchCase' => 1, 'indentation.chain' => 'flat',
		],
		Rules\Functions\MultilineCallRule::class => ['multiline.items.argument' => 'perLine', 'multiline.edges.argument' => 'apart'],
	], "<?php\nfunction f()\n{\n  \$a = \$foo\n  ->bar(\n    1,\n      2,\n    )\n        ->baz();\n}\n", "<?php\nfunction f()\n{\n\t\$a = \$foo\n\t\t->bar(\n\t\t\t1,\n\t\t\t2,\n\t\t)\n\t\t->baz();\n}\n");
});


test('a comma asked for only because another rule spread the array follows that rule', function () {
	$result = interplay([
		Rules\Arrays\MultilineArrayRule::class => [
			'multiline.items.array' => 'perLine',
			'multiline.edges.array' => 'apart',
			'multiline.maxWidth.array' => 30,
		],
		Rules\Arrays\TrailingCommaRule::class => [
			'multiline.trailingComma.array' => 'required', 'multiline.trailingComma.argument' => 'optional',
			'multiline.trailingComma.parameter' => 'keep', 'multiline.trailingComma.matchArm' => 'keep',
			'multiline.trailingComma.closureUse' => 'keep', 'multiline.trailingComma.import' => 'optional',
			'multiline.trailingComma.list' => 'optional',
		],
	], "<?php\n\$a = ['alpha' => 1, 'beta' => 2, 'gamma' => 3];\n", "<?php\n\$a = [\n\t'alpha' => 1,\n\t'beta' => 2,\n\t'gamma' => 3,\n];\n");
	Assert::count(2, $result->violations);
	$byRule = array_column($result->violations, null, 'decision');
	Assert::null($byRule['multiline.items.array']->derivedFrom);
	Assert::same($byRule['multiline.items.array']->fingerprint, $byRule['multiline.trailingComma.array']->derivedFrom);

	// the same holds for the arguments of a call, the report standing on the closing bracket whatever the list is
	$result = interplay([
		Rules\Functions\MultilineCallRule::class => ['multiline.items.argument' => 'perLine', 'multiline.edges.argument' => 'apart'],
		Rules\Arrays\TrailingCommaRule::class => ['multiline.trailingComma.argument' => 'required'],
	], "<?php\nfoo(\n\t1, 2);\n", "<?php\nfoo(\n\t1,\n\t2,\n);\n");
	Assert::count(2, $result->violations);
	$byRule = array_column($result->violations, null, 'decision');
	Assert::null($byRule['multiline.items.argument']->derivedFrom);
	Assert::same($byRule['multiline.items.argument']->fingerprint, $byRule['multiline.trailingComma.argument']->derivedFrom);

	// an array the file itself spread owes the comma to nobody
	$result = interplay([
		Rules\Arrays\MultilineArrayRule::class => [
			'multiline.items.array' => 'perLine',
			'multiline.edges.array' => 'apart',
			'multiline.maxWidth.array' => 'none',
		],
		Rules\Arrays\TrailingCommaRule::class => [
			'multiline.trailingComma.array' => 'required', 'multiline.trailingComma.argument' => 'optional',
			'multiline.trailingComma.parameter' => 'keep', 'multiline.trailingComma.matchArm' => 'keep',
			'multiline.trailingComma.closureUse' => 'keep', 'multiline.trailingComma.import' => 'optional',
			'multiline.trailingComma.list' => 'optional',
		],
	], "<?php\n\$a = [\n\t'alpha' => 1,\n\t'beta' => 2\n];\n", "<?php\n\$a = [\n\t'alpha' => 1,\n\t'beta' => 2,\n];\n");
	Assert::same([null], array_map(fn(Violation $violation) => $violation->derivedFrom, $result->violations));

	// and the comma claims nothing about the line of the bracket, which the lines counted from it are placed by
	$result = interplay([
		Rules\Arrays\TrailingCommaRule::class => [
			'multiline.trailingComma.array' => 'required', 'multiline.trailingComma.argument' => 'optional',
			'multiline.trailingComma.parameter' => 'keep', 'multiline.trailingComma.matchArm' => 'keep',
			'multiline.trailingComma.closureUse' => 'keep', 'multiline.trailingComma.import' => 'optional',
			'multiline.trailingComma.list' => 'optional',
		],
		Rules\Whitespace\IndentationRule::class => [
			'indentation.unit' => 'tab', 'indentation.binaryOperator' => 0, 'indentation.ternary' => 1,
			'indentation.switchCase' => 1, 'indentation.chain' => 'flat',
		],
	], "<?php\n\$a = [\n\t1,\n\t2\n] + [\n\t\t3,\n];\n", "<?php\n\$a = [\n\t1,\n\t2,\n] + [\n\t3,\n];\n");
	Assert::same([null, null], array_map(fn(Violation $violation) => $violation->derivedFrom, $result->violations));
});


test('a line a structural rewrite moves follows the rewrite, so that its new indentation is no finding of its own', function () {
	$result = interplay([
		Rules\ControlFlow\TernaryForIfRule::class => true,
		Rules\Whitespace\IndentationRule::class => [
			'indentation.unit' => 'tab', 'indentation.binaryOperator' => 0, 'indentation.ternary' => 1,
			'indentation.switchCase' => 1, 'indentation.chain' => 'flat',
		],
	], "<?php\nfunction f(\$a)\n{\n\tif (\$a) {\n\t\treturn g(\n\t\t\t\t1,\n\t\t);\n\t} else {\n\t\treturn 2;\n\t}\n}\n", "<?php\nfunction f(\$a)\n{\n\treturn \$a\n\t\t? g(\n\t\t\t1,\n\t\t)\n\t\t: 2;\n}\n");
	$byRule = array_column($result->violations, null, 'decision');
	Assert::null($byRule['controlFlow.ifElseReturning']->derivedFrom);
	Assert::same($byRule['controlFlow.ifElseReturning']->fingerprint, $byRule['indentation.unit']->derivedFrom);
});


test('a comment commentSpacing rewrites keeps its line for the rule that reports it next', function () {
	$result = interplay([
		Rules\Comments\CommentSpacingRule::class => ['spacing.comment.marker' => '// text'],
		Rules\Comments\NoHashCommentsRule::class => true,
	], "<?php\n\$a = 1;\n#foo\n\$b = 2;\n", "<?php\n\$a = 1;\n// foo\n\$b = 2;\n");
	Assert::same([3, 3], array_map(fn(Violation $violation) => $violation->line, $result->violations));
});
