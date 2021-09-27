<?php
declare(strict_types=1);

namespace ADT;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Cockpit
{
	const SIZE_SMALL = 'small';
	const SIZE_THUMBS = 'thumbs';
	const SIZE_HEADER_IMG = 'headerimage';
	const SIZE_FULL = 'full';

	protected string $apiUrl;
	protected string $apiToken;

	public function setParameters(string $url, string $apiToken): void
	{
		$this->apiUrl = rtrim($url, '/') . '/api';
		$this->apiToken = $apiToken;
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
				'Cockpit-Token' => $this->apiToken
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
		$entries = $this->get($this->apiUrl . '/collections/get/' .  $collection, $this->getData($filters, $sorts))['entries'];

		foreach ($entries as &$entry) {
			$entry = $this->replaceCollectionLinks($entry);
		}
		
		return $entries;
	}

	/**
	 * @param string $collection
	 * @param array $filters
	 * @param array $sorts
	 * @return array|null
	 * @throws GuzzleException
	 */
	public function getEntry(string $collection, array $filters = [], array $sorts = []): ?array
	{
		return $this->getEntries($collection, $filters, $sorts)[0] ?? null;
	}

	/**
	 * @param string $singleton
	 * @return array
	 * @throws GuzzleException
	 */
	public function getSingleton(string $singleton): array
	{
		return $this->get($this->apiUrl . '/singletons/get/' .  $singleton);
	}

	public static function getThumbnailPath(string $size, array $entry): string
	{
		if (in_array($size, [
			self::SIZE_SMALL, self::SIZE_THUMBS, self::SIZE_HEADER_IMG, self::SIZE_FULL
		])) {
			if (isset($entry['sizes'])) {
				// asset
				return $entry['sizes'][$size]['path'];
			} else {
				// gallery, image
				preg_match("/[^\/]+$/", $entry['path'], $matches);
				return "/" . $size . "/" . $matches[0];
			}
		}
	}

	/**
	 * @param array $entry
	 * @return array
	 * @throws GuzzleException
	 */
	private function replaceCollectionLinks(array $entry): array
	{
		if (!isset($entry['slug'])) {
			return $entry;
		}

		foreach ($entry as &$value) {
			if (!is_string($value)) {
				continue;
			}

			$value = preg_replace_callback(
				'|collection://[0-9A-z/]+|',
				function($matches) {
					[$collection, $id] = explode('/', str_replace('collection://', '', $matches[0]));
					return $this->getEntry($collection, ['_id' => $id])['slug'];
				},
				$value
			);
		}

		return $entry;
	}

	private function getData(array $filters, array $sorts): array
	{
		return [
			'filter' => $filters,
			'sort' => $sorts,
		];
	}
}
