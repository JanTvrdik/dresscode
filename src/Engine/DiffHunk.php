<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;


/**
 * One change of a unified diff with its context.
 * @internal
 */
final readonly class DiffHunk
{
	/** `@@ -12,3 +10,3 @@` */
	public string $header;


	public function __construct(
		/** the first line of the old side, from 1; the line before the change where the side has none */
		public int $oldStart,
		public int $oldLines,
		public int $newStart,
		public int $newLines,
		/** @var list<string>  each prefixed with `' '`, `'-'` or `'+'`, without its line ending */
		public array $lines,
	) {
		$this->header = "@@ -$oldStart,$oldLines +$newStart,$newLines @@";
	}
}
