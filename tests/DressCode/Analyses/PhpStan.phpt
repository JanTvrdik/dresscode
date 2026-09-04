<?php declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\{Assert, Environment};

require __DIR__ . '/../../bootstrap.php';


test('a class the project lacks, which PHPStan looks for through the autoloaders, stays whole in the file cache of opcache', function () {
	$probe = shell_exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=1 -r "echo (int) function_exists(\'opcache_get_status\');"');
	if ($probe !== '1') {
		Environment::skip('opcache is not loaded');
	}

	$root = createTempDir('phpstan-opcache');
	mkdir("$root/src");
	mkdir("$root/opcache");
	file_put_contents("$root/composer.json", "{}\n");
	file_put_contents("$root/dresscode.neon", "use: [nette]\ntypeAnalysis: phpstan\npaths: [src]\ncacheDir: cache\n");
	// code naming classes of DressCode itself, some of which the process loads only after PHPStan has looked for them
	$code = FileSystem::read(__DIR__ . '/../../../src/Rules/NodeHelpers.php');
	file_put_contents("$root/src/Helpers.php", str_replace('namespace DressCode\Rules;', 'namespace App;', $code));
	$command = [
		PHP_BINARY,
		'-d', 'opcache.enable_cli=1',
		'-d', "opcache.file_cache=$root/opcache",
		'-d', 'opcache.file_cache_only=1',
		__DIR__ . '/../../../bin/dresscode',
		'check', '--no-cache', '--jobs', '1', '--no-color',
	];
	foreach ([1, 2] as $run) {
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root)
			?: throw new RuntimeException('Cannot start DressCode.');
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		$exit = proc_close($process);
		Assert::true($exit < 2, "run $run exits $exit: $output");
	}
});
