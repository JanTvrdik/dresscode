<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use PhpSyntax\SymbolKind;


/**
 * What the rules of names share: the references of the names of a namespace with the form each is written in, the
 * class names its doc comments write and the imports.
 * @internal
 */
final class NameReferences
{
	/** The key a name is looked up by: a constant is case-sensitive, a class and a function are not. */
	public static function toKey(SymbolKind $kind, string $name): string
	{
		return $kind === SymbolKind::Constant ? $name : strtolower($name);
	}
}
