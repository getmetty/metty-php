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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP vrstva klienta: autentifikácia, opakovanie a preklad chýb.
 *
 * `Authorization` sa nikdy neloguje ani nedostane do výnimky — to isté pravidlo ako na serveri.
 */
final class Transport
{
    /**
     * Opakujeme iba `429` a `5xx`; ostatné `4xx` sú chyby požiadavky a opakovanie ich neopraví.
     */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

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
        /** Uspávanie medzi pokusmi; testy ho nahradia, aby nečakali. */
        private readonly ?\Closure $sleeper = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, scalar|array<int, string>|null> $query
     * @param array<string, string>                         $headers
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], bool $authenticated = false, array $headers = []): array
    {
        return $this->send('GET', $path, $query, null, $authenticated, $headers);
    }

    /**
     * @param array<string, scalar|array<int, string>|null> $query
     * @param array<string, mixed>|list<mixed>              $body
     * @param array<string, string>                         $headers
     *
     * @return array<string, mixed>
     */
    public function send(
        string $method,
        string $path,
        array $query = [],
        array|null $body = null,
        bool $authenticated = false,
        array $headers = [],
    ): array {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->httpClient->sendRequest($this->buildRequest($method, $path, $query, $body, $authenticated, $headers));
            } catch (ClientExceptionInterface $exception) {
                if ($attempt > $this->configuration->maxRetries) {
                    throw new TransportException('The request to Metty failed: ' . $exception->getMessage(), 0, $exception);
                }

                $this->wait($attempt, null);
                continue;
            }

            $status = $response->getStatusCode();
            if ($status < 400) {
                return $this->decode($response);
            }

            if (in_array($status, self::RETRYABLE_STATUSES, true) && $attempt <= $this->configuration->maxRetries) {
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

    /**
     * @param array<string, scalar|array<int, string>|null> $query
     * @param array<string, mixed>|list<mixed>|null         $body
     * @param array<string, string>                         $headers
     */
    private function buildRequest(
        string $method,
        string $path,
        array $query,
        array|null $body,
        bool $authenticated,
        array $headers,
    ): \Psr\Http\Message\RequestInterface {
        $query['api_key'] ??= $this->configuration->apiKey;
        $uri = $this->configuration->baseUrl . $path;
        $queryString = $this->buildQueryString($query);
        if ($queryString !== '') {
            $uri .= '?' . $queryString;
        }

        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'metty-php-client');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($authenticated) {
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
                // Facetové filtre chodia ako `f[]=…` a poradie musí zostať zachované.
                foreach ($value as $item) {
                    $parts[] = rawurlencode($name . '[]') . '=' . rawurlencode((string) $item);
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
        $payload = [];

        try {
            $payload = $this->decode($response);
        } catch (TransportException) {
            // Chybová odpoveď bez JSON tela: zostane len stavový kód.
        }

        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

        return new ApiException(
            $status,
            (string) ($error['code'] ?? 'http_error'),
            (string) ($error['message'] ?? 'The Metty API returned an error.'),
        );
    }

    private function wait(int $attempt, ?string $retryAfter): void
    {
        $milliseconds = $retryAfter !== null && is_numeric($retryAfter)
            ? (int) ((float) $retryAfter * 1000)
            // Exponenciálny backoff s jitterom, aby sa opakovania viacerých procesov nezrazili.
            : self::BASE_BACKOFF_MS * (2 ** ($attempt - 1)) + random_int(0, self::BASE_BACKOFF_MS);

        if ($this->sleeper !== null) {
            ($this->sleeper)($milliseconds);

            return;
        }

        usleep($milliseconds * 1000);
    }
}
