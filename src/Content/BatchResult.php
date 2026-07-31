<?php

declare(strict_types=1);

namespace Metty\Client\Content;

/**
 * Výsledok celej synchronizácie — aj keď sa rozpadla na viac dávok.
 *
 * Dávka neuspeje celá alebo vôbec: klient preto vracia stav každého objektu, nie jednu výnimku.
 */
final class BatchResult implements \Countable
{
    /**
     * @param list<ObjectOutcome> $outcomes
     */
    public function __construct(
        public readonly array $outcomes = [],
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $outcomes = [];
        foreach ($payload['objects'] ?? [] as $object) {
            if (is_array($object)) {
                $outcomes[] = ObjectOutcome::fromArray($object);
            }
        }

        return new self($outcomes);
    }

    public function merge(self $other): self
    {
        return new self([...$this->outcomes, ...$other->outcomes]);
    }

    /**
     * @return list<ObjectOutcome>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->outcomes, static fn (ObjectOutcome $outcome): bool => $outcome->failed()));
    }

    public function succeededCount(): int
    {
        return count($this->outcomes) - count($this->failures());
    }

    public function hasFailures(): bool
    {
        return $this->failures() !== [];
    }

    public function count(): int
    {
        return count($this->outcomes);
    }
}
