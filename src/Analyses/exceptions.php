<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * The code of the file is not code PHP compiles, which the parser of PhpSyntax took and an analysis does not.
 */
final class UncompilableCodeException extends \Exception
{
}


/**
 * PHPStan failed on the file, mostly on syntax its version does not support yet, so the nodes it did not reach have no
 * types.
 */
final class TypesUnavailableException extends \Exception
{
}
