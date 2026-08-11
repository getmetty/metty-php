<?php

declare(strict_types=1);

namespace Metty\Client\Search;

/**
 * A product in the search results.
 *
 * `highlight` holds the matched fields including the `[]` markers exactly as the server returned
 * them; the client never guesses highlighting. The compact product from `GET /suggest` carries only
 * `id`, `name`, `url`, `image`, `price` and `currency`.
 */
final class Product
{
    /**
     * @param array<string, string> $highlight
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name = null,
        public readonly ?string $url = null,
        public readonly ?string $image = null,
        public readonly ?float $price = null,
        public readonly ?float $listPrice = null,
        public readonly ?string $currency = null,
        public readonly ?bool $inStock = null,
        public readonly ?string $brand = null,
        public readonly ?string $category = null,
        public readonly array $highlight = [],
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $highlight = [];
        foreach (is_array($payload['highlight'] ?? null) ? $payload['highlight'] : [] as $field => $value) {
            $highlight[(string) $field] = (string) $value;
        }

        return new self(
            (string) ($payload['id'] ?? ''),
            self::text($payload['name'] ?? null),
            self::text($payload['url'] ?? null),
            self::text($payload['image'] ?? null),
            is_numeric($payload['price'] ?? null) ? (float) $payload['price'] : null,
            is_numeric($payload['list_price'] ?? null) ? (float) $payload['list_price'] : null,
            self::text($payload['currency'] ?? null),
            is_bool($payload['in_stock'] ?? null) ? $payload['in_stock'] : null,
            self::text($payload['brand'] ?? null),
            self::text($payload['category'] ?? null),
            $highlight,
        );
    }

    public function isDiscounted(): bool
    {
        return $this->listPrice !== null && $this->price !== null && $this->listPrice > $this->price;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
