<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Config\PluginRegistry;
use DressCode\Console\{CommandContext, Markup, ProgressBar};
use DressCode\Engine\{FileSummary, Helpers, Runner, RunResult};
use DressCode\{Reporter, Reporters};
use Nette\CommandLine\{Attributes as Cli, Console, ParseResult, UsageException};
use Nette\Utils\FileSystem;
use function count, in_array, is_string, sprintf, strlen;


/**
 * The commands `check` and `fix`: the run over the files of the project, or over the code of stdin.
 * @internal
 */
final readonly class CheckCommand
{
	public function __construct(
		private CommandContext $context,
	) {
	}


	#[Cli\Command('Report the violations')]
	public function check(ParseResult $args, RunOptions $run, CheckOptions $check, ConfigOptions $configuration): int
	{
		return $this->run($args, $configuration, $run, $check);
	}


	#[Cli\Command('Fix what the rules can and report the rest')]
	public function fix(ParseResult $args, RunOptions $run, CheckOptions $check, ConfigOptions $configuration): int
	{
		return $this->run($args, $configuration, $run, $check, fix: true);
	}


	private function run(ParseResult $args, ConfigOptions $configuration, RunOptions $run, CheckOptions $check, bool $fix = false): int
	{
		// `fix --stdin` is the formatter of an editor, which reads the text and the exit code alone
		$formatter = $fix && $check->stdin !== null;
		foreach ([
			'--format' => $run->format,
			'--diff' => $check->diff,
			'--max-warnings' => $check->maxWarnings,
		] as $option => $value) {
			if ($formatter && $value !== null) {
				throw new UsageException(
					"`fix --stdin` writes the fixed code alone, so it takes no `$option`; `check --stdin` reports on the code in any format.",
					$args->command,
				);
			}
		}

		[$factory, $resolution, $configFile] = $this->context->resolveProject($args, $configuration, $check->only);
		[$config, $root] = [$resolution->config, $resolution->root];
		$runner = $factory->createRunner(
			$resolution,
			strict: (bool) $run->strictRules,
			fixRisky: (bool) $check->fixRisky,
		);
		[$out, $err] = [$this->context->out, $this->context->err];
		foreach ($formatter ? [] : $resolution->warnings as $warning => $docs) {
			$err->writeLine(Markup::highlightCode($err, "Warning: $warning", 'yellow'));
			if ($docs !== null) {
				$err->writeLine(Markup::formatDocsLink($err, $docs));
			}
		}

		$stdinPath = $check->stdin;
		$paths = array_values(array_unique(array_map($this->context->resolvePath(...), $run->paths)));
		$maxWarnings = $check->maxWarnings;
		$format = self::resolveFormat($run->format, detect: true);

		if (is_string($stdinPath)) {
			if ($paths) {
				throw new UsageException('Paths cannot be combined with `--stdin`.', $args->command);
			}

			return $formatter
				? $this->runFormatter($runner, $stdinPath, (bool) $run->skipExcluded, $config->paths)
				: $this->runStdin(
					self::resolveFormat($run->format, detect: false), // the caller of stdin is an editor or a hook waiting for the format it asked for
					(bool) $check->diff,
					$runner,
					$factory->registry,
					$root,
					$stdinPath,
					$maxWarnings,
					(bool) $run->skipExcluded,
					$config->paths,
				);
		}

		$scope = $paths ? $runner->narrowPaths($paths, $config->paths) : $config->paths;
		if (!$scope) {
			throw new UsageException('No paths given and none configured.', $args->command);
		}

		// the machine-readable formats must not be prefaced
		$preface = in_array($format, ['console', 'github'], true);
		if ($preface) {
			if ($this->context->xdebug) {
				$err->writeLine('Warning: Xdebug is loaded and makes the run many times slower.', 'red');
			}

			$this->context->writeHeader($configFile, $resolution);
			$out->setStatus($out->color('gray', $fix ? 'Fixing     ' : 'Checking   ') . 'looking for files…');
		}

		try {
			$files = $runner->findFiles($scope, skipExcluded: (bool) $run->skipExcluded, configured: $config->paths);
		} finally {
			$out->clearStatus();
		}

		if ($preface) {
			$this->writeScope(files: $files, paths: $scope, root: $root, fix: $fix, narrowed: $paths && $scope !== $paths);
		}

		$progress = $format === 'console' && count($files) > 1 && $out->isTerminal()
			? new ProgressBar($out, count($files))
			: null;
		$onProgress = $progress === null ? null : $progress->advance(...);

		try {
			$reporter = $this->createReporter((bool) $check->diff, $out, $this->context->stdout, $root, $format, $factory->registry);
			return $runner->run($files, $fix, $reporter, $onProgress, $maxWarnings)->getExitCode();
		} finally {
			$progress?->clear(); // an error must not be written into the bar
		}
	}


	/**
	 * Reports on the code read from stdin as if it were the file at the path; nothing of a path the excluded ones leave
	 * out where the caller asks to skip it.
	 * @param  list<string>  $configured
	 */
	private function runStdin(
		string $format,
		bool $diff,
		Runner $runner,
		PluginRegistry $registry,
		string $root,
		string $path,
		?int $maxWarnings,
		bool $skipExcluded,
		array $configured,
	): int
	{
		$reporter = $this->createReporter($diff, $this->context->out, $this->context->stdout, $root, $format, $registry);
		$code = (string) stream_get_contents($this->context->stdin);
		$absolute = $this->context->resolvePath($path);
		$skipped = $skipExcluded && $runner->excludes($absolute, $configured);
		$reporter->start($runner->createRunInfo(false, $skipped ? 0 : 1));
		$result = $skipped ? null : $runner->processPath($absolute, fix: false, code: $code);
		if ($result) {
			$reporter->reportFile($result);
		}

		$run = new RunResult($result ? [FileSummary::of($result)] : [], fix: false, maxWarnings: $maxWarnings);
		$reporter->finish($run);
		return $run->getExitCode();
	}


	/**
	 * Formats the code read from stdin as if it were the file at the path, for an editor: the whole fixed code goes to
	 * stdout with exit code 0, whatever it still violates; a syntax error or a failure leaves the code as it came, its
	 * one line on stderr, and so does a path the excluded ones leave out, without a word.
	 * @param  list<string>  $configured
	 */
	private function runFormatter(Runner $runner, string $path, bool $skipExcluded, array $configured): int
	{
		$code = (string) stream_get_contents($this->context->stdin);
		$path = $this->context->resolvePath($path);
		$result = $skipExcluded && $runner->excludes($path, $configured)
			? null
			: $runner->processPath($path, fix: false, code: $code);
		[$output, $error, $exit] = match (true) {
			$result?->syntaxError !== null => [$code, "$result->path:$result->syntaxErrorLine: Syntax error: $result->syntaxError", 1],
			$result?->failure !== null => [$code, "$result->path: " . strtok($result->failure, "\n"), 2],
			default => [$result->output ?? $code, null, 0],
		};

		if ($error !== null) {
			fwrite($this->context->stderr, "$error\n");
		}

		return fwrite($this->context->stdout, $output) === strlen($output) ? $exit : 2;
	}


	/**
	 * What the rules are applied to: the scope reaches wherever the configuration was found,
	 * not where the run was started.
	 * @param  list<string>  $files  relative to the root, or absolute when outside it
	 * @param  list<string>  $paths  they were found under these
	 * @param  bool  $narrowed  a directory named on the command line was narrowed to the configured paths
	 */
	private function writeScope(array $files, array $paths, string $root, bool $fix, bool $narrowed): void
	{
		$out = $this->context->out;
		$absolute = array_map(fn(string $file) => FileSystem::isAbsolute($file) ? $file : "$root/$file", $files);
		$scope = count($files) === 1
			? FileSystem::platformSlashes($absolute[0])
			: sprintf('%d files in %s', count($files), FileSystem::platformSlashes(self::findCommonDirectory($absolute) ?: $root));
		$out->write($files
			? $out->color('gray', $fix ? 'Fixing     ' : 'Checking   ') . $scope
				. ($narrowed ? $out->color('gray', ', narrowed to the configured paths') : '') . "\n\n"
			: $out->color('yellow', sprintf(
				($fix ? 'Nothing to fix' : 'Nothing to check') . ': no file in %s',
				implode(', ', array_map(FileSystem::platformSlashes(...), $paths)),
			)) . "\n");
	}


	/**
	 * The directory all the files share; that is the scope the run really has.
	 * @param  list<string>  $files
	 */
	private static function findCommonDirectory(array $files): string
	{
		$common = null;
		foreach ($files as $file) {
			$segments = explode('/', $file);
			array_pop($segments);
			if ($common === null) {
				$common = $segments;
				continue;
			}

			$length = 0;
			while (isset($common[$length], $segments[$length]) && $common[$length] === $segments[$length]) {
				$length++;
			}

			$common = array_slice($common, 0, $length);
		}

		return implode('/', $common ?? []);
	}


	/**
	 * @param  resource  $stream  the same place as the console; a machine-readable format is no text of a console
	 * @param  string  $root  the paths of the results are relative to it
	 */
	private function createReporter(
		bool $diff,
		Console $console,
		$stream,
		string $root,
		string $format,
		PluginRegistry $registry,
	): Reporter
	{
		return match ($format) {
			'json' => new Reporters\JsonReporter($stream),
			'checkstyle' => new Reporters\CheckstyleReporter($stream),
			'github' => new Reporters\GithubReporter($stream, $root, self::findEnv('GITHUB_WORKSPACE')),
			default => new Reporters\ConsoleReporter(
				$console,
				diff: $diff,
				root: $root,
				cwd: Helpers::canonicalizePath($this->context->workingDirectory),
				findDecisionUrl: $registry->findUrl(...),
			),
		};
	}


	/**
	 * The format asked for, or the one the surroundings call for: annotations when the run is a step
	 * of a GitHub Actions workflow, where nobody reads the log.
	 * @param  bool  $detect  let the surroundings decide when the command line does not
	 */
	private static function resolveFormat(?string $format, bool $detect): string
	{
		return $format ?? ($detect && self::findEnv('GITHUB_ACTIONS') === 'true' ? 'github' : 'console');
	}


	private static function findEnv(string $name): ?string
	{
		$value = getenv($name);
		return $value === false || $value === '' ? null : $value;
	}
}
