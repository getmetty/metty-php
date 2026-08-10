# Metty PHP client

PHP klient pre Metty API — synchronizácia katalógu a vyhľadávanie.

Bez frameworkovej závislosti: HTTP ide cez PSR-18 klienta a PSR-17 factory, ktoré si dodá integrátor
(alebo sa nájdu cez `php-http/discovery`). Logovanie je voliteľné cez PSR-3.

## Inštalácia

```bash
composer require getmetty/metty-php
```

Ak v projekte ešte nemáte PSR-18 klienta, doinštalujte si ľubovoľnú implementáciu, napr.:

```bash
composer require symfony/http-client nyholm/psr7
```

## Použitie

```php
use Metty\Client\MettyClient;
use Metty\Client\Content\ContentItem;
use Metty\Client\Search\SearchQuery;

$client = MettyClient::create(
    baseUrl: 'https://api.metty.eu',
    apiKey: 'verejny-api-kluc',      // čítanie
    secretKey: 'msk_…',              // zápisy; nikdy nepatrí do frontendu
);
```

### Vyhľadávanie

```php
$response = $client->search()->search(
    SearchQuery::for('vŕtačka')
        ->filter('brand', 'Bosch')      // OR v rámci poľa, AND medzi poľami
        ->exclude('availability', '0')  // negácia
        ->range('price', 100, 200)
        ->facet('brand', 10)
        ->sortBy('price', 'asc')
        ->size(20)
        ->page(2),
);

foreach ($response->hits as $hit) {
    echo $hit->identity, ' ', $hit->title(), ' ', $hit->webUrl(), PHP_EOL;
}

echo $response->totalHits;
```

`identity` je identita objektu; klikateľný odkaz je v `attributes['web_url']`.

Ďalšie čítacie metódy: `autocomplete('vŕta')` a `facetValue('brand', 'bo')`.

### Zápis katalógu

```php
$result = $client->content()->replace([
    ContentItem::product('sku-1', 'Príklepová vŕtačka', 'https://eshop.sk/vrtacka', price: 129.9, availability: 3, brand: 'Bosch'),
    ContentItem::product('sku-2', 'Uhlová brúska', 'https://eshop.sk/bruska', price: 89.5),
], idempotencyKey: 'nightly-2026-07-31');

if ($result->hasFailures()) {
    foreach ($result->failures() as $failure) {
        echo $failure->identity, ': ', $failure->errorCode, ' — ', $failure->errorMessage, PHP_EOL;
    }
}
```

`patch()` mení iba uvedené polia, `delete(['sku-1'])` objekty odstráni.

### Bezpečný full snapshot

```php
$outcome = $client->content()->synchronizeCatalog($items, generation: '2026-07-31');
echo $outcome['commit']['removed']; // koľko starých objektov zmizlo
```

Ak sa niektorý objekt nepodarí nahrať, klient generáciu **necommitne** — inak by commit zmazal
objekty, ktoré práve neprešli. Vedomé prepísanie je `force: true`.

### Export

```php
foreach ($client->content()->export() as $object) {
    echo $object['identity'], PHP_EOL;
}
```

## Čo klient rieši za vás

- **dávkovanie** — katalóg sa rozdelí na dávky podľa limitu servera (100 objektov)
- **partial failure** — dávka nespadne celá; dostanete stav každého objektu
- **idempotency key** — každá dávka má vlastný kľúč, opakované odoslanie nevytvorí duplicity
- **retry s backoffom** — iba `429` (rešpektuje `Retry-After`) a `5xx`; ostatné `4xx` sa neopakujú
- **poistka pri snapshote** — neúplná generácia sa necommitne

`Authorization` sa nikdy neloguje ani nedostane do výnimky.

## Podpora

PHP 8.1+. Klient sa verzuje nezávisle od servera.
