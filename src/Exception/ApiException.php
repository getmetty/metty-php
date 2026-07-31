<?php

declare(strict_types=1);

namespace Metty\Client\Exception;

/**
 * Chyba vrátená serverom v tvare `{"error": {"code": …, "message": …}}`.
 *
 * Výnimka zámerne nenesie hlavičky requestu — `Authorization` sa nesmie dostať do logu ani do
 * `__toString()`.
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
