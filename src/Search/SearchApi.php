<?php

declare(strict_types=1);

namespace Metty\Client\Search;

use Metty\Client\Exception\ConfigurationException;
use Metty\Client\Http\Transport;

/**
 * The read side of the Metty API: public, authenticated with a `pk_…` key.
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
     * Walks the results page by page up to the window the server ranks (200 results).
     *
     * It pages by the maximum size so that the window is consumed in full; with a smaller page the
     * last results would fall past the boundary and silently disappear.
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
