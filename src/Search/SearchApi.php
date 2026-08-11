<?php

declare(strict_types=1);

namespace Metty\Client\Search;

use Metty\Client\Exception\ConfigurationException;
use Metty\Client\Http\Transport;

/**
 * Čítacia časť Metty API — verejná, autentifikovaná kľúčom `pk_…`.
 */
final class SearchApi
{
    public const MAX_SUGGEST_LIMIT = 20;

    public function __construct(
        private readonly Transport $transport,
    ) {}

    public function search(SearchQuery|string $query): SearchResponse
    {
        $query = $query instanceof SearchQuery ? $query : SearchQuery::for($query);

        return SearchResponse::fromArray($this->transport->get('/search', $query->toQueryParameters()));
    }

    /**
     * Prejde výsledky po stránkach až po okno, ktoré server ranguje (200 výsledkov).
     *
     * Stránkuje po maxime, aby sa okno vyčerpalo celé — pri menšej stránke by posledné výsledky
     * padli za hranicu a ticho vypadli.
     *
     * @return \Generator<int, Product>
     */
    public function searchAll(SearchQuery $query): \Generator
    {
        $page = 1;

        do {
            $response = $this->search((clone $query)->perPage(SearchQuery::MAX_PER_PAGE)->page($page));
            foreach ($response->products as $product) {
                yield $product;
            }

            $page++;
        } while ($response->hasNextPage());
    }

    public function suggest(string $query, int $limit = 8): SuggestResponse
    {
        if ($limit < 1 || $limit > self::MAX_SUGGEST_LIMIT) {
            throw new ConfigurationException(sprintf('The suggest limit must be between 1 and %d.', self::MAX_SUGGEST_LIMIT));
        }

        return SuggestResponse::fromArray($this->transport->get('/suggest', ['q' => $query, 'limit' => $limit]));
    }
}
