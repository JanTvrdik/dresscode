<?php declare(strict_types=1);

use DressCode\Engine\LineRanges;
use DressCode\{FileResult, Risk, Severity, Violation};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @param ?array{int, int} $lines */
function rangedViolation(string $message, ?array $lines): Violation
{
	return new Violation('test/a', $message, $lines[0] ?? 1, null, Severity::Error, fingerprint: $message, originalLines: $lines);
}


test('a range is a line or two of them, counted from 1, the first not after the second', function () {
	Assert::same([20, 21], LineRanges::parseRange('20-21'));
	Assert::same([7, 7], LineRanges::parseRange('7'));
	Assert::same([3, 3], LineRanges::parseRange('3-3'));
	foreach (['0', '0-2', '5-2', '-1', '2-', 'x', '1,2', ' 1', '01'] as $value) {
		Assert::null(LineRanges::parseRange($value), $value);
	}
});


test('a violation is listed where its lines reach into a range, or where the run cannot place it', function () {
	$violations = [
		rangedViolation('before', [1, 1]),
		rangedViolation('reaching in', [2, 4]),
		rangedViolation('within', [5, 5]),
		rangedViolation('unplaced', null),
		rangedViolation('after', [7, 8]),
	];
	$result = new FileResult('a.php', str_repeat("x\n", 8), null, $violations, remaining: [$violations[0], $violations[4]]);

	$check = new LineRanges([[4, 5]])->select($result, fix: false);
	Assert::same(['reaching in', 'within', 'unplaced'], array_map(fn($v) => $v->message, $check->violations));
	Assert::same([], $check->remaining);
	Assert::same(2, $check->unlisted);

	// a fix counts what it leaves, and ranges join
	$fix = new LineRanges([[1, 1], [8, 8]])->select($result, fix: true);
	Assert::same(['before', 'after'], array_map(fn($v) => $v->message, $fix->remaining));
	Assert::same(0, $fix->unlisted);
});


test('a range past the end of the file is its last line, the line ending of which opens no line', function () {
	$result = new FileResult('a.php', "a\nb\n", null, [rangedViolation('last', [2, 2])]);
	Assert::same(0, new LineRanges([[3, 9]])->select($result, fix: false)->unlisted);
	Assert::same(1, new LineRanges([[1, 1]])->select($result, fix: false)->unlisted);
});


test(
	'knowing the file before the edit, what it kept is listed anywhere, what it broke only where judgement is due',
	function () {
		$violation = fn(string $decision, int $line, bool $fixable = true, ?Risk $risk = null) => new Violation(
			$decision,
			"$decision $line",
			$line,
			null,
			Severity::Error,
			fingerprint: "$decision $line",
			fixable: $fixable,
			risk: $risk,
			originalLines: [$line, $line],
		);
		$remaining = [
			$violation('kept.unused', 1, fixable: false),
			$violation('left.quotes', 2),
			$violation('left.quotes', 5),
			$violation('left.loose', 5, risk: Risk::TypeUnknown),
			$violation('left.loose', 7, risk: Risk::TypeUnknown),
			$violation('left.silence', 5, fixable: false),
		];
		$result = new FileResult('a.php', str_repeat("x\n", 8), null, remaining: $remaining);
		$fix = new LineRanges([[5, 5]], ['left.quotes' => true, 'left.loose' => true, 'left.silence' => true])->select($result, fix: true);
		Assert::same(['kept.unused 1', 'left.loose 5', 'left.silence 5'], array_map(fn($v) => $v->message, $fix->remaining));
		Assert::same(3, $fix->unlisted);
		Assert::same(['left.quotes'], $fix->leftDecisions); // the risky one outside the lines is no style to follow
	},
);
