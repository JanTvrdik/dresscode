<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Reporters;

use DressCode\Engine\{RunInfo, RunResult};
use DressCode\{FileResult, Reporter, Violation};
use Nette\Utils\FileSystem;
use function strlen;


/**
 * One line for every event, in the shape compilers write, which the generic linters of the editors read and a person
 * reads as the output of a compiler; a clean run prints nothing, and so does a rewritten file nothing is left in:
 *
 *     src/Order.php:20:9: error: The `@` operator must not be used. [mistakes.errorSuppression]
 *     src/Order.php:21:13: warning: The `==` comparison must be written `===`. [expressions.comparison.loose; risk=typeUnknown]
 *     src/Order.php:4: error: Syntax error: Unexpected `;`
 *     src/Order.php: failure: Rule `Acme\Rule` failed in `src/Order.php`: …
 *
 * The column is where the span of the violation starts, when it starts on the line of the violation, else the column of
 * the reported token. A fix run lists what the fixed text still violates, at its lines in that text. The path is
 * relative to the working directory, absolute outside it, with forward slashes; a line break in a message becomes a space.
 * @internal
 */
final class LinesReporter implements Reporter
{
	/** @var resource */
	private $stream;
	private bool $fix = false;


	/**
	 * @param  ?resource  $stream
	 */
	public function __construct(
		$stream = null,
		/** the paths of the results are relative to it */
		private readonly string $root = '',
		/** a file under it is reported relative to it, the others absolutely */
		private readonly string $cwd = '',
	) {
		$this->stream = $stream ?? STDOUT;
	}


	public function start(RunInfo $run): void
	{
		$this->fix = $run->fix;
	}


	public function reportFile(FileResult $result): void
	{
		$path = $this->formatPath($result->path);
		if ($result->failure !== null) {
			$this->write("$path: failure: " . self::flatten($result->failure));
		}

		if ($result->syntaxError !== null) {
			$this->write("$path:" . ($result->syntaxErrorLine ?? 1) . ': error: Syntax error: ' . self::flatten($result->syntaxError));
		}

		foreach ($result->warnings as $warning) {
			$this->write("$path: warning: " . self::flatten($warning));
		}

		foreach ($this->fix ? $result->remaining : $result->violations as $violation) {
			[$line, $column] = $violation->findSpanStart($this->fix ? $result->output ?? $result->code : $result->code) ?? [null, null];
			$column = $line === $violation->line ? $column : $violation->column;
			$this->write($path . ':' . $violation->line . ($column === null ? '' : ":$column")
				. ': ' . $violation->severity->value . ': ' . self::flatten($violation->message) . ' ' . self::formatTags($violation));
		}
	}


	public function finish(RunResult $result): void
	{
		foreach ($result->warnings as $warning) {
			$this->write('dresscode: warning: ' . self::flatten($warning));
		}
	}


	/** The decision, and the risk where the fix of the occurrence may change what the code does. */
	private static function formatTags(Violation $violation): string
	{
		return '[' . $violation->decision . ($violation->risk === null ? '' : '; risk=' . $violation->risk->value) . ']';
	}


	/** Relative to the working directory when the file lies under it, absolute otherwise, with forward slashes. */
	private function formatPath(string $path): string
	{
		// a path outside the root is absolute already
		$path = strtr($this->root === '' || FileSystem::isAbsolute($path) ? $path : "$this->root/$path", '\\', '/');
		$cwd = strtr($this->cwd, '\\', '/');
		return $cwd !== '' && str_starts_with($path, "$cwd/") ? substr($path, strlen($cwd) + 1) : $path;
	}


	/** The text on one line, a line break and any other control character a space. */
	private static function flatten(string $text): string
	{
		return trim((string) preg_replace('~[\x00-\x1F\x7F]+~', ' ', $text));
	}


	private function write(string $line): void
	{
		fwrite($this->stream, "$line\n");
	}
}
