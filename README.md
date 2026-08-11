# Metty PHP client

PHP klient pre Metty API — synchronizácia katalógu a vyhľadávanie.

Bez frameworkovej závislosti: HTTP ide cez PSR-18 klienta a PSR-17 factory, ktoré si dodá integrátor
(alebo sa nájdu cez `php-http/discovery`). Logovanie je voliteľné cez PSR-3.

Dokumentácia: **[docs.metty.eu/klient/php](https://docs.metty.eu/klient/php)** — klient,
[Search API](https://docs.metty.eu/api/hladanie), [Catalog API](https://docs.metty.eu/api/zapis)
a [chybové kódy](https://docs.metty.eu/api/chyby).

## Inštalácia

```bash
composer require getmetty/metty-php
```

Ak v projekte ešte nemáte PSR-18 klienta, doinštalujte si ľubovoľnú implementáciu, napr.:

```bash
composer require symfony/http-client nyholm/psr7
```

## Kľúče

```php
use Metty\Client\MettyClient;

$client = MettyClient::create(
    publicKey: 'pk_…',   // čítanie; patrí aj do frontendu
    secretKey: 'sk_…',   // zápisy katalógu; nikdy nesmie ísť do frontendu
);
```

Stačí ten kľúč, ktorý naozaj potrebujete — klient s `pk_` vie iba čítať, klient s `sk_` iba zapisovať.
Prehodené kľúče klient odmietne hneď pri vytvorení, aby secret neskončil v URL.

Search API (`search.api.metty.eu`) a Catalog API (`catalog.api.metty.eu`) sú predvolené, adresy sa
prepisujú len pre staging alebo lokálny vývoj:

```php
$client = MettyClient::create('pk_…', 'sk_…',
    searchUrl: 'https://search.api.metty.click',
    catalogUrl: 'https://catalog.api.metty.click',
);
```

## Vyhľadávanie

```php
use Metty\Client\Search\SearchQuery;

$response = $client->search()->search(
    SearchQuery::for('vŕtačka')
        ->facet('brand', 'Bosch')         // AND medzi poľami, OR medzi hodnotami jedného poľa
        ->facet('brand', 'Makita')
        ->category('Náradie > Vŕtačky')
        ->priceRange(100, 200)
        ->sortBy('price_asc')             // relevance | price_asc | price_desc | name_asc
        ->withSections('facets', 'categories', 'suggestions')
        ->perPage(24)
        ->page(2),
);

foreach ($response->products as $product) {
    echo $product->id, ' ', $product->name, ' ', $product->url, PHP_EOL;
}

echo $response->total, ' výsledkov na ', $response->pages, ' stránkach';
```

- `facet()` berie ľubovoľné facetové pole z feedu, `category()` cestu v tom istom tvare, aký vracia
  `category` produktu.
- `highlight` prichádza zo servera aj so značkami `[]`; klient zvýraznenie nedohaduje.
- `categories`, `facets`, `priceRange` a `suggestions` sú naplnené iba pri `withSections()`.
- Prázdny dotaz (`SearchQuery::for()`) je listing katalógu podľa filtrov.

Server ranguje prvých 200 výsledkov a hlbšie stránkovanie odmieta. Klient tú hranicu pozná:
`hasNextPage()` ju rešpektuje, `searchAll()` na nej skončí a dotaz mimo okna zlyhá ešte pred
requestom.

```php
foreach ($client->search()->searchAll(SearchQuery::for('vŕtačka')) as $product) {
    echo $product->name, PHP_EOL;
}
```

`searchAll()` si veľkosť stránky určuje sám, aby okno vyčerpal celé.

Našepkávanie:

```php
$suggest = $client->search()->suggest('vŕta', limit: 8);

$suggest->suggestions;  // [['query' => 'vŕtačka', 'count' => 41], …]
$suggest->products;     // najviac 5 skrátených produktov
```

## Zápis katalógu

```php
use Metty\Client\Catalog\CatalogProduct;

$result = $client->catalog()->replace([
    CatalogProduct::create('sku-1', 'Príklepová vŕtačka', 'https://eshop.sk/vrtacka',
        price: 129.9, inStock: true, brand: 'Bosch', category: 'Náradie > Vŕtačky',
        params: ['farba' => 'modrá', 'príkon' => '800 W']),
    CatalogProduct::create('sku-2', 'Uhlová brúska', 'https://eshop.sk/bruska', price: 89.5),
]);

if ($result->hasFailures()) {
    foreach ($result->failures() as $failure) {
        echo $failure->id, ': ', $failure->error, ' — ', $failure->message, PHP_EOL;
    }
}
```

`replace()` je úplné nahradenie — neuvedené pole sa na serveri zmaže. `patch()` mení iba uvedené
polia a `delete(['sku-1'])` produkty odstráni.

Pri `patch()` je rozdiel medzi vynechaným poľom a poľom s hodnotou `null`; vymazanie sa preto
zapisuje priamo:

```php
$client->catalog()->patch([
    new CatalogProduct('sku-1', ['price' => 99.0, 'brand' => null]),
]);
```

`params` sú jediný zdroj facetovateľných atribútov — to isté miesto, kam chodia facety z XML feedu.

## Bezpečný full snapshot

```php
$outcome = $client->catalog()->synchronize($products);

echo $outcome['sync_id'];
echo $outcome['commit']['removed'];  // koľko starých produktov zmizlo
```

Klient otvorí sync, nahrá pod ním celý katalóg a až potom ho commitne. Ak sa niektorý produkt
nepodarí nahrať, sync **necommitne** — inak by commit zmazal produkty, ktoré práve neprešli — a
vyhodí `SyncIncompleteException` s `syncId` a výsledkami dávky. Sync ostáva otvorený, takže chybné
produkty sa dajú dopísať a commitnúť neskôr:

```php
use Metty\Client\Exception\SyncIncompleteException;

try {
    $client->catalog()->synchronize($products);
} catch (SyncIncompleteException $exception) {
    $client->catalog()->replace($opravene, $exception->syncId);
    $client->catalog()->commit($exception->syncId);
}
```

Táto poistka sa nedá vypnúť — `force: true` sa týka výhradne serverovej poistky, ktorá odmietne
snapshot pokrývajúci menej než polovicu katalógu. Prázdny snapshot klient odmietne vždy.

## Export

```php
foreach ($client->catalog()->export() as $product) {
    echo $product['id'], PHP_EOL;
}
```

## Čo klient rieši za vás

- **dávkovanie** — katalóg sa rozdelí na dávky podľa limitu servera (100 produktov)
- **partial failure** — dávka nespadne celá; dostanete stav každého produktu
- **hranice servera** — neznáme radenie, sekcia, facetový názov či stránka mimo okna zlyhajú
  lokálne, nie až ako `422`
- **retry s backoffom** — `429` vždy (rešpektuje `Retry-After`), chyba servera alebo siete iba pri
  metódach, ktoré sa dajú bez následkov zopakovať; ostatné `4xx` nikdy

Idempotency kľúč netreba: server zapisuje podľa `id`, takže zopakovaná dávka nevytvorí duplicity.
`Authorization` sa nikdy neloguje ani nedostane do výnimky.

## Chyby

| výnimka | kedy |
|---|---|
| `ConfigurationException` | zlé nastavenie alebo dotaz, ktorý by server odmietol |
| `ApiException` | server vrátil `{"error": …, "message": …}`; nesie `statusCode` a `errorCode` |
| `SyncIncompleteException` | full sync sa nedokončil celý a nebol commitnutý |
| `TransportException` | sieťová chyba alebo odpoveď, ktorá sa nedá spracovať |

Všetky implementujú `Metty\Client\Exception\MettyException`.

## Podpora

PHP 8.1+. Klient sa verzuje nezávisle od servera podľa [SemVer](https://semver.org/lang/sk/); zmeny
sú v [CHANGELOG.md](CHANGELOG.md).

Otázky a chyby: [GitHub issues](https://github.com/getmetty/metty-php/issues).

## Licencia

[MIT](LICENSE).
