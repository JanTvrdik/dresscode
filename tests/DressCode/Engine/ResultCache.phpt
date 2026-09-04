<?php declare(strict_types=1);

use DressCode\Engine\ResultCache;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$dir = createTempDir('result-cache');
$file = "$dir/sub/cache.json";


test('a missing file is an empty cache; entries survive a save and load under the same configuration', function () use ($file) {
	$cache = ResultCache::load($file, 'config-a');
	Assert::same(0, $cache->count());
	$key = ResultCache::hashContent('src/a.php', "<?php\n");
	Assert::null($cache->findClean($key));
	$cache->markClean($key, ['f1']);
	Assert::same(['f1'], $cache->findClean($key));
	$cache->save();

	$cache = ResultCache::load($file, 'config-a');
	Assert::same(1, $cache->count());
	Assert::same(['f1'], $cache->findClean($key));
	Assert::null($cache->findClean(ResultCache::hashContent('src/a.php', "<?php\n\n")));
	Assert::null($cache->findClean(ResultCache::hashContent('src/b.php', "<?php\n"))); // the same text of another file is not known
});


test('a cache that serves nothing still remembers, and forgets a content found not clean', function () use ($file) {
	$clean = ResultCache::hashContent('src/a.php', "<?php\n");
	$stale = ResultCache::hashContent('src/b.php', "<?php\n");
	$cache = ResultCache::load($file, 'config-refresh');
	$cache->markClean($clean);
	$cache->markClean($stale);
	$cache->save();

	$cache = ResultCache::load($file, 'config-refresh', serves: false);
	Assert::null($cache->findClean($clean));
	$cache->forget($stale);
	$cache->save();

	$cache = ResultCache::load($file, 'config-refresh');
	Assert::same([], $cache->findClean($clean));
	Assert::null($cache->findClean($stale));
});


test('another configuration starts empty and replaces the file', function () use ($file) {
	$cache = ResultCache::load($file, 'config-b');
	Assert::same(0, $cache->count());
	$cache->markClean('x');
	$cache->save();
	Assert::same(0, ResultCache::load($file, 'config-a')->count());
	Assert::same(1, ResultCache::load($file, 'config-b')->count());
});


test('expired entries are dropped on save, touched ones are refreshed', function () use ($file) {
	file_put_contents($file, json_encode(['config' => 'config-c', 'entries' => [
		'old' => [time() - 40 * 24 * 3600, []],
		'recent' => [time() - 3600, []],
		'used' => [1, ['f']],
	]]));
	$cache = ResultCache::load($file, 'config-c');
	Assert::same(3, $cache->count());
	Assert::same(['f'], $cache->findClean('used'));
	$cache->save();
	$data = json_decode((string) file_get_contents($file), associative: true);
	Assert::same(['recent', 'used'], array_keys($data['entries']));
	Assert::same(['f'], $data['entries']['used'][1]);
	Assert::true($data['entries']['used'][0] > time() - 60);
});


test('a changed or a missing dependency empties the cache, an unchanged one keeps it and is written again', function () use ($file) {
	$stamps = ['stubs/a.stub' => '1|10', 'stubs/b.stub' => '1|20'];
	$stamp = function (string $dependency) use (&$stamps): string {
		return $stamps[$dependency] ?? '';
	};
	$cache = ResultCache::load($file, 'config-d', stamp: $stamp);
	$cache->markClean('a');
	$cache->recordDependencies(['stubs/b.stub', 'stubs/a.stub']);
	$cache->save();
	Assert::same($stamps, json_decode((string) file_get_contents($file), associative: true)['dependencies']);

	$cache = ResultCache::load($file, 'config-d', stamp: $stamp);
	Assert::same(1, $cache->count());
	$cache->save(); // a run that built no container keeps the dependencies it was given
	Assert::same(1, ResultCache::load($file, 'config-d', stamp: $stamp)->count());

	$stamps['stubs/b.stub'] = '2|20';
	Assert::same(0, ResultCache::load($file, 'config-d', stamp: $stamp)->count());
	unset($stamps['stubs/b.stub']);
	Assert::same(0, ResultCache::load($file, 'config-d', stamp: $stamp)->count());
});


test('a broken file is an empty cache, and an entry of another shape is none', function () use ($file) {
	file_put_contents($file, '{');
	Assert::same(0, ResultCache::load($file, 'config-c')->count());
	file_put_contents($file, json_encode(['config' => 'config-c', 'entries' => ['a' => 1, 'b' => [1, [2]], 'c' => [1, ['f']]]]));
	Assert::same(1, ResultCache::load($file, 'config-c')->count());
});
