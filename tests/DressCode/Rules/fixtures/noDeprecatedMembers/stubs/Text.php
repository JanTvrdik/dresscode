<?php

namespace Acme\Text;

class Text
{
	/** @deprecated use Encoding::isValid() */
	public static function check(string $s): bool
	{
		return Encoding::isValid($s);
	}


	/** @deprecated use Encoding::hidden() */
	public static function guard(string $s): bool
	{
		return true;
	}


	/** @deprecated use Encoding::isValid() */
	public function verify(string $s): bool
	{
		return Encoding::isValid($s);
	}
}

class Encoding
{
	public static function isValid(mixed $value): bool
	{
		return true;
	}


	private static function hidden(string $s): bool
	{
		return true;
	}
}
