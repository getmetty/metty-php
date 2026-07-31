<?php

declare(strict_types=1);

namespace Metty\Client\Tests;

use Metty\Client\Configuration;
use Metty\Client\Content\ContentItem;
use Metty\Client\Exception\ApiException;
use Metty\Client\Http\Transport;
use Metty\Client\Search\SearchQuery;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    use FakeHttpTrait;

    public function testRateLimitIsRetriedWithRetryAfter(): void
    {
        $psr17 = new Psr17Factory();
        $httpClient = new MockClient($psr17);
        $slept = [];
        $transport = new Transport(
            new Configuration('https://api.metty.eu', 'public-key', 'msk_secret', 100, 2),
            $httpClient,
            $psr17,
            $psr17,
            null,
            static function (int $milliseconds) use (&$slept): void {
                $slept[] = $milliseconds;
            },
        );

        $httpClient->addResponse($psr17->createResponse(429)->withHeader('Retry-After', '2'));
        $httpClient->addResponse(
            $psr17->createResponse(200)->withBody($psr17->createStream('{"results":{"hits":[],"total_hits":0}}')),
        );

        $payload = $transport->get('/search', ['q' => 'x']);

        self::assertSame([2000], $slept, 'Retry-After musí určiť dĺžku čakania.');
        self::assertSame(0, $payload['results']['total_hits']);
    }

    public function testClientErrorIsNotRetried(): void
    {
        $client = $this->client(maxRetries: 3);
        $this->queueJson(['error' => ['code' => 'invalid_query', 'message' => 'bad facet']], 400);

        try {
            $client->search()->search(SearchQuery::for('x'));
            self::fail('Očakávaná ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->statusCode);
            self::assertFalse($exception->isRateLimited());
        }

        self::assertCount(1, $this->sentRequests(), '4xx okrem 429 sa nesmie opakovať.');
    }

    public function testSecretKeyNeverLeaksIntoException(): void
    {
        $client = $this->client();
        $this->queueJson(['error' => ['code' => 'invalid_credentials', 'message' => 'nope']], 401);

        try {
            $client->content()->replace([ContentItem::product('a', 'A', 'https://e.sk/a')]);
            self::fail('Očakávaná ApiException.');
        } catch (ApiException $exception) {
            $serialized = $exception->getMessage() . print_r($exception, true);
            self::assertStringNotContainsString('msk_secret', $serialized);
            self::assertStringNotContainsString('Authorization', $serialized);
        }
    }

    public function testWriteRequestCarriesBearerSecret(): void
    {
        $client = $this->client();
        $this->queueJson(['objects' => []]);

        $client->content()->delete(['a']);

        self::assertSame('Bearer msk_secret', $this->sentRequests()[0]->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('msk_secret', (string) $this->sentRequests()[0]->getUri());
    }

    public function testReadRequestUsesPublicApiKeyOnly(): void
    {
        $client = $this->client();
        $this->queueJson(['results' => ['hits' => [], 'total_hits' => 0]]);

        $client->search()->search(SearchQuery::for('vŕtačka'));

        $request = $this->sentRequests()[0];
        self::assertStringContainsString('api_key=public-key', (string) $request->getUri());
        self::assertSame('', $request->getHeaderLine('Authorization'));
    }
}
