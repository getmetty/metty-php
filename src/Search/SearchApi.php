<?php

declare(strict_types=1);

namespace Metty\Client\Search;

use Metty\Client\Http\Transport;

/**
 * Čítacia časť Metty API — verejná, autentifikovaná `api_key`.
 */
final class SearchApi
{
    public function __construct(
        private readonly Transport $transport,
    ) {}

    public function search(SearchQuery|string $query): SearchResponse
    {
        $query = $query instanceof SearchQuery ? $query : SearchQuery::for($query);

        return SearchResponse::fromArray($this->transport->get('/search', $query->toQueryParameters()));
    }

    /**
     * Prejde všetky stránky výsledkov; server ranguje najviac 200 objektov.
     *
     * @return \Generator<int, Hit>
     */
    public function searchAll(SearchQuery $query): \Generator
    {
        $page = 1;

        do {
            $response = $this->search((clone $query)->page($page));
            foreach ($response->hits as $hit) {
                yield $hit;
            }

            $page = $response->nextPage;
        } while ($page !== null);
    }

    /**
     * @return list<Hit>
     */
    public function autocomplete(string $query): array
    {
        $response = $this->transport->get('/autocomplete/v2', ['q' => $query]);

        $hits = [];
        foreach ($response['hits'] ?? [] as $hit) {
            if (is_array($hit)) {
                $hits[] = Hit::fromArray($hit);
            }
        }

        return $hits;
    }

    /**
     * Našepkávanie medzi hodnotami jedného facetu, napr. značiek.
     *
     * @return list<array{value: string, count: int}>
     */
    public function facetValue(string $facet, string $query = '', int $size = 10): array
    {
        $response = $this->transport->get('/v1/facet_value', [
            'facet' => $facet,
            'q' => $query,
            'size' => $size,
        ]);

        /** @var list<array{value: string, count: int}> $values */
        $values = is_array($response['values'] ?? null) ? $response['values'] : [];

        return $values;
    }
}
