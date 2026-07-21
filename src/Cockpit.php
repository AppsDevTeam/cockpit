<?php
declare(strict_types=1);

namespace ADT\Cockpit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;

class Cockpit
{
	const UPLOADS_DIR = "/storage/uploads";

	protected string $apiUrl;
	protected string $apiKey;
	protected string $host;

	protected array $onLoadEntry = [];

	protected array $onGetEntryOffset = [];

	protected ?Client $client = null;

	/** Number of retries for connection failures and 5xx responses. */
	protected int $maxRetries = 3;

	/** Seconds to wait for the connection to be established. */
	protected float $connectTimeout = 2.0;

	/** Seconds to wait for the whole request to finish (0 = no limit). */
	protected float $timeout = 30.0;

	/** Base backoff between retries in milliseconds (grows exponentially). */
	protected int $retryBaseDelayMs = 250;

	public function setParameters(string $url, string $apiKey, ?string $host = null): void
	{
		$this->apiUrl = rtrim($url, '/') . '/api';
		$this->apiKey = $apiKey;
		$this->host = $host ?: preg_replace('(^https?://)', '', rtrim($url, '/'));
	}

	public function setOnLoadEntry(array $callbacks): void
	{
		$this->onLoadEntry = $callbacks;
	}

	public function setOnGetEntryOffset(array $callbacks): void
	{
		$this->onGetEntryOffset = $callbacks;
	}

	/**
	 * Tune HTTP resilience. Call before the first request (e.g. from DI setup).
	 */
	public function setHttpOptions(int $maxRetries = 3, float $connectTimeout = 2.0, float $timeout = 30.0, int $retryBaseDelayMs = 250): void
	{
		$this->maxRetries = $maxRetries;
		$this->connectTimeout = $connectTimeout;
		$this->timeout = $timeout;
		$this->retryBaseDelayMs = $retryBaseDelayMs;
		$this->client = null; // force rebuild with the new options
	}

	protected function getClient(): Client
	{
		if ($this->client !== null) {
			return $this->client;
		}

		$stack = HandlerStack::create();
		$stack->push(Middleware::retry(
			function (int $retries, $request, ?ResponseInterface $response = null, ?\Throwable $e = null): bool {
				if ($retries >= $this->maxRetries) {
					return false;
				}

				// Connection-level failures (e.g. cURL error 7 "couldn't connect",
				// DNS errors, connect timeouts) — typically a dependency being restarted.
				if ($e instanceof ConnectException) {
					return true;
				}

				// Transient server errors (5xx), surfaced either as a response or an exception.
				$status = $response?->getStatusCode();
				if ($status === null && $e instanceof RequestException && $e->getResponse() !== null) {
					$status = $e->getResponse()->getStatusCode();
				}

				return $status !== null && $status >= 500;
			},
			function (int $retries): int {
				return $this->retryBaseDelayMs * (2 ** ($retries - 1)); // ms: 250, 500, 1000, ...
			}
		));

		return $this->client = new Client([
			'handler' => $stack,
			'connect_timeout' => $this->connectTimeout,
			'timeout' => $this->timeout,
		]);
	}

	/**
	 * @param string $url
	 * @param array $data
	 * @return array
	 * @throws GuzzleException
	 */
	private function get(string $url, array $data = []): array
	{
		$response = $this->getClient()->request("GET", $url, [
			'headers' => [
				'Content-Type' => 'application/json',
				'Cockpit-Token' => $this->apiKey,
				'Host' => $this->host
			],
			'body' => json_encode($data),
		]);

		return json_decode($response->getBody()->getContents(), true);
	}

	/**
	 * @param string $collection
	 * @param array $filters
	 * @param array $sorts
	 * @return array
	 * @throws GuzzleException
	 */
	public function getEntries(string $collection, array $filters = [], array $sorts = [], int $limit = -1): array
	{
		$entries = [];
		foreach ($this->get($this->apiUrl . '/collections/get/' .  $collection, $this->getData($filters, $sorts, $limit))['entries'] as $_entry) {
			$entries[] = new Entry($_entry, $this->onLoadEntry, $this->onGetEntryOffset);
		}

		return $entries;
	}

	/**
	 * @throws GuzzleException
	 */
	public function getEntry(string $collection, array $filters = [], array $sorts = []): ?Entry
	{
		return $this->getEntries($collection, $filters, $sorts)[0] ?? null;
	}

	/**
	 * @throws GuzzleException
	 */
	public function getSingleton(string $singleton): array
	{
		return $this->get($this->apiUrl . '/singletons/get/' .  $singleton);
	}

	public static function getAssetPath(array|Entry $file, ?string $size = null): string
	{
		if ($file instanceof Entry) {
			$file = $file->toArray();
		}

		if (is_null($size)) {
			return static::UPLOADS_DIR . '/' . ltrim($file['path'], static::UPLOADS_DIR);
		}

		if (isset($file['sizes'][$size])) {
			return static::UPLOADS_DIR . $file['sizes'][$size]['path'];
		}

		return static::UPLOADS_DIR . '/' . $size . '/' . array_reverse(explode('/', $file['path']))[0];
	}

	private function getData(array $filters, array $sorts, int $limit = -1): array
	{
		return [
			'filter' => $filters,
			'sort' => $sorts,
			'limit' => $limit,
			'populate' => 1
		];
	}
}
