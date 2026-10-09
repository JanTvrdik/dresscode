<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{FileResult, Violation};
use function count;


/**
 * The lines of the file as read a run reports on, both ends included; a range past the end of the file is the last
 * line. The violations outside them are left out of the report and its exit code, and only counted: what the rules
 * fix, what a comment silences and what the cache keeps stay those of the whole file. Where the run knows the file as
 * it was before the edit, the decisions it kept are reported wherever they are broken, the edit having broken them,
 * and the decisions it broke, which the run leaves as the file writes them, only where they wait for a judgement
 * (a risky fix, none at all) on these lines.
 * @internal
 */
final readonly class LineRanges
{
	public function __construct(
		/** @var list<array{int, int}> */
		private array $ranges,
		/** @var ?array<string, true>  the decisions the file broke before the edit; null where the run does not know that file */
		private ?array $left = null,
	) {
	}


	/**
	 * Parses a range written as `20-21` or `20`; null for anything else, a line counting from 1.
	 * @return ?array{int, int}
	 */
	public static function parseRange(string $value): ?array
	{
		if (!preg_match('~^([1-9]\d*)(?:-([1-9]\d*))?$~D', $value, $m)) {
			return null;
		}

		$range = [(int) $m[1], (int) ($m[2] ?? $m[1])];
		return $range[0] <= $range[1] ? $range : null;
	}


	/**
	 * The result reporting what the lines hold: the violations found in a check, what the fix leaves in a fix; the
	 * one the run cannot place in the file as read is reported whatever the lines.
	 */
	public function select(FileResult $result, bool $fix): FileResult
	{
		// the line ending of the last line opens no line of its own
		$last = max(1, preg_match_all('~\r\n|\r|\n~', $result->code) + (preg_match('~[\r\n]$~D', $result->code) ? 0 : 1));
		$within = fn(Violation $v) => $v->originalLines === null || $this->contains($v->originalLines, $last);
		$listed = $this->left === null
			? $within
			: fn(Violation $v) => !isset($this->left[$v->decision]) || (($v->risk !== null || !$v->fixable) && $within($v));
		$violations = array_values(array_filter($result->violations, $listed));
		$remaining = array_values(array_filter($result->remaining, $listed));
		$unlisted = array_filter($fix ? $result->remaining : $result->violations, fn(Violation $v) => !$listed($v));
		// the style of the file, which the code written there is to follow; what waits for a judgement is no style
		$styled = array_filter($unlisted, fn(Violation $v) => $v->fixable && $v->risk === null);
		$leftDecisions = $this->left === null ? [] : array_values(array_unique(array_column($styled, 'decision')));
		sort($leftDecisions);
		return $result->withListed($violations, $remaining, count($unlisted), $leftDecisions);
	}


	/** @param  array{int, int}  $lines */
	private function contains(array $lines, int $last): bool
	{
		foreach ($this->ranges as [$from, $to]) {
			if (min($from, $last) <= $lines[1] && $lines[0] <= min($to, $last)) {
				return true;
			}
		}

		return false;
	}
}
