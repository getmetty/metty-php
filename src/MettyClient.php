<?php

declare(strict_types=1);

namespace Metty\Client;

use Metty\Client\Catalog\CatalogApi;
use Metty\Client\Http\Transport;
use Metty\Client\Search\SearchApi;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Vstupný bod klienta Metty API.
 *
 * ```php
 * $client = MettyClient::create('pk_…', 'sk_…');
 * $client->search()->search(SearchQuery::for('vŕtačka')->facet('brand', 'Bosch'));
 * $client->catalog()->replace([CatalogProduct::create('sku-1', 'Vŕtačka', 'https://…')]);
 * ```
 */
final class MettyClient
{
    private readonly CatalogApi $catalog;

    private readonly SearchApi $search;

    public function __construct(
        private readonly Configuration $configuration,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ) {
        $transport = new Transport($configuration, $httpClient, $requestFactory, $streamFactory, $logger);
        $this->catalog = new CatalogApi($transport);
        $this->search = new SearchApi($transport);
    }

    public static function create(
        ?string $publicKey = null,
        ?string $secretKey = null,
        ?string $searchUrl = null,
        ?string $catalogUrl = null,
    ): self {
        return new self(new Configuration($publicKey, $secretKey, $searchUrl, $catalogUrl));
    }

    public function catalog(): CatalogApi
    {
        return $this->catalog;
    }

    public function search(): SearchApi
    {
        return $this->search;
    }

    public function configuration(): Configuration
    {
        return $this->configuration;
    }
}
