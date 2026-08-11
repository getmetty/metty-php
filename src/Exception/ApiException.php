<?php

declare(strict_types=1);

namespace Metty\Client\Exception;

/**
 * An error returned by the server as `{"error": …, "message": …}`.
 *
 * The exception deliberately carries no request headers: `Authorization` must reach neither the
 * log nor `__toString()`.
 */
final class ApiException extends \RuntimeException implements MettyException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct(sprintf('%s (HTTP %d): %s', $errorCode, $statusCode, $message));
    }

    public function isRateLimited(): bool
    {
        return $this->statusCode === 429;
    }
}
