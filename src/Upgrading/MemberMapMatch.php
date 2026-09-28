<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrading;

use DressCode\Analyses\MemberAccess;


/**
 * The entry of a map of members an access was found under, the access, and how the arguments of the call bind to the
 * key where the call was asked about.
 * @template T
 */
final readonly class MemberMapMatch
{
	/** @internal made by the lookups of `MemberMap` */
	public function __construct(
		/** @var MemberMapEntry<T> */
		public MemberMapEntry $entry,
		public MemberAccess $access,
		/** null where the arguments were not asked about */
		public ?ArgumentBindings $bindings = null,
	) {
	}
}
