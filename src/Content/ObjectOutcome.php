<?php

declare(strict_types=1);

namespace Metty\Client\Content;

/**
 * Výsledok jedného objektu v dávke.
 */
final class ObjectOutcome
{
    public function __construct(
        public readonly ?string $identity,
        public readonly string $status,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

        return new self(
            isset($payload['identity']) ? (string) $payload['identity'] : null,
            (string) ($payload['status'] ?? 'failed'),
            isset($error['code']) ? (string) $error['code'] : null,
            isset($error['message']) ? (string) $error['message'] : null,
        );
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }
}
