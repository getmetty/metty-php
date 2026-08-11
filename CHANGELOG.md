# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows
[SemVer](https://semver.org/).

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

[1.0.0]: https://github.com/getmetty/metty-php/releases/tag/v1.0.0
