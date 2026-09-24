<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrading;

use DressCode\Analyses\MemberAccess;


/**
 * The entry of a map of members an access was found under, and the access.
 * @template T
 */
final readonly class MemberMapMatch
{
	/** @internal made by the lookups of `MemberMap` */
	public function __construct(
		/** @var MemberMapEntry<T> */
		public MemberMapEntry $entry,
		public MemberAccess $access,
	) {
	}
}
