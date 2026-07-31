<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * How a project puts the names of its imports into `use` statements: the decisions `imports.grouping.*` that give
 * each kind the shapes that pass, `separated`, `combined` and `grouped`.
 */
final readonly class ImportStyle
{
	/** the name of a kind => its word in the path of its decision, `imports.grouping.class` */
	public const Kinds = ['ClassLike' => 'class', 'Function' => 'function', 'Constant' => 'constant'];

	/** the decisions of a tree of the core the style is read from, which every catalogue knows */
	public const Decisions = ['imports.grouping.class', 'imports.grouping.function', 'imports.grouping.constant'];
}
