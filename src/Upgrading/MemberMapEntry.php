<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrading;


/**
 * An entry of a map of members: its key and what the rule keeps of its value.
 * @template T
 */
final readonly class MemberMapEntry
{
	/** @internal made by `MemberMap` */
	public function __construct(
		public MemberPattern $pattern,
		/** @var T */
		public mixed $value,
	) {
	}
}
