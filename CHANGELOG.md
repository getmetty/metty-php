# Changelog

Formát podľa [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), verzovanie podľa
[SemVer](https://semver.org/lang/sk/).

## [1.0.0] — 2026-08-11

Prvé verejné vydanie.

### Pridané

- `Search\SearchApi` — `search()`, `suggest()` a `searchAll()` nad `GET /search` a `GET /suggest`.
- `Catalog\CatalogApi` — `replace()`, `patch()`, `delete()`, `beginSync()`, `commit()`,
  `synchronize()` a `export()` nad `/catalog/*`.
- Dávkovanie po 100 produktoch, výsledok každého produktu zvlášť vo `WriteResult`.
- Poistka pri full syncu: neúplný alebo prázdny snapshot sa necommitne, klient vyhodí
  `SyncIncompleteException` s otvoreným `syncId`.
- Opakovanie `429` podľa `Retry-After` a chýb servera len pri metódach, ktoré sa dajú bezpečne
  zopakovať.
- Kontrola hraníc servera na strane klienta — okno 200 výsledkov, veľkosť stránky, radenie, sekcie
  a prefixy kľúčov.

[1.0.0]: https://github.com/getmetty/metty-php/releases/tag/v1.0.0
