<?php

declare(strict_types=1);

namespace Metty\Client\Tests;

use Metty\Client\Content\ContentItem;
use Metty\Client\Exception\ApiException;
use Metty\Client\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class ContentApiTest extends TestCase
{
    use FakeHttpTrait;

    public function testLargeCatalogIsSplitIntoBatches(): void
    {
        $client = $this->client(batchSize: 2);
        $this->queueJson(['objects' => [['identity' => 'a', 'status' => 'created'], ['identity' => 'b', 'status' => 'created']]]);
        $this->queueJson(['objects' => [['identity' => 'c', 'status' => 'created']]]);

        $result = $client->content()->replace([
            ContentItem::product('a', 'A', 'https://e.sk/a'),
            ContentItem::product('b', 'B', 'https://e.sk/b'),
            ContentItem::product('c', 'C', 'https://e.sk/c'),
        ]);

        self::assertCount(2, $this->sentRequests());
        self::assertCount(3, $result);
        self::assertSame(3, $result->succeededCount());
        self::assertFalse($result->hasFailures());
    }

    public function testPartialFailureIsReportedPerObject(): void
    {
        $client = $this->client();
        $this->queueJson(['objects' => [
            ['identity' => 'a', 'status' => 'created'],
            ['identity' => 'b', 'status' => 'failed', 'error' => ['code' => 'missing_field', 'message' => 'title']],
        ]]);

        $result = $client->content()->replace([
            ContentItem::product('a', 'A', 'https://e.sk/a'),
            new ContentItem('b'),
        ]);

        self::assertTrue($result->hasFailures());
        self::assertSame(1, $result->succeededCount());
        self::assertSame('missing_field', $result->failures()[0]->errorCode);
    }

    public function testIdempotencyKeyIsUniquePerBatch(): void
    {
        $client = $this->client(batchSize: 1);
        $this->queueJson(['objects' => [['identity' => 'a', 'status' => 'created']]]);
        $this->queueJson(['objects' => [['identity' => 'b', 'status' => 'created']]]);

        $client->content()->replace([
            ContentItem::product('a', 'A', 'https://e.sk/a'),
            ContentItem::product('b', 'B', 'https://e.sk/b'),
        ], idempotencyKey: 'nightly');

        self::assertSame('nightly-0', $this->sentRequests()[0]->getHeaderLine('Idempotency-Key'));
        self::assertSame('nightly-1', $this->sentRequests()[1]->getHeaderLine('Idempotency-Key'));
    }

    public function testWritingRequiresSecretKey(): void
    {
        $client = $this->client(secretKey: null);

        $this->expectException(ConfigurationException::class);

        $client->content()->replace([ContentItem::product('a', 'A', 'https://e.sk/a')]);
    }

    public function testSnapshotIsNotCommittedWhenObjectsFailed(): void
    {
        $client = $this->client();
        $this->queueJson(['objects' => [
            ['identity' => 'a', 'status' => 'created'],
            ['identity' => 'b', 'status' => 'failed', 'error' => ['code' => 'invalid_field', 'message' => 'price']],
        ]]);

        $this->expectExceptionMessageMatches('/snapshot_incomplete/');

        $client->content()->synchronizeCatalog([
            ContentItem::product('a', 'A', 'https://e.sk/a'),
            ContentItem::product('b', 'B', 'https://e.sk/b'),
        ], '2026-07-31');
    }

    public function testSnapshotCommitsWhenEverythingPassed(): void
    {
        $client = $this->client();
        $this->queueJson(['objects' => [['identity' => 'a', 'status' => 'created']]]);
        $this->queueJson(['generation' => 'g1', 'status' => 'committed', 'kept' => 1, 'removed' => 4]);

        $outcome = $client->content()->synchronizeCatalog([ContentItem::product('a', 'A', 'https://e.sk/a')], 'g1');

        self::assertSame(4, $outcome['commit']['removed']);
        $body = (string) $this->sentRequests()[0]->getBody();
        self::assertStringContainsString('"generation":"g1"', $body);
    }

    public function testExportFollowsTheCursor(): void
    {
        $client = $this->client();
        $this->queueJson(['objects' => [['identity' => 'a'], ['identity' => 'b']], 'next_cursor' => 12]);
        $this->queueJson(['objects' => [['identity' => 'c']], 'next_cursor' => null]);

        $identities = [];
        foreach ($client->content()->export(2) as $object) {
            $identities[] = $object['identity'];
        }

        self::assertSame(['a', 'b', 'c'], $identities);
        self::assertStringContainsString('cursor=12', (string) $this->sentRequests()[1]->getUri());
    }

    public function testServerErrorBecomesApiException(): void
    {
        $client = $this->client();
        $this->queueJson(['error' => ['code' => 'catalog_mode_conflict', 'message' => 'feed mode']], 409);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/catalog_mode_conflict/');

        $client->content()->delete(['a']);
    }
}
