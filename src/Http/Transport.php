<?php

declare(strict_types=1);

namespace Metty\Client\Http;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Metty\Client\Configuration;
use Metty\Client\Exception\ApiException;
use Metty\Client\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP vrstva klienta: autentifikácia, opakovanie a preklad chýb.
 *
 * Search API sa autentifikuje verejným kľúčom v query, Catalog API hlavičkou `Authorization: Bearer
 * sk_…`; každé beží na vlastnom hoste, preto ten istý príznak vyberá aj adresu. Secret sa nikdy
 * neloguje ani nedostane do výnimky — to isté pravidlo ako na serveri.
 *
 * Opakovaná hodnota v query odchádza ako `pole[]=…`; bez zátvoriek si server ponechá iba poslednú.
 *
 * `429` sa opakuje vždy — server požiadavku odmietol, nevykonal ju. Chybu servera alebo siete
 * opakujeme iba pri metódach, ktoré sa dajú zopakovať bez zmeny výsledku.
 */
final class Transport
{
    private const SERVER_ERROR_STATUSES = [500, 502, 503, 504];

    /**
     * Metódy, ktoré server vykoná rovnako aj pri zopakovaní. `POST` otvára sync alebo commit
     * a `DELETE` hlási druhýkrát `not_found` — po nejasnom výsledku by opakovanie klamalo.
     */
    private const REPEATABLE_METHODS = ['GET', 'PUT', 'PATCH'];

    private const BASE_BACKOFF_MS = 200;

    private readonly ClientInterface $httpClient;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Configuration $configuration,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        private readonly ?\Closure $sleeper = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, scalar|array<int, string>|null> $query
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], bool $catalog = false): array
    {
        return $this->send('GET', $path, $query, null, $catalog);
    }

    /**
     * @param array<string, scalar|array<int, string>|null> $query
     * @param array<string, mixed>|list<mixed>|null         $body
     *
     * @return array<string, mixed>
     */
    public function send(
        string $method,
        string $path,
        array $query = [],
        array|null $body = null,
        bool $catalog = false,
    ): array {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->httpClient->sendRequest($this->buildRequest($method, $path, $query, $body, $catalog));
            } catch (ClientExceptionInterface $exception) {
                if (!$this->mayRepeat($method) || $attempt > $this->configuration->maxRetries) {
                    throw new TransportException('The request to Metty failed: ' . $exception->getMessage(), 0, $exception);
                }

                $this->wait($attempt, null);
                continue;
            }

            $status = $response->getStatusCode();
            if ($status < 400) {
                return $this->decode($response);
            }

            if ($this->isRetryable($method, $status) && $attempt <= $this->configuration->maxRetries) {
                $this->logger->warning('Metty request retried.', [
                    'path' => $path,
                    'status' => $status,
                    'attempt' => $attempt,
                ]);
                $this->wait($attempt, $response->getHeaderLine('Retry-After'));
                continue;
            }

            throw $this->apiException($response, $status);
        }
    }

    private function isRetryable(string $method, int $status): bool
    {
        if ($status === 429) {
            return true;
        }

        return $this->mayRepeat($method) && in_array($status, self::SERVER_ERROR_STATUSES, true);
    }

    private function mayRepeat(string $method): bool
    {
        return in_array($method, self::REPEATABLE_METHODS, true);
    }

    /**
     * @param array<string, scalar|array<int, string>|null> $query
     * @param array<string, mixed>|list<mixed>|null         $body
     */
    private function buildRequest(
        string $method,
        string $path,
        array $query,
        array|null $body,
        bool $catalog,
    ): RequestInterface {
        if (!$catalog) {
            $query['key'] = $this->configuration->requirePublicKey();
        }

        $uri = $this->configuration->baseUrl($catalog) . $path;
        $queryString = $this->buildQueryString($query);
        if ($queryString !== '') {
            $uri .= '?' . $queryString;
        }

        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'metty-php');

        if ($catalog) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->configuration->requireSecretKey());
        }

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream((string) json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        }

        return $request;
    }

    /**
     * @param array<string, scalar|array<int, string>|null> $query
     */
    private function buildQueryString(array $query): string
    {
        $parts = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = rawurlencode($name . '[]') . '=' . rawurlencode($item);
                }

                continue;
            }

            $parts[] = rawurlencode($name) . '=' . rawurlencode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        return implode('&', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $contents = (string) $response->getBody();
        if (trim($contents) === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new TransportException('Metty returned a response that is not valid JSON.', 0, $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function apiException(ResponseInterface $response, int $status): ApiException
    {
        try {
            $payload = $this->decode($response);
        } catch (TransportException) {
            $payload = [];
        }

        $error = $payload['error'] ?? null;
        if (is_array($error)) {
            $payload = $error;
            $error = $error['code'] ?? null;
        }

        return new ApiException(
            $status,
            is_string($error) ? $error : 'http_error',
            is_string($payload['message'] ?? null) ? $payload['message'] : 'The Metty API returned an error.',
        );
    }

    private function wait(int $attempt, ?string $retryAfter): void
    {
        $milliseconds = $retryAfter !== null && is_numeric($retryAfter)
            ? (int) ((float) $retryAfter * 1000)
            : self::BASE_BACKOFF_MS * (2 ** ($attempt - 1)) + random_int(0, self::BASE_BACKOFF_MS);

        if ($this->sleeper !== null) {
            ($this->sleeper)($milliseconds);

            return;
        }

        usleep($milliseconds * 1000);
    }
}
