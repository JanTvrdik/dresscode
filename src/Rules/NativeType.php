<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;


/**
 * The names of the native types, as a doc comment may write them, and whether a type is iterable.
 * @internal
 */
final class NativeType
{
	public const Builtin = [
		'int', 'float', 'string', 'bool', 'array', 'iterable', 'callable', 'object', 'mixed', 'null', 'void', 'never',
		'false', 'true', 'self', 'static', 'parent',
	];

	/** the other names PHP gives a scalar type, which a doc comment may write */
	public const Synonyms = ['integer' => 'int', 'boolean' => 'bool', 'double' => 'float'];

	private const Iterable = ['array', 'iterable'];


	/**
	 * Whether the native type is iterable, or a class configured as traversable.
	 * @param  list<string>  $traversableClasses  lowercased fully qualified names
	 * @param  callable(string): string  $resolveClass
	 */
	public static function isTraversable(string $name, array $traversableClasses, callable $resolveClass): bool
	{
		return in_array(strtolower($name), self::Iterable, true)
			|| (
				preg_match('~^\\\?[A-Za-z_\x80-\xff][\w\x80-\xff\\\]*$~', $name) === 1
				&& !in_array(strtolower($name), self::Builtin, true)
				&& in_array(strtolower(ltrim($resolveClass($name), '\\')), $traversableClasses, true)
			);
	}
}
