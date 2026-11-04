<?php

declare(strict_types=1);

use ADT\Cockpit\Cockpit;
use ADT\Cockpit\Entry;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('getEntries: populate by default, filter, sort ASC/DESC, limit, default and explicit locale', function () {
	$mock = new CockpitMock(json([['_id' => 'a', 'title' => 'A']]), json([]), json([]));

	$entries = $mock->createCockpit()->getEntries('Article', ['magazine._id' => 'm1'], ['number' => 'DESC', 'title' => 'asc'], 10);
	Assert::type(Entry::class, $entries[0]);
	Assert::same('A', $entries[0]['title']);
	Assert::same([
		'filter' => '{"magazine._id":"m1"}',
		'sort' => '{"number":-1,"title":1}',
		'limit' => '10',
		'populate' => '1',
	], $mock->query(0));

	$cockpit = $mock->createCockpit();
	$cockpit->setLocale('en');
	$cockpit->setPopulate(0);
	$cockpit->getEntries('Article');
	Assert::same(['locale' => 'en'], $mock->query(1));

	$cockpit->getEntries('Article', locale: 'el', populate: 2);
	Assert::same(['populate' => '2', 'locale' => 'el'], $mock->query(2));
});


test('getEntry asks for one item', function () {
	$mock = new CockpitMock(json([['_id' => 'a']]), json([]));
	$cockpit = $mock->createCockpit();

	Assert::same('a', $cockpit->getEntry('Article', ['url' => 'x'])['_id']);
	Assert::same('1', $mock->query(0)['limit']);
	Assert::null($cockpit->getEntry('Article', ['url' => 'y']));
});


test('sortable models are sorted by _o only without filter, sort and limit', function () {
	$mock = new CockpitMock(json([]), json([]));
	$cockpit = $mock->createCockpit();
	$cockpit->setSortableModels(['Product']);

	$cockpit->getEntries('Product');
	Assert::same('{"_o":1}', $mock->query(0)['sort']);

	$cockpit->getEntries('Product', ['active' => true]);
	Assert::false(isset($mock->query(1)['sort']));
});


test('links to deleted items are removed from lists, a single false stays', function () {
	$mock = new CockpitMock(json([[
		'_id' => 'a',
		'tags' => [['_id' => 't1', 'name' => 'Tag'], false],
		'magazine' => false,
		'display' => false,
	]]));

	$entry = $mock->createCockpit()->getEntries('Article')[0];
	Assert::same([['_id' => 't1', 'name' => 'Tag']], $entry['tags']);
	Assert::false($entry['magazine']);
	Assert::false($entry['display']);
});


test('nested entries and callbacks are passed to the entries', function () {
	$mock = new CockpitMock(json([['title' => 'Článek', 'title_en' => 'Article', 'magazine' => ['_id' => 'm1', 'title' => 'NÉA']]]));
	$cockpit = $mock->createCockpit();
	$cockpit->setNestedEntries();
	$cockpit->setOnGetEntryOffset([function (array $values, string|int &$offset) {
		if (is_string($offset) && isset($values[$offset . '_en'])) {
			$offset .= '_en';
		}
	}]);

	$entry = $cockpit->getEntries('Article')[0];
	Assert::same('Article', $entry['title']);
	Assert::type(Entry::class, $entry['magazine']);
});


test('getSingleton: data, empty array when missing', function () {
	$mock = new CockpitMock(json(['text' => 'Patička']), json(['error' => 'Not found'], 404));
	$cockpit = $mock->createCockpit();

	Assert::same(['text' => 'Patička'], $cockpit->getSingleton('Footer'));
	Assert::same('/api/content/item/Footer', $mock->requests[0]->getUri()->getPath());
	Assert::same(['populate' => '1'], $mock->query(0));
	Assert::same([], $cockpit->getSingleton('Missing'));
});


test('getAssetPath', function () {
	$asset = ['path' => '/2026/06/02/cover.jpg', 'mime' => 'image/jpeg', 'sizes' => ['thumbnail' => ['path' => '/thumbs/cover.jpg']]];

	Assert::same('/storage/uploads/2026/06/02/cover.jpg', Cockpit::getAssetPath($asset));
	Assert::same('/storage/uploads/2026/06/02/cover.jpg', Cockpit::getAssetPath(['path' => '/storage/uploads/2026/06/02/cover.jpg']));
	Assert::same('/storage/uploads/thumbs/cover.jpg', Cockpit::getAssetPath(new Entry($asset), 'thumbnail'));
	Assert::same('/storage/uploads/small/cover.jpg', Cockpit::getAssetPath($asset, 'small'));
});
