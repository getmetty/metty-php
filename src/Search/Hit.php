<?php

declare(strict_types=1);

namespace Metty\Client\Search;

/**
 * Jeden výsledok hľadania.
 *
 * `identity` je identita objektu; klikateľný odkaz je v `attributes['web_url']`.
 */
final class Hit
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly string $identity,
        public readonly string $type,
        public readonly array $attributes = [],
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            (string) ($payload['url'] ?? ''),
            (string) ($payload['type'] ?? 'item'),
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [],
        );
    }

    public function title(): ?string
    {
        $title = $this->attributes['title'] ?? null;

        return is_string($title) ? $title : null;
    }

    public function webUrl(): ?string
    {
        $url = $this->attributes['web_url'] ?? null;

        return is_string($url) ? $url : null;
    }
}
