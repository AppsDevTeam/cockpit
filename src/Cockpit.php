<?php

declare(strict_types=1);

namespace ADT\Cockpit;

use ADT\Cockpit\Exception\RequestException;
use ADT\Cockpit\Exception\UnavailableException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Utils;
use JsonException;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Client of the Cockpit v2 REST API (`/api/content/*`, API key in the `api-key` header).
 *
 * Every read goes to Cockpit, so the data is always fresh; the same GET within one request
 * (or CLI run) is sent only once, and any write forgets it. Transient failures are retried,
 * see setHttpOptions(). With setFallbackStorage() the last successful response of each GET
 * is kept and returned only when Cockpit fails, see request().
 */
class Cockpit
{
	public const UPLOADS_DIR = '/storage/uploads';

	/** HTTP statuses worth retrying; 501 and 505 are permanent. */
	private const RETRY_STATUSES = [500, 502, 503, 504];

	/** cURL errors after which the request certainly did not reach the server (6 = DNS, 7 = connection refused). */
	private const NOT_SENT_CURL_ERRORS = [6, 7];

	private const CACHE_NAMESPACE = 'ADT.Cockpit';

	private string $apiUrl;
	private string $apiKey;

	/** Host header, when the API is called through an internal address (e.g. http://nginx inside the docker network). */
	private ?string $host = null;

	private int $maxRetries = 1;
	private float $connectTimeout = 2.0;
	private float $timeout = 5.0;
	private int $retryBaseDelayMs = 250;
	private float $uploadTimeout = 300.0;

	private ?Cache $fallbackCache = null;
	private int $failureHold = 30;

	/** @var list<callable(Throwable, string, bool): void> */
	private array $onError = [];

	private int $populate = 1;
	private ?string $locale = null;
	private bool $nestedEntries = false;

	/** @var list<string> */
	private array $sortableModels = [];

	/** @var list<callable(array<string|int, mixed>&): void> */
	private array $onLoadEntry = [];

	/** @var list<callable(array<string|int, mixed>, string|int&): void> */
	private array $onGetEntryOffset = [];

	/** @var callable|null */
	private $httpHandler = null;

	private ?Client $http = null;

	/** @var array<string, mixed> responses of GET requests sent during this request/run */
	private array $memory = [];


	public function __construct(?string $url = null, ?string $apiKey = null, ?string $host = null)
	{
		if ($url !== null && $apiKey !== null) {
			$this->setParameters($url, $apiKey, $host);
		}
	}


	/**
	 * @param string $url Cockpit URL, with or without the trailing `/api`
	 * @param string|null $host Host header; null = taken from $url
	 */
	public function setParameters(string $url, string $apiKey, ?string $host = null): void
	{
		$url = rtrim($url, '/');
		$this->apiUrl = (str_ends_with($url, '/api') ? $url : $url . '/api') . '/';
		$this->apiKey = $apiKey;
		$this->host = $host !== null && $host !== '' ? $host : null;
		$this->http = null;
		$this->memory = [];
	}


	/**
	 * Worst case of one read without a fallback: (1 + $maxRetries) × $timeout + delays,
	 * i.e. 2 × 5 s + 0.25 s with the defaults. Raise the values for CLI jobs, not for web requests.
	 */
	public function setHttpOptions(
		int $maxRetries = 1,
		float $connectTimeout = 2.0,
		float $timeout = 5.0,
		int $retryBaseDelayMs = 250,
	): void
	{
		$this->maxRetries = $maxRetries;
		$this->connectTimeout = $connectTimeout;
		$this->timeout = $timeout;
		$this->retryBaseDelayMs = $retryBaseDelayMs;
		$this->http = null;
	}


	public function setUploadTimeout(float $uploadTimeout): void
	{
		$this->uploadTimeout = $uploadTimeout;
	}


	/**
	 * Keeps the last successful response of every read. It is returned only when Cockpit fails
	 * (UnavailableException), never instead of a successful response. After a failure Cockpit
	 * is not called for $failureHold seconds, so that a stalled Cockpit does not hold every
	 * PHP worker for the whole timeout.
	 */
	public function setFallbackStorage(Storage $storage, int $failureHold = 30): void
	{
		$this->fallbackCache = new Cache($storage, self::CACHE_NAMESPACE);
		$this->failureHold = $failureHold;
	}


	/**
	 * Called on every failed request: callback(Throwable $e, string $url, bool $servedFromFallback).
	 * The library does not log by itself, this is the place to do it.
	 * @param list<callable(Throwable, string, bool): void> $callbacks
	 */
	public function setOnError(array $callbacks): void
	{
		$this->onError = $callbacks;
	}


	/**
	 * Default depth of populated links, 0 = links stay as {_id, _model}. Default 1, as in 1.x.
	 */
	public function setPopulate(int $populate): void
	{
		$this->populate = $populate;
	}


	/**
	 * Default locale of reads; null = the default locale of Cockpit.
	 */
	public function setLocale(?string $locale): void
	{
		$this->locale = $locale;
	}


	/**
	 * Nested arrays of entries become Entry (with the callbacks) on the first read, as in 1.x.
	 */
	public function setNestedEntries(bool $nestedEntries = true): void
	{
		$this->nestedEntries = $nestedEntries;
	}


	/**
	 * Models sorted by `_o` when getEntries() gets no filter, sort and limit
	 * (models of type tree, `sortable` collections in Cockpit v1).
	 * @param list<string> $models
	 */
	public function setSortableModels(array $models): void
	{
		$this->sortableModels = $models;
	}


	/**
	 * @param list<callable(array<string|int, mixed>&): void> $callbacks
	 */
	public function setOnLoadEntry(array $callbacks): void
	{
		$this->onLoadEntry = $callbacks;
	}


	/**
	 * @param list<callable(array<string|int, mixed>, string|int&): void> $callbacks
	 */
	public function setOnGetEntryOffset(array $callbacks): void
	{
		$this->onGetEntryOffset = $callbacks;
	}


	/**
	 * For tests: Guzzle handler, e.g. GuzzleHttp\Handler\MockHandler.
	 */
	public function setHttpHandler(callable $handler): void
	{
		$this->httpHandler = $handler;
		$this->http = null;
	}


	/**
	 * Items of a collection model.
	 * @param array<string, mixed> $filter Mongo-like filter of Cockpit v2; types are compared strictly,
	 *        a link is filtered by `<field>._id`
	 * @param array<string, int|string> $sort direction 1/-1, or 'ASC'/'DESC'
	 * @param array<string, bool|int> $fields projection, e.g. ['title' => true]
	 * @param int|null $populate depth of populated links; null = setPopulate()
	 * @return list<Entry>
	 * @throws UnavailableException
	 * @throws RequestException
	 */
	public function getEntries(
		string $model,
		array $filter = [],
		array $sort = [],
		int $limit = -1,
		?string $locale = null,
		array $fields = [],
		?int $populate = null,
	): array
	{
		if (!$filter && !$sort && $limit < 0 && in_array($model, $this->sortableModels, true)) {
			$sort = ['_o' => 1];
		}

		$result = $this->get('content/items/' . rawurlencode($model), [
			'filter' => $filter ?: null,
			'sort' => $sort ? self::normalizeSort($sort) : null,
			'limit' => $limit > 0 ? $limit : null,
			'fields' => $fields ?: null,
			'populate' => ($populate ?? $this->populate) ?: null,
			'locale' => $locale ?? $this->locale,
		]);

		// with `skip` Cockpit wraps the list in {data, meta}
		if (is_array($result) && !array_is_list($result) && isset($result['data']) && is_array($result['data'])) {
			$result = $result['data'];
		}

		if (!is_array($result) || !array_is_list($result)) {
			return [];
		}

		return array_values(array_map(
			fn(array $item): Entry => new Entry(self::removeUnresolvedLinks($item), $this->nestedEntries, $this->onLoadEntry, $this->onGetEntryOffset),
			array_filter($result, 'is_array'),
		));
	}


	/**
	 * First item of a collection model matching the filter.
	 * @param array<string, mixed> $filter
	 * @param array<string, int|string> $sort
	 * @param array<string, bool|int> $fields
	 * @throws UnavailableException
	 * @throws RequestException
	 */
	public function getEntry(
		string $model,
		array $filter = [],
		array $sort = [],
		?string $locale = null,
		array $fields = [],
		?int $populate = null,
	): ?Entry
	{
		return $this->getEntries($model, $filter, $sort, 1, $locale, $fields, $populate)[0] ?? null;
	}


	/**
	 * Data of a singleton model.
	 * @return array<string, mixed> empty when the singleton does not exist
	 * @throws UnavailableException
	 * @throws RequestException
	 */
	public function getSingleton(string $model, ?string $locale = null, ?int $populate = null): array
	{
		try {
			$result = $this->get('content/item/' . rawurlencode($model), [
				'populate' => ($populate ?? $this->populate) ?: null,
				'locale' => $locale ?? $this->locale,
			]);
		} catch (RequestException $e) {
			if ($e->getStatusCode() === 404) {
				return [];
			}

			throw $e;
		}

		return is_array($result) ? self::removeUnresolvedLinks($result) : [];
	}


	/**
	 * Creates an item, or with `_id` changes only the given fields of a stored item.
	 * Links to other items have to be written as link() — without `_model` Cockpit does not populate them.
	 * @param array<string, mixed> $data
	 * @return array<string, mixed> the stored item
	 * @throws UnavailableException
	 * @throws RequestException
	 */
	public function saveEntry(string $model, array $data): array
	{
		$result = $this->request('POST', 'content/item/' . rawurlencode($model), ['json' => ['data' => $data]]);

		return is_array($result) ? $result : [];
	}


	/**
	 * Uploads a file to a project endpoint. Cockpit v2 has no REST upload, projects add their own
	 * (e.g. akesoholding .docker/cockpit/config/api/assets/upload.post.php, field `files[]`).
	 * @return array<mixed> response of the endpoint
	 * @throws UnavailableException
	 * @throws RequestException
	 */
	public function upload(string $file, ?string $mimeType = null, ?string $fileName = null, string $endpoint = 'assets/upload'): array
	{
		$result = $this->request('POST', ltrim($endpoint, '/'), [
			'multipart' => [[
				'name' => 'files[]',
				'contents' => Utils::tryFopen($file, 'r'),
				'filename' => $fileName ?? basename($file),
				'headers' => $mimeType !== null ? ['Content-Type' => $mimeType] : [],
			]],
			'timeout' => $this->uploadTimeout,
		]);

		return is_array($result) ? $result : [];
	}


	/**
	 * Link to another item in the form Cockpit v2 populates.
	 * @return array{_id: string, _model: string}
	 */
	public static function link(string $id, string $model): array
	{
		return ['_id' => $id, '_model' => $model];
	}


	/**
	 * URL path of an asset file: without $size the original, otherwise the thumbnail from `sizes`,
	 * or `<size>/<file name>`.
	 * @param array<string, mixed>|Entry $file
	 */
	public static function getAssetPath(array|Entry $file, ?string $size = null): string
	{
		if ($file instanceof Entry) {
			$file = $file->toArray();
		}

		if ($size === null) {
			$path = $file['path'];
			if (str_starts_with($path, self::UPLOADS_DIR)) {
				$path = substr($path, strlen(self::UPLOADS_DIR));
			}

			return self::UPLOADS_DIR . '/' . ltrim($path, '/');
		}

		if (isset($file['sizes'][$size])) {
			return self::UPLOADS_DIR . '/' . ltrim($file['sizes'][$size]['path'], '/');
		}

		return self::UPLOADS_DIR . '/' . $size . '/' . basename($file['path']);
	}


	/**
	 * @param array<string, mixed> $query arrays are sent as JSON, null values are skipped
	 * @throws UnavailableException
	 * @throws RequestException
	 */
	private function get(string $path, array $query): mixed
	{
		return $this->request('GET', $path, ['query' => self::encodeQuery($query)]);
	}


	/**
	 * @param array<string, mixed> $options Guzzle request options
	 * @throws UnavailableException
	 * @throws RequestException
	 */
	private function request(string $method, string $path, array $options): mixed
	{
		$url = $this->apiUrl . $path;

		if ($method !== 'GET') {
			$this->memory = [];

			try {
				return $this->send($method, $url, $options);
			} catch (UnavailableException | RequestException $e) {
				$this->reportError($e, $url, false);
				throw $e;
			}
		}

		$key = md5(implode("\n", [$this->host, $url, json_encode($options['query'] ?? []), md5($this->apiKey)]));
		if (array_key_exists($key, $this->memory)) {
			return $this->memory[$key];
		}

		$cached = $this->fallbackCache?->load($key);
		$hasFallback = is_array($cached) && array_key_exists('data', $cached);
		$now = time();

		if (is_array($cached) && ($cached['failedAt'] ?? 0) + $this->failureHold > $now) {
			if ($hasFallback) {
				return $this->memory[$key] = $cached['data'];
			}

			throw new UnavailableException(sprintf('Cockpit failed %d s ago, not called again yet: %s', $now - $cached['failedAt'], $url));
		}

		try {
			$data = $this->send($method, $url, $options);

		} catch (UnavailableException $e) {
			$this->reportError($e, $url, $hasFallback);
			$this->fallbackCache?->save($key, ($hasFallback ? ['data' => $cached['data']] : []) + ['failedAt' => $now]);

			if ($hasFallback) {
				return $this->memory[$key] = $cached['data'];
			}

			throw $e;

		} catch (RequestException $e) {
			$this->reportError($e, $url, false);
			throw $e;
		}

		// unchanged data is not written again, a page view should not mean a disk write
		if (!$hasFallback || isset($cached['failedAt']) || $cached['data'] !== $data) {
			$this->fallbackCache?->save($key, ['data' => $data]);
		}

		return $this->memory[$key] = $data;
	}


	/**
	 * @param array<string, mixed> $options
	 * @throws UnavailableException
	 * @throws RequestException
	 */
	private function send(string $method, string $url, array $options): mixed
	{
		$options['headers'] = ($options['headers'] ?? [])
			+ ['api-key' => $this->apiKey, 'Accept' => 'application/json']
			+ ($this->host !== null ? ['Host' => $this->host] : []);

		try {
			$response = $this->getHttpClient()->request($method, $url, $options);

		} catch (ConnectException $e) {
			throw new UnavailableException('Cockpit connection failed: ' . $e->getMessage(), 0, $e);

		} catch (BadResponseException $e) {
			$status = $e->getResponse()->getStatusCode();
			if ($status >= 500) {
				throw new UnavailableException(sprintf('Cockpit responded %d: %s', $status, $url), $status, $e);
			}

			throw new RequestException(sprintf('Cockpit rejected the request (%d): %s', $status, $url), $status, $e);

		} catch (GuzzleException $e) {
			throw new UnavailableException('Cockpit request failed: ' . $e->getMessage(), 0, $e);
		}

		$body = (string) $response->getBody();
		if ($body === '') {
			return null;
		}

		try {
			return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			// typically an HTML error page of a proxy in front of Cockpit
			throw new UnavailableException(sprintf('Cockpit returned invalid JSON (HTTP %d): %s', $response->getStatusCode(), $url), 0, $e);
		}
	}


	private function reportError(Throwable $e, string $url, bool $servedFromFallback): void
	{
		foreach ($this->onError as $callback) {
			$callback($e, $url, $servedFromFallback);
		}
	}


	private function getHttpClient(): Client
	{
		if ($this->http !== null) {
			return $this->http;
		}

		$stack = HandlerStack::create($this->httpHandler);
		$stack->push(Middleware::retry(
			function (int $retries, RequestInterface $request, ?ResponseInterface $response = null, ?Throwable $e = null): bool {
				if ($retries >= $this->maxRetries) {
					return false;
				}

				// A write is repeated only when it certainly did not reach Cockpit, otherwise it might have been stored.
				if ($e instanceof ConnectException) {
					return $request->getMethod() === 'GET'
						|| in_array($e->getHandlerContext()['errno'] ?? null, self::NOT_SENT_CURL_ERRORS, true);
				}

				if ($request->getMethod() !== 'GET') {
					return false;
				}

				$status = $response?->getStatusCode();
				if ($status === null && $e instanceof BadResponseException) {
					$status = $e->getResponse()->getStatusCode();
				}

				return in_array($status, self::RETRY_STATUSES, true);
			},
			fn(int $retries): int => $this->retryBaseDelayMs * (2 ** ($retries - 1)),
		));

		return $this->http = new Client([
			'handler' => $stack,
			'connect_timeout' => $this->connectTimeout,
			'timeout' => $this->timeout,
		]);
	}


	/**
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	private static function encodeQuery(array $query): array
	{
		$encoded = [];
		foreach ($query as $key => $value) {
			if ($value === null) {
				continue;
			}

			$encoded[$key] = match (true) {
				is_array($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				is_bool($value) => (int) $value,
				default => $value,
			};
		}

		return $encoded;
	}


	/**
	 * @param array<string, int|string> $sort
	 * @return array<string, int>
	 */
	private static function normalizeSort(array $sort): array
	{
		return array_map(fn(int|string $direction): int => match (strtolower((string) $direction)) {
			'asc' => 1,
			'desc' => -1,
			default => (int) $direction,
		}, $sort);
	}


	/**
	 * Cockpit v2 returns a link to a deleted item as `false`. In lists (multiple links) it is removed,
	 * so templates get only existing items; a single field keeps it, `false` may be a boolean value.
	 * @param array<mixed> $values
	 * @return array<mixed>
	 */
	private static function removeUnresolvedLinks(array $values): array
	{
		$isList = array_is_list($values);
		foreach ($values as $key => $value) {
			if ($value === false && $isList) {
				unset($values[$key]);
			} elseif (is_array($value)) {
				$values[$key] = self::removeUnresolvedLinks($value);
			}
		}

		return $isList ? array_values($values) : $values;
	}
}
