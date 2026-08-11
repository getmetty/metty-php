# Metty PHP client

[![Packagist](https://img.shields.io/packagist/v/getmetty/metty-php.svg)](https://packagist.org/packages/getmetty/metty-php)
[![PHP](https://img.shields.io/packagist/dependency-v/getmetty/metty-php/php.svg)](https://packagist.org/packages/getmetty/metty-php)
[![License](https://img.shields.io/packagist/l/getmetty/metty-php.svg)](LICENSE)

PHP client for the Metty API — catalog synchronisation and search.

No framework dependency: HTTP goes through a PSR-18 client and PSR-17 factories that you provide
(or that are discovered through `php-http/discovery`). Logging is optional through PSR-3.

Documentation: **[docs.metty.eu/client/php](https://docs.metty.eu/client/php)** — the client,
[Search API](https://docs.metty.eu/api/search), [Catalog API](https://docs.metty.eu/api/catalog)
and [error codes](https://docs.metty.eu/api/errors).

## Installation

```bash
composer require getmetty/metty-php
```

If your project does not have a PSR-18 client yet, install any implementation, for example:

```bash
composer require symfony/http-client nyholm/psr7
```

## Keys

```php
use Metty\Client\MettyClient;

$client = MettyClient::create(
    publicKey: '<PUBLIC_API_KEY>',   // reads; safe to expose in a frontend
    secretKey: '<SECRET_API_KEY>',   // catalog writes; must never reach a frontend
);
```

Pass only the key you actually need — a client with `pk_` can only read, a client with `sk_` can
only write. Swapped keys are rejected at construction time so that a secret never ends up in a URL.

The Search API (`search.api.metty.eu`) and the Catalog API (`catalog.api.metty.eu`) are the
defaults; override the addresses only for staging or local development:

```php
$client = MettyClient::create('<PUBLIC_API_KEY>', '<SECRET_API_KEY>',
    searchUrl: 'https://search.api.metty.click',
    catalogUrl: 'https://catalog.api.metty.click',
);
```

## Search

```php
use Metty\Client\Search\SearchQuery;

$response = $client->search()->search(
    SearchQuery::for('drill')
        ->facet('colour', 'blue')          // AND across fields, OR within one field
        ->facet('colour', 'black')
        ->category('Tools > Drills')
        ->priceRange(100, 200)
        ->sortBy('price_asc')              // relevance | price_asc | price_desc | name_asc
        ->withSections('facets', 'categories', 'suggestions')
        ->perPage(24)
        ->page(2),
);

foreach ($response->products as $product) {
    echo $product->id, ' ', $product->name, ' ', $product->url, PHP_EOL;
}

echo $response->total, ' results across ', $response->pages, ' pages';
```

- `facet()` takes any facet field from your catalog, `category()` a path in the same format the
  product's `category` is returned in.
- `highlight` arrives from the server with `[]` markers already in place; the client never guesses
  highlighting on its own.
- `categories`, `facets`, `priceRange` and `suggestions` are only populated when requested through
  `withSections()`.
- An empty query (`SearchQuery::for()`) lists the catalog according to the filters.

The server ranks the first 200 results and rejects deeper paging. The client knows that boundary:
`hasNextPage()` respects it, `searchAll()` stops there, and a query outside the window fails before
the request is sent.

```php
foreach ($client->search()->searchAll(SearchQuery::for('drill')) as $product) {
    echo $product->name, PHP_EOL;
}
```

`searchAll()` picks its own page size so that it consumes the whole window.

Autocomplete:

```php
$suggest = $client->search()->suggest('dri', limit: 8);

$suggest->suggestions;  // [['query' => 'drill', 'count' => 41], …]
$suggest->products;     // at most 5 compact products
```

## Writing the catalog

```php
use Metty\Client\Catalog\CatalogProduct;

$result = $client->catalog()->replace([
    CatalogProduct::create('sku-1', 'Impact drill', 'https://shop.example/drill',
        price: 129.9, inStock: true, brand: 'Bosch', category: 'Tools > Drills',
        params: ['colour' => 'blue', 'power' => '800 W']),
    CatalogProduct::create('sku-2', 'Angle grinder', 'https://shop.example/grinder', price: 89.5),
]);

if ($result->hasFailures()) {
    foreach ($result->failures() as $failure) {
        echo $failure->id, ': ', $failure->error, ' — ', $failure->message, PHP_EOL;
    }
}
```

`replace()` is a full replacement — a field you omit is cleared on the server. `patch()` changes
only the fields you send and `delete(['sku-1'])` removes products.

With `patch()` an omitted field differs from a field set to `null`, so clearing a value is written
explicitly:

```php
$client->catalog()->patch([
    new CatalogProduct('sku-1', ['price' => 99.0, 'brand' => null]),
]);
```

`params` is the only source of facetable attributes — the same place facets from an XML feed end up
in.

## Safe full snapshot

```php
$outcome = $client->catalog()->synchronize($products);

echo $outcome['sync_id'];
echo $outcome['commit']['removed'];  // how many stale products were dropped
```

The client opens a sync, uploads the whole catalog under it and only then commits. If any product
fails to upload, the sync is **not** committed — committing would delete exactly the products that
just failed — and a `SyncIncompleteException` carrying the `syncId` and the batch results is thrown.
The sync stays open, so the failed products can be resent and committed later:

```php
use Metty\Client\Exception\SyncIncompleteException;

try {
    $client->catalog()->synchronize($products);
} catch (SyncIncompleteException $exception) {
    $client->catalog()->replace($fixed, $exception->syncId);
    $client->catalog()->commit($exception->syncId);
}
```

This safeguard cannot be turned off. `force: true` applies solely to the server-side safeguard that
rejects a snapshot covering less than half of the catalog. An empty snapshot is always rejected by
the client.

## Export

```php
foreach ($client->catalog()->export() as $product) {
    echo $product['id'], PHP_EOL;
}
```

## What the client handles for you

- **batching** — the catalog is split into batches according to the server limit of 100 products
- **partial failure** — a batch never fails as a whole; you get the status of every product
- **server boundaries** — an unknown sort, section, or a page outside the ranked window fails
  locally instead of coming back as a `422`
- **retry with backoff** — `429` always (honouring `Retry-After`), a server or network error only
  for methods that are safe to repeat; other `4xx` never

No idempotency key is needed: the server writes by `id`, so a repeated batch cannot create
duplicates. `Authorization` is never logged and never ends up in an exception.

## Errors

| exception | when |
|---|---|
| `ConfigurationException` | invalid configuration, or a query the server would reject |
| `ApiException` | the server returned `{"error": …, "message": …}`; carries `statusCode` and `errorCode` |
| `SyncIncompleteException` | a full sync did not complete and was therefore not committed |
| `TransportException` | a network error, or a response that cannot be parsed |

All of them implement `Metty\Client\Exception\MettyException`.

## Support

PHP 8.1+. The client is versioned independently of the server following
[SemVer](https://semver.org/); changes are listed in [CHANGELOG.md](CHANGELOG.md).

Questions and bugs: [GitHub issues](https://github.com/getmetty/metty-php/issues).

## License

[MIT](LICENSE).
