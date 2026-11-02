<?php

declare(strict_types=1);

use ADT\Cockpit\Entry;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$values = [
	'title' => 'Článek',
	'title_en' => 'Article',
	'image' => ['path' => '/a.jpg', 'mime' => 'image/jpeg'],
	'magazine' => ['_id' => 'm1', 'title' => 'NÉA 23'],
	'tags' => [
		['_id' => 't1', 'name' => 'Tag'],
		['_id' => 't2', '_model' => 'Tag'], // not populated
	],
];


test('flat: nested data are arrays, missing key is null', function () use ($values) {
	$entry = new Entry($values);

	Assert::same('Článek', $entry['title']);
	Assert::null($entry['missing']);
	Assert::false(isset($entry['missing']));
	Assert::same(['_id' => 'm1', 'title' => 'NÉA 23'], $entry['magazine']);
	Assert::count(2, $entry['tags']);
	Assert::same($values, $entry->toArray());
	Assert::same(5, count($entry));
});


test('nested: links and lists become Entry, assets stay arrays, unpopulated links are removed', function () use ($values) {
	$entry = new Entry($values, true);

	Assert::type(Entry::class, $entry['magazine']);
	Assert::same('NÉA 23', $entry['magazine']['title']);
	Assert::type(Entry::class, $entry['tags']);
	Assert::count(1, $entry['tags']);
	Assert::type(Entry::class, $entry['tags'][0]);
	Assert::same(['path' => '/a.jpg', 'mime' => 'image/jpeg'], $entry['image']);
	Assert::same([['_id' => 't1', 'name' => 'Tag']], $entry->toArray()['tags']);
});


test('callbacks: onLoad once, onGetOffset may change the key, nested entries get them too', function () use ($values) {
	$loads = 0;
	$onLoad = function (array &$values) use (&$loads) {
		$loads++;
	};
	$localize = function (array $values, string|int &$offset) {
		if (is_string($offset) && isset($values[$offset . '_en'])) {
			$offset .= '_en';
		}
	};
	$entry = new Entry($values, true, [$onLoad], [$localize]);

	Assert::same('Article', $entry['title']);
	Assert::same('Article', $entry['title']);
	Assert::same(1, $loads);

	$entry['magazine']['title'];
	Assert::same(2, $loads);
});


test('iteration returns the converted values', function () use ($values) {
	$entry = new Entry($values, true);
	$types = [];
	foreach ($entry as $key => $value) {
		$types[$key] = get_debug_type($value);
	}

	Assert::same(['title' => 'string', 'title_en' => 'string', 'image' => 'array', 'magazine' => Entry::class, 'tags' => Entry::class], $types);
});


test('json_encode gives the values', function () use ($values) {
	Assert::same(json_encode($values), json_encode(new Entry($values)));
});


test('offsetSet accepts only string and int keys', function () {
	$entry = new Entry([]);
	$entry['a'] = 1;
	$entry[0] = 2;
	Assert::same(['a' => 1, 0 => 2], $entry->toArray());

	Assert::exception(fn() => $entry[null] = 3, InvalidArgumentException::class);
});
