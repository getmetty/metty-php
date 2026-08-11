<?php

declare(strict_types=1);

namespace Metty\Client\Search;

/**
 * Odpoveď `GET /search`.
 *
 * `categories`, `facets`, `priceRange` a `suggestions` sú naplnené iba vtedy, keď si ich dotaz
 * vyžiadal cez `withSections()`.
 */
final class SearchResponse
{
    /**
     * @param list<Product>                                                                  $products
     * @param list<array{name: string, path: string, count: int}>                            $categories
     * @param list<array{field: string, label: string, values: list<array{value: string, count: int}>}> $facets
     * @param array{min: float, max: float}|null                                             $priceRange
     * @param list<array{query: string, count: int}>                                         $suggestions
     */
    public function __construct(
        public readonly string $query,
        public readonly ?string $correctedQuery,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $pages,
        public readonly array $products = [],
        public readonly array $categories = [],
        public readonly array $facets = [],
        public readonly ?array $priceRange = null,
        public readonly array $suggestions = [],
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $products = [];
        foreach (is_array($payload['products'] ?? null) ? $payload['products'] : [] as $product) {
            if (is_array($product)) {
                $products[] = Product::fromArray($product);
            }
        }

        /** @var list<array{name: string, path: string, count: int}> $categories */
        $categories = is_array($payload['categories'] ?? null) ? array_values($payload['categories']) : [];

        /** @var list<array{field: string, label: string, values: list<array{value: string, count: int}>}> $facets */
        $facets = is_array($payload['facets'] ?? null) ? array_values($payload['facets']) : [];

        $range = is_array($payload['price_range'] ?? null) ? $payload['price_range'] : null;
        $priceRange = $range === null ? null : [
            'min' => (float) ($range['min'] ?? 0),
            'max' => (float) ($range['max'] ?? 0),
        ];

        /** @var list<array{query: string, count: int}> $suggestions */
        $suggestions = is_array($payload['suggestions'] ?? null) ? array_values($payload['suggestions']) : [];

        return new self(
            (string) ($payload['query'] ?? ''),
            is_string($payload['corrected_query'] ?? null) ? $payload['corrected_query'] : null,
            (int) ($payload['total'] ?? 0),
            (int) ($payload['page'] ?? 1),
            (int) ($payload['per_page'] ?? 0),
            (int) ($payload['pages'] ?? 0),
            $products,
            $categories,
            $facets,
            $priceRange,
            $suggestions,
        );
    }

    /**
     * Ďalšia stránka existuje iba v okne, ktoré server ranguje — za ním by request skončil `422`.
     */
    public function hasNextPage(): bool
    {
        return $this->page < $this->pages && ($this->page + 1) * $this->perPage <= SearchQuery::MAX_WINDOW;
    }
}
