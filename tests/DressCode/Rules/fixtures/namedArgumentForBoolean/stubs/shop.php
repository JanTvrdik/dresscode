<?php declare(strict_types=1);

namespace Acme\Shop;

class Cart
{
	public function __construct(bool $persistent = false)
	{
	}


	public function add(string $item, int $count = 1, bool $replace = false, ?bool $notify = null): void
	{
	}


	public static function create(bool $persistent): static
	{
		return new static($persistent);
	}


	public function setVisible(bool $visible): void
	{
	}


	public function store(mixed $value, $raw = false): void
	{
	}
}

final class Order
{
	public function close(bool $force = false): void
	{
	}


	public function open(bool $force = false): void
	{
	}
}

interface Notifier
{
	public function notify(string $message, bool $urgent = false): void;
}

function format(string $text, bool $escape = true): string
{
	return $text;
}
