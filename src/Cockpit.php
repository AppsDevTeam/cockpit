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

	public function setOnLoadEntry(array $callbacks): void
	{
		$this->onLoadEntry = $callbacks;
	}

	public function setOnGetEntryOffset(array $callbacks): void
	{
		$this->onGetEntryOffset = $callbacks;
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
