<?php

declare(strict_types=1);

namespace Metty\Client;

use Metty\Client\Exception\ConfigurationException;

/**
 * Nastavenie klienta.
 *
 * `apiKey` je verejný identifikátor katalógu a stačí na čítanie. `secretKey` je privátny kľúč pre
 * zápisy a nikdy nesmie ísť do frontendu ani do logu.
 */
final class Configuration
{
    /**
     * Server prijme najviac 100 objektov na dávku; klient väčší katalóg rozdelí sám.
     */
    public const MAX_BATCH_SIZE = 100;

    public readonly string $baseUrl;

    /** @var int<1, 100> */
    public readonly int $batchSize;

    public function __construct(
        string $baseUrl,
        public readonly string $apiKey,
        public readonly ?string $secretKey = null,
        int $batchSize = self::MAX_BATCH_SIZE,
        public readonly int $maxRetries = 3,
        public readonly float $timeoutSeconds = 30.0,
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException('The base URL must be an absolute URL.');
        }

        if ($apiKey === '') {
            throw new ConfigurationException('The api key must not be empty.');
        }

        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new ConfigurationException(sprintf('The batch size must be between 1 and %d.', self::MAX_BATCH_SIZE));
        }

        if ($maxRetries < 0) {
            throw new ConfigurationException('The retry count must not be negative.');
        }

        $this->baseUrl = $baseUrl;
        $this->batchSize = $batchSize;
    }

    /**
     * @throws ConfigurationException keď je klient nastavený len na čítanie
     */
    public function requireSecretKey(): string
    {
        if ($this->secretKey === null || $this->secretKey === '') {
            throw new ConfigurationException('Writing requires a secret key; the client is configured for reads only.');
        }

        return $this->secretKey;
    }
}
