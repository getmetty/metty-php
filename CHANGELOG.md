# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows
[SemVer](https://semver.org/).

## [Unreleased]

### Fixed

- `synchronize()` rejects an empty snapshot before opening a sync on the server.
- `commit()` validates the response and throws a `TransportException` when `sync_id`, `status`,
  `kept` or `removed` is missing or has the wrong type.
- A request body that cannot be encoded as JSON (for example invalid UTF-8) throws a
  `ConfigurationException` instead of a raw `JsonException`.
- A network failure message now includes the cause, and a retried network error is logged like a
  retried status.

### Removed

- The nested `{"error": {"code", "message"}}` error shape is no longer decoded; the API returns the
  flat `{"error", "message"}` form only.

## [1.3.0] — 2026-10-23

### Added

- `CatalogProduct` accepts `item_group_id` and `create()` has an `itemGroupId` argument. Products with
  the same value count as one product towards the Metty plan limit. Requires the matching Catalog API
  release; an older server ignores the field.

## [1.2.0] — 2026-09-28

### Added

- `SearchQuery::imageSize()` and the `imageSize` argument of `SearchApi::suggest()` request the
  size of the product `image` (`image_size`); only the sizes in `SearchQuery::IMAGE_SIZES` are
  accepted, anything else is a `ConfigurationException` before the request.

### Changed

- **Breaking wire format, the PHP API is unchanged.** Facet filters are sent as
  `filter[<field>][]=<value>` instead of top-level `<field>[]=<value>`; the Search API no longer
  accepts the old form. A facet name with a space or a dot (`Max. výkon`) or one named like a search
  parameter (`sort`) now filters as expected, and `facet()` no longer rejects reserved names.
  Requires the matching Search API release; an older client gets `422` for any facet filter.
- `search()` and `suggest()` no longer retry a `429`: they throw an `ApiException`
  (`isRateLimited()`) at once instead of sleeping up to 60 s per attempt on `Retry-After` inside a
  page render. Catalog calls still retry a `429`; server and network errors on reads are still
  retried with a short backoff.
- The README "Timeouts" section moved to [docs.metty.eu/client/php](https://docs.metty.eu/client/php#timeouts).

### Fixed

- The Search and Catalog API URLs must use `https`; a plain `http://` or any other scheme is
  rejected with a `ConfigurationException`, so the secret key is never sent in cleartext.
- The secret key is marked `#[\SensitiveParameter]` in `Configuration::__construct()` and
  `MettyClient::create()`, so on PHP 8.2+ it no longer appears in exception stack-trace arguments.

## [1.1.0] — 2026-08-22

### Fixed

- A network error no longer attaches the PSR-18 exception, which carries the authenticated request
  and its bearer secret, as the cause.
- A `3xx` response is no longer read as a success; only `2xx` is.
- `Retry-After` also accepts an HTTP-date, and the wait is clamped to 60 s.
- `beginSync()` rejects a response without a usable `sync_id`, and a write response without a
  `results` list is reported as a `TransportException` instead of looking like a success.

### Added

- README: the timeouts a PSR-18 client needs, and how to inject a preconfigured one.

## [1.0.1] — 2026-08-11

### Fixed

- `homepage` and `support.docs` in `composer.json` pointed at the old documentation path, which no
  longer exists.

## [1.0.0] — 2026-08-11

First public release.

### Added

- `Search\SearchApi` — `search()`, `suggest()` and `searchAll()` over `GET /search` and
  `GET /suggest`.
- `Catalog\CatalogApi` — `replace()`, `patch()`, `delete()`, `beginSync()`, `commit()`,
  `synchronize()` and `export()` over `/catalog/*`.
- Batching by 100 products, with the result of every product reported separately in `WriteResult`.
- Full sync safeguard: an incomplete or empty snapshot is never committed; the client throws
  `SyncIncompleteException` with the sync left open.
- Retries for `429` honouring `Retry-After`, and for server errors only on methods that are safe
  to repeat.
- Client-side enforcement of server boundaries — the 200 result window, page size, sorting,
  sections and key prefixes.

[1.3.0]: https://github.com/getmetty/metty-php/releases/tag/v1.3.0
[1.2.0]: https://github.com/getmetty/metty-php/releases/tag/v1.2.0
[1.1.0]: https://github.com/getmetty/metty-php/releases/tag/v1.1.0
[1.0.1]: https://github.com/getmetty/metty-php/releases/tag/v1.0.1
[1.0.0]: https://github.com/getmetty/metty-php/releases/tag/v1.0.0
