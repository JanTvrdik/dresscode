<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;


/**
 * The processors of a run: one for every kind of file, told by its extension, and for every combination of overrides
 * a file can match. A variant is built once and reused, because a rule carries the options it was configured with
 * and must never be reconfigured under a file that is already being processed.
 * @internal
 */
final class FileProcessors
{
	/** @var array<string, FileProcessor>  the extension with a builder of its own, or none, and the matching overrides => the processor */
	private array $processors = [];


	public function __construct(
		/** @var list<list<string>> the patterns of every override, in the order they were written */
		private readonly array $patterns,
		/** @var \Closure(list<int>): FileProcessor builds the processor of a file whose extension has no builder of its own */
		private readonly \Closure $build,
		/** @var array<string, \Closure(list<int>): FileProcessor> extension in lower case => the builder of the processor of such files */
		private readonly array $builders = [],
	) {
	}


	/** A run with no overrides and one kind of file: one processor for every file. */
	public static function of(FileProcessor $processor): self
	{
		return new self([], fn() => $processor);
	}


	public function getBase(): FileProcessor
	{
		return $this->processors[':'] ??= ($this->build)([]);
	}


	public function get(string $path): FileProcessor
	{
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$build = $this->builders[$extension] ?? null;
		$overrides = $this->findOverrides($path);
		return $this->processors[($build === null ? '' : $extension) . ':' . implode(',', $overrides)]
			??= ($build ?? $this->build)($overrides);
	}


	/**
	 * Indexes of the overrides the path matches, in the order they were written.
	 * @return list<int>
	 */
	public function findOverrides(string $path): array
	{
		$overrides = [];
		foreach ($this->patterns as $index => $patterns) {
			if (Helpers::matchesAny($patterns, $path)) {
				$overrides[] = $index;
			}
		}

		return $overrides;
	}
}
