# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows
[SemVer](https://semver.org/).

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

[1.1.0]: https://github.com/getmetty/metty-php/releases/tag/v1.1.0
[1.0.1]: https://github.com/getmetty/metty-php/releases/tag/v1.0.1
[1.0.0]: https://github.com/getmetty/metty-php/releases/tag/v1.0.0
