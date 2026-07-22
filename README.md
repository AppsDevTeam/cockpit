# ADT Cockpit

A small PHP client for the [Cockpit CMS](https://getcockpit.com/) content API. It fetches
collections and singletons over HTTP (Guzzle), wraps entries in a convenient, lazily
evaluated `Entry` object, and supports per-field callbacks (e.g. for localization).

## Installation

```
composer require adt/cockpit
```

Requires PHP >= 8.0 and `guzzlehttp/guzzle ^7.0`.

## Configuration

Register the service in your Nette DI config (`config.neon`):

```neon
services:
	cockpit:
		class: ADT\Cockpit\Cockpit
		setup:
			- setParameters(%cockpit.url%, %cockpit.apiKey%, %cockpit.host%)
			# - setHttpOptions(3, 2.0, 30.0, 250)  # optional, see "HTTP resilience"
			# - setOnGetEntryOffset([[@App\Model\CockpitFilters, localizeValue]])  # optional, see "Callbacks"
```

```neon
parameters:
	cockpit:
		url: https://cockpit.example.com
		apiKey: your-cockpit-api-token
		host: null
```

### `setParameters(string $url, string $apiKey, ?string $host = null)`

| Argument | Description |
|----------|-------------|
| `$url`    | Base URL of the Cockpit instance. `/api` is appended automatically. |
| `$apiKey` | Cockpit API token, sent as the `Cockpit-Token` header. |
| `$host`   | Optional `Host` header override. When omitted, it is derived from `$url`. |

The `$host` override exists for setups where Cockpit is reached over an **internal address**
(e.g. a Docker service name) while it must still receive a public host name for routing:

```neon
parameters:
	cockpit:
		url: http://nginx          # internal, reached over the container network
		host: cockpit.example.com  # sent as the Host header so the backend routes correctly
```

## Usage

Once configured, inject the service and query your content.

```php
use ADT\Cockpit\Cockpit;

public function __construct(private Cockpit $cockpit) {}
```

### Collections

```php
// All entries of a collection, optionally filtered and sorted.
// getEntries(string $collection, array $filter = [], array $sort = [], int $limit = -1): Entry[]
$pages = $this->cockpit->getEntries('sites', [], ['position' => 'ASC']);

foreach ($pages as $page) {
	echo $page['internalKey'];
}

// A single entry (the first match, fetched with limit 1), or null.
// getEntry(string $collection, array $filter = [], array $sort = []): ?Entry
$page = $this->cockpit->getEntry('sites', ['slug' => 'contact']);
```

`$filter` and `$sort` are passed through to Cockpit as its `filter` and `sort` query
parameters; `$limit` defaults to `-1` (no limit). Entries are returned already populated
(`populate = 1`).

### Singletons

```php
// getSingleton(string $singleton): array
$footer = $this->cockpit->getSingleton('Footer');
echo $footer['address'];
```

### The `Entry` object

`getEntries()` / `getEntry()` return `Entry` objects (singletons are returned as plain
arrays). `Entry` implements `ArrayAccess`, `Countable` and `IteratorAggregate`, so you can
use it like an array:

```php
$page['title'];                 // read a field
isset($page['subtitle']);       // check a field
count($page);                   // number of fields
foreach ($page as $key => $val) // iterate

$page->toArray();               // the raw underlying array (nested Entry objects are unwrapped)
```

Nested arrays are wrapped in `Entry` objects too, so deep access works the same way. The
exception is file/asset fields (arrays containing `path` and `mime`) — those intentionally
stay plain arrays, ready to be passed to `getAssetPath()`.

Evaluation is **lazy**: the registered callbacks (see below) run on first access — any
field read, `isset()`, `count()` or iteration — not when the entry is fetched.

### Assets

`getAssetPath()` builds the public path (under `/storage/uploads`) for a Cockpit asset,
optionally for a specific image size:

```php
use ADT\Cockpit\Cockpit;

Cockpit::getAssetPath($entry['image']);            // original
Cockpit::getAssetPath($entry['image'], 'thumb');   // a named size
```

## HTTP resilience

Cockpit content is typically fetched over HTTP on **every request**, so a brief
unavailability of the backend — for example an nginx/Cockpit container being recreated
during a deploy or image update — would otherwise surface directly as a request error
(e.g. *cURL error 7: Failed to connect*) and, in turn, as an error page for the visitor.

The client therefore uses a Guzzle retry middleware and sensible timeouts by default:

- **Retries** on connection-level failures (`ConnectException`: connection refused, DNS
  errors, connect timeouts) and on transient **5xx** responses. `4xx` responses are **not**
  retried.
- **Exponential backoff** between attempts.
- **Connect and overall timeouts** so a stuck backend fails fast instead of hanging.

Defaults are applied automatically — no configuration required. To tune them, call
`setHttpOptions()` before the first request (e.g. from DI setup):

```neon
services:
	cockpit:
		class: ADT\Cockpit\Cockpit
		setup:
			- setParameters(%cockpit.url%, %cockpit.apiKey%, %cockpit.host%)
			- setHttpOptions(3, 2.0, 5.0, 250)  # e.g. a tighter timeout for a latency-sensitive site
```

### `setHttpOptions(int $maxRetries = 3, float $connectTimeout = 2.0, float $timeout = 30.0, int $retryBaseDelayMs = 250)`

| Argument            | Default | Description |
|---------------------|---------|-------------|
| `$maxRetries`       | `3`     | Number of retries on connection failures / 5xx responses (4 attempts total). |
| `$connectTimeout`   | `2.0`   | Seconds to wait for the connection to be established. |
| `$timeout`          | `30.0`  | Seconds to wait for the whole request to finish. `0` disables the limit. |
| `$retryBaseDelayMs` | `250`   | Base backoff in milliseconds; grows exponentially (250, 500, 1000, …). |

With the defaults, fast connection failures (connection refused while a container is
being recreated) are absorbed transparently, adding at most ~1.75 s of backoff before the
request finally fails. When the failure is a timeout, each attempt itself may additionally
take up to `$connectTimeout` (or `$timeout`) seconds.

### Choosing a timeout

An exceeded **total timeout** surfaces as a connection error (cURL error 28) and is
therefore **retried** as well — a request that genuinely needs longer than `$timeout`
gets attempted `$maxRetries + 1` times before failing, multiplying the load on the
backend. Keep `$timeout` comfortably above your slowest legitimate response:

- **Latency-sensitive web pages** may prefer a tighter timeout, e.g.
  `setHttpOptions(3, 2.0, 5.0)`, so a stuck backend fails fast.
- **Console commands / batch reads** of large collections (thousands of populated
  entries in a single `getEntries()` call) should raise the limit, or disable it
  entirely with `setHttpOptions(3, 2.0, 0)` — that matches the pre-1.3 behavior of
  no timeout at all.

### Errors

Fetch methods throw `GuzzleHttp\Exception\GuzzleException` when the request ultimately
fails (including 4xx/5xx responses, after any retries) and `JsonException` when the
response body is not valid JSON.

## Callbacks

Two callback hooks let you post-process entry data. Each callback receives the entry's
values **by reference**, so it can mutate them in place. They apply to `Entry` objects
(collections), not to singletons.

### `setOnGetEntryOffset(array $callbacks)`

Called every time a field is read. The callback signature is
`function (array &$values, string|int $offset)`. This is the typical place for
**localization** — resolving a locale-specific field on access:

```neon
services:
	cockpit:
		setup:
			- setOnGetEntryOffset([[@App\Model\CockpitFilters, localizeValue]])
```

### `setOnLoadEntry(array $callbacks)`

Called once, the first time any field of an entry is accessed. The callback signature is
`function (array &$values)`. Use it for one-off preparation of an entry before its fields
are read.

## License

MIT — see [LICENSE](LICENSE).
