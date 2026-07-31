<?php

declare(strict_types=1);

namespace Metty\Client\Search;

/**
 * Odpoveď `GET /search`.
 */
final class SearchResponse
{
    /**
     * @param list<Hit>                                        $hits
     * @param array<string, list<array{value: string, count: int}>> $facets
     */
    public function __construct(
        public readonly array $hits,
        public readonly int $totalHits,
        public readonly int $offset,
        public readonly ?int $nextPage,
        public readonly array $facets = [],
        public readonly ?string $correctedQuery = null,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];

        $hits = [];
        foreach ($results['hits'] ?? [] as $hit) {
            if (is_array($hit)) {
                $hits[] = Hit::fromArray($hit);
            }
        }

        /** @var array<string, list<array{value: string, count: int}>> $facets */
        $facets = is_array($results['facets'] ?? null) ? $results['facets'] : [];

        return new self(
            $hits,
            (int) ($results['total_hits'] ?? 0),
            (int) ($payload['offset'] ?? 0),
            isset($payload['next_page']) ? (int) $payload['next_page'] : null,
            $facets,
            isset($results['corrected_query']) ? (string) $results['corrected_query'] : null,
        );
    }

    public function hasNextPage(): bool
    {
        return $this->nextPage !== null;
    }
}
