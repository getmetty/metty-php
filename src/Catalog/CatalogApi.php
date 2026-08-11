<?php

declare(strict_types=1);

namespace Metty\Client\Catalog;

use Metty\Client\Exception\ConfigurationException;
use Metty\Client\Exception\SyncIncompleteException;
use Metty\Client\Http\Transport;

/**
 * Zápisová časť Metty API — `Authorization: Bearer sk_…`.
 *
 * Klient rieši za integrátora dávkovanie na limit servera a partial failure. Idempotency kľúč
 * netreba, server zapisuje podľa `id`, takže zopakovaná dávka nevytvorí duplicity.
 */
final class CatalogApi
{
    /** Server prijme najviac 100 produktov na dávku; klient väčší katalóg rozdelí sám. */
    public const MAX_BATCH_SIZE = 100;

    public const MAX_EXPORT_PER_PAGE = 500;

    private const PRODUCTS_PATH = '/catalog/products';

    private const SYNCS_PATH = '/catalog/syncs';

    public function __construct(
        private readonly Transport $transport,
    ) {}

    /**
     * Úplné nahradenie — neuvedené pole sa na serveri zmaže.
     *
     * @param iterable<CatalogProduct> $products
     */
    public function replace(iterable $products, ?string $syncId = null): WriteResult
    {
        return $this->write('PUT', $products, $syncId);
    }

    /**
     * Čiastočná zmena — neuvedené pole ostáva; neexistujúci produkt je chyba, nie create.
     *
     * @param iterable<CatalogProduct> $products
     */
    public function patch(iterable $products): WriteResult
    {
        return $this->write('PATCH', $products, null);
    }

    /**
     * @param iterable<string> $ids
     */
    public function delete(iterable $ids): WriteResult
    {
        $result = new WriteResult();
        foreach (array_chunk($this->toList($ids), self::MAX_BATCH_SIZE) as $batch) {
            $result = $result->merge(WriteResult::fromArray(
                $this->transport->send('DELETE', self::PRODUCTS_PATH, [], ['ids' => $batch], true),
            ));
        }

        return $result;
    }

    /**
     * Otvorí full sync; produkty zapísané pod jeho ID tvoria nový snapshot katalógu.
     */
    public function beginSync(): string
    {
        return (string) ($this->transport->send('POST', self::SYNCS_PATH, [], null, true)['sync_id'] ?? '');
    }

    /**
     * Uzavrie sync; produkty, ktoré počas neho neprišli, server zmaže.
     *
     * Snapshot pokrývajúci menej než polovicu katalógu server odmietne (`generation_incomplete`) —
     * poistka proti commitu neúplného snapshotu. `force` ju vedome prepíše.
     *
     * @return array{sync_id: string, status: string, kept: int, removed: int}
     */
    public function commit(string $syncId, bool $force = false): array
    {
        /** @var array{sync_id: string, status: string, kept: int, removed: int} $response */
        $response = $this->transport->send('POST', self::SYNCS_PATH . '/' . rawurlencode($syncId) . '/commit', [], ['force' => $force], true);

        return $response;
    }

    /**
     * Bezpečný full snapshot: otvorí sync, nahrá celý katalóg a až potom ho commitne.
     *
     * Snapshot čo i len s jedným zlyhaným produktom sa nikdy necommitne — `force` sa týka výhradne
     * serverovej poistky proti malému snapshotu a odovzdáva sa až commitu.
     *
     * @param iterable<CatalogProduct> $products
     *
     * @return array{sync_id: string, result: WriteResult, commit: array{sync_id: string, status: string, kept: int, removed: int}}
     */
    public function synchronize(iterable $products, bool $force = false): array
    {
        $syncId = $this->beginSync();
        $result = $this->replace($products, $syncId);

        if (count($result) === 0) {
            throw new ConfigurationException('A full sync needs at least one product; committing an empty snapshot would delete the whole catalog.');
        }

        if ($result->hasFailures()) {
            throw new SyncIncompleteException($syncId, $result);
        }

        return [
            'sync_id' => $syncId,
            'result' => $result,
            'commit' => $this->commit($syncId, $force),
        ];
    }

    /**
     * Prejde celý katalóg na serveri po stránkach.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function export(int $perPage = 100): \Generator
    {
        $page = 1;

        do {
            $response = $this->transport->get(self::PRODUCTS_PATH, [
                'page' => $page,
                'per_page' => min($perPage, self::MAX_EXPORT_PER_PAGE),
            ], true);

            foreach (is_array($response['products'] ?? null) ? $response['products'] : [] as $product) {
                if (is_array($product)) {
                    yield $product;
                }
            }

            $pages = (int) ($response['pages'] ?? 0);
            $page++;
        } while ($page <= $pages);
    }

    /**
     * @param iterable<CatalogProduct> $products
     */
    private function write(string $method, iterable $products, ?string $syncId): WriteResult
    {
        $result = new WriteResult();
        $batch = [];

        foreach ($products as $product) {
            $batch[] = $product->jsonSerialize();

            if (count($batch) === self::MAX_BATCH_SIZE) {
                $result = $result->merge($this->sendBatch($method, $batch, $syncId));
                $batch = [];
            }
        }

        return $batch === [] ? $result : $result->merge($this->sendBatch($method, $batch, $syncId));
    }

    /**
     * @param list<array<string, mixed>> $batch
     */
    private function sendBatch(string $method, array $batch, ?string $syncId): WriteResult
    {
        return WriteResult::fromArray(
            $this->transport->send($method, self::PRODUCTS_PATH, $syncId === null ? [] : ['sync' => $syncId], $batch, true),
        );
    }

    /**
     * @param iterable<string> $ids
     *
     * @return list<string>
     */
    private function toList(iterable $ids): array
    {
        $list = [];
        foreach ($ids as $id) {
            $list[] = $id;
        }

        return $list;
    }
}
