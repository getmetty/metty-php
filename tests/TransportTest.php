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
use Metty\Client\MettyClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class TransportTest extends TestCase
{
    use FakeHttpTrait;

    public function testCatalogRateLimitIsRetriedWithRetryAfter(): void
    {
        $slept = [];
        $transport = $this->transport(2, $slept);

        $this->httpClient->addResponse($this->psr17->createResponse(429)->withHeader('Retry-After', '2'));
        $this->httpClient->addResponse($this->psr17->createResponse(200)->withBody($this->psr17->createStream('{"results":[]}')));

        $payload = $transport->send('PUT', '/catalog/products', [], [['id' => 'a']], true);

        self::assertSame([2000], $slept, 'Retry-After must dictate how long the client waits.');
        self::assertSame([], $payload['results']);
    }

    public function testSearchRateLimitIsThrownWithoutRetrying(): void
    {
        $client = $this->client(maxRetries: 3);
        $this->queueJson(['error' => 'rate_limited', 'message' => 'Too many requests.'], 429, ['Retry-After' => '0']);
        $this->queueJson(['error' => 'rate_limited', 'message' => 'Too many requests.'], 429, ['Retry-After' => '0']);

        foreach ([static fn () => $client->search()->search('x'), static fn () => $client->search()->suggest('x')] as $call) {
            try {
                $call();
                self::fail('Expected an ApiException.');
            } catch (ApiException $exception) {
                self::assertTrue($exception->isRateLimited());
            }
        }

        self::assertCount(2, $this->sentRequests(), 'A rate-limited read must not wait and repeat inside a page render.');
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
        $slept = [];
        $transport = $this->transport(1, $slept);

        $this->httpClient->addResponse($this->psr17->createResponse(503));
        $this->httpClient->addResponse($this->psr17->createResponse(200)->withBody($this->psr17->createStream('{"results":[]}')));

        $transport->send('PUT', '/catalog/products', [], [['id' => 'a']], true);

        self::assertCount(2, $this->httpClient->getRequests());
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
        $this->queueJson(['error' => 'rate_limited', 'message' => 'Too many requests; slow down and retry later.'], 429);

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

    #[DataProvider('insecureUrls')]
    public function testApiUrlWithoutHttpsIsRejected(string $url): void
    {
        $this->expectExceptionMessage('The API URL must use https.');

        new Configuration('pk_public', catalogUrl: $url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function insecureUrls(): iterable
    {
        yield 'http' => ['http://catalog.api.metty.eu'];
        yield 'uppercase http' => ['HTTP://catalog.api.metty.eu'];
        yield 'ftp' => ['ftp://catalog.api.metty.eu'];
    }

    public function testHttpsUrlOverrideIsAccepted(): void
    {
        $configuration = new Configuration('pk_public', searchUrl: 'https://search.example.test/');

        self::assertSame('https://search.example.test', $configuration->searchUrl);
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

    #[RequiresPhp('>= 8.2')]
    public function testSecretKeyNeverLeaksIntoConstructionTrace(): void
    {
        $ignoreArgs = (string) ini_set('zend.exception_ignore_args', '0');

        try {
            MettyClient::create('pk_public', 'sk_secret', 'not a url');
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertNotContains('sk_secret', array_merge(...array_column($exception->getTrace(), 'args')));
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs);
        }
    }

    public function testRequestBodyThatCannotBeEncodedIsAConfigurationException(): void
    {
        $client = $this->client();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('could not be encoded as JSON');

        $client->catalog()->replace([CatalogProduct::create("a\xB1", 'A', 'https://e.sk/a')]);
    }

    public function testNetworkFailureMessageNamesTheCauseAndRetriesAreLogged(): void
    {
        $psr17 = new Psr17Factory();
        $httpClient = new MockClient($psr17);
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $transport = new Transport(
            new Configuration('pk_public', 'sk_secret', maxRetries: 1),
            $httpClient,
            $psr17,
            $psr17,
            $logger,
            static function (int $milliseconds): void {},
        );
        $request = $psr17->createRequest('GET', 'https://search.api.metty.eu/search');
        $httpClient->addException(new NetworkException('Operation timed out.', $request));
        $httpClient->addException(new NetworkException('Operation timed out.', $request));

        try {
            $transport->get('/search');
            self::fail('Expected a TransportException.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('Operation timed out.', $exception->getMessage());
        }

        self::assertCount(1, $logger->records);
        self::assertSame('Metty request retried.', $logger->records[0][0]);
        self::assertSame(NetworkException::class, $logger->records[0][1]['exception']);
    }

    #[DataProvider('retryMatrix')]
    public function testRetryMatrix(string $method, string $failure, int $expectedRequests): void
    {
        $slept = [];
        $transport = $this->transport(1, $slept);
        $success = $this->psr17->createResponse(200)->withBody($this->psr17->createStream('{"results":[]}'));

        if ($failure === 'network') {
            $this->httpClient->addException(new NetworkException('down', $this->psr17->createRequest($method, 'https://catalog.api.metty.eu/x')));
        } else {
            $this->httpClient->addResponse($this->psr17->createResponse((int) $failure));
        }

        $this->httpClient->addResponse($success);

        try {
            $transport->send($method, '/catalog/products', [], null, true);
        } catch (TransportException | ApiException) {
        }

        self::assertCount($expectedRequests, $this->httpClient->getRequests());
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function retryMatrix(): iterable
    {
        yield 'GET network' => ['GET', 'network', 2];
        yield 'PUT network' => ['PUT', 'network', 2];
        yield 'POST network' => ['POST', 'network', 1];
        yield 'DELETE network' => ['DELETE', 'network', 1];
        yield 'GET 429' => ['GET', '429', 2];
        yield 'POST 429' => ['POST', '429', 2];
        yield 'DELETE 429' => ['DELETE', '429', 2];
        yield 'GET 503' => ['GET', '503', 2];
        yield 'PUT 503' => ['PUT', '503', 2];
        yield 'POST 503' => ['POST', '503', 1];
        yield 'DELETE 503' => ['DELETE', '503', 1];
    }

    /**
     * @param list<int> $slept
     */
    private function transport(int $maxRetries, array &$slept): Transport
    {
        $this->psr17 = new Psr17Factory();
        $this->httpClient = new MockClient($this->psr17);

        return new Transport(
            new Configuration('pk_public', 'sk_secret', maxRetries: $maxRetries),
            $this->httpClient,
            $this->psr17,
            $this->psr17,
            null,
            static function (int $milliseconds) use (&$slept): void {
                $slept[] = $milliseconds;
            },
        );
    }

    /**
     * @return list<int>
     */
    private function retryAfter(string $header): array
    {
        $slept = [];
        $transport = $this->transport(1, $slept);
        $psr17 = $this->psr17;
        $httpClient = $this->httpClient;

        $httpClient->addResponse($psr17->createResponse(429)->withHeader('Retry-After', $header));
        $httpClient->addResponse($psr17->createResponse(200)->withBody($psr17->createStream('{"results":[]}')));

        $transport->send('PUT', '/catalog/products', [], [['id' => 'a']], true);

        return $slept;
    }
}
