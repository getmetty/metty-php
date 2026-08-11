<?php

declare(strict_types=1);

namespace Metty\Client;

use Metty\Client\Exception\ConfigurationException;

/**
 * Nastavenie klienta.
 *
 * `publicKey` (`pk_…`) je verejný kľúč pre čítanie a chodí v query, `secretKey` (`sk_…`) je privátny
 * kľúč pre zápisy katalógu a nikdy nesmie ísť do frontendu ani do logu. Prefix kľúča sa kontroluje
 * hneď, aby sa secret nedal omylom poslať v URL.
 */
final class Configuration
{
    public const PUBLIC_KEY_PREFIX = 'pk_';

    public const SECRET_KEY_PREFIX = 'sk_';

    public readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        public readonly ?string $publicKey = null,
        public readonly ?string $secretKey = null,
        public readonly int $maxRetries = 3,
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException('The base URL must be an absolute URL.');
        }

        if ($publicKey === null && $secretKey === null) {
            throw new ConfigurationException('The client needs at least a public key for reads or a secret key for writes.');
        }

        if ($publicKey !== null && !str_starts_with($publicKey, self::PUBLIC_KEY_PREFIX)) {
            throw new ConfigurationException('The public key must start with "pk_".');
        }

        if ($secretKey !== null && !str_starts_with($secretKey, self::SECRET_KEY_PREFIX)) {
            throw new ConfigurationException('The secret key must start with "sk_".');
        }

        if ($maxRetries < 0) {
            throw new ConfigurationException('The retry count must not be negative.');
        }

        $this->baseUrl = $baseUrl;
    }

    /**
     * @throws ConfigurationException keď klient nemá verejný kľúč
     */
    public function requirePublicKey(): string
    {
        if ($this->publicKey === null || $this->publicKey === '') {
            throw new ConfigurationException('Reading requires a public key; the client is configured for writes only.');
        }

        return $this->publicKey;
    }

    /**
     * @throws ConfigurationException keď klient nemá secret kľúč
     */
    public function requireSecretKey(): string
    {
        if ($this->secretKey === null || $this->secretKey === '') {
            throw new ConfigurationException('Writing requires a secret key; the client is configured for reads only.');
        }

        return $this->secretKey;
    }
}
