<?php

declare(strict_types=1);

use ADT\Cockpit\Exception\RequestException;
use ADT\Cockpit\Exception\UnavailableException;
use Nette\Caching\Storage;
use Nette\Caching\Storages\MemoryStorage;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// MemoryStorage that counts writes.
final class CountingStorage implements Storage
{
	public int $writes = 0;

	private MemoryStorage $storage;


	public function __construct()
	{
		$this->storage = new MemoryStorage;
	}


	public function read(string $key): mixed
	{
		return $this->storage->read($key);
	}


	public function lock(string $key): void
	{
		$this->storage->lock($key);
	}


	public function write(string $key, $data, array $dependencies): void
	{
		$this->writes++;
		$this->storage->write($key, $data, $dependencies);
	}


	public function remove(string $key): void
	{
		$this->storage->remove($key);
	}


	public function clean(array $conditions): void
	{
		$this->storage->clean($conditions);
	}
}


test('without fallback storage nothing is kept between requests', function () {
	$mock = new CockpitMock(json([['_id' => 'a']]), json([], 503), json([], 503));

	Assert::same([['_id' => 'a']], arrays($mock->createCockpit()->getEntries('Article')));
	Assert::exception(fn() => $mock->createCockpit()->getEntries('Article'), UnavailableException::class);
});


test('fresh data on success, last success only on failure, then no call during the hold', function () {
	$mock = new CockpitMock(json([['_id' => 'a']]), json([['_id' => 'b']]), json([], 503), json([], 503));
	$storage = new MemoryStorage;
	$errors = [];
	$request = function () use ($mock, $storage, &$errors) {
		$cockpit = $mock->createCockpit(fallbackStorage: $storage);
		$cockpit->setOnError([function (Throwable $e, string $url, bool $fromFallback) use (&$errors) {
			$errors[] = $fromFallback;
		}]);

		return arrays($cockpit->getEntries('Article'));
	};

	Assert::same([['_id' => 'a']], $request());
	Assert::same([['_id' => 'b']], $request()); // fresh, not the stored one
	Assert::same([['_id' => 'b']], $request()); // 503, 503 → last success
	Assert::same([true], $errors);
	Assert::count(4, $mock->requests);

	Assert::same([['_id' => 'b']], $request()); // hold: Cockpit is not called
	Assert::count(4, $mock->requests);
});


test('failure without stored data throws, and during the hold throws without calling Cockpit', function () {
	$mock = new CockpitMock(json([], 504), json([], 504));
	$storage = new MemoryStorage;

	Assert::exception(
		fn() => $mock->createCockpit(fallbackStorage: $storage)->getEntries('Article'),
		UnavailableException::class,
		'Cockpit responded 504: %a%',
	);
	Assert::exception(
		fn() => $mock->createCockpit(fallbackStorage: $storage)->getEntries('Article'),
		UnavailableException::class,
		'Cockpit failed %d% s ago, not called again yet: %a%',
	);
	Assert::count(2, $mock->requests);
});


test('after the hold Cockpit is called again and the failure mark is cleared', function () {
	$mock = new CockpitMock(json([['_id' => 'a']]), json([], 503), json([], 503), json([['_id' => 'b']]), json([['_id' => 'c']]));
	$storage = new MemoryStorage;
	$request = fn() => arrays($mock->createCockpit(fallbackStorage: $storage, failureHold: 0)->getEntries('Article'));

	Assert::same([['_id' => 'a']], $request());
	Assert::same([['_id' => 'a']], $request());
	Assert::same([['_id' => 'b']], $request());
	Assert::same([['_id' => 'c']], $request());
	Assert::count(5, $mock->requests);
});


test('stored per query: another filter does not get foreign data', function () {
	$mock = new CockpitMock(json([['_id' => 'a']]), json([], 503), json([], 503));
	$storage = new MemoryStorage;

	$mock->createCockpit(fallbackStorage: $storage)->getEntries('Article', ['section' => 'novinky']);
	Assert::exception(
		fn() => $mock->createCockpit(fallbackStorage: $storage)->getEntries('Article', ['section' => 'siesta']),
		UnavailableException::class,
	);
});


test('4xx is never answered from the fallback', function () {
	$mock = new CockpitMock(json([['_id' => 'a']]), json(['error' => 'Forbidden'], 403));
	$storage = new MemoryStorage;

	$mock->createCockpit(fallbackStorage: $storage)->getEntries('Article');
	Assert::exception(fn() => $mock->createCockpit(fallbackStorage: $storage)->getEntries('Article'), RequestException::class);
});


test('singleton uses the fallback too', function () {
	$mock = new CockpitMock(json(['text' => 'Patička']), json([], 503), json([], 503));
	$storage = new MemoryStorage;

	Assert::same(['text' => 'Patička'], $mock->createCockpit(fallbackStorage: $storage)->getSingleton('Footer'));
	Assert::same(['text' => 'Patička'], $mock->createCockpit(fallbackStorage: $storage)->getSingleton('Footer'));
});


test('unchanged data is not written again, writes do not touch the storage', function () {
	$mock = new CockpitMock(json([['_id' => 'a']]), json([['_id' => 'a']]), json([['_id' => 'b']]), json(['_id' => 'x']));
	$storage = new CountingStorage;

	$mock->createCockpit(fallbackStorage: $storage)->getEntries('Article');
	$mock->createCockpit(fallbackStorage: $storage)->getEntries('Article');
	Assert::same(1, $storage->writes);

	$mock->createCockpit(fallbackStorage: $storage)->getEntries('Article');
	Assert::same(2, $storage->writes);

	$mock->createCockpit(fallbackStorage: $storage)->saveEntry('Applicant', ['name' => 'Jan']);
	Assert::same(2, $storage->writes);
});
