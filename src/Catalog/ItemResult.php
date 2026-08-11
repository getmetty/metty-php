<?php

declare(strict_types=1);

namespace Metty\Client\Catalog;

/**
 * The result of a single product within a batch.
 */
final class ItemResult
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $status,
        public readonly ?string $error = null,
        public readonly ?string $message = null,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            isset($payload['id']) ? (string) $payload['id'] : null,
            (string) ($payload['status'] ?? 'error'),
            isset($payload['error']) ? (string) $payload['error'] : null,
            isset($payload['message']) ? (string) $payload['message'] : null,
        );
    }

    public function failed(): bool
    {
        return $this->status !== 'ok';
    }
}
