<?php

declare(strict_types=1);

namespace Metty\Client\Tests;

use Http\Client\Exception\NetworkException;
use Http\Mock\Client as MockClient;
use Metty\Client\Catalog\CatalogProduct;
use Metty\Client\Configuration;
use Metty\Client\Exception\ApiException;
use Metty\Client\Exception\ConfigurationException;
use Metty\Client\Exception\TransportException;
use Metty\Client\Http\Transport;
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
            new Configuration('pk_public', 'sk_secret', maxRetries: 2),
            $httpClient,
            $psr17,
            $psr17,
            null,
            static function (int $milliseconds) use (&$slept): void {
                $slept[] = $milliseconds;
            },
        );

        $httpClient->addResponse($psr17->createResponse(429)->withHeader('Retry-After', '2'));
        $httpClient->addResponse($psr17->createResponse(200)->withBody($psr17->createStream('{"total":0}')));

        $payload = $transport->get('/search', ['q' => 'x']);

        self::assertSame([2000], $slept, 'Retry-After must dictate how long the client waits.');
        self::assertSame(0, $payload['total']);
    }

    public function testClientErrorIsNotRetried(): void
    {
        $client = $this->client(maxRetries: 3);
        $this->queueJson(['error' => 'invalid_parameter', 'message' => 'Unknown sort "nonsense".'], 422);

        try {
            $client->search()->search('x');
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->statusCode);
            self::assertSame('invalid_parameter', $exception->errorCode);
            self::assertFalse($exception->isRateLimited());
        }

        self::assertCount(1, $this->sentRequests(), 'A 4xx other than 429 must never be retried.');
    }

    public function testServerErrorIsNotRetriedForCommit(): void
    {
        $client = $this->client(maxRetries: 3);
        $this->queueJson(['error' => 'internal_error', 'message' => 'boom'], 503);

        try {
            $client->catalog()->commit('sync_abc');
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(503, $exception->statusCode);
        }

        self::assertCount(1, $this->sentRequests(), 'A repeated commit would report a success as a conflict.');
    }

    public function testServerErrorIsRetriedForProductBatch(): void
    {
        $psr17 = new Psr17Factory();
        $httpClient = new MockClient($psr17);
        $transport = new Transport(
            new Configuration('pk_public', 'sk_secret', maxRetries: 1),
            $httpClient,
            $psr17,
            $psr17,
            null,
            static function (int $milliseconds): void {},
        );

        $httpClient->addResponse($psr17->createResponse(503));
        $httpClient->addResponse($psr17->createResponse(200)->withBody($psr17->createStream('{"results":[]}')));

        $transport->send('PUT', '/catalog/products', [], [['id' => 'a']], true);

        self::assertCount(2, $httpClient->getRequests());
    }

    public function testSecretKeyNeverLeaksIntoException(): void
    {
        $client = $this->client();
        $this->queueJson(['error' => 'invalid_credentials', 'message' => 'nope'], 401);

        try {
            $client->catalog()->replace([CatalogProduct::create('a', 'A', 'https://e.sk/a')]);
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            $serialized = $exception->getMessage() . print_r($exception, true);
            self::assertStringNotContainsString('sk_secret', $serialized);
            self::assertStringNotContainsString('Authorization', $serialized);
        }
    }

    public function testWriteRequestCarriesBearerSecretAndNoPublicKey(): void
    {
        $client = $this->client();
        $this->queueJson(['results' => []]);

        $client->catalog()->delete(['a']);

        $request = $this->sentRequests()[0];
        self::assertSame('Bearer sk_secret', $request->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('sk_secret', (string) $request->getUri());
        self::assertStringNotContainsString('key=', (string) $request->getUri());
    }

    public function testSearchAndCatalogUseTheirOwnHosts(): void
    {
        $client = $this->client();
        $this->queueJson(['total' => 0, 'products' => []]);
        $this->queueJson(['results' => []]);

        $client->search()->search('x');
        $client->catalog()->delete(['a']);

        [$search, $catalog] = $this->sentRequests();
        self::assertSame('search.api.metty.eu', $search->getUri()->getHost());
        self::assertSame('catalog.api.metty.eu', $catalog->getUri()->getHost());
    }

    public function testRateLimitPayloadKeepsItsErrorCode(): void
    {
        $client = $this->client(maxRetries: 0);
        $this->queueJson(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests.']], 429);

        try {
            $client->search()->search('x');
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertSame('rate_limited', $exception->errorCode);
            self::assertTrue($exception->isRateLimited());
        }
    }

    public function testSecretKeyInThePublicKeySlotIsRejected(): void
    {
        $this->expectExceptionMessageMatches('/public key must start with/');

        new Configuration('sk_secret');
    }

    public function testClientNeedsAtLeastOneKey(): void
    {
        $this->expectException(ConfigurationException::class);

        new Configuration();
    }

    public function testRedirectIsNotTreatedAsSuccess(): void
    {
        $client = $this->client();
        $this->queueJson([], 302, ['Location' => 'https://elsewhere.example/search']);

        try {
            $client->search()->search('x');
            self::fail('Expected an ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(302, $exception->statusCode);
        }
    }

    public function testRetryAfterAsHttpDateIsHonoured(): void
    {
        $slept = $this->retryAfter(gmdate('D, d M Y H:i:s \G\M\T', time() + 5));

        self::assertGreaterThanOrEqual(4000, $slept[0]);
        self::assertLessThanOrEqual(5000, $slept[0]);
    }

    public function testRetryAfterInThePastDoesNotWait(): void
    {
        self::assertSame([0], $this->retryAfter(gmdate('D, d M Y H:i:s \G\M\T', time() - 60)));
    }

    public function testRetryAfterIsClampedToAnUpperBound(): void
    {
        self::assertSame([60000], $this->retryAfter('86400'));
    }

    public function testNegativeRetryAfterDoesNotWait(): void
    {
        self::assertSame([0], $this->retryAfter('-10'));
    }

    public function testUnparsableRetryAfterFallsBackToBackoff(): void
    {
        $slept = $this->retryAfter('soon');

        self::assertGreaterThanOrEqual(200, $slept[0]);
        self::assertLessThanOrEqual(400, $slept[0]);
    }

    public function testNetworkExceptionIsNotAttachedToTheThrownException(): void
    {
        $psr17 = new Psr17Factory();
        $httpClient = new MockClient($psr17);
        $transport = new Transport(
            new Configuration('pk_public', 'sk_secret', maxRetries: 0),
            $httpClient,
            $psr17,
            $psr17,
        );

        $request = $psr17->createRequest('GET', 'https://search.api.metty.eu/search')
            ->withHeader('Authorization', 'Bearer sk_secret');
        $httpClient->addException(new NetworkException('Connection refused.', $request));

        try {
            $transport->get('/search', ['q' => 'x']);
            self::fail('Expected a TransportException.');
        } catch (TransportException $exception) {
            self::assertNull($exception->getPrevious(), 'The PSR-18 exception carries the authenticated request.');
            self::assertStringNotContainsString('sk_secret', print_r($exception, true));
        }
    }

    /**
     * @return list<int>
     */
    private function retryAfter(string $header): array
    {
        $psr17 = new Psr17Factory();
        $httpClient = new MockClient($psr17);
        $slept = [];
        $transport = new Transport(
            new Configuration('pk_public', 'sk_secret', maxRetries: 1),
            $httpClient,
            $psr17,
            $psr17,
            null,
            static function (int $milliseconds) use (&$slept): void {
                $slept[] = $milliseconds;
            },
        );

        $httpClient->addResponse($psr17->createResponse(429)->withHeader('Retry-After', $header));
        $httpClient->addResponse($psr17->createResponse(200)->withBody($psr17->createStream('{"total":0}')));

        $transport->get('/search', ['q' => 'x']);

        return $slept;
    }
}
