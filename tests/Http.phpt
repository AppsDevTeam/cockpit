<?php

declare(strict_types=1);

use ADT\Cockpit\Cockpit;
use ADT\Cockpit\Exception\RequestException;
use ADT\Cockpit\Exception\UnavailableException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('URL with or without /api, headers, Host only when given', function () {
	$mock = new CockpitMock(json([]), json([]), json([]));
	$mock->createCockpit('https://cockpit.example.com')->getEntries('Article');
	$mock->createCockpit('http://nginx/api/', 'cockpit.example.com')->getEntries('Article');
	$mock->createCockpit('https://cockpit.example.com/api')->getSingleton('Footer');

	Assert::same('/api/content/items/Article', $mock->requests[0]->getUri()->getPath());
	Assert::same('cockpit.example.com', $mock->requests[0]->getUri()->getHost());
	Assert::same('secret', $mock->requests[0]->getHeaderLine('api-key'));
	Assert::same('application/json', $mock->requests[0]->getHeaderLine('Accept'));
	Assert::same('cockpit.example.com', $mock->requests[0]->getHeaderLine('Host'));

	Assert::same('nginx', $mock->requests[1]->getUri()->getHost());
	Assert::same('/api/content/items/Article', $mock->requests[1]->getUri()->getPath());
	Assert::same('cockpit.example.com', $mock->requests[1]->getHeaderLine('Host'));

	Assert::same('/api/content/item/Footer', $mock->requests[2]->getUri()->getPath());
});


test('query: arrays as JSON without escaping, defaults skipped', function () {
	$mock = new CockpitMock(json([]), json([]));
	$mock->createCockpit()->getEntries(
		'Organization',
		['title' => 'Léto', 'tags._id' => 'a/b'],
		['order' => 1],
		5,
		'en',
		['_id' => true, 'title' => true],
		0,
	);
	$mock->createCockpit()->getEntries('Organization');

	Assert::same([
		'filter' => '{"title":"Léto","tags._id":"a/b"}',
		'sort' => '{"order":1}',
		'limit' => '5',
		'fields' => '{"_id":true,"title":true}',
		'locale' => 'en',
	], $mock->query(0));
	Assert::same(['populate' => '1'], $mock->query(1));
});


test('{data, meta} envelope and unexpected responses', function () {
	$mock = new CockpitMock(
		json(['data' => [['_id' => 'c']], 'meta' => ['total' => 1]]),
		json(['error' => 'x']),
		json([['_id' => 'a'], 'not an item']),
	);

	Assert::same([['_id' => 'c']], arrays($mock->createCockpit()->getEntries('Article')));
	Assert::same([], $mock->createCockpit()->getEntries('Article'));
	Assert::same([['_id' => 'a']], arrays($mock->createCockpit()->getEntries('Article')));
});


test('the same read is sent once per instance, a write forgets it', function () {
	$mock = new CockpitMock(json([['_id' => 'a']]), json([['_id' => 'b']]), json(['_id' => 's']), json([['_id' => 'c']]));
	$cockpit = $mock->createCockpit();

	Assert::same([['_id' => 'a']], arrays($cockpit->getEntries('Article')));
	Assert::same([['_id' => 'a']], arrays($cockpit->getEntries('Article')));
	Assert::same([['_id' => 'b']], arrays($cockpit->getEntries('Article', ['section' => 'novinky'])));
	Assert::count(2, $mock->requests);

	$cockpit->saveEntry('Subscriber', ['email' => 'a@example.com']);
	Assert::same([['_id' => 'c']], arrays($cockpit->getEntries('Article')));
	Assert::count(4, $mock->requests);
});


test('a read is retried after 5xx and after a connection error', function () {
	$mock = new CockpitMock(json([], 503), json([['_id' => 'a']]), curlError(28), json([['_id' => 'b']]));

	Assert::same([['_id' => 'a']], arrays($mock->createCockpit()->getEntries('Article')));
	Assert::same([['_id' => 'b']], arrays($mock->createCockpit()->getEntries('Article')));
	Assert::count(4, $mock->requests);
});


test('a read fails after the retries are used up; exception is a GuzzleException', function () {
	$mock = new CockpitMock(json([], 502), json([], 502));

	$e = Assert::exception(
		fn() => $mock->createCockpit()->getEntries('Article'),
		UnavailableException::class,
		'Cockpit responded 502: https://cockpit.example.com/api/content/items/Article',
	);
	Assert::type(GuzzleException::class, $e);
	Assert::same(502, $e->getCode());
	Assert::count(2, $mock->requests);
});


test('501 is not retried', function () {
	$mock = new CockpitMock(json([], 501));

	Assert::exception(fn() => $mock->createCockpit()->getEntries('Article'), UnavailableException::class);
	Assert::count(1, $mock->requests);
});


test('a write is retried only when it certainly did not reach Cockpit', function () {
	$mock = new CockpitMock(curlError(7), json(['_id' => 'a']), curlError(28), json([], 503));
	$cockpit = $mock->createCockpit();

	Assert::same(['_id' => 'a'], $cockpit->saveEntry('Applicant', ['name' => 'Jan']));
	Assert::count(2, $mock->requests);

	Assert::exception(fn() => $cockpit->saveEntry('Applicant', ['name' => 'Jan']), UnavailableException::class);
	Assert::count(3, $mock->requests);

	Assert::exception(fn() => $cockpit->saveEntry('Applicant', ['name' => 'Jan']), UnavailableException::class);
	Assert::count(4, $mock->requests);
});


test('4xx: RequestException with status, not retried, reported to onError', function () {
	$mock = new CockpitMock(json(['error' => 'Authentication failed'], 412), json([], 503));
	$cockpit = $mock->createCockpit();
	$errors = [];
	$cockpit->setOnError([function (Throwable $e, string $url, bool $fromFallback) use (&$errors) {
		$errors[] = [$e::class, $url, $fromFallback];
	}]);

	$e = Assert::exception(fn() => $cockpit->getEntries('Career'), RequestException::class);
	Assert::same(412, $e->getStatusCode());
	Assert::count(1, $mock->requests);

	Assert::exception(fn() => $cockpit->saveEntry('Applicant', []), UnavailableException::class);
	Assert::same([
		[RequestException::class, 'https://cockpit.example.com/api/content/items/Career', false],
		[UnavailableException::class, 'https://cockpit.example.com/api/content/item/Applicant', false],
	], $errors);
});


test('response that is not JSON (proxy error page) is UnavailableException', function () {
	$mock = new CockpitMock(new Response(200, [], '<html>Bad gateway</html>'));

	Assert::exception(
		fn() => $mock->createCockpit()->getEntries('Article'),
		UnavailableException::class,
		'Cockpit returned invalid JSON (HTTP 200): %a%',
	);
});


test('saveEntry sends {data}, link has _id and _model', function () {
	$mock = new CockpitMock(json(['_id' => 'x', 'name' => 'Jan']));
	$result = $mock->createCockpit()->saveEntry('Applicant', ['name' => 'Jan', 'career' => Cockpit::link('c1', 'Career')]);

	Assert::same(['_id' => 'x', 'name' => 'Jan'], $result);
	Assert::same('POST', $mock->requests[0]->getMethod());
	Assert::same('/api/content/item/Applicant', $mock->requests[0]->getUri()->getPath());
	Assert::same(
		['data' => ['name' => 'Jan', 'career' => ['_id' => 'c1', '_model' => 'Career']]],
		json_decode((string) $mock->requests[0]->getBody(), true),
	);
});


test('upload sends the file as files[] to the project endpoint', function () {
	$file = tempnam(sys_get_temp_dir(), 'cockpit');
	file_put_contents($file, '%PDF-1.4');
	$mock = new CockpitMock(json([['_id' => 'asset1']]));

	$result = $mock->createCockpit()->upload($file, 'application/pdf', 'cv.pdf');
	unlink($file);

	Assert::same([['_id' => 'asset1']], $result);
	Assert::same('/api/assets/upload', $mock->requests[0]->getUri()->getPath());
	$body = (string) $mock->requests[0]->getBody();
	Assert::contains('name="files[]"; filename="cv.pdf"', $body);
	Assert::contains('Content-Type: application/pdf', $body);
	Assert::contains('%PDF-1.4', $body);
});
