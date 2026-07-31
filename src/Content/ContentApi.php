<?php

declare(strict_types=1);

namespace Metty\Client\Content;

use Metty\Client\Configuration;
use Metty\Client\Exception\ApiException;
use Metty\Client\Http\Transport;

/**
 * Zápisová časť Metty API.
 *
 * Klient rieši za integrátora dávkovanie, partial failure a idempotenciu — presne to, čo si inak
 * každý napíše zle.
 */
final class ContentApi
{
    private const PATH = '/v1/content';

    public function __construct(
        private readonly Transport $transport,
        private readonly Configuration $configuration,
    ) {}

    /**
     * Create alebo úplné nahradenie — neuvedené pole sa na serveri zmaže.
     *
     * @param iterable<ContentItem> $items
     */
    public function replace(iterable $items, ?string $idempotencyKey = null): BatchResult
    {
        return $this->write('POST', $items, $idempotencyKey);
    }

    /**
     * Čiastočná zmena — neuvedené pole ostáva; neexistujúci objekt je chyba, nie create.
     *
     * @param iterable<ContentItem> $items
     */
    public function patch(iterable $items, ?string $idempotencyKey = null): BatchResult
    {
        return $this->write('PATCH', $items, $idempotencyKey);
    }

    /**
     * @param iterable<string> $identities
     */
    public function delete(iterable $identities, ?string $idempotencyKey = null): BatchResult
    {
        $items = [];
        foreach ($identities as $identity) {
            $items[] = ['identity' => $identity];
        }

        return $this->sendBatches('DELETE', $items, $idempotencyKey);
    }

    /**
     * Uzavrie generáciu; objekty mimo nej server odstráni.
     *
     * Server odmietne commit generácie, ktorá pokrýva menej než polovicu katalógu — poistka proti
     * commitu neúplného snapshotu. `force` ju vedome prepíše.
     *
     * @return array{generation: string, status: string, kept: int, removed: int}
     */
    public function commit(string $generation, bool $force = false): array
    {
        /** @var array{generation: string, status: string, kept: int, removed: int} $response */
        $response = $this->transport->send('POST', self::PATH . '/commit', [], [
            'generation' => $generation,
            'force' => $force,
        ], true);

        return $response;
    }

    /**
     * Bezpečný full snapshot: nahrá celý katalóg pod jednou generáciou a až potom ju commitne.
     *
     * @param iterable<ContentItem> $items
     *
     * @return array{result: BatchResult, commit: array{generation: string, status: string, kept: int, removed: int}}
     */
    public function synchronizeCatalog(iterable $items, string $generation, bool $force = false): array
    {
        $stamped = [];
        foreach ($items as $item) {
            $stamped[] = $item->withGeneration($generation);
        }

        $result = $this->replace($stamped);
        if ($result->hasFailures() && !$force) {
            // Commit po čiastočne neúspešnom snapshote by zmazal objekty, ktoré sa práve nepodarilo
            // nahrať. Radšej vrátime chyby a katalóg necháme tak, ako bol.
            throw new ApiException(
                409,
                'snapshot_incomplete',
                sprintf('%d objects failed; the generation was not committed.', count($result->failures())),
            );
        }

        return [
            'result' => $result,
            'commit' => $this->commit($generation, $force),
        ];
    }

    /**
     * Prejde celý katalóg na serveri po stránkach.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function export(int $pageSize = 100): \Generator
    {
        $cursor = null;

        do {
            $response = $this->transport->get('/v1/content_export', [
                'size' => $pageSize,
                'cursor' => $cursor,
            ], true);

            foreach ($response['objects'] ?? [] as $object) {
                if (is_array($object)) {
                    yield $object;
                }
            }

            $cursor = $response['next_cursor'] ?? null;
        } while ($cursor !== null);
    }

    /**
     * @param iterable<ContentItem> $items
     */
    private function write(string $method, iterable $items, ?string $idempotencyKey): BatchResult
    {
        $payload = [];
        foreach ($items as $item) {
            $payload[] = $item->jsonSerialize();
        }

        return $this->sendBatches($method, $payload, $idempotencyKey);
    }

    /**
     * @param list<array<string, mixed>> $objects
     */
    private function sendBatches(string $method, array $objects, ?string $idempotencyKey): BatchResult
    {
        $result = new BatchResult();
        if ($objects === []) {
            return $result;
        }

        foreach (array_chunk($objects, $this->configuration->batchSize) as $index => $batch) {
            $headers = [];
            if ($idempotencyKey !== null) {
                // Kľúč musí byť pre každú dávku iný, inak by druhá dávka dostala odpoveď prvej.
                $headers['Idempotency-Key'] = $idempotencyKey . '-' . $index;
            }

            $response = $this->transport->send($method, self::PATH, [], $batch, true, $headers);
            $result = $result->merge(BatchResult::fromArray($response));
        }

        return $result;
    }
}
