<?php

declare(strict_types=1);

namespace Metty\Client\Search;

/**
 * Odpoveď `GET /suggest` — našepkávané frázy a najviac päť skrátených produktov.
 */
final class SuggestResponse
{
    /**
     * @param list<array{query: string, count: int}> $suggestions
     * @param list<Product>                          $products
     */
    public function __construct(
        public readonly array $suggestions = [],
        public readonly array $products = [],
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var list<array{query: string, count: int}> $suggestions */
        $suggestions = is_array($payload['suggestions'] ?? null) ? array_values($payload['suggestions']) : [];

        $products = [];
        foreach (is_array($payload['products'] ?? null) ? $payload['products'] : [] as $product) {
            if (is_array($product)) {
                $products[] = Product::fromArray($product);
            }
        }

        return new self($suggestions, $products);
    }
}
