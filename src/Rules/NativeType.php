<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use PHPStan\PhpDocParser\Ast\Type;
use function in_array;


/**
 * What the rules ask of a doc comment type beside a native one: whether the two say the same, and whether a type is
 * iterable.
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
	 * Whether the annotation says exactly what the native type says: an identifier, a nullable one or a union
	 * of identifiers naming the same types; `int[]` or `array<string, Foo>` say more than `array`.
	 * @param  ?callable(string): string  $resolveClass  fully qualified name of a class, so that an imported `Order` and
	 *   `\Acme\Shop\Order` are one
	 */
	public static function matches(Type\TypeNode $annotation, string $native, ?callable $resolveClass = null): bool
	{
		return self::isPlain($annotation)
			&& self::normalize((string) $annotation, $resolveClass) === self::normalize($native, $resolveClass);
	}


	private static function isPlain(Type\TypeNode $type): bool
	{
		if ($type instanceof Type\UnionTypeNode) {
			return array_all($type->types, fn(Type\TypeNode $member) => self::isPlain($member));
		}

		return match (true) {
			$type instanceof Type\IdentifierTypeNode => !in_array(strtolower($type->name), ['static', '$this'], true),
			$type instanceof Type\NullableTypeNode => self::isPlain($type->type),
			default => false,
		};
	}


	/**
	 * Canonical form of a type: no parentheses or spaces, ?T as T|null, members sorted, builtins lowercased, classes
	 * resolved where a resolver is given and without their backslashes otherwise.
	 * @param  ?callable(string): string  $resolveClass
	 */
	private static function normalize(string $type, ?callable $resolveClass = null): string
	{
		$type = str_replace(['(', ')', ' '], '', $type);
		if (str_starts_with($type, '?')) {
			$type = substr($type, 1) . '|null';
		}

		$members = array_map(
			fn(string $member) => match (true) {
				in_array(strtolower($member), self::Builtin, true) => strtolower($member),
				$resolveClass === null => str_replace('\\', '', $member),
				// a type of the analyzers, `non-empty-string`, is no class to resolve
				preg_match('~^\\\?[A-Za-z_\x80-\xff][\w\x80-\xff\\\]*$~', $member) !== 1 => strtolower($member),
				default => strtolower(ltrim($resolveClass($member), '\\')),
			},
			explode('|', $type),
		);
		sort($members);
		return implode('|', $members);
	}


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
