<?php declare(strict_types=1);

use DressCode\Config\{Layer, LayerKind, UpgradingFile};
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function readUpgradingFile(string $content): UpgradingFile
{
	$file = createTempDir('upgrading-file') . '/upgrading.neon';
	FileSystem::write($file, $content);
	return UpgradingFile::fromFile($file, '`upgrading.neon`');
}


test('what a section reached rewrites is not reported again, however its key is written', function () {
	$upgrading = readUpgradingFile(<<<'XX'
		package: acme/lib

		since 3.0:
			replacedMembers:
				'Acme\Lib\Order::pay()': settle

		since 4.0:
			forbiddenMembers:
				Acme\Lib\Order::pay: gone
				Acme\Lib\Order::refund: gone
			forbiddenFunctions:
				\Acme_Open: gone
		XX);

	$data = $upgrading->selectFor('^3.2 || ^4.0', new Layer(LayerKind::Package, 'upgrading.neon', 'acme/lib'));
	Assert::same(
		[
			'replacedMembers' => ['Acme\Lib\Order::pay()' => 'settle'],
			'forbiddenMembers' => ['Acme\Lib\Order::refund' => 'gone'],
			'forbiddenFunctions' => ['\Acme_Open' => 'gone'],
		],
		$data->maps,
	);
	Assert::same(
		['forbiddenMembers' => ['Acme\Lib\Order::refund' => '4.0'], 'forbiddenFunctions' => ['\Acme_Open' => '4.0']],
		$data->retirements,
	);
});
