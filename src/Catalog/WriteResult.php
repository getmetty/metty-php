<?php

declare(strict_types=1);

namespace Metty\Client\Catalog;

/**
 * Výsledok celého zápisu — aj keď sa rozpadol na viac dávok.
 *
 * Dávka nikdy nepadá celá, preto klient vracia stav každého produktu, nie jednu výnimku.
 */
final class WriteResult implements \Countable
{
    /**
     * @param list<ItemResult> $items
     */
    public function __construct(
        public readonly array $items = [],
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $items = [];
        foreach (is_array($payload['results'] ?? null) ? $payload['results'] : [] as $item) {
            if (is_array($item)) {
                $items[] = ItemResult::fromArray($item);
            }
        }

        return new self($items);
    }

    public function merge(self $other): self
    {
        return new self([...$this->items, ...$other->items]);
    }

    /**
     * @return list<ItemResult>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->items, static fn (ItemResult $item): bool => $item->failed()));
    }

    public function succeededCount(): int
    {
        return count($this->items) - count($this->failures());
    }

    public function hasFailures(): bool
    {
        return $this->failures() !== [];
    }

    public function count(): int
    {
        return count($this->items);
    }
}
