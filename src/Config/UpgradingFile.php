<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{ConfigurationException, Profile};
use Nette\Neon\{Exception as NeonException, Neon};
use function is_array, is_string;


/**
 * An upgrading file as it is written: the package whose versions its sections are of, what the namespaces of the
 * package declare, and the maps every section brings with the requirements of the rules of the plugin of the package it
 * turns on, which `selectFor()` lays out for the versions of the package the code must work with.
 * @internal
 */
final readonly class UpgradingFile
{
	/** the maps of what the code must not have, by which what a version above the lowest one retires is reported */
	private const ForbiddenMaps = ['forbiddenClasses', 'forbiddenFunctions', 'forbiddenConstants'];

	private const SectionPattern = '~^since (\d+(?:\.\d+)*)$~D';
	private const PackagePattern = '~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D';


	private function __construct(
		/** the package the sections are versions of */
		public string $package,
		/** @var array{functions: list<string>, constants: list<string>}  what the package declares in its namespaces, fully qualified */
		public array $namespaces,
		/** @var array<string, array<string, array<string, mixed>>>  version => map => its entries, the oldest first */
		private array $sections,
		/** @var array<string, array<string, string>>  version => a requirement of the plugin of the package => its value */
		private array $requirements = [],
	) {
	}


	/**
	 * Reads the file.
	 * @throws ConfigurationException
	 */
	public static function fromFile(string $file, string $label): self
	{
		if (!is_file($file)) {
			throw new ConfigurationException("Upgrading file $label does not exist.");
		}

		try {
			$data = Neon::decodeFile($file);
		} catch (NeonException $e) {
			throw new ConfigurationException("Upgrading file $label is not valid NEON: {$e->getMessage()}", previous: $e);
		}

		$package = is_array($data) ? $data['package'] ?? null : null;
		if (!is_string($package) || !preg_match(self::PackagePattern, $package)) {
			throw new ConfigurationException("Upgrading file $label: The key `package` must name the package the sections are versions of, as `vendor/name`.");
		}

		$sections = $requirements = [];
		foreach ($data as $key => $section) {
			if ($key === 'package' || $key === 'namespaces') {
				continue;
			} elseif (!is_string($key) || !preg_match(self::SectionPattern, $key, $m)) {
				throw new ConfigurationException("Upgrading file $label: Unexpected key `$key`; the file holds `package`, `namespaces` and sections `since <version>`.");
			} elseif (!is_array($section) || (array_is_list($section) && $section !== [])) {
				throw new ConfigurationException("Upgrading file $label: The section `$key` must be a map of maps to their entries.");
			}

			foreach ($section as $map => $entries) {
				if (is_string($map) && str_contains($map, '.') && is_string($entries)) {
					$requirements[$m[1]][$map] = $entries;
					continue;
				} elseif (!is_string($map) || !is_array($entries)) {
					throw new ConfigurationException("Upgrading file $label: The map `$map` in `$key` must be a map of entries; a package turns on only a rule of its own plugin, by the path of its decision and a word.");
				}

				$sections[$m[1]][$map] = $entries;
			}
		}

		uksort($sections, fn(string $a, string $b) => version_compare($a, $b));
		uksort($requirements, fn(string $a, string $b) => version_compare($a, $b));
		try {
			$namespaces = new Profile(namespaces: is_array($data['namespaces'] ?? null) ? $data['namespaces'] : [])->namespaces;
		} catch (\InvalidArgumentException $e) {
			throw new ConfigurationException("Upgrading file $label: {$e->getMessage()}", previous: $e);
		}

		return new self($package, $namespaces, $sections, $requirements);
	}


	/**
	 * The data for code that must work with the versions of the constraint, null where any version does. An entry is
	 * rewritten where the lowest version reaches its section, a later section having the last word on it: every entry
	 * where any version does, none where there is no lower bound.
	 * What a section above the lowest version retires, where a version the constraint allows reaches it, is reported
	 * without a fix as the entry of a forbidden map (`retire()`), unless a section reached rewrites or forbids the same
	 * thing already. A requirement is turned on where the lowest version reaches its section, and says nothing above it.
	 */
	public function selectFor(?string $constraint, Layer $layer): UpgradingData
	{
		$lowest = $constraint === null ? null : Versions::findLowestVersion($constraint);
		$reaches = fn(string $version) => $constraint === null || ($lowest !== null && version_compare($lowest, $version, '>='));
		$maps = $retirements = $retired = [];
		foreach ($this->sections as $since => $section) {
			$since = (string) $since;
			$left = [];
			foreach ($section as $map => $entries) {
				foreach ($entries as $key => $value) {
					if (!$reaches($since)) {
						$left[$map][$key] = $value;
						continue;
					}

					$maps[$map][$key] = $value;
					unset($retirements[$map][$key]);
					if (in_array($map, self::ForbiddenMaps, true) && $value !== 'keep') {
						$retirements[$map][$key] = $since;
					}
				}
			}

			if ($left !== [] && $constraint !== null && !Versions::isSubset($constraint, "<$since")) {
				foreach (self::retire($left) as [$map, $key, $sentence]) {
					$retired[$map][$key] = [$sentence, $since];
				}
			}
		}

		$written = [];
		foreach ($maps as $map => $entries) {
			foreach (array_keys($entries) as $key) {
				$target = self::findForbidden($map, (string) $key);
				if ($target !== null) {
					$written[$target[0]][self::identify($target[1])] = true;
				}
			}
		}

		foreach ($retired as $map => $entries) {
			foreach ($entries as $key => [$sentence, $version]) {
				if (!isset($written[$map][self::identify($key)])) {
					$maps[$map][$key] = $sentence;
					$retirements[$map][$key] = $version;
				}
			}
		}

		$requirements = [];
		foreach ($this->requirements as $since => $values) {
			if ($reaches((string) $since)) {
				$requirements = array_replace($requirements, $values);
			}
		}

		return new UpgradingData($layer, $this->package, $this->namespaces, $maps, $retirements, requirements: $requirements);
	}


	/**
	 * What entries left above the lowest version say the code still meets on a version it may run on, as entries of the
	 * forbidden maps (`findForbidden()`), since what they write instead is not there in the lowest one: what they forbid,
	 * and what they replace, each with its sentence.
	 * @param  array<string, array<string, mixed>>  $section  map => its entries
	 * @return list<array{string, string, ?string}>  forbidden map, key, sentence
	 */
	private static function retire(array $section): array
	{
		$retired = [];
		foreach ($section as $map => $entries) {
			foreach ($entries as $key => $value) {
				$target = self::findForbidden($map, (string) $key);
				if ($target === null || $value === 'keep') {
					continue;
				}

				[$forbidden, $name] = $target;
				$retired[] = match (true) {
					$map === $forbidden => [$forbidden, $name, $value],
					is_string($value) => [$forbidden, $name, 'replaced by `' . ltrim($value, '\\') . '`'],
					default => [$forbidden, $name, 'replaced'],
				};
			}
		}

		return $retired;
	}


	/**
	 * The forbidden map an entry of the map is reported by where it cannot be rewritten, and its key there; null for the
	 * maps of plugins, which forbid nothing by themselves.
	 * @return ?array{string, string}
	 */
	private static function findForbidden(string $map, string $key): ?array
	{
		return match (true) {
			in_array($map, self::ForbiddenMaps, true) => [$map, $key],
			$map === 'replacedClasses' => ['forbiddenClasses', $key],
			$map === 'replacedFunctions' => ['forbiddenFunctions', $key],
			default => null,
		};
	}


	/**
	 * What the key of a forbidden map names whatever way it is written, so that one rewritten and one forbidden meet: a
	 * class, a function and a constant by its name.
	 */
	private static function identify(string $key): string
	{
		return strtolower(ltrim($key, '\\'));
	}
}
