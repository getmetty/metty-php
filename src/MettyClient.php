<?php

declare(strict_types=1);

namespace Metty\Client;

use Metty\Client\Content\ContentApi;
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
 * $client = MettyClient::create('https://api.metty.eu', 'api-kluc', 'msk_secret');
 * $client->search()->search(SearchQuery::for('vŕtačka')->filter('brand', 'Bosch'));
 * $client->content()->replace([ContentItem::product('sku-1', 'Vŕtačka', 'https://…')]);
 * ```
 */
final class MettyClient
{
    private readonly ContentApi $content;

    private readonly SearchApi $search;

    public function __construct(
        private readonly Configuration $configuration,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ) {
        $transport = new Transport($configuration, $httpClient, $requestFactory, $streamFactory, $logger);
        $this->content = new ContentApi($transport, $configuration);
        $this->search = new SearchApi($transport);
    }

    public static function create(string $baseUrl, string $apiKey, ?string $secretKey = null): self
    {
        return new self(new Configuration($baseUrl, $apiKey, $secretKey));
    }

    public function content(): ContentApi
    {
        return $this->content;
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
