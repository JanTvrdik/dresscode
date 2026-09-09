<?php declare(strict_types=1);

namespace Acme\Mail;

interface Transport
{
	public function send(string $message, int $priority = 0): void;
}

class Mailer
{
	public function __construct(string $host, int $port = 25)
	{
	}


	public function deliver(string $to, int $priority = 0, bool $html = false): void
	{
	}


	public static function create(string $host, int $timeout = 30): self
	{
		return new self($host);
	}


	public function header(string $name, ?string $value = null): ?string
	{
		return func_num_args() === 1 ? $name : $value;
	}
}

class Queue
{
	public function deliver(string $to, int $priority = 1, bool $html = false): void
	{
	}
}
