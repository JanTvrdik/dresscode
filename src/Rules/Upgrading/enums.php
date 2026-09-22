<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;


/**
 * What a forbidden map says of a use: the project forbids it, or the library whose upgrading data hold the entry
 * deprecated it, its installed version still having the symbol, or removed it; forbidden too where the run cannot
 * tell, having no types.
 * @internal
 */
enum Retirement: string
{
	case Forbidden = 'forbidden';
	case Deprecated = 'deprecated';
	case Removed = 'removed';


	public static function of(bool $fromPackage, ?bool $exists): self
	{
		return match (true) {
			!$fromPackage, $exists === null => self::Forbidden,
			$exists => self::Deprecated,
			default => self::Removed,
		};
	}
}
