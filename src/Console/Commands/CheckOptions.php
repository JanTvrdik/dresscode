<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use Nette\CommandLine\{Attributes as Cli, Normalizers};


/**
 * The inputs `check` and `fix` have beyond those of the run, which a baseline does not take: it holds what the
 * configuration as it is leaves.
 * @internal
 */
final readonly class CheckOptions
{
	public function __construct(
		#[Cli\Flag('Show the fixes as a unified diff (`console` format)')]
		public ?bool $diff = null,
		#[Cli\Option(
			'Read the code from stdin as if it were the file at the path; `fix` is then the formatter of an editor and writes the fixed code alone to stdout',
			valueName: 'path',
		)]
		public ?string $stdin = null,
		#[Cli\Option(
			'Exit with `1` when more than `n` warnings are left; without it any number of warnings keeps the run clean',
			valueName: 'n',
			normalizer: [self::class, 'parseMaxWarnings'],
		)]
		public ?int $maxWarnings = null,
		/** @var list<(string | true)> */
		#[Cli\Option(
			'Also make the fixes that may change what the code does, of every rule, or with `=name` of that decision, rule or preset, beyond those `fixRisky` allows; they are reported either way',
			valueName: 'name',
			valueOptional: true,
			repeatable: true,
		)]
		public array $fixRisky = [],
		/** @var list<string> */
		#[Cli\Option(
			'Only these: a decision, a section, a rule, a preset, or a package of upgrading data or a library for the maps of the files about it and the rules reading them, narrowed to what the configuration runs; the values stay as the configuration resolves them',
			valueName: 'name',
			repeatable: true,
		)]
		public array $only = [],
	) {
	}


	public static function parseMaxWarnings(string $value): int
	{
		return Normalizers::int($value, min: 0);
	}
}
