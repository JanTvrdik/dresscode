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
	/** @throws RuleException|ConvergenceException|ConfigurationException */
	function process(string $path, string $code): FileResult;

	/**
	 * The decisions the rules report in this run.
	 * @return list<string>
	 */
	function getReportedDecisions(): array;
}
