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
 * fix, what a comment silences and what the cache keeps stay those of the whole file.
 * @internal
 */
final readonly class LineRanges
{
	public function __construct(
		/** @var list<array{int, int}> */
		private array $ranges,
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
		$violations = array_values(array_filter($result->violations, $within));
		$remaining = array_values(array_filter($result->remaining, $within));
		$unlisted = $fix
			? count($result->remaining) - count($remaining)
			: count($result->violations) - count($violations);
		return $result->withListed($violations, $remaining, $unlisted);
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
