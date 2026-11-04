<?php

declare(strict_types=1);

use ADT\Cockpit\Cockpit;
use GuzzleHttp\Handler\MockHandler;
use Psr\Http\Message\RequestInterface;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();
Tester\Environment::setupFunctions();


/**
 * Mock of Cockpit: queue of responses/exceptions, sent requests are collected in $requests.
 */
final class CockpitMock
{
	/** @var list<RequestInterface> */
	public array $requests = [];

	public MockHandler $handler;


	/**
	 * @param mixed ...$queue Response, Throwable, or Closure(RequestInterface): Response|Throwable
	 */
	public function __construct(mixed ...$queue)
	{
		$this->handler = new MockHandler(array_map(
			fn($response) => function (RequestInterface $request) use ($response) {
				$this->requests[] = $request;

				// MockHandler turns a returned Throwable into a rejected promise, as a real handler does
				return $response instanceof Closure ? $response($request) : $response;
			},
			$queue,
		));
	}


	/**
	 * A new instance = a new web request; the fallback storage survives between requests.
	 */
	public function createCockpit(string $url = 'https://cockpit.example.com', ?string $host = null, ?Nette\Caching\Storage $fallbackStorage = null, int $failureHold = 30): Cockpit
	{
		$cockpit = new Cockpit($url, 'secret', $host);
		$cockpit->setHttpOptions(1, 1.0, 1.0, 0);
		$cockpit->setHttpHandler($this->handler);
		if ($fallbackStorage !== null) {
			$cockpit->setFallbackStorage($fallbackStorage, $failureHold);
		}

		return $cockpit;
	}


	/** @return array<string, string> */
	public function query(int $index): array
	{
		parse_str($this->requests[$index]->getUri()->getQuery(), $query);

		return $query;
	}
}


function json(mixed $data, int $status = 200): GuzzleHttp\Psr7\Response
{
	return new GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], json_encode($data));
}


/**
 * @param list<ADT\Cockpit\Entry> $entries
 * @return list<array<string|int, mixed>>
 */
function arrays(array $entries): array
{
	return array_map(fn(ADT\Cockpit\Entry $entry) => $entry->toArray(), $entries);
}


/** cURL error as Guzzle reports it (7 = connection refused, 28 = timeout). */
function curlError(int $errno): Closure
{
	return fn(RequestInterface $request) => new GuzzleHttp\Exception\ConnectException("cURL error $errno", $request, null, ['errno' => $errno]);
}
