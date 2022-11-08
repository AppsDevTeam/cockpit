<?php
declare(strict_types=1);

namespace ADT\Cockpit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Cockpit
{
	const UPLOADS_DIR = "/storage/uploads";

	protected string $apiUrl;
	protected string $apiKey;
	protected string $host;

	protected array $onLoadEntry = [];

	protected array $onGetEntryOffset = [];

	public function setParameters(string $url, string $apiKey, ?string $host = null): void
	{
		$this->apiUrl = rtrim($url, '/') . '/api';
		$this->apiKey = $apiKey;
		$this->host = $host ?: preg_replace('(^https?://)', '', rtrim($url, '/'));
	}

	/**
	 * @param string $url
	 * @param array $data
	 * @return array
	 * @throws GuzzleException
	 */
	private function get(string $url, array $data = []): array
	{
		$client = new Client(['base_uri' => $this->apiUrl]);
		$response = $client->request("GET",  $url, [
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
	public function getEntries(string $collection, array $filters = [], array $sorts = []): array
	{
		$entries = [];
		foreach ($this->get($this->apiUrl . '/collections/get/' .  $collection, $this->getData($filters, $sorts))['entries'] as $_entry) {
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

	public static function getAssetPath(array $entry, ?string $size = null): string
	{
		if (is_null($size)) {
			return static::UPLOADS_DIR . '/' . ltrim($entry['path'], static::UPLOADS_DIR);
		}

		if (isset($entry['sizes'][$size])) {
			return static::UPLOADS_DIR . $entry['sizes'][$size]['path'];
		}

		return static::UPLOADS_DIR . '/' . $size . '/' . array_reverse(explode('/', $entry['path']))[0];
	}

	private function getData(array $filters, array $sorts): array
	{
		return [
			'filter' => $filters,
			'sort' => $sorts,
			'populate' => 1
		];
	}
}
