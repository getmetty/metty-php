<?php

declare(strict_types=1);

namespace Metty\Client\Catalog;

use Metty\Client\Exception\ConfigurationException;
use Metty\Client\Exception\SyncIncompleteException;
use Metty\Client\Exception\TransportException;
use Metty\Client\Http\Transport;

/**
 * The write side of the Metty API, authenticated with `Authorization: Bearer sk_…`.
 *
 * The client takes care of batching up to the server limit and of partial failures. No idempotency
 * key is needed: the server writes by `id`, so a repeated batch cannot create duplicates.
 */
final class CatalogApi
{
    /** The server accepts at most 100 products per batch; a larger catalog is split by the client. */
    public const MAX_BATCH_SIZE = 100;

    public const MAX_EXPORT_PER_PAGE = 500;

    private const PRODUCTS_PATH = '/catalog/products';

    private const SYNCS_PATH = '/catalog/syncs';

    public function __construct(
        private readonly Transport $transport,
    ) {}

    /**
     * Full replacement: a field that is not listed is cleared on the server.
     *
     * @param iterable<CatalogProduct> $products
     */
    public function replace(iterable $products, ?string $syncId = null): WriteResult
    {
        return $this->write('PUT', $products, $syncId);
    }

    /**
     * Partial change: an omitted field is kept, and a missing product is an error rather than a create.
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
     * Opens a full sync; the products written under its ID form the new catalog snapshot.
     */
    public function beginSync(): string
    {
        $syncId = $this->transport->send('POST', self::SYNCS_PATH, [], null, true)['sync_id'] ?? null;
        if (!is_string($syncId) || $syncId === '') {
            throw new TransportException('Metty did not return a sync_id when opening a sync.');
        }

        return $syncId;
    }

    /**
     * Closes the sync; products that did not arrive during it are deleted by the server.
     *
     * A snapshot covering less than half of the catalog is rejected (`generation_incomplete`) as a
     * safeguard against committing an incomplete snapshot. `force` overrides it deliberately.
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
     * Safe full snapshot: opens a sync, uploads the whole catalog and only then commits it.
     *
     * A snapshot with even one failed product is never committed. `force` applies solely to the
     * server-side safeguard against a small snapshot and is passed on to the commit.
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
     * Walks the whole catalog on the server, page by page.
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
