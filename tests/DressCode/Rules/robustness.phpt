<?php declare(strict_types=1);

/**
 * The fixtures of every rule under a whole standard, which no fixture of one rule tries: the fixer must not fail on
 * them, must not write code that no longer parses, and must settle, whatever other rules make of the code.
 * @dataProvider robustness.provider.php
 */

use DressCode\{Config, ConvergenceException, RuleException};
use DressCode\Config\{ConfigResolver, PluginRegistry, RuleBuilder};
use DressCode\Engine\{PhpFileProcessor, ReportPolicy};
use Tester\{Assert, Environment};

require __DIR__ . '/../../bootstrap.php';

$presets = Environment::loadData()['presets'];
$registry = new PluginRegistry;
$resolved = new ConfigResolver($registry)->resolve(new Config(use: $presets), Config::DefaultPhpVersion);
$style = $resolved->createStyle();
$processor = new PhpFileProcessor(
	RuleBuilder::buildRules($resolved),
	$resolved->createAnalyses($style),
	Config::DefaultPhpVersion,
	$style,
	policy: new ReportPolicy($registry->expandSuppressedName(...), $resolved->suppressionComments, strict: true),
);

$failures = [];
foreach (glob(__DIR__ . '/fixtures/*/*.code') ?: [] as $file) {
	$name = basename(dirname($file)) . '/' . basename($file);
	try {
		$result = $processor->process($name, (string) file_get_contents($file));
		if ($result->failure !== null) {
			$failures[] = "$name: $result->failure";
		}
	} catch (RuleException|ConvergenceException $e) {
		$failures[] = "$name: {$e->getMessage()}";
	}
}

Assert::same([], $failures);
