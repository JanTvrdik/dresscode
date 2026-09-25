<?php

namespace Acme\Text;

/** @deprecated use format() */
function format_old(string $s): string
{
	return $s;
}


function format(string $s): string
{
	return $s;
}


/** @deprecated the encoding is detected */
function detect(string $s): string
{
	return $s;
}


/** @deprecated use Acme\Html\escape() */
function escape_old(string $s): string
{
	return $s;
}


namespace Acme\Html;

function escape(string $s): string
{
	return $s;
}


namespace App;

/** @deprecated use run() */
function strlen(string $s): int
{
	return 0;
}
