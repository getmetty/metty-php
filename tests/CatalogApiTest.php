<?php

declare(strict_types=1);

namespace Metty\Client\Tests;

use Metty\Client\Catalog\CatalogApi;
use Metty\Client\Catalog\CatalogProduct;
use Metty\Client\Exception\ApiException;
use Metty\Client\Exception\ConfigurationException;
use Metty\Client\Exception\SyncIncompleteException;
use PHPUnit\Framework\TestCase;

final class CatalogApiTest extends TestCase
{
    use FakeHttpTrait;

    public function testLargeCatalogIsSplitIntoBatches(): void
    {
        $client = $this->client();
        $products = [];
        for ($i = 0; $i < CatalogApi::MAX_BATCH_SIZE + 1; $i++) {
            $products[] = CatalogProduct::create('sku-' . $i, 'Produkt ' . $i, 'https://e.sk/' . $i);
        }

        $this->queueJson(['results' => array_fill(0, CatalogApi::MAX_BATCH_SIZE, ['id' => 'x', 'status' => 'ok'])]);
        $this->queueJson(['results' => [['id' => 'x', 'status' => 'ok']]]);

        $result = $client->catalog()->replace($products);

        self::assertCount(2, $this->sentRequests());
        self::assertSame('PUT', $this->sentRequests()[0]->getMethod());
        self::assertCount(CatalogApi::MAX_BATCH_SIZE + 1, $result);
        self::assertSame(CatalogApi::MAX_BATCH_SIZE + 1, $result->succeededCount());
        self::assertFalse($result->hasFailures());
    }

    public function testProductIsSentInTheFlatCatalogShape(): void
    {
        $client = $this->client();
        $this->queueJson(['results' => [['id' => 'sku-1', 'status' => 'ok']]]);

        $client->catalog()->replace([
            CatalogProduct::create('sku-1', 'Vŕtačka', 'https://e.sk/1', price: 129.9, inStock: true, brand: 'Bosch', params: ['farba' => 'modrá']),
        ]);

        $body = json_decode((string) $this->sentRequests()[0]->getBody(), true);

        self::assertSame([[
            'id' => 'sku-1',
            'name' => 'Vŕtačka',
            'url' => 'https://e.sk/1',
            'price' => 129.9,
            'in_stock' => true,
            'brand' => 'Bosch',
            'params' => ['farba' => 'modrá'],
        ]], $body);
    }

    public function testPatchKeepsAnExplicitNullToClearAField(): void
    {
        $client = $this->client();
        $this->queueJson(['results' => [['id' => 'sku-1', 'status' => 'ok']]]);

        $client->catalog()->patch([new CatalogProduct('sku-1', ['brand' => null, 'price' => 9.9])]);

        $request = $this->sentRequests()[0];
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame([['id' => 'sku-1', 'brand' => null, 'price' => 9.9]], json_decode((string) $request->getBody(), true));
    }

    public function testUnknownProductFieldIsRejectedBeforeTheRequest(): void
    {
        $this->expectExceptionMessageMatches('/Unknown product fields: in_stok/');

        new CatalogProduct('sku-1', ['in_stok' => true]);
    }

    public function testPartialFailureIsReportedPerProduct(): void
    {
        $client = $this->client();
        $this->queueJson(['results' => [
            ['id' => 'a', 'status' => 'ok'],
            ['id' => 'b', 'status' => 'error', 'error' => 'missing_name', 'message' => 'The field "name" is required.'],
        ]]);

        $result = $client->catalog()->replace([
            CatalogProduct::create('a', 'A', 'https://e.sk/a'),
            new CatalogProduct('b'),
        ]);

        self::assertTrue($result->hasFailures());
        self::assertSame(1, $result->succeededCount());
        self::assertSame('missing_name', $result->failures()[0]->error);
    }

    public function testDeleteSendsIdsInTheBody(): void
    {
        $client = $this->client();
        $this->queueJson(['results' => [['id' => 'a', 'status' => 'ok']]]);

        $client->catalog()->delete(['a']);

        $request = $this->sentRequests()[0];
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('https://api.metty.eu/catalog/products', (string) $request->getUri());
        self::assertSame(['ids' => ['a']], json_decode((string) $request->getBody(), true));
    }

    public function testWritingRequiresSecretKey(): void
    {
        $client = $this->client(secretKey: null);

        $this->expectException(ConfigurationException::class);

        $client->catalog()->replace([CatalogProduct::create('a', 'A', 'https://e.sk/a')]);
    }

    public function testSynchronizeOpensStampsAndCommitsTheSync(): void
    {
        $client = $this->client();
        $this->queueJson(['sync_id' => 'sync_abc']);
        $this->queueJson(['results' => [['id' => 'a', 'status' => 'ok']]]);
        $this->queueJson(['sync_id' => 'sync_abc', 'status' => 'committed', 'kept' => 1, 'removed' => 4]);

        $outcome = $client->catalog()->synchronize([CatalogProduct::create('a', 'A', 'https://e.sk/a')]);

        self::assertSame('sync_abc', $outcome['sync_id']);
        self::assertSame(4, $outcome['commit']['removed']);
        self::assertStringContainsString('sync=sync_abc', (string) $this->sentRequests()[1]->getUri());
        self::assertSame('https://api.metty.eu/catalog/syncs/sync_abc/commit', (string) $this->sentRequests()[2]->getUri());
        self::assertSame(['force' => false], json_decode((string) $this->sentRequests()[2]->getBody(), true));
    }

    public function testSyncIsNotCommittedWhenProductsFailed(): void
    {
        $client = $this->client();
        $this->queueJson(['sync_id' => 'sync_abc']);
        $this->queueJson(['results' => [
            ['id' => 'a', 'status' => 'ok'],
            ['id' => 'b', 'status' => 'error', 'error' => 'invalid_price', 'message' => 'price'],
        ]]);

        try {
            $client->catalog()->synchronize([
                CatalogProduct::create('a', 'A', 'https://e.sk/a'),
                CatalogProduct::create('b', 'B', 'https://e.sk/b'),
            ]);
            self::fail('Očakávaná SyncIncompleteException.');
        } catch (SyncIncompleteException $exception) {
            self::assertSame('sync_abc', $exception->syncId);
            self::assertSame('invalid_price', $exception->result->failures()[0]->error);
        }

        self::assertCount(2, $this->sentRequests(), 'Commit sa nesmie odoslať.');
    }

    public function testForceDoesNotCommitASnapshotWithFailedProducts(): void
    {
        $client = $this->client();
        $this->queueJson(['sync_id' => 'sync_abc']);
        $this->queueJson(['results' => [['id' => 'a', 'status' => 'error', 'error' => 'invalid_price', 'message' => 'price']]]);

        $this->expectException(SyncIncompleteException::class);

        $client->catalog()->synchronize([CatalogProduct::create('a', 'A', 'https://e.sk/a')], force: true);
    }

    public function testEmptySnapshotIsNeverCommitted(): void
    {
        $client = $this->client();
        $this->queueJson(['sync_id' => 'sync_abc']);

        $this->expectExceptionMessageMatches('/would delete the whole catalog/');

        $client->catalog()->synchronize([], force: true);
    }

    public function testExportFollowsThePages(): void
    {
        $client = $this->client();
        $this->queueJson(['total' => 3, 'page' => 1, 'per_page' => 2, 'pages' => 2, 'products' => [['id' => 'a'], ['id' => 'b']]]);
        $this->queueJson(['total' => 3, 'page' => 2, 'per_page' => 2, 'pages' => 2, 'products' => [['id' => 'c']]]);

        $ids = [];
        foreach ($client->catalog()->export(2) as $product) {
            $ids[] = $product['id'];
        }

        self::assertSame(['a', 'b', 'c'], $ids);
        self::assertStringContainsString('page=2', (string) $this->sentRequests()[1]->getUri());
    }

    public function testServerErrorBecomesApiException(): void
    {
        $client = $this->client();
        $this->queueJson(['error' => 'catalog_mode_conflict', 'message' => 'The site is in feed mode.'], 409);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/catalog_mode_conflict/');

        $client->catalog()->delete(['a']);
    }
}
