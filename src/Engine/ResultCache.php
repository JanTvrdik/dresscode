<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use Nette\Utils\{Json, JsonException};
use function count, is_array, is_int, is_string;


/**
 * Remembers which file contents came out clean under an effective configuration, so an unchanged file is not
 * processed again, together with what the baseline matched in them, so the run can still count it. One JSON
 * file per project root; entries of another configuration, or of one a file it depends on has changed under, are
 * dropped, entries untouched for a month expire.
 * @internal
 */
final class ResultCache
{
	private const Expiration = 30 * 24 * 3600;

	/** @var array<string, array{int, list<string>}>  content hash => time last confirmed and the fingerprints the baseline matched */
	private array $entries = [];

	/** @var array<string, true> */
	private array $touched = [];

	/** @var array<string, string>  file => stamp, of the files the results depend on that the configuration does not name */
	private array $dependencies = [];


	public function __construct(
		private readonly string $file,
		private readonly string $configHash,
		/** a run that processes every file serves none from the cache, and writes what it learns for the next one */
		private readonly bool $serves = true,
		/** @var ?\Closure(string): string  file => its stamp, by which a dependency is told unchanged */
		private readonly ?\Closure $stamp = null,
	) {
	}


	/**
	 * Loads the entries of the file when it belongs to the same configuration and none of the dependencies it names
	 * changed since; an unreadable file is an empty cache.
	 * @param  ?\Closure(string): string  $stamp  file => its stamp
	 */
	public static function load(string $file, string $configHash, bool $serves = true, ?\Closure $stamp = null): self
	{
		$cache = new self($file, $configHash, $serves, $stamp);
		$json = @file_get_contents($file); // @ a missing or unreadable file is an empty cache
		try {
			$data = $json === false ? null : Json::decode($json, forceArrays: true);
		} catch (JsonException) {
			$data = null;
		}

		if (!is_array($data) || ($data['config'] ?? null) !== $configHash || !is_array($data['entries'] ?? null)) {
			return $cache;
		}

		foreach (is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [] as $dependency => $stamp) {
			if (!is_string($stamp) || $stamp !== $cache->stamp?->__invoke((string) $dependency)) {
				return $cache;
			}

			$cache->dependencies[(string) $dependency] = $stamp;
		}

		foreach ($data['entries'] as $key => $entry) {
			if (!is_string($key) || !is_array($entry) || !is_int($entry[0] ?? null) || !is_array($entry[1] ?? null)) {
				continue;
			}

			$fingerprints = array_values(array_filter($entry[1], is_string(...)));
			if (count($fingerprints) === count($entry[1])) {
				$cache->entries[$key] = [$entry[0], $fingerprints];
			}
		}

		return $cache;
	}


	/**
	 * The identity of the content of a file under one configuration. The path is part of it: an override or a
	 * rule reading the path gives two files of the same text different verdicts.
	 */
	public static function hashContent(string $path, string $code): string
	{
		return hash('xxh128', "$path\0$code");
	}


	/**
	 * What the baseline matched in a content known to be clean, by fingerprint; null when the content is not
	 * known. Asking keeps the entry alive.
	 * @return ?list<string>
	 */
	public function findClean(string $key): ?array
	{
		if (!$this->serves || !isset($this->entries[$key])) {
			return null;
		}

		$this->touched[$key] = true;
		return $this->entries[$key][1];
	}


	/** @param list<string> $baselined  fingerprints the baseline matched in the content */
	public function markClean(string $key, array $baselined = []): void
	{
		$this->entries[$key] = [time(), $baselined];
		$this->touched[$key] = true;
	}


	/** Forgets a content that is not clean, which a run that served nothing may have found known as clean. */
	public function forget(string $key): void
	{
		unset($this->entries[$key], $this->touched[$key]);
	}


	/**
	 * Names the files the results depend on besides those the configuration names, such as what the configuration of
	 * PHPStan includes, so that a change of any of them drops the cache; they replace those of the run before.
	 * @param  list<string>  $files
	 */
	public function recordDependencies(array $files): void
	{
		$this->dependencies = [];
		foreach ($files as $file) {
			$this->dependencies[$file] = $this->stamp?->__invoke($file) ?? '';
		}

		ksort($this->dependencies);
	}


	/** Writes the entries touched by this run and the recent ones; failures are silent, a cache is a convenience. */
	public function save(): void
	{
		$now = time();
		$entries = [];
		foreach ($this->entries as $key => [$time, $baselined]) {
			if (isset($this->touched[$key])) {
				$entries[$key] = [$now, $baselined];
			} elseif ($now - $time < self::Expiration) {
				$entries[$key] = [$time, $baselined];
			}
		}

		$dir = dirname($this->file);
		if (!is_dir($dir) && !@mkdir($dir, recursive: true) && !is_dir($dir)) { // @ failure is silent
			return;
		}

		$json = Json::encode([
			'config' => $this->configHash,
			'dependencies' => (object) $this->dependencies,
			'entries' => (object) $entries,
		]);
		@file_put_contents($this->file, $json, LOCK_EX); // @ failure is silent; a torn read loads as an empty cache
	}


	public function count(): int
	{
		return count($this->entries);
	}
}
