<?php declare(strict_types=1);

use DressCode\{Analyses, Config, FileResult};
use DressCode\Engine\{FileProcessor, FileProcessors, PhpFileProcessor, Runner};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** Records the files it was given and reports nothing. */
final class ProcessorsStub implements FileProcessor
{
	/** @var list<string> */
	public array $paths = [];


	public function __construct(
		public readonly string $name,
	) {
	}


	public function process(string $path, string $code): FileResult
	{
		$this->paths[] = $path;
		return new FileResult($path, $code, $code);
	}


	public function getReportedDecisions(): array
	{
		return ["$this->name.decision"];
	}
}


test('a file goes to the processor of its extension, in any letter case, the others to the default one', function () {
	$processors = new FileProcessors(
		[],
		fn() => new ProcessorsStub('php'),
		['neon' => fn() => new ProcessorsStub('neon')],
	);
	$kind = fn(FileProcessor $processor) => $processor->getReportedDecisions()[0];
	Assert::same('php.decision', $kind($processors->get('src/a.php')));
	Assert::same('php.decision', $kind($processors->get('src/a.phpt')));
	Assert::same('php.decision', $kind($processors->get('Makefile')));
	Assert::same('neon.decision', $kind($processors->get('config/common.neon')));
	Assert::same('neon.decision', $kind($processors->get('config/COMMON.NEON')));
	Assert::same('php.decision', $kind($processors->getBase()));
});


test('one processor per kind of file and combination of overrides, built once', function () {
	$built = [];
	$build = function (string $name) use (&$built) {
		return function (array $overrides) use ($name, &$built) {
			$built[] = $name . ':' . implode(',', $overrides);
			return new ProcessorsStub($name);
		};
	};
	$processors = new FileProcessors([['tests']], $build('php'), ['neon' => $build('neon')]);
	Assert::same($processors->get('src/a.php'), $processors->get('src/b.php'));
	Assert::same($processors->get('src/a.neon'), $processors->get('src/b.neon'));
	Assert::notSame($processors->get('src/a.php'), $processors->get('tests/a.php'));
	Assert::notSame($processors->get('src/a.neon'), $processors->get('tests/a.neon'));
	Assert::notSame($processors->get('src/a.php'), $processors->get('src/a.neon'));
	Assert::same($processors->getBase(), $processors->get('src/a.php'));
	Assert::same(['php:', 'neon:', 'php:0', 'neon:0'], $built);
});


test('the runner hands a file to the processor of its extension', function () {
	$root = createTempDir('processors');
	$neon = new ProcessorsStub('neon');
	$runner = new Runner(
		new FileProcessors(
			[],
			fn() => new PhpFileProcessor([], new Analyses\Registry, Config::DefaultPhpVersion),
			['neon' => fn() => $neon],
		),
		$root,
		fileExtensions: ['php', 'neon'],
	);
	Assert::same('config/a.neon', $runner->processCode("$root/config/a.neon", "a: 1\n")->path);
	Assert::same('src/a.php', $runner->processCode("$root/src/a.php", "<?php\n")->path);
	Assert::same(['config/a.neon'], $neon->paths);
});
