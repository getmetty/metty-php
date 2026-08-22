<?php

declare(strict_types=1);

namespace Metty\Client\Catalog;

use Metty\Client\Exception\TransportException;

/**
 * The result of a whole write, even when it was split across several batches.
 *
 * A batch never fails as a whole, so the client reports the status of every product instead of
 * throwing a single exception.
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
        $results = $payload['results'] ?? null;
        if (!is_array($results)) {
            throw new TransportException('Metty returned a write response without a "results" list.');
        }

        $items = [];
        foreach ($results as $item) {
            if (!is_array($item)) {
                throw new TransportException('Metty returned a write response with a malformed item.');
            }

            $items[] = ItemResult::fromArray($item);
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
