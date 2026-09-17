<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{ConfigurationException, ConvergenceException, FileResult, RuleException};


/**
 * Processes the files of one kind, told by their extension: a pure function of the path and the text.
 * @internal
 */
interface FileProcessor
{
	/**
	 * @param  array<string, true>  $acceptedRisks  fingerprints of the occurrences whose risky fix is allowed on top of
	 *   what the configuration allows
	 * @throws RuleException|ConvergenceException|ConfigurationException
	 */
	function process(string $path, string $code, array $acceptedRisks = []): FileResult;

	/**
	 * The decisions the rules report in this run.
	 * @return list<string>
	 */
	function getReportedDecisions(): array;
}
