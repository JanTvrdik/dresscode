<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrading;


/** What a key of a map of members is of, as it is written, static or not alike. */
enum MemberPatternKind
{
	/** `Class::name`, whichever of the two the code accesses; `Class::NAME` without a lower-case letter is a constant only */
	case ConstantOrMethod;

	/** `Class::name()` */
	case Method;

	/** `Class::$name` */
	case Property;

	/** `Class::__construct()` */
	case Constructor;
}
