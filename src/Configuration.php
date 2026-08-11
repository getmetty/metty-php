<?php

declare(strict_types=1);

namespace Metty\Client;

use Metty\Client\Exception\ConfigurationException;

/**
 * Nastavenie klienta.
 *
 * `publicKey` (`pk_…`) is the read key sent in the query, `secretKey` (`sk_…`) is the private key
 * for catalog writes that must never reach a frontend or a log. The key prefix is validated up
 * front so that a secret cannot end up in a URL by accident.
 *
 * The Search and Catalog APIs run on separate hosts; the addresses are only overridden for staging
 * or local development.
 */
final class Configuration
{
    public const PUBLIC_KEY_PREFIX = 'pk_';

    public const SECRET_KEY_PREFIX = 'sk_';

    public const DEFAULT_SEARCH_URL = 'https://search.api.metty.eu';

    public const DEFAULT_CATALOG_URL = 'https://catalog.api.metty.eu';

    public readonly string $searchUrl;

    public readonly string $catalogUrl;

    public function __construct(
        public readonly ?string $publicKey = null,
        public readonly ?string $secretKey = null,
        ?string $searchUrl = null,
        ?string $catalogUrl = null,
        public readonly int $maxRetries = 3,
    ) {
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

        $this->searchUrl = self::normalizeUrl($searchUrl ?? self::DEFAULT_SEARCH_URL);
        $this->catalogUrl = self::normalizeUrl($catalogUrl ?? self::DEFAULT_CATALOG_URL);
    }

    /**
     * The API address, chosen by whether this is a catalog write.
     */
    public function baseUrl(bool $catalog): string
    {
        return $catalog ? $this->catalogUrl : $this->searchUrl;
    }

    /**
     * @throws ConfigurationException when the client has no public key
     */
    public function requirePublicKey(): string
    {
        if ($this->publicKey === null || $this->publicKey === '') {
            throw new ConfigurationException('Reading requires a public key; the client is configured for writes only.');
        }

        return $this->publicKey;
    }

    /**
     * @throws ConfigurationException when the client has no secret key
     */
    public function requireSecretKey(): string
    {
        if ($this->secretKey === null || $this->secretKey === '') {
            throw new ConfigurationException('Writing requires a secret key; the client is configured for reads only.');
        }

        return $this->secretKey;
    }

    private static function normalizeUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException('The API URL must be an absolute URL.');
        }

        return $url;
    }
}
