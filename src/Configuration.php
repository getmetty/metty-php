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
 *
 * Search a Catalog API bežia na samostatných hostoch; adresy sa prepisujú iba pre staging alebo
 * lokálny vývoj.
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
     * Adresa API podľa toho, či ide o zápisové volanie katalógu.
     */
    public function baseUrl(bool $catalog): string
    {
        return $catalog ? $this->catalogUrl : $this->searchUrl;
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

    private static function normalizeUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException('The API URL must be an absolute URL.');
        }

        return $url;
    }
}
