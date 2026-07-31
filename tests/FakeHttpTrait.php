<?php

declare(strict_types=1);

namespace Metty\Client\Tests;

use Http\Mock\Client as MockClient;
use Metty\Client\Configuration;
use Metty\Client\MettyClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestInterface;

/**
 * Fake PSR-18 klient a odchytené requesty — testy nesmú siahať na sieť.
 */
trait FakeHttpTrait
{
    private MockClient $httpClient;

    private Psr17Factory $psr17;

    private function client(int $batchSize = Configuration::MAX_BATCH_SIZE, int $maxRetries = 0, ?string $secretKey = 'msk_secret'): MettyClient
    {
        $this->psr17 = new Psr17Factory();
        $this->httpClient = new MockClient($this->psr17);

        return new MettyClient(
            new Configuration('https://api.metty.eu', 'public-key', $secretKey, $batchSize, $maxRetries),
            $this->httpClient,
            $this->psr17,
            $this->psr17,
        );
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    private function queueJson(array $payload, int $status = 200, array $headers = []): void
    {
        $response = $this->psr17->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->psr17->createStream((string) json_encode($payload)));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $this->httpClient->addResponse($response);
    }

    /**
     * @return list<RequestInterface>
     */
    private function sentRequests(): array
    {
        return array_values($this->httpClient->getRequests());
    }
}
