<?php

declare(strict_types=1);

namespace Metty\Client\Tests;

use Metty\Client\Exception\ConfigurationException;
use Metty\Client\Search\SearchQuery;
use PHPUnit\Framework\TestCase;

final class SearchApiTest extends TestCase
{
    use FakeHttpTrait;

    public function testFiltersAreSentAsRepeatableParameters(): void
    {
        $client = $this->client();
        $this->queueJson(['query' => 'vŕtačka', 'total' => 0, 'page' => 1, 'per_page' => 20, 'pages' => 0, 'products' => []]);

        $client->search()->search(
            SearchQuery::for('vŕtačka')
                ->facet('brand', 'Bosch')
                ->facet('brand', 'Makita')
                ->category('Náradie > Vŕtačky')
                ->priceRange(100, 200)
                ->sortBy('price_desc')
                ->withSections('facets', 'suggestions')
                ->perPage(20)
                ->page(2),
        );

        $uri = urldecode((string) $this->sentRequests()[0]->getUri());
        self::assertStringContainsString('q=vŕtačka', $uri);
        self::assertStringContainsString('brand[]=Bosch', $uri);
        self::assertStringContainsString('brand[]=Makita', $uri);
        self::assertStringContainsString('category[]=Náradie > Vŕtačky', $uri);
        self::assertStringContainsString('price_min=100', $uri);
        self::assertStringContainsString('price_max=200', $uri);
        self::assertStringContainsString('sort=price_desc', $uri);
        self::assertStringContainsString('include=facets,suggestions', $uri);
        self::assertStringContainsString('per_page=20', $uri);
        self::assertStringContainsString('page=2', $uri);
    }

    public function testResponseIsMappedToTypedObjects(): void
    {
        $client = $this->client();
        $this->queueJson([
            'query' => 'vrtacka',
            'corrected_query' => 'vŕtačka',
            'total' => 24,
            'page' => 1,
            'per_page' => 24,
            'pages' => 1,
            'products' => [[
                'id' => 'sku-1',
                'name' => 'Vŕtačka Bosch',
                'url' => 'https://e.sk/1',
                'image' => 'https://e.sk/1.jpg',
                'price' => 129.9,
                'list_price' => 159.9,
                'currency' => 'EUR',
                'in_stock' => true,
                'brand' => 'Bosch',
                'category' => 'Náradie > Vŕtačky',
                'highlight' => ['name' => '[Vŕtačka] Bosch'],
            ]],
            'facets' => [['field' => 'brand', 'label' => 'Brand', 'values' => [['value' => 'Bosch', 'count' => 8]]]],
            'price_range' => ['min' => 9.9, 'max' => 899.0],
        ]);

        $response = $client->search()->search('vrtacka');
        $product = $response->products[0];

        self::assertSame(24, $response->total);
        self::assertSame('vŕtačka', $response->correctedQuery);
        self::assertSame('sku-1', $product->id);
        self::assertSame('https://e.sk/1', $product->url);
        self::assertTrue($product->inStock);
        self::assertTrue($product->isDiscounted());
        self::assertSame('[Vŕtačka] Bosch', $product->highlight['name']);
        self::assertSame(8, $response->facets[0]['values'][0]['count']);
        self::assertSame(['min' => 9.9, 'max' => 899.0], $response->priceRange);
    }

    public function testUnknownSortIsRejectedBeforeTheRequest(): void
    {
        $this->expectExceptionMessageMatches('/Sorting supports only/');

        SearchQuery::for('x')->sortBy('popularity');
    }

    public function testPagingBeyondTheRankedWindowIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        SearchQuery::for('x')->perPage(100)->page(3)->toQueryParameters();
    }

    public function testReservedParameterCannotBeUsedAsFacet(): void
    {
        $this->expectExceptionMessageMatches('/reserved search parameter/');

        SearchQuery::for('x')->facet('sort', 'price_asc');
    }

    public function testSearchAllWalksTheWholeWindowAtFullPageSize(): void
    {
        $client = $this->client();
        $this->queueJson(['query' => 'x', 'total' => 500, 'page' => 1, 'per_page' => 100, 'pages' => 5, 'products' => [['id' => 'a']]]);
        $this->queueJson(['query' => 'x', 'total' => 500, 'page' => 2, 'per_page' => 100, 'pages' => 5, 'products' => [['id' => 'b']]]);

        $ids = [];
        foreach ($client->search()->searchAll(SearchQuery::for('x')->perPage(24)) as $product) {
            $ids[] = $product->id;
        }

        self::assertSame(['a', 'b'], $ids);
        self::assertCount(2, $this->sentRequests(), 'Menšia stránka by nechala koniec okna nedostupný.');
        self::assertStringContainsString('per_page=100', (string) $this->sentRequests()[0]->getUri());
    }

    public function testSuggestReturnsPhrasesAndCompactProducts(): void
    {
        $client = $this->client();
        $this->queueJson([
            'suggestions' => [['query' => 'vŕtačka', 'count' => 41]],
            'products' => [['id' => 'sku-1', 'name' => 'Vŕtačka', 'url' => 'https://e.sk/1', 'image' => null, 'price' => 129.9, 'currency' => 'EUR']],
        ]);

        $response = $client->search()->suggest('vŕta', 3);

        self::assertSame('vŕtačka', $response->suggestions[0]['query']);
        self::assertSame('sku-1', $response->products[0]->id);
        self::assertNull($response->products[0]->inStock);
        self::assertStringContainsString('limit=3', (string) $this->sentRequests()[0]->getUri());
    }

    public function testReadRequestUsesThePublicKeyOnly(): void
    {
        $client = $this->client();
        $this->queueJson(['query' => 'x', 'total' => 0, 'page' => 1, 'per_page' => 24, 'pages' => 0, 'products' => []]);

        $client->search()->search('x');

        $request = $this->sentRequests()[0];
        self::assertStringContainsString('key=pk_public', (string) $request->getUri());
        self::assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testReadingRequiresPublicKey(): void
    {
        $client = $this->client(publicKey: null);

        $this->expectException(ConfigurationException::class);

        $client->search()->search('x');
    }
}
