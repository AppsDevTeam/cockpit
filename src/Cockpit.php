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

	protected ?CockpitTranslator $translator = null;

	public function setParameters(string $url, string $apiKey, ?string $host = null): void
	{
		$this->apiUrl = rtrim($url, '/') . '/api';
		$this->apiKey = $apiKey;
		$this->host = $host ?: preg_replace('(^https?://)', '', rtrim($url, '/'));
	}

	public function setTranslator(CockpitTranslator $translator): void
	{
		$this->translator = $translator;
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
		//file_put_contents('aaa', print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), true), FILE_APPEND);
		$_entries = $this->get($this->apiUrl . '/collections/get/' .  $collection, $this->getData($filters, $sorts))['entries'];
		//print_r ($entries);

		$entries = [];
		foreach ($_entries as &$_entry) {
			$entries[] = Entry::from($_entry);

			//$entry = $this->replaceCollectionLinks($entry);

//			if ($this->translator) {
//				$this->replaceLocalizedValues($entry);
//			}
		}

		return $entries;
	}

	/**
	 * @throws GuzzleException
	 */
	public function getEntry(string $collection, array $filters = [], array $sorts = []): ?array
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

	private function replaceLocalizedValues(array &$entry): void
	{
		if ($this->translator->getLocale() === $this->translator->getDefaultLocale()) {
			return;
		}

		foreach ($entry as $field => &$value) {
			$localizedField = $field . '_' . $this->translator->getLocale();
			$localizedField = isset($entry[$localizedField]) ? $localizedField : $field;

			$value = $entry[$localizedField];
		}
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
