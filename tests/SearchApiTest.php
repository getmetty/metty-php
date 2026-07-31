<?php

declare(strict_types=1);

namespace Metty\Client\Tests;

use Metty\Client\Search\SearchQuery;
use PHPUnit\Framework\TestCase;

final class SearchApiTest extends TestCase
{
    use FakeHttpTrait;

    public function testFacetFiltersAreSentInLegacySyntax(): void
    {
        $client = $this->client();
        $this->queueJson(['results' => ['hits' => [], 'total_hits' => 0]]);

        $client->search()->search(
            SearchQuery::for('vŕtačka')
                ->filter('brand', 'Bosch')
                ->filter('brand', 'Makita')
                ->filterAll('category', 'Náradie')
                ->exclude('availability', '0')
                ->range('price', 100, 200)
                ->facet('brand', 5)
                ->sortBy('price', 'desc')
                ->size(20)
                ->page(2),
        );

        $uri = urldecode((string) $this->sentRequests()[0]->getUri());
        self::assertStringContainsString('f[]=brand:Bosch', $uri);
        self::assertStringContainsString('f[]=brand:Makita', $uri);
        self::assertStringContainsString('f_must[]=category:Náradie', $uri);
        self::assertStringContainsString('neg_f[]=availability:0', $uri);
        self::assertStringContainsString('f[]=price:100|200', $uri);
        self::assertStringContainsString('facets=brand:5', $uri);
        self::assertStringContainsString('sort=price:desc', $uri);
        self::assertStringContainsString('page=2', $uri);
    }

    public function testResponseIsMappedToTypedObjects(): void
    {
        $client = $this->client();
        $this->queueJson([
            'results' => [
                'hits' => [[
                    'url' => 'sku-1',
                    'type' => 'item',
                    'attributes' => ['title' => 'Vŕtačka', 'web_url' => 'https://e.sk/1', 'price' => 129.9],
                ]],
                'total_hits' => 24,
                'facets' => ['brand' => [['value' => 'Bosch', 'count' => 8]]],
            ],
            'offset' => 0,
            'next_page' => 2,
        ]);

        $response = $client->search()->search('vŕtačka');

        self::assertSame(24, $response->totalHits);
        self::assertTrue($response->hasNextPage());
        self::assertSame('sku-1', $response->hits[0]->identity);
        self::assertSame('Vŕtačka', $response->hits[0]->title());
        self::assertSame('https://e.sk/1', $response->hits[0]->webUrl());
        self::assertSame(8, $response->facets['brand'][0]['count']);
    }

    public function testUnknownSortIsRejectedBeforeTheRequest(): void
    {
        $this->expectExceptionMessageMatches('/Sorting supports only/');

        SearchQuery::for('x')->sortBy('popularity');
    }

    public function testAutocompleteReturnsTypedHits(): void
    {
        $client = $this->client();
        $this->queueJson(['hits' => [
            ['url' => 'vŕtačka', 'type' => 'query', 'attributes' => ['phrase' => 'vŕtačka']],
            ['url' => 'sku-1', 'type' => 'item', 'attributes' => ['title' => 'Vŕtačka']],
        ]]);

        $hits = $client->search()->autocomplete('vŕta');

        self::assertCount(2, $hits);
        self::assertSame('query', $hits[0]->type);
        self::assertSame('item', $hits[1]->type);
    }

    public function testFacetValueReturnsValuesWithCounts(): void
    {
        $client = $this->client();
        $this->queueJson(['values' => [['value' => 'Bosch', 'count' => 8]]]);

        $values = $client->search()->facetValue('brand', 'bo');

        self::assertSame([['value' => 'Bosch', 'count' => 8]], $values);
        self::assertStringContainsString('facet=brand', (string) $this->sentRequests()[0]->getUri());
    }

    public function testSearchAllWalksEveryPage(): void
    {
        $client = $this->client();
        $this->queueJson(['results' => ['hits' => [['url' => 'a']], 'total_hits' => 2], 'offset' => 0, 'next_page' => 2]);
        $this->queueJson(['results' => ['hits' => [['url' => 'b']], 'total_hits' => 2], 'offset' => 1, 'next_page' => null]);

        $identities = [];
        foreach ($client->search()->searchAll(SearchQuery::for('x')->size(1)) as $hit) {
            $identities[] = $hit->identity;
        }

        self::assertSame(['a', 'b'], $identities);
    }
}
