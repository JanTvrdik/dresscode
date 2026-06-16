<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\Engine\Helpers;
use function is_array, is_string;


/**
 * What the files of Composer say about a project: where its composer.json is and the PHP it is written for.
 * @internal
 */
final class ComposerJson
{
	/**
	 * The composer.json of the root or of a directory above it, the way the configuration file is looked up.
	 */
	public static function findFile(string $root): ?string
	{
		foreach (Helpers::walkUp($root) as $directory) {
			if (is_file("$directory/composer.json")) {
				return "$directory/composer.json";
			}
		}

		return null;
	}


	/**
	 * What a JSON file of Composer holds; null when there is none, it cannot be read or it is not an object.
	 * @return ?array<mixed>
	 */
	public static function read(?string $file): ?array
	{
		$json = $file === null ? false : @file_get_contents($file); // @ the file is optional
		$data = $json === false ? null : json_decode($json, associative: true);
		return is_array($data) ? $data : null;
	}


	/**
	 * The versions of PHP the project is written for: the constraint of require.php from the version of
	 * config.platform.php on, which Composer resolves the packages for and the project usually runs on, `^8.1` with
	 * `8.3` being `^8.1, >=8.3`; null where neither gives a lower bound (`<8.4`, `*`) or the constraint is none, which
	 * leaves the version to the default rather than to a guess.
	 */
	public static function detectPhpTarget(?string $composerFile): ?string
	{
		$data = self::read($composerFile);
		$constraint = $data['require']['php'] ?? null;
		$constraint = is_string($constraint) && Versions::isConstraint($constraint) ? $constraint : null;
		$platform = $data['config']['platform']['php'] ?? null;
		if (is_string($platform) && Versions::isVersion($platform)) {
			return $constraint === null ? ">=$platform" : Versions::raiseLowerBound($constraint, $platform);
		}

		return $constraint !== null && Versions::findLowestVersion($constraint) !== null ? $constraint : null;
	}
}
