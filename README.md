# ADT Cockpit

PHP client of the [Cockpit CMS](https://getcockpit.com/) **v2** REST API (`/api/content/*`).
One class, `ADT\Cockpit\Cockpit`, used the same way in every project.

- Every read goes to Cockpit, the data is always fresh. The same read within one request
  (or CLI run) is sent only once; any write forgets it.
- Transient failures (connection error, timeout, HTTP 500/502/503/504) are retried.
- Optionally the last successful response of each read is kept and returned **only when Cockpit fails**,
  so a page does not break during a short outage (e.g. a stalled shared filesystem under Cockpit's SQLite).

Version 1.x is the client of Cockpit v1 (`/api/collections/get`), it lives on the `1.x` branch.

## Installation

```
composer require adt/cockpit ^2.0
```

PHP >= 8.3, `guzzlehttp/guzzle ^7.4`; `nette/caching` for the fallback storage, `tracy/tracy` for `TracyLogger`.

## Configuration (Nette DI)

```neon
services:
	cockpit:
		class: ADT\Cockpit\Cockpit
		setup:
			- setParameters(%cockpit.url%, %cockpit.apiKey%, %cockpit.host%)
			- setFallbackStorage(@Nette\Caching\Storage)
			- setOnError([ADT\Cockpit\TracyLogger()])
```

`TracyLogger` logs HTTP 4xx (wrong key, missing permission, unknown model) as an exception, so it is e-mailed
in production mode; unavailability goes as one line into `log/cockpit.log`, without e-mail. A missing singleton
(404) is an expected answer, it is not reported.

A second Cockpit (e.g. careers from cockpit.akesoholding.cz) is a second service of the same class.

| Method | Default | Meaning |
|---|---|---|
| `setParameters($url, $apiKey, $host = null)` | | `$url` with or without `/api`. `$host` = `Host` header when the API is called through an internal address (`http://nginx`). |
| `setHttpOptions($maxRetries, $connectTimeout, $timeout, $retryBaseDelayMs)` | `1, 2.0, 5.0, 250` | Worst case of one read without fallback: (1 + retries) × timeout + delays = 10.25 s. Raise for CLI jobs only. |
| `setUploadTimeout($seconds)` | `300` | Timeout of `upload()`. |
| `setFallbackStorage(Storage $storage, $failureHold = 30)` | off | Keeps the last successful response of each read. Used only on `UnavailableException`. After a failure no read goes to this Cockpit for `$failureHold` seconds, counted from the end of the failed request: every read returns its stored data, or throws immediately. Without the storage the hold applies within one request only. |
| `setOnError([callable])` | | `callback(Throwable $e, string $url, bool $servedFromFallback)` on every failed request. The library does not log. |
| `setPopulate($depth)` | `1` | Default depth of populated links; `0` keeps links as `{_id, _model}`. |
| `setLocale($locale)` | `null` | Default locale of reads. |
| `setNestedEntries(true)` | `false` | Nested arrays become `Entry` on the first read (behaviour of 1.x). |
| `setOnLoadEntry([callable])`, `setOnGetEntryOffset([callable])` | | Callbacks of `Entry`, e.g. localization `title` → `title_en`. |
| `setSortableModels([...])` | | Models sorted by `_o` when `getEntries()` gets no filter, sort and limit (tree models, `sortable` collections of v1). |

## Usage

```php
// collection
$articles = $cockpit->getEntries('Article', ['magazine._id' => $id], ['_created' => 'DESC'], 10);
$organizations = $cockpit->getEntries('Organization', sort: ['order' => 1], fields: ['_id' => true, 'title' => true]);
$article = $cockpit->getEntry('Article', ['url' => $url]);                  // ?Entry
$career = $cockpit->getEntry('Career', ['slug' => $slug], locale: 'en', populate: 2);

// singleton
$footer = $cockpit->getSingleton('Footer');                                   // array, [] when missing

// write
$cockpit->saveEntry('Applicant', ['name' => $name, 'career' => Cockpit::link($careerId, 'Career')]);
$cockpit->saveEntry('Applicant', ['_id' => $id, 'note' => $note]);           // changes only the given fields
$asset = $cockpit->upload($path, 'application/pdf', 'cv.pdf');               // project endpoint assets/upload, field files[]

// files
Cockpit::getAssetPath($article['image']);                                     // /storage/uploads/…
Cockpit::getAssetPath($article['image'], 'thumbnail');
```

`getEntries(string $model, array $filter = [], array $sort = [], int $limit = -1, ?string $locale = null, array $fields = [], ?int $populate = null)`

`Entry` is accessed like an array, a missing key returns `null`; `toArray()` and `json_encode()` give plain arrays.
Links to deleted items (`false` in Cockpit v2) are removed from lists.

## Errors

All exceptions implement `GuzzleHttp\Exception\GuzzleException`, so existing `catch (GuzzleException $e)` keeps working.

| Exception | When | Retried | Fallback |
|---|---|---|---|
| `ADT\Cockpit\Exception\UnavailableException` | connection error, timeout, HTTP 5xx, response that is not JSON | reads always; writes only after cURL 6/7 (request did not leave) | yes |
| `ADT\Cockpit\Exception\RequestException` | HTTP 4xx (`getStatusCode()`): wrong key (412), missing permission, unknown model (404) | no | no |

## Cockpit v2 notes

- Filters compare types strictly: `"311579"` does not match `311579`. Data migrated from v1 often has numbers as strings.
- A link is filtered by `<field>._id`.
- Links have to be written with `_model` (`Cockpit::link()`), otherwise `populate` does not resolve them.
- The API returns only published items (`_state = 1`).

## Upgrade from 1.x / from project copies

| 1.x / project copy | 2.x |
|---|---|
| 1.x `getEntries($c, $filters, $sorts, $limit)` | same; `$locale`, `$fields`, `$populate` as further (named) arguments |
| filema `getEntries($c, $locale, $filters, $sorts)` | `getEntries($c, $filters, $sorts, locale: $locale)` |
| ms-nemo, filema (no populate) | `setPopulate(0)` |
| senimed, 1.x nested `Entry` with callbacks; hero-and-outlaw nested `Entry` | `setNestedEntries(true)` |
| cteam `CockpitClient::items($m, $options)` / `item($m, ['filter' => …])` / `item($m)` | `getEntries()` / `getEntry()` / `getSingleton()` |
| akesoholding, nemocnice `getCollectionItems($m, ['filter' => …, 'sort' => …, 'fields' => …])` | `getEntries($m, $filter, $sort, fields: $fields)` |
| `getOneCollectionItem($m, …)` | `getEntry($m, …)` |
| `createOrUpdate($m, ['data' => $data])` | `saveEntry($m, $data)` |
| `uploadAsset(FileUpload $f)` | `upload($f->getTemporaryFile(), $f->getContentType(), $f->getUntrustedName())` |
| project `CockpitCache` (filema) | `setFallbackStorage()` |
| defaults 3 retries / 30 s timeout | 1 retry / 5 s; `setHttpOptions()` to change |

## Tests

```
composer install
vendor/bin/tester tests -C
```

## License

MIT
