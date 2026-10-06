<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrading;


/**
 * An entry of a map of members: its key, what the rule keeps of its value, and the decision a use of it is reported
 * under, the map itself or the requirement that laid the upgrading data holding it.
 * @template T
 */
final readonly class MemberMapEntry
{
	/** @internal made by `MemberMap` */
	public function __construct(
		public MemberPattern $pattern,
		/** @var T */
		public mixed $value,
		public string $decision,
	) {
	}
}
